# Proposed Governance Amendment — M3 Foreign Party-Credit Tranches

**Status:** Proposed for governance review; not frozen and not approved for implementation.

## 1. Scope and Decision

This proposal resolves only foreign-currency PartyCredit consumption for M3 Settlement. It changes no withholding law, automatic allocation policy, FX source, rounding policy, approval threshold, M4/M5 behavior, or public endpoint outside the four identified below.

Party credit is tranche-based. Every unapplied source creates an immutable CreditTranche source fact containing:

- `credit_tranche_id`, `entity_id`, `party_type`, `party_id`, and `currency`;
- non-negative original and remaining transaction-currency Money;
- non-negative original and remaining functional-currency Money;
- the immutable source RateRecord reference when currency differs from functional currency;
- source Settlement Allocation ID and optional source document/receipt/payment reference;
- UTC `created_at` and optimistic `version`.

Original amounts, source linkage, currency, party, entity, and RateRecord reference never change. Consumption and restoration are append-only facts. Remaining amounts are versioned projections rebuilt from the source and those facts; they may be stored for guarded hot-path reads but are not destructive history. A total PartyCreditBalance is also a rebuildable projection and is not sufficient authority for consumption.

Every application or refund explicitly supplies `credit_sources`. No single-tranche shortcut is approved. FIFO, LIFO, weighted-average, pro-rata, and automatic source selection are prohibited.

```json
{
  "credit_sources":[
    {
      "credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53",
      "amount":{"amount":"10.0000","currency":"USD"},
      "expected_version":2
    }
  ]
}
```

Each selected amount is positive, cannot exceed that tranche's remaining amount, and uses its currency. Every tranche must belong to the active entity, party, party type, and request currency. Duplicate tranche IDs in one request are rejected. Each expected version is checked transactionally. Exact idempotent replay consumes nothing twice.

## 2. Shared CreditTranche Schemas

### 2.1 CreditTranche

The response schema exposes immutable source facts and current projected remainder. It never exposes a mutable numeric rate supplied by a client.

```json
{
  "credit_tranche":{
    "credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53",
    "party_type":"customer",
    "party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b",
    "currency":"USD",
    "original_amount":{"amount":"20.0000","currency":"USD"},
    "remaining_amount":{"amount":"10.0000","currency":"USD"},
    "original_functional_amount":{"amount":"2000.0000","currency":"BDT"},
    "remaining_functional_amount":{"amount":"1000.0000","currency":"BDT"},
    "source_exchange_rate_reference":{"rate_record_id":"c30a6168-6cd8-41f7-be30-b604fc47d06c","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-10"},
    "source_allocation_id":"1204d0d4-3d0a-4c16-83ec-99f39802714c",
    "source_reference":"RCPT-CONFIGURED",
    "created_at":"2026-07-10T10:00:00Z",
    "version":2
  }
}
```

For functional-currency credit, both Money pairs are equal and `source_exchange_rate_reference` is null. For foreign credit, original functional Money is calculated once using the exact source RateRecord and frozen rounding policy. Partial consumption carries functional value proportionally within that single tranche using the approved rounding boundary; it never averages across tranches. Any final consumption takes the tranche's exact remaining functional amount so the tranche closes to zero without drift.

### 2.2 CreditConsumption

Each application, refund, or reversal appends an immutable consumption/restoration record. A restoration references the exact prior consumption and reverses its transaction and functional values without recalculation.

```json
{
  "credit_consumption":{
    "id":"819bc9d0-5515-4800-b2fc-93733344fc58",
    "credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53",
    "allocation_id":"bc35144f-1813-4760-8e12-9432695b6910",
    "operation":"application",
    "amount":{"amount":"10.0000","currency":"USD"},
    "functional_amount":{"amount":"1000.0000","currency":"BDT"},
    "source_rate_record_id":"c30a6168-6cd8-41f7-be30-b604fc47d06c",
    "comparison_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7",
    "reverses_consumption_id":null,
    "occurred_at":"2026-07-21T10:00:00Z"
  }
}
```

## 3. Public API Corrections

### 3.1 Credit Application

The request for `POST /v1/credits/{party}/apply` replaces aggregate-level PartyCredit `If-Match` with required per-source `expected_version`. Missing or stale source versions follow the stable errors in §4. All other shared M3 headers, approval behavior, idempotency, document expected versions, and atomicity remain unchanged.

Required request fields are `party_type`, `currency`, `application_date`, nonempty `credit_sources`, and nonempty `allocations`. The sum of selected credit-source amounts must equal the sum of document allocation amounts. No bank, withholding, settlement RateRecord, client-calculated functional amount, or client-calculated realised FX is accepted.

For functional currency, no realised FX is calculated. For foreign currency, FX is calculated per selected CreditTranche and per target-document allocation. The tranche source RateRecord is the carrying-rate baseline; the target document's immutable RateRecord is the comparison rate. Settlement invokes the FX-owned internal calculation and persists its result and references atomically with credit consumption, document application, posting, audit, idempotency, and outbox.

When one selected tranche is applied across multiple documents, or multiple tranches are applied to one document, the request order defines explicit pairings through `credit_tranche_id` on each document allocation. Each allocation line draws from exactly one selected source; totals grouped by tranche must equal the corresponding `credit_sources` amount. This is explicit source assignment, not automatic allocation.

```json
{
  "request":{
    "party_type":"customer",
    "currency":"USD",
    "application_date":"2026-07-21",
    "credit_sources":[
      {"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","amount":{"amount":"10.0000","currency":"USD"},"expected_version":2},
      {"credit_tranche_id":"a72ea97c-9ea4-45f1-a285-d365195069fc","amount":{"amount":"5.0000","currency":"USD"},"expected_version":1}
    ],
    "allocations":[
      {"invoice_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","applied_amount":{"amount":"10.0000","currency":"USD"},"expected_version":3},
      {"invoice_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","credit_tranche_id":"a72ea97c-9ea4-45f1-a285-d365195069fc","applied_amount":{"amount":"5.0000","currency":"USD"},"expected_version":3}
    ]
  },
  "response":{
    "allocation":{"id":"bc35144f-1813-4760-8e12-9432695b6910","operation":"credit_application","state":"posted","version":1},
    "consumed_credit_sources":[
      {"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","amount":{"amount":"10.0000","currency":"USD"},"functional_amount":{"amount":"1000.0000","currency":"BDT"},"remaining_amount":{"amount":"0.0000","currency":"USD"},"remaining_functional_amount":{"amount":"0.0000","currency":"BDT"},"version":3},
      {"credit_tranche_id":"a72ea97c-9ea4-45f1-a285-d365195069fc","amount":{"amount":"5.0000","currency":"USD"},"functional_amount":{"amount":"550.0000","currency":"BDT"},"remaining_amount":{"amount":"15.0000","currency":"USD"},"remaining_functional_amount":{"amount":"1650.0000","currency":"BDT"},"version":2}
    ],
    "realised_fx_results":[
      {"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","source_functional_amount":{"amount":"1000.0000","currency":"BDT"},"comparison_functional_amount":{"amount":"900.0000","currency":"BDT"},"realised_fx":{"amount":"100.0000","currency":"BDT"},"classification":"gain","source_rate_record_id":"c30a6168-6cd8-41f7-be30-b604fc47d06c","comparison_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7"},
      {"credit_tranche_id":"a72ea97c-9ea4-45f1-a285-d365195069fc","document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","source_functional_amount":{"amount":"550.0000","currency":"BDT"},"comparison_functional_amount":{"amount":"450.0000","currency":"BDT"},"realised_fx":{"amount":"100.0000","currency":"BDT"},"classification":"gain","source_rate_record_id":"8e6dc9a0-ce52-4451-943d-e94c333809a7","comparison_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7"}
    ]
  }
}
```

The example consumes USD 15 with BDT 1,550 carrying value and clears BDT 1,350 of customer receivable carrying value. The FX-owned calculation returns BDT 200 gain; the customer-credit debit of BDT 1,550 equals the BDT 1,350 receivable credit plus BDT 200 FX-gain credit.

### 3.2 Credit Refund

The request for `POST /v1/credits/{party}/refund` requires nonempty `credit_sources`; the selected sum must equal `refund_amount`. Per-source versions replace aggregate-level PartyCredit `If-Match`. Functional-currency refund rejects a RateRecord. Foreign refund requires exact `rate_record_id`, resolved to the approved immutable refund settlement RateRecord; no historical rate or calculated FX value is accepted from the client.

For each selected foreign tranche, the source RateRecord determines carrying functional Money and the refund RateRecord determines bank/cash functional Money. The FX-owned internal contract calculates realised FX per tranche. Consumption, bank posting, FX recognition, audit, idempotency, and outbox commit atomically.

```json
{
  "request":{
    "party_type":"customer",
    "refund_date":"2026-07-22",
    "bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22",
    "refund_amount":{"amount":"10.0000","currency":"USD"},
    "expected_available_balance":{"amount":"25.0000","currency":"USD"},
    "rate_record_id":"670aa770-074c-4556-9c09-f81ea68cfe96",
    "credit_sources":[
      {"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","amount":{"amount":"10.0000","currency":"USD"},"expected_version":2}
    ]
  },
  "response":{
    "allocation":{"id":"79331f48-c7b1-4330-b154-ed9b0b91b82c","operation":"credit_refund","state":"posted","version":1},
    "consumed_credit_sources":[
      {"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","amount":{"amount":"10.0000","currency":"USD"},"functional_amount":{"amount":"1000.0000","currency":"BDT"},"remaining_amount":{"amount":"0.0000","currency":"USD"},"remaining_functional_amount":{"amount":"0.0000","currency":"BDT"},"version":3}
    ],
    "realised_fx_results":[
      {"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","source_functional_amount":{"amount":"1000.0000","currency":"BDT"},"comparison_functional_amount":{"amount":"1200.0000","currency":"BDT"},"realised_fx":{"amount":"-200.0000","currency":"BDT"},"classification":"loss","source_rate_record_id":"c30a6168-6cd8-41f7-be30-b604fc47d06c","comparison_rate_record_id":"670aa770-074c-4556-9c09-f81ea68cfe96"}
    ]
  }
}
```

The example consumes USD 10 carrying BDT 1,000 and refunds bank cash valued at BDT 1,200. The customer-credit debit of BDT 1,000 plus BDT 200 FX-loss debit equals the BDT 1,200 bank credit.

### 3.3 Party Credit Query

`GET /v1/credits/{party}` continues to return the derived available balance and immutable source entries, and adds paginated `credit_tranches`. Each tranche uses §2.1. Cursor ordering is `created_at DESC, credit_tranche_id DESC` and inherits the frozen signed-cursor binding. Optional `currency` filters both total and tranches; without it, the response returns one balance per currency and never sums different currencies.

```json
{
  "response":{
    "party_credit":{"party_type":"customer","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","balances":[{"available_balance":{"amount":"25.0000","currency":"USD"},"functional_carrying_balance":{"amount":"2650.0000","currency":"BDT"}}],"projection_version":6},
    "credit_tranches":[{"credit_tranche_id":"a72ea97c-9ea4-45f1-a285-d365195069fc","currency":"USD","remaining_amount":{"amount":"20.0000","currency":"USD"},"remaining_functional_amount":{"amount":"2200.0000","currency":"BDT"},"source_exchange_rate_reference":{"rate_record_id":"8e6dc9a0-ce52-4451-943d-e94c333809a7","base_currency":"USD","quote_currency":"BDT","rate":"110.00000000","effective_date":"2026-07-15"},"version":1}],
    "page":{"limit":50,"next_cursor":null}
  }
}
```

### 3.4 Allocation Reversal

`POST /v1/allocations/{id}/reverse` remains an empty-body command protected by Allocation `If-Match`. It reads the original immutable CreditConsumption records and appends one restoration record for each. It restores the same source tranches with the exact transaction amounts, functional amounts, source RateRecords, comparison RateRecords, and prior consumption linkage. The client cannot choose replacement tranches or rates.

```json
{
  "response":{
    "original_allocation":{"id":"bc35144f-1813-4760-8e12-9432695b6910","state":"reversed","version":2},
    "reversal_allocation":{"id":"4aa6dc20-f314-40da-ae3e-0e66e1496da2","operation":"reversal","state":"posted","version":1},
    "restored_credit_sources":[
      {"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","restored_amount":{"amount":"10.0000","currency":"USD"},"restored_functional_amount":{"amount":"1000.0000","currency":"BDT"},"source_rate_record_id":"c30a6168-6cd8-41f7-be30-b604fc47d06c","original_consumption_id":"819bc9d0-5515-4800-b2fc-93733344fc58","new_version":4},
      {"credit_tranche_id":"a72ea97c-9ea4-45f1-a285-d365195069fc","restored_amount":{"amount":"5.0000","currency":"USD"},"restored_functional_amount":{"amount":"550.0000","currency":"BDT"},"source_rate_record_id":"8e6dc9a0-ce52-4451-943d-e94c333809a7","original_consumption_id":"b585413d-d07f-4a57-b906-4b3e66e15faa","new_version":3}
    ],
    "reversal_linkage":{"original_allocation_id":"bc35144f-1813-4760-8e12-9432695b6910","reversal_allocation_id":"4aa6dc20-f314-40da-ae3e-0e66e1496da2","reversed_at":"2026-07-23T09:00:00Z"}
  }
}
```

Transactional uniqueness permits one restoration for each original consumption and one reversal for the Allocation. Failure restores nothing and emits no partial posting, audit, or business event.

## 4. Stable Errors and Concurrency

- Unknown or cross-entity tranche: `404 not_found` with rule `credit_tranche_not_found`.
- Wrong currency: `422 invariant_violation` with rule `credit_tranche_currency_mismatch`.
- Wrong party or party type: `422 invariant_violation` with rule `credit_tranche_party_mismatch`.
- Selected amount exceeds remaining: `422 invariant_violation` with rule `insufficient_credit_tranche_balance`.
- Missing or stale source version: `428 precondition_required` when absent; `409 concurrency_conflict` with rule `credit_tranche_concurrency_conflict` and `required_version` when stale.
- Foreign tranche lacks its immutable source reference: `422 invariant_violation` with rule `missing_credit_rate_reference`.
- FX-owned calculation cannot validate references or calculate: `422 invariant_violation` with rule `credit_fx_calculation_failed`.

All existing shared validation, idempotency, correlation, approval, and cursor behavior is inherited unchanged.

## 5. Aggregate, Persistence, Repository, and Event Alignment

The smallest frozen-artifact amendments are:

- `HiveFin_Aggregate_Design.md`: add CreditTranche under PartyCreditBalance; source facts immutable; consumption explicit and append-only; remaining projections non-negative and versioned; application/refund/reversal consume or restore named tranches; foreign application invokes FX per tranche.
- `HiveFin_Database_Design.md`: add Settlement-owned `credit_tranche` and `credit_consumption` tables. Store original/projected remaining transaction and functional amounts, source RateRecord UUID/reference snapshot, source Allocation/reference, version, and reversal linkage. Add indexes by entity/party/currency/remainder and unique reversal-of-consumption. Use no cross-context foreign key.
- `HiveFin_Repository_Contracts.md`: add `CreditTrancheRepository.GetById`, version-checked `Consume`, append-only `RecordConsumption`, exact `Restore`, and party/currency query. It participates in the existing Settlement Unit of Work.
- `HiveFin_API_Contracts.md`: replace the four endpoint clauses and schemas exactly as §§3–4 specify; retain all other approved M3 text.
- `HiveFin_Decision_Log.md`: record explicit source selection, source-rate carrying basis, per-tranche FX, append-only consumption, and the prohibition on automatic selection.

Existing event payloads cannot identify multiple consumed/restored tranches. `HiveFin_Domain_Events.md` therefore requires backward-compatible v2 schemas for `CreditHeld`, `CreditApplied`, `CreditRefunded`, and `AllocationReversed`. V2 adds `creditSources` or `restoredCreditSources` containing tranche ID, transaction Money, functional Money, source RateRecord ID, comparison RateRecord ID where applicable, and consumption linkage. Existing v1 names and meanings remain preserved; consumers opt into v2.

No other frozen artifact requires amendment.

## 6. Accounting Reconciliation and Atomicity

Foreign credit application compares each selected tranche's immutable carrying value with the target document's immutable carrying value. Foreign refund compares each selected tranche's immutable carrying value with bank/cash value at the approved refund RateRecord. Direction-sensitive gain/loss classification and exact arithmetic remain FX-owned. The client supplies neither calculated functional Money nor realised FX.

The examples reconcile:

- Application: BDT 1,550 customer-credit debit = BDT 1,350 AR credit + BDT 200 FX-gain credit.
- Refund: BDT 1,000 customer-credit debit + BDT 200 FX-loss debit = BDT 1,200 bank credit.
- Reversal: USD 15 and BDT 1,550 return to the same two source tranches; no rate or tranche is substituted.

Credit source consumption/restoration, document application, bank movement where applicable, Ledger posting, realised FX, audit, idempotency, and outbox are one transaction. Failure leaves every tranche, projection, document, journal, audit record, and business event unchanged. Approval failure remains recoverable under the frozen durable approval lifecycle.

## 7. Exclusions and Remaining Decisions

This proposal adds no Credit Note, Debit Note, Period Close, reconciliation, migration, ageing, reporting, cash forecasting, automatic matching, withholding rule, or public FX endpoint.

No governance decision remains unresolved within this amendment. Explicit source selection is always required; carrying and comparison RateRecords are defined; partial consumption is confined to one named tranche at a time; final consumption closes rounding residue; reversal restores recorded values to the same tranches; and all configurable rates, rounding, accounts, approval, and legal policies remain governed by existing frozen configuration.
