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
| `CreditNoteCreated` | CreditNote | CreateCreditNoteDraft | creditNoteId, sourceInvoiceId, draft identity | Audit | draft traceability only |
| `CreditNoteIssued` v2 | CreditNote | PostCreditNote | note/source/party IDs, immutable posted and disposition Money fields, TaxSnapshot hashes, RateRecord, number, journal IDs | Reporting, Tax(summary), Audit | VAT adjustment in note period |
| `CreditNoteApplied` / `CreditNoteHeld` / `CreditNoteRefunded` | CreditNote | explicit disposition command | operation value, resulting five-field state, document/tranche/FX refs | Reporting, Audit | exact partial-disposition projection |
| `CreditNoteReversed` | CreditNote | ReverseCreditNote | original/reversal IDs, impact hash, restored document/tranche refs and exact state | Reporting, Tax, Audit | linked correction reversal |
| `CreditNoteDispositionSet` v1 | CreditNote | historical SetDisposition only | historical payload | legacy consumers | superseded for new M4 writes |

### Payables
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `BillApproved` | Bill | ApproveBill | billId, vendorId, Money, taxSnapshots[], sbuSplit, ait/vds, rateRef | Reporting, Audit | AP/ageing/expense projections |
| `BillStatusChanged` | Bill | ApplySettlement⚡→event | billId, status, openBalance | Reporting, Notifications | display status, ageing |
| `BillVoided` | Bill | VoidBill | billId, reasonCode | Reporting, Audit | reverse projections |
| `DebitNoteCreated` | DebitNote | CreateDebitNoteDraft | debitNoteId, sourceBillId, draft identity | Audit | draft traceability only |
| `DebitNoteIssued` v2 | DebitNote | PostDebitNote | note/source/party IDs, immutable posted and disposition Money fields, TaxSnapshot hashes, RateRecord, number, journal IDs | Reporting, Tax, Audit | input-VAT adjustment in note period |
| `DebitNoteApplied` / `DebitNoteHeld` / `DebitNoteRefunded` | DebitNote | explicit disposition command | operation value, resulting five-field state, document/tranche/FX refs | Reporting, Audit | exact partial-disposition projection |
| `DebitNoteReversed` | DebitNote | ReverseDebitNote | original/reversal IDs, impact hash, restored document/tranche refs and exact state | Reporting, Tax, Audit | linked correction reversal |
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
| `PeriodSoftClosed` v2 | AccountingPeriod | SoftClose | period/state/version/VAT transitions and actor metadata | Ledger/Rec/Pay/Settlement, Reporting | restrict to adjusting entries |
| `PeriodHardClosed` v2 | AccountingPeriod | HardClose after all gates | period/state/version/VAT transitions, approval IDs, immutable evidence-set hash | all posting contexts, Reporting | block in-period postings |
| `VATPeriodLocked` v2 | AccountingPeriod | successful HardClose atomically | period, VAT transition, approval/evidence refs | Receivables, Settlement, Tax | enforce void-window (ADR-003) |
| `PeriodReopened` v2 | AccountingPeriod | approved Reopen | period/state/version/VAT transitions, reason, approval IDs, reclose flag | affected users(notify), Audit | approved adjustments only; re-close due |
| `VATPeriodUnlocked` v1 | AccountingPeriod | policy-authorized approved Reopen | period, VAT transition, reason, approval IDs | Receivables, Settlement, Tax, Audit | unlock only under approved jurisdiction policy |
| `YearEndRolled` | AccountingPeriod | RollYearEnd | fiscalYear, retainedEarningsPosting | Ledger, Reporting | 3020/3030 roll |

`CloseGateFailed` is deliberately not a domain event. An unmet or missing M5/M6 gate returns `422 close_gate_unmet` and creates no business outbox mutation.

#### M4 note event schemas

All events use the frozen envelope: UUID event ID, canonical name, integer version, UTC occurred time, entity ID, correlation ID, causation ID, aggregate ID/version, and payload. Exact idempotency permits one effective event per committed operation. Payloads never contain mutable aggregates, client-calculated FX, secrets, or sensitive bank data.

Created v1 events contain draft identity only and no posting. Issued v2 carries source document/version, posted period, immutable TaxSnapshot IDs/hashes, exact source RateRecord ID, number, journal IDs, and the five exact current-state amounts. Applied/Held/Refunded v1 carries the operation-specific transferred value, resulting five-field state, source/target IDs and versions, and Settlement/CreditTranche/FX refs. Historical hold transfer is named `transferred_amount`, never generic current-state `held_amount`. Reversed v1 carries original/reversal IDs, exact impact-graph hash, restored document/tranche IDs, immediately preceding category, resulting five-field state, original valuation refs, and journal IDs.

```json
{
  "event_id":"7ad70db7-5445-429c-b6d9-b45588985d88",
  "event_name":"CreditNoteIssued",
  "event_version":2,
  "occurred_at":"2026-07-21T10:00:00Z",
  "entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"67458ee5-ec19-4f47-ac20-5eff93fd06c6",
  "aggregate_id":"efeb15bc-8a19-4afd-a406-783dd225db64",
  "aggregate_version":2,
  "payload":{"party_type":"customer","document_type":"invoice","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","source_document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","document_number":"CN-CONFIGURED","posted_amount":{"amount":"115.0000","currency":"BDT"},"applied_amount":{"amount":"0.0000","currency":"BDT"},"refunded_amount":{"amount":"0.0000","currency":"BDT"},"held_remaining_amount":{"amount":"0.0000","currency":"BDT"},"undisposed_amount":{"amount":"115.0000","currency":"BDT"},"period_ref":"2026-07","source_rate_record_id":null,"tax_snapshot_hashes":["CONFIGURED_SHA256"],"journal_entry_ids":["be44f14a-2873-4d47-84b7-2ba6545925f8"]}
}
```

```json
{
  "event_id":"0967b189-d2a8-4a96-a335-9f688d5492d2",
  "event_name":"DebitNoteRefunded",
  "event_version":1,
  "occurred_at":"2026-07-23T10:00:00Z",
  "entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"538a83ed-41d1-4f34-9c84-65bdc4548e86",
  "aggregate_id":"376df80e-30b4-4db4-b0a8-6093b6726e50",
  "aggregate_version":6,
  "payload":{"refund_amount":{"amount":"30.0000","currency":"BDT"},"posted_amount":{"amount":"230.0000","currency":"BDT"},"applied_amount":{"amount":"100.0000","currency":"BDT"},"refunded_amount":{"amount":"30.0000","currency":"BDT"},"held_remaining_amount":{"amount":"50.0000","currency":"BDT"},"undisposed_amount":{"amount":"50.0000","currency":"BDT"},"settlement_allocation_id":"4adc9a87-0085-472d-84c6-2d93fca66ccd","credit_tranche_ids":["86836460-2222-41a0-83c1-318f45bf8596"],"source_rate_record_ids":[],"comparison_rate_record_id":null}
}
```

M3 `CreditHeld`, `CreditApplied`, `CreditRefunded`, and `AllocationReversed` v2 remain unchanged and are emitted in the same UoW when a note operation changes M3 state.

#### M4 period event schemas

Period transition v2 payloads contain period UUID/ref, from/to states, versions before/after, VAT before/after, maker/approver/approval IDs, Reopen reason where applicable, and the immutable close evidence-set hash for Hard Close. `PeriodReopened` v2 is the affected-user notification fact. `VATPeriodLocked` v2 occurs only with successful Hard Close; `VATPeriodUnlocked` v1 only with approved policy-authorized Reopen.

```json
{
  "event_id":"86067192-257a-40aa-b51c-24731c7494ba",
  "event_name":"PeriodHardClosed",
  "event_version":2,
  "occurred_at":"2026-09-15T12:00:00Z",
  "entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"3530ca0e-4201-4ab1-8521-20f851defd44",
  "aggregate_id":"45124343-5a6e-48c2-821d-6685cf1fd46e",
  "aggregate_version":3,
  "payload":{"period_ref":"2026-07","from_state":"SoftClosed","to_state":"HardClosed","version_before":2,"version_after":3,"vat_status_before":"unlocked","vat_status_after":"locked","maker_id":"8e1cc916-3312-4f15-a1d7-9b46ab95722d","approver_id":"1b8f3c2f-4e62-4fa9-a924-77848017a9a6","approval_id":"3530ca0e-4201-4ab1-8521-20f851defd44","close_evidence_set_hash":"CONFIGURED_SHA256"}
}
```

```json
{
  "event_id":"a416d34d-d9ad-4bc2-b421-4fd0e0966c7a",
  "event_name":"PeriodReopened",
  "event_version":2,
  "occurred_at":"2026-09-16T09:00:00Z",
  "entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"81ec946c-cbb7-4fcf-a3c5-191f9a8b168d",
  "aggregate_id":"45124343-5a6e-48c2-821d-6685cf1fd46e",
  "aggregate_version":4,
  "payload":{"period_ref":"2026-07","from_state":"HardClosed","to_state":"Reopened","version_before":3,"version_after":4,"reason_code":"CONFIGURED_REASON","narrative":"Approved late adjustment required","vat_status_before":"locked","vat_status_after":"locked","maker_id":"8e1cc916-3312-4f15-a1d7-9b46ab95722d","approver_id":"1b8f3c2f-4e62-4fa9-a924-77848017a9a6","approval_id":"81ec946c-cbb7-4fcf-a3c5-191f9a8b168d","reclose_required":true,"notification_audience":"affected_entity_users"}
}
```

### Reporting (`M5-GOV-001`)
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `ReportRunGenerated` | ReportRun | `POST /v1/report-runs` | reportRunId, reportType, entityId, periodRef/asOf, basis, sourceDataWatermark, contentHash | Audit | evidence snapshot created |
| `ReportRunApproved` | ReportRun | `POST /v1/report-runs/{id}/approve` commits | reportRunId, approvedBy, reviewedBy, evidenceVersion, evidenceHash | Audit | gate-eligible |
| `ReportRunRejected` | ReportRun | durable approval declines | reportRunId, rejectedBy, reason | Audit | not gate-eligible |
| `ReportRunSuperseded` | ReportRun | a later run for the same reproducibility key is approved | reportRunId, supersededByReportRunId | Audit | prior evidence retired |

None of the four is subscribed to by Period — `CloseGateProvider` remains a **pull** contract (Period calls `evaluate()` synchronously at Hard Close time, §12.7), unchanged by this amendment. Export (`GET /v1/report-runs/{id}/export`) produces no event — it is a pure read of already-immutable `content`.

#### M5 ReportRun event schemas

All events use the frozen envelope (UUID event ID, canonical name, integer version, UTC occurred time, entity ID, correlation ID, causation ID, aggregate ID/version, payload), unchanged.

```json
{
  "event_id":"9c1e5c2a-9d0e-4c8a-9c3a-1f9e7b0a2d3c",
  "event_name":"ReportRunGenerated",
  "event_version":1,
  "occurred_at":"2026-07-31T18:04:22.000Z",
  "entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d",
  "aggregate_id":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d",
  "aggregate_version":1,
  "payload":{"report_type":"trial_balance","basis":"accrual","as_of":"2026-07-31","source_data_watermark":"2026-07-31T18:04:22.000Z","content_hash":"1f3d...af02"}
}
```

```json
{
  "event_id":"4b8a6d1f-2e3c-4a9b-8d7e-6c5a4b3d2e1f",
  "event_name":"ReportRunApproved",
  "event_version":1,
  "occurred_at":"2026-08-01T09:12:00.000Z",
  "entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"4b8a6d1f-2e3c-4a9b-8d7e-6c5a4b3d2e1f",
  "aggregate_id":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d",
  "aggregate_version":2,
  "payload":{"approved_by":"1b8f3c2f-4e62-4fa9-a924-77848017a9a6","reviewed_by":"1b8f3c2f-4e62-4fa9-a924-77848017a9a6","evidence_version":2,"evidence_hash":"1f3d...af02"}
}
```

`ReportRunRejected` and `ReportRunSuperseded` follow the same envelope with their §"Payload" fields above; no separate schema is introduced for either.

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

### Reconciliation (`M6-GOV-001`)
| Event | Producer | Trigger | Payload | Consumers | Effect |
|---|---|---|---|---|---|
| `StatementImported` | BankReconciliation | ImportStatement | reconciliationId, reconciliationAccountId, importBatchId, fileHash, importedCount, conflictCount | Audit | statement lines available for matching |
| `MatchSuggestionsGenerated` | BankReconciliation | GenerateMatchSuggestions | reconciliationId, suggestedCount, unexplainedCount | Audit | suggestions available for review — no match confirmed |
| `LineMatched` | BankReconciliation | MatchLine | reconciliationId, lineId, allocationIds[] | Audit | line moved to Matched, not yet reconciled |
| `LineReconciled` | BankReconciliation | ConfirmMatch | reconciliationId, lineId, allocationIds[] | Reporting, Audit | line moved to Reconciled; Allocation(s) consumed |
| `BankOnlyEntryPosted` | BankReconciliation | CreateEntryForBankLine, on approved commit | reconciliationId, lineId, journalEntryId, offsetAccountId, Money | Ledger(already posted), Reporting, Audit | line resolved and Reconciled by a manually classified entry |
| `ReconciliationCompleted` | BankReconciliation | CompleteReconciliation, on approved commit | reconciliationId, reconciliationAccountId, periodRef, closingBalance, sourceDataWatermark, contentHash | Reporting, Audit | gate-eligible evidence available |
| `ReconciliationReopened` | BankReconciliation | ReopenReconciliation, on approved commit | reconciliationId, reopenedBy, reason | Audit | prior evidence now re-editable; existing Hard Close unaffected until Period's own Reopen |

None of the seven is subscribed to by Period — `CloseGateProvider` remains a **pull** contract (Period calls `evaluate()` synchronously at Hard Close time, §12.7), unchanged by this amendment, exactly matching M5's `ReportRun` events (§ Reporting above). Export (`GET /v1/reconciliations/{id}/statement`) produces no event — it is a pure read.

#### M6 BankReconciliation event schemas

All events use the frozen envelope (UUID event ID, canonical name, integer version, UTC occurred time, entity ID, correlation ID, causation ID, aggregate ID/version, payload), unchanged.

```json
{
  "event_id":"2a4b6c8d-0e1f-4a2b-8c3d-4e5f6a7b8c9d",
  "event_name":"ReconciliationCompleted",
  "event_version":1,
  "occurred_at":"2026-08-01T09:12:00.000Z",
  "entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee",
  "correlation_id":"070e4872-c8e3-4718-9937-70e09bc82784",
  "causation_id":"9c1e5c2a-9d0e-4c8a-9c3a-1f9e7b0a2d3c",
  "aggregate_id":"9c1e5c2a-9d0e-4c8a-9c3a-1f9e7b0a2d3c",
  "aggregate_version":4,
  "payload":{"reconciliation_account_id":"a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d","period_ref":"2026-07","closing_balance":{"amount":"12800000.0000","currency":"BDT"},"source_data_watermark":"2026-07-31T18:04:22.000Z","content_hash":"7ad2...c410"}
}
```

`StatementImported`, `MatchSuggestionsGenerated`, `LineMatched`, `LineReconciled`, `BankOnlyEntryPosted`, and `ReconciliationReopened` follow the same envelope with their §"Payload" fields above; no separate schema is introduced for any of them.

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
`HardClose` (four-eyes) evaluates every mandatory immutable close-gate result. M5 supplies reporting evidence and M6 supplies reconciliation evidence. Until every provider returns satisfied evidence, the command returns `422 close_gate_unmet` and emits no business event. A Reopened period must first transition to SoftClosed; it cannot bypass directly to HardClosed. On success from SoftClosed, one transaction copies the accepted evidence set, moves to `HardClosed`, locks VAT, and writes `PeriodHardClosed` v2 + `VATPeriodLocked` v2 to the outbox. Posting contexts then reject in-period dates and Receivables/Settlement disable the void-window (ADR-003). `CloseGateFailed` is never emitted.

**E. Period-end FX revaluation**
Soft Close → `RunRevaluation` → `UnrealisedFXRevalued` → Ledger posts adjusting entries → next-period start → `RevaluationReversed`.

**F. Bank-only line during reconciliation**
`CreateEntryForBankLine` → **requests** posting via the owning context's application service (AP-001) → `JournalPosted` → `LineMatched`.

**G. Migration cutover**
`RunDryRun` (↺, no post) → `ExecuteFinalMigration` invokes each target's **import application services** (AP-001) → `ConversionPosted`.

**H. ReportRun generation and approval (`M5-GOV-001`)**
`GenerateReportRun` → one transaction: compute and freeze `content`/`content_hash`/`source_data_watermark`, state `Generated` → commit → emit `ReportRunGenerated` → Audit. `ApproveReportRun` (durable four-eyes where configured; generator cannot approve their own run) → one transaction: state `Approved`, `reviewed_by`/`approved_by` set atomically, and — only if a prior `Approved` run exists for the identical reproducibility key — that prior run moves to `Superseded` in the same transaction → commit → emit `ReportRunApproved` (+ `ReportRunSuperseded` when applicable) → Audit. Period never subscribes to these events; `CloseGateProvider.evaluate()` pulls the current `Approved` run synchronously at Hard Close time (§"Period & Close" above).

---

## 4. Validation

- **AP-001:** ✔ no event triggers a cross-context *write*; strong cross-aggregate work is done by in-transaction commands through owning services; events drive only the eventual read/notify tier.
- **Acyclic:** ✔ events flow producer→consumer (mostly →Reporting/Audit); no event creates a synchronous back-dependency.
- **Reproducibility/audit:** ✔ events carry TaxSnapshot/RateRef and mirror the immutable record.
- **Identity ≠ number:** ✔ payloads carry DocumentId (UUID), never business numbers.
- **No business rule introduced or modified; no contradiction; no AP-001 violation; no unresolved major risk.**

**Domain Events are complete and internally consistent.**
