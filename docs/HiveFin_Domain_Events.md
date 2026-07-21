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
| `AdvanceRecorded` / `CreditHeld` | CreditTranche | RecordAdvance/HoldCredit | partyId, Money | Reporting | immutable source + 2060/1075 projection |
| `CreditApplied` / `CreditRefunded` | Allocation | ApplyCredit/RefundCredit | allocationId, partyId, Money, targetDocId? | Reporting, Receivables/Payables(display) | credit drawdown |
| `AllocationReversed` | Allocation | ReverseAllocation | allocationId | Reporting, Audit | reverse settlement projections |

#### Settlement party-credit events v2

The existing v1 names and meanings remain available. Version 2 is backward-compatible and adds complete immutable CreditTranche identity, transaction/functional values, RateRecord references, and consumption/restoration linkage. Consumers opt into v2. Event payloads never authorize source selection; commands have already selected and version-checked every source. Event metadata follows the standard envelope with UUID `event_id`, the unchanged `event_name`, integer `event_version` equal to `2`, UTC `occurred_at`, effective `correlation_id`, and triggering command or event `causation_id`.

##### CreditHeld v2

- **Owner:** Settlement & Cash Application.
- **Trigger:** a posted receipt/payment retains unapplied value and atomically creates immutable source tranches.
- **Idempotency:** one `CreditHeld` v2 event per originating Allocation; replay emits no new tranche or event.
- **Causation/correlation:** the posted receipt/payment command is causation; the effective command correlation ID is propagated.

```json
{
  "event_id":"265705b7-bd8a-441a-8257-d458dafd6e35",
  "event_name":"CreditHeld",
  "event_version":2,
  "occurred_at":"2026-07-10T10:00:00Z",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"1cc3fed9-d78a-421c-b31d-33de19cd1501",
  "payload":{
    "allocationId":"1204d0d4-3d0a-4c16-83ec-99f39802714c",
    "partyType":"customer",
    "partyId":"2d692c41-a3b1-4d2f-8946-fbb56491d00b",
    "money":{"amount":"20.0000","currency":"USD"},
    "creditSources":[
      {"creditTrancheId":"555a1bee-36a8-40d2-a160-83982d410a53","transactionMoney":{"amount":"20.0000","currency":"USD"},"functionalMoney":{"amount":"2000.0000","currency":"BDT"},"sourceRateRecordId":"c30a6168-6cd8-41f7-be30-b604fc47d06c","comparisonRateRecordId":null,"consumptionId":null,"sourceAllocationId":"1204d0d4-3d0a-4c16-83ec-99f39802714c"}
    ]
  }
}
```

##### CreditApplied v2

- **Owner:** Settlement & Cash Application.
- **Trigger:** named credit sources are consumed and applied to versioned open documents in a posted Allocation.
- **Idempotency:** one event per successful application Allocation; replay emits no new consumption or event.
- **Causation/correlation:** the application command is causation; its effective correlation ID is propagated.

```json
{
  "event_id":"1afe09dc-b7dd-4278-b642-c68356bb93a0",
  "event_name":"CreditApplied",
  "event_version":2,
  "occurred_at":"2026-07-21T10:00:00Z",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"90c3c83b-ceb3-439a-8980-684ad1368ca0",
  "payload":{
    "allocationId":"bc35144f-1813-4760-8e12-9432695b6910",
    "partyType":"customer",
    "partyId":"2d692c41-a3b1-4d2f-8946-fbb56491d00b",
    "money":{"amount":"15.0000","currency":"USD"},
    "targetDocId":"b57cd935-d096-4e9b-a86f-b2bd41f61063",
    "creditSources":[
      {"creditTrancheId":"555a1bee-36a8-40d2-a160-83982d410a53","transactionMoney":{"amount":"10.0000","currency":"USD"},"functionalMoney":{"amount":"1000.0000","currency":"BDT"},"sourceRateRecordId":"c30a6168-6cd8-41f7-be30-b604fc47d06c","comparisonRateRecordId":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","consumptionId":"819bc9d0-5515-4800-b2fc-93733344fc58","targetDocumentId":"b57cd935-d096-4e9b-a86f-b2bd41f61063"},
      {"creditTrancheId":"a72ea97c-9ea4-45f1-a285-d365195069fc","transactionMoney":{"amount":"5.0000","currency":"USD"},"functionalMoney":{"amount":"550.0000","currency":"BDT"},"sourceRateRecordId":"8e6dc9a0-ce52-4451-943d-e94c333809a7","comparisonRateRecordId":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","consumptionId":"b585413d-d07f-4a57-b906-4b3e66e15faa","targetDocumentId":"b57cd935-d096-4e9b-a86f-b2bd41f61063"}
    ]
  }
}
```

##### CreditRefunded v2

- **Owner:** Settlement & Cash Application.
- **Trigger:** named credit sources are consumed and refunded through configured bank/cash posting.
- **Idempotency:** one event per successful refund Allocation; replay emits no new consumption, bank effect, or event.
- **Causation/correlation:** the refund command is causation; its effective correlation ID is propagated.

```json
{
  "event_id":"2f157d50-b9e0-46fb-8343-a21407c8caac",
  "event_name":"CreditRefunded",
  "event_version":2,
  "occurred_at":"2026-07-22T09:00:00Z",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"0a164941-bba9-457b-a538-ae737658897f",
  "payload":{
    "allocationId":"79331f48-c7b1-4330-b154-ed9b0b91b82c",
    "partyType":"customer",
    "partyId":"2d692c41-a3b1-4d2f-8946-fbb56491d00b",
    "money":{"amount":"10.0000","currency":"USD"},
    "targetDocId":null,
    "creditSources":[
      {"creditTrancheId":"555a1bee-36a8-40d2-a160-83982d410a53","transactionMoney":{"amount":"10.0000","currency":"USD"},"functionalMoney":{"amount":"1000.0000","currency":"BDT"},"sourceRateRecordId":"c30a6168-6cd8-41f7-be30-b604fc47d06c","comparisonRateRecordId":"670aa770-074c-4556-9c09-f81ea68cfe96","consumptionId":"819bc9d0-5515-4800-b2fc-93733344fc58","targetDocumentId":null}
    ]
  }
}
```

##### AllocationReversed v2

- **Owner:** Settlement & Cash Application.
- **Trigger:** a linked posted reversal atomically restores every original consumption to the same source tranche using recorded values.
- **Idempotency:** one event per reversal Allocation; unique reversal and restoration linkage prevent duplicates.
- **Causation/correlation:** the reversal command is causation; its effective correlation ID is propagated. Each restoration also identifies its original consumption.

```json
{
  "event_id":"65e02439-8112-41e5-840b-04737479453e",
  "event_name":"AllocationReversed",
  "event_version":2,
  "occurred_at":"2026-07-23T09:00:00Z",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"8bf96514-ac1d-4135-bf3e-c7acdc4b94c3",
  "payload":{
    "allocationId":"bc35144f-1813-4760-8e12-9432695b6910",
    "reversalAllocationId":"4aa6dc20-f314-40da-ae3e-0e66e1496da2",
    "restoredCreditSources":[
      {"creditTrancheId":"555a1bee-36a8-40d2-a160-83982d410a53","transactionMoney":{"amount":"10.0000","currency":"USD"},"functionalMoney":{"amount":"1000.0000","currency":"BDT"},"sourceRateRecordId":"c30a6168-6cd8-41f7-be30-b604fc47d06c","comparisonRateRecordId":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","originalConsumptionId":"819bc9d0-5515-4800-b2fc-93733344fc58","restorationConsumptionId":"aa83f251-e74f-4d8e-be93-705685cb8063"},
      {"creditTrancheId":"a72ea97c-9ea4-45f1-a285-d365195069fc","transactionMoney":{"amount":"5.0000","currency":"USD"},"functionalMoney":{"amount":"550.0000","currency":"BDT"},"sourceRateRecordId":"8e6dc9a0-ce52-4451-943d-e94c333809a7","comparisonRateRecordId":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","originalConsumptionId":"b585413d-d07f-4a57-b906-4b3e66e15faa","restorationConsumptionId":"947c3b97-cdd3-4132-97f5-569e6f84b088"}
    ]
  }
}
```

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
