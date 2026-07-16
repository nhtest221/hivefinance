# HiveFin — Domain Events

**Frozen inputs (immutable):** SRS v3.0 · ADR-001…009 · Domain Model v2 · Interaction Matrix · AP-001 · Context Map (ApplySettlement refinement) · Aggregate Design.
**Contradiction check:** none. No business rule introduced or modified.
**Purpose:** the definitive event catalog + choreography, distinguishing what commits in-transaction from what propagates eventually.

---

## 1. Event Design Principles

- **Past-tense facts.** Events record what *happened* (`InvoiceIssued`), never intent.
- **Immutable & audit-aligned.** Every event is immutable and mirrors the immutable posted record (ADR-002); events feed the audit log and Reporting.
- **UUID-only payloads.** Events carry `DocumentId`/entity/party/account/rate **IDs** + primitives + `Money`/`TaxSnapshot`/`RateRef` value objects — never aggregate instances or business numbers (identity ≠ number, ADR-009).
- **Emitted post-commit.** An event is published only after its producing aggregate's transaction commits.
- **Two propagation tiers** (from Aggregate Design §0):
  - **In-transaction commands** do the strong work (recognition, settlement postings, `ApplySettlement`). Events do **not** drive these.
  - **Events** drive the **eventual** tier: Reporting projections, audit, notifications.
- **In-process bus (MVP).** The modular monolith dispatches events in-process; each event has a **versioned schema** so future extraction (Reporting, Tax, Identity) needs no rewrite.
- **Idempotent consumers.** Projection/notification handlers are idempotent (event id + version).

---

## 2. Event Catalog (by producing context)

Columns: **Event** · **Producer (aggregate)** · **Trigger (command)** · **Key payload (UUIDs/VOs)** · **Consumers** · **Effect**. All consumption is **async** unless marked ⚡ (in-transaction, strong).

### Ledger & Posting
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `JournalPosted` | JournalEntry | PostJournal / recognition / settlement | entryId, entityId, periodRef, lines[accountId, debit/credit Money, sbu], sourceDocumentId | Reporting, Audit | TB/GL/P&L/BS projections |
| `JournalReversed` | JournalEntry | ReverseJournal | entryId, reversalOfEntryId | Reporting, Audit | projection reversal |
| `SystemEntryPosted` | JournalEntry | recognition/settlement | entryId, sourceDocumentId | Reporting, Audit | projection update |

### Receivables
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `InvoiceIssued` | Invoice | IssueInvoice | invoiceId, customerId, docNumber, Money, taxSnapshots[], rateRef, dueDate, periodRef | Reporting, Audit | AR/ageing/P&L projections |
| `InvoiceStatusChanged` | Invoice | ApplySettlement⚡→event | invoiceId, status, openBalance | Reporting, Notifications | display status, ageing |
| `InvoiceVoided` | Invoice | VoidInvoice | invoiceId, reasonCode | Reporting, Audit | reverse projections |
| `CreditNoteIssued` | CreditNote | IssueCreditNote | creditNoteId, sourceInvoiceId, Money, taxSnapshot, periodRef | Reporting, Tax(summary), Audit | VAT adj in note period |
| `CreditNoteDispositionSet` | CreditNote | SetDisposition | creditNoteId, disposition(Applied/Held/Refunded) | Reporting | AR/credit projections |

### Payables
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `BillApproved` | Bill | ApproveBill | billId, vendorId, Money, taxSnapshots[], sbuSplit, ait/vds, rateRef | Reporting, Audit | AP/ageing/expense projections |
| `BillStatusChanged` | Bill | ApplySettlement⚡→event | billId, status, openBalance | Reporting, Notifications | display status, ageing |
| `BillVoided` | Bill | VoidBill | billId, reasonCode | Reporting, Audit | reverse projections |
| `DebitNoteIssued` | DebitNote | IssueDebitNote | debitNoteId, sourceBillId, Money, taxSnapshot | Reporting, Tax, Audit | input-VAT adj in note period |
| `ExpenseRecorded` | Expense | RecordExpense | expenseId, category, sbuSplit, settlementType, Money, ait | Reporting, Audit | expense projections |

### Settlement & Cash Application
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `ReceiptAllocated` | Allocation | RecordReceipt | allocationId, links[invoiceId, applied Money], bankAccountId, rateRef, withholding | Reporting(cash view), Audit | cash collections, AR |
| `PaymentAllocated` | Allocation | RecordPayment | allocationId, links[billId, applied Money], bankAccountId, rateRef, withholding | Reporting(cash view), Audit | cash payments, AP |
| `RealisedFXRecognised` | Allocation | settlement | allocationId, Money(gain/loss), accountId | Reporting, Audit | FX P&L projection |
| `WithholdingCaptured` | Allocation | ApplyWithholding | allocationId, type(AIT/VDS), Money, accountId | Reporting(tax), Audit | 1070/1072 registers |
| `AdvanceRecorded` / `CreditHeld` | PartyCreditBalance | RecordAdvance/HoldCredit | partyId, Money | Reporting | 2060/1075 projection |
| `CreditApplied` / `CreditRefunded` | Allocation | ApplyCredit/RefundCredit | allocationId, partyId, Money, targetDocId? | Reporting, Receivables/Payables(display) | credit drawdown |
| `AllocationReversed` | Allocation | ReverseAllocation | allocationId | Reporting, Audit | reverse settlement projections |

### Tax
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `TaxDetermined` | TaxCode | DetermineTax | snapshot(code, rate, method, jurisdiction, version) | (embedded in document) | reproducible tax |
| `TaxCodeVersioned` | TaxCode | AddVersion | codeId, versionId, effectiveDates | Reporting, Audit | future determinations |
| `TaxPackConfigured` | TaxPack | ConfigureTaxPack | packId, jurisdiction | Audit | new jurisdiction |

### Currency & FX
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `RateRecordAdded` | RateRecord | AddRateRecord | rateId, pair, rate, effectiveDate, source | (referenced by documents/allocations) | valuation input |
| `UnrealisedFXRevalued` | RevaluationRun | RunRevaluation | runId, periodRef, figures[accountId, Money] | Ledger(post), Reporting, Audit | period-end revaluation |
| `RevaluationReversed` | RevaluationRun | next-period start | runId | Ledger(post), Reporting | reversal |

### Period & Close
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `PeriodSoftClosed` | AccountingPeriod | SoftClose | periodRef | Ledger/Rec/Pay/Settlement, Reporting | restrict to adjusting entries |
| `PeriodHardClosed` | AccountingPeriod | HardClose | periodRef | all posting contexts, Reporting | block in-period postings |
| `VATPeriodLocked` | AccountingPeriod | LockVAT | periodRef | Receivables, Settlement | enforce void-window (ADR-003) |
| `PeriodReopened` | AccountingPeriod | Reopen | periodRef, reasonCode, approverId | affected users(notify), Audit | temporary unlock + re-close due |
| `YearEndRolled` | AccountingPeriod | RollYearEnd | fiscalYear, retainedEarningsPosting | Ledger, Reporting | 3020/3030 roll |

### Identity & Access
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `UserInvited` / `UserDeactivated` | User | Invite/Deactivate | userId, entityGrants | Audit | access change |
| `RoleAssigned` | User | AssignRole | userId, roleId | Audit | permission change |
| `SoDExceptionRaised` | User | RaiseSoDException | userId, conflict, justification | Reporting(review), Audit | post-facto review queue |
| `BreakGlassActivated` | User | ActivateBreakGlass | userId, reason, expiry | Owner(notify), Audit | emergency elevation |
| `ApprovalRequested` | ApprovalRequest | RequireApproval | approvalId, entityId, makerId, commandType, commandSchemaVersion, resourceId, approvalVersion, requestedAt | Audit, originating context | durable pending command |
| `ApprovalGranted` | ApprovalRequest | Approve | approvalId, entityId, makerId, approverId, commandType, commandSchemaVersion, resourceId, approvalVersion, approvedAt | Audit, originating context | approved command committed |

#### ApprovalRequested v1

- **Owner:** Identity & Access.
- **Trigger:** configured maker-checker policy commits a durable pending approval request.
- **Idempotency:** one event per approval ID; replay of the originating idempotency key emits no additional event.
- **Causation:** originating command request ID, or its durable idempotency-record ID when no request ID exists.
- **Correlation:** effective originating correlation ID.

Payload:

```json
{
  "approval_id": "3530ca0e-4201-4ab1-8521-20f851defd44",
  "entity_id": "2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d",
  "maker_id": "b7447cf1-adf8-439b-bf4c-34c5752cfdd7",
  "command_type": "tax_code_version_create",
  "command_schema_version": 1,
  "resource_id": "fb861bea-a516-4546-b92e-2a96a19a3379",
  "approval_version": 1,
  "requested_at": "2026-07-16T10:30:00Z"
}
```

Metadata:

```json
{
  "event_id": "64033b88-c073-48bb-8c85-da4a8ec76871",
  "event_name": "ApprovalRequested",
  "event_version": 1,
  "occurred_at": "2026-07-16T10:30:00Z",
  "correlation_id": "00f777b2-a18e-46dd-b6c7-9fac9063f213",
  "causation_id": "41d7a445-6734-44d3-a2b5-d61a60ec44dc"
}
```

The event never contains the command payload, payload hash, idempotency key, sensitive fields, or encryption details.

#### ApprovalGranted v1

- **Owner:** Identity & Access.
- **Trigger:** approval transition and originating command commit successfully in the approved atomic transaction.
- **Idempotency:** one event per approval ID; duplicate or concurrent approval emits no additional event and cannot repeat the originating command.
- **Causation:** corresponding `ApprovalRequested` event ID.
- **Correlation:** effective approval-command correlation ID; durable approval metadata retains the originating correlation link.

Payload:

```json
{
  "approval_id": "3530ca0e-4201-4ab1-8521-20f851defd44",
  "entity_id": "2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d",
  "maker_id": "b7447cf1-adf8-439b-bf4c-34c5752cfdd7",
  "approver_id": "1b8f3c2f-4e62-4fa9-a924-77848017a9a6",
  "command_type": "tax_code_version_create",
  "command_schema_version": 1,
  "resource_id": "fb861bea-a516-4546-b92e-2a96a19a3379",
  "approval_version": 2,
  "approved_at": "2026-07-16T11:00:00Z"
}
```

Metadata:

```json
{
  "event_id": "7fea2473-2cf0-469f-936b-0f798ef8f803",
  "event_name": "ApprovalGranted",
  "event_version": 1,
  "occurred_at": "2026-07-16T11:00:00Z",
  "correlation_id": "e0aee0cf-bcd3-45de-a409-c5bb42ad9857",
  "causation_id": "64033b88-c073-48bb-8c85-da4a8ec76871"
}
```

The event never contains the command payload, command result, payload hash, idempotency key, sensitive fields, or encryption details.

### Reconciliation
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `StatementImported` | BankReconciliation | ImportStatement | reconciliationId, bankAccountId, lineCount | Audit | begin reconciliation |
| `LineMatched` | BankReconciliation | ConfirmMatch | reconciliationId, lineId, txnId | Reporting | match status |
| `ReconciliationCompleted` | BankReconciliation | CompleteReconciliation | reconciliationId, closingBalances | Reporting, Audit | reconciliation statement |

### Migration
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `DryRunCompleted` | StagingBatch | RunDryRun | batchId, validationResult, controlTotals | (finance review) | validation report |
| `StagingReset` | StagingBatch | ResetStaging | batchId | Audit | zero-impact reset |
| `ConversionPosted` | StagingBatch | ExecuteFinalMigration | batchId, migrationId | Reporting, Audit | opening projections |

---

## 3. Cross-Context Choreography (key business moments)

**A. Foreign-currency customer receipt, net of AIT** *(the max-context flow)*
1. Command `RecordReceipt` → **one transaction** (settlement service):
   - Allocation created; `Allocation.ApplyWithholding` (AIT → 1070);
   - `Invoice.ApplySettlement(applied, expectedVersion)` ⚡ (Receivables owns balance; optimistic version guards no-over-allocation);
   - `FXGainLossCalculation` invoked → realised FX; settlement `JournalEntry` posted (Ledger).
2. **Commit.** Then emit (async): `ReceiptAllocated`, `WithholdingCaptured`, `RealisedFXRecognised`, `JournalPosted`, `InvoiceStatusChanged`.
3. Reporting projections update (cash view, AR ageing, tax registers); Audit records all.

**B. Invoice issuance (recognition)**
`IssueInvoice` → one transaction: `TaxDetermined` snapshot embedded + recognition `JournalEntry` posted → commit → emit `InvoiceIssued`, `JournalPosted` → Reporting.

**C. Correction after filing (credit note)**
`IssueCreditNote` → one transaction: credit note posted, VAT adjustment dated in **note's** period, `JournalEntry` → commit → emit `CreditNoteIssued` → Tax summary (note period), Reporting.

**D. Period hard close**
`HardClose` (four-eyes) → emit `PeriodHardClosed` + `VATPeriodLocked` → posting contexts reject in-period dates; Receivables/Settlement disable void-window for that period (ADR-003).

**E. Period-end FX revaluation**
Soft Close → `RunRevaluation` → `UnrealisedFXRevalued` → Ledger posts adjusting entries → next-period start → `RevaluationReversed`.

**F. Bank-only line during reconciliation**
`CreateEntryForBankLine` → **requests** posting via the owning context's application service (AP-001) → `JournalPosted` → `LineMatched`.

**G. Migration cutover**
`RunDryRun` (↺, no post) → `ExecuteFinalMigration` invokes each target's **import application services** (AP-001) → `ConversionPosted`.

---

## 4. Validation

- **AP-001:** ✔ no event triggers a cross-context *write*; strong cross-aggregate work is done by in-transaction commands through owning services; events drive only the eventual read/notify tier.
- **Acyclic:** ✔ events flow producer→consumer (mostly →Reporting/Audit); no event creates a synchronous back-dependency.
- **Reproducibility/audit:** ✔ events carry TaxSnapshot/RateRef and mirror the immutable record.
- **Identity ≠ number:** ✔ payloads carry DocumentId (UUID), never business numbers.
- **No business rule introduced or modified; no contradiction; no AP-001 violation; no unresolved major risk.**

**Domain Events are complete and internally consistent.**
