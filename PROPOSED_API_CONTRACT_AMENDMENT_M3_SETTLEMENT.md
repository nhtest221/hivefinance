# Proposed API Contract Amendment — M3 Settlement

**Status:** Proposed for governance review; not frozen and not approved for implementation.
**Scope:** Public M3 Settlement contracts only. Existing frozen M0, M1, M2, approval, error, correlation, idempotency, concurrency, Money, TaxSnapshot, RateRecord, audit, and outbox conventions are inherited and not redefined.

## 1. Scope and Common Protocol

This proposal covers Settlement-owned receipt, payment, party-credit, allocation-query, and allocation-reversal operations. Internal document-balance, Tax, FX, Numbering, Period, and Ledger services remain in-process contracts and are never exposed as HTTP endpoints.

Every endpoint inherits the frozen M0/M1/M2 shared protocol without change: TLS, authentication, entity isolation, `X-Entity-Id`, optional `X-Correlation-Id`, malformed-correlation handling, correlation echo and propagation, canonical UUID/date/timestamp/decimal formats, the common error envelope and codes, unknown-field rejection, `Idempotency-Key`, canonical request comparison, `Idempotent-Replay: true`, `If-Match`, durable approval responses, and opaque cursor behavior. This section states M3 applicability only and does not redefine any shared schema or behavior.

All endpoints require the stated entity-scoped capability and remain default-deny. Cross-entity resources return the inherited `404 not_found`. State-changing endpoints require the inherited idempotency protocol; read endpoints do not. `If-Match` is required only where an endpoint below states it. A missing required `If-Match` returns `428 precondition_required`; a stale value returns `409 concurrency_conflict`. Responses never expose internal stack traces, mutable approval payloads, raw rates, or cross-context data.

## 2. Shared Schemas and Accounting Invariants

### 2.1 Money and Settlement Equations

M3 uses the frozen shared Money schema verbatim; it is not redefined here. The following is an inherited Money value. `gross_amount`, `bank_amount`, `withholding_amount`, and `unapplied_amount` are non-negative Money values, and their currencies must be identical within a settlement.

```json
{"amount":"1250.0000","currency":"BDT"}
```

- `gross_amount` is total value being settled before withholding.
- `bank_amount` is actual cash received or paid.
- `withholding_amount` is approved withholding treated as settlement value.
- `unapplied_amount` is gross value retained as party credit rather than allocated to documents.
- `gross_amount = bank_amount + withholding_amount`.
- `gross_amount = sum(document_allocations) + unapplied_amount`.
- With zero withholding, `gross_amount = bank_amount`.
- With zero unapplied credit, `gross_amount = sum(document_allocations)`.

```json
{
  "gross_amount":{"amount":"120.0000","currency":"USD"},
  "bank_amount":{"amount":"108.0000","currency":"USD"},
  "withholding_amount":{"amount":"12.0000","currency":"USD"},
  "document_allocations":[{"amount":"100.0000","currency":"USD"}],
  "unapplied_amount":{"amount":"20.0000","currency":"USD"}
}
```

### 2.2 ExchangeRateReference and RealisedFXResult

An `ExchangeRateReference` is copied from the exact immutable M1 RateRecord. Clients provide only `rate_record_id`; the response supplies the reference. Functional amounts and realised FX are calculated by the internal FX contract using exact document and settlement RateRecord references. A signed realised-FX Money amount is positive for a gain, negative for a loss, and zero for none; `classification` is `gain`, `loss`, or `none` and must agree with the sign.

```json
{
  "exchange_rate_reference":{"rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","base_currency":"USD","quote_currency":"BDT","rate":"110.00000000","effective_date":"2026-07-20"},
  "realised_fx_result":{"document_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","settlement_rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","document_functional_amount":{"amount":"10000.0000","currency":"BDT"},"settlement_functional_amount":{"amount":"11000.0000","currency":"BDT"},"realised_fx":{"amount":"1000.0000","currency":"BDT"},"classification":"gain"}
}
```

### 2.3 WithholdingLine

A WithholdingLine contains `withholding_code`, non-negative Money `amount`, and exactly one server-produced immutable reference: `tax_snapshot` under the frozen M1 schema or `withholding_configuration_reference` containing configured UUID `configuration_id`, positive integer `version`, and `code`. A command supplies only `withholding_code` and `amount`; the internal Tax contract resolves and persists the applicable immutable reference. AIT, VDS, and any other code are accepted only when active approved configuration determines the direction, accounts, treatment, date applicability, and legal metadata. Clients cannot provide a rate, GL account, legal treatment, snapshot, or configuration version. The sum of line amounts must equal `withholding_amount`.

```json
{
  "withholding_line":{"withholding_code":"CONFIGURED_WITHHOLDING","amount":{"amount":"12.0000","currency":"USD"},"tax_snapshot":null,"withholding_configuration_reference":{"configuration_id":"f3aa9ca9-7b21-435e-a68d-0226d952c232","version":3,"code":"CONFIGURED_WITHHOLDING"}}
}
```

### 2.4 OpenDocumentReference and AllocationLink

An open-document reference is obtained through `GetOpenReceivable` or `GetOpenPayable`, never by reading another context's tables. The client supplies the document UUID, applied Money, and `expected_version`. A successful response includes immutable before/after references. Each applied amount is positive, uses the settlement currency, does not exceed the open balance, and belongs to the same party and entity. A request cannot mix invoices and bills.

```json
{
  "allocation_link":{"document_type":"invoice","document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","applied_amount":{"amount":"100.0000","currency":"USD"},"expected_version":2,"open_document":{"document_number":"INV-CONFIGURED","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","open_balance_before":{"amount":"100.0000","currency":"USD"},"open_balance_after":{"amount":"0.0000","currency":"USD"},"version_before":2,"version_after":3,"status_after":"paid"},"realised_fx_result":{"document_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","settlement_rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","document_functional_amount":{"amount":"10000.0000","currency":"BDT"},"settlement_functional_amount":{"amount":"11000.0000","currency":"BDT"},"realised_fx":{"amount":"1000.0000","currency":"BDT"},"classification":"gain"}}
}
```

### 2.5 Allocation, Receipt, and Payment

An Allocation is a posted immutable Settlement record. `operation` is `receipt`, `payment`, `credit_application`, `credit_refund`, or `reversal`; `state` is `posted` or `reversed`. Receipt and Payment are Allocation representations whose operation is respectively `receipt` or `payment`. `party_type` is `customer` for a receipt and `vendor` for a payment. `allocation_number` is drawn through the configured Numbering contract; its format is not defined here.

Cash-origin records contain a bank account, gross/bank/withholding/unapplied amounts, zero or more withholding lines, and one or more document links unless the full gross amount is unapplied. `allocated_amount` is the server-derived sum of link amounts and is included in compact summaries so the second settlement equation remains visible when links are omitted. Records persist exact transaction and functional amounts, the settlement RateRecord reference when currencies differ, journal references, version, audit timestamps, and optional reversal linkage. Posted records are never edited or deleted.

```json
{
  "receipt":{"id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","allocation_number":"RCPT-CONFIGURED","operation":"receipt","party_type":"customer","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","settlement_date":"2026-07-20","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","gross_amount":{"amount":"120.0000","currency":"USD"},"bank_amount":{"amount":"108.0000","currency":"USD"},"withholding_amount":{"amount":"12.0000","currency":"USD"},"unapplied_amount":{"amount":"20.0000","currency":"USD"},"withholding_lines":[{"withholding_code":"CONFIGURED_WITHHOLDING","amount":{"amount":"12.0000","currency":"USD"},"tax_snapshot":null,"withholding_configuration_reference":{"configuration_id":"f3aa9ca9-7b21-435e-a68d-0226d952c232","version":3,"code":"CONFIGURED_WITHHOLDING"}}],"links":[{"document_type":"invoice","document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","applied_amount":{"amount":"100.0000","currency":"USD"},"expected_version":2}],"exchange_rate_reference":{"rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","base_currency":"USD","quote_currency":"BDT","rate":"110.00000000","effective_date":"2026-07-20"},"functional_gross_amount":{"amount":"13200.0000","currency":"BDT"},"journal_entry_ids":["be44f14a-2873-4d47-84b7-2ba6545925f8"],"state":"posted","version":1,"reversal":null,"posted_at":"2026-07-20T10:00:00Z"},
  "payment":{"id":"d8e6bdc7-e29b-41f8-a76c-fcd122b820dd","allocation_number":"PAY-CONFIGURED","operation":"payment","party_type":"vendor","party_id":"8bdf810a-f3e5-4078-ac85-9a762543ed0d","settlement_date":"2026-07-20","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","gross_amount":{"amount":"100.0000","currency":"BDT"},"bank_amount":{"amount":"100.0000","currency":"BDT"},"withholding_amount":{"amount":"0.0000","currency":"BDT"},"unapplied_amount":{"amount":"0.0000","currency":"BDT"},"withholding_lines":[],"links":[{"document_type":"bill","document_id":"ee3195b1-2b7b-411f-92fa-67c1ce9350f2","applied_amount":{"amount":"100.0000","currency":"BDT"},"expected_version":2}],"exchange_rate_reference":null,"functional_gross_amount":{"amount":"100.0000","currency":"BDT"},"journal_entry_ids":["94266065-346f-423c-bb9d-fad65a470ec3"],"state":"posted","version":1,"reversal":null,"posted_at":"2026-07-20T10:00:00Z"}
}
```

### 2.6 PartyCredit and SourceEntry

PartyCredit is the non-negative unapplied balance for one entity, party, and currency. The frozen aggregate permits no implicit currency conversion; a command in another currency returns `422 credit_currency_mismatch`. Each immutable source entry records a hold, application, refund, or reversal and links to the originating allocation. Balance changes are optimistic-versioned.

```json
{
  "party_credit":{"party_type":"customer","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","available_balance":{"amount":"20.0000","currency":"USD"},"version":4,"source_entries":[{"id":"128a01c6-fcdd-4cc1-a2cf-35d63aa13255","entry_type":"held","amount":{"amount":"20.0000","currency":"USD"},"allocation_id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","occurred_at":"2026-07-20T10:00:00Z"}]}
}
```

### 2.7 ReversalLinkage, ApprovalResponse, and Pagination

A reversal creates a new posted Allocation linked bidirectionally to the original. It never changes or deletes the original financial facts; the original state changes only from `posted` to `reversed` with an immutable linkage and incremented version. The standard durable approval response contains no originating payload. Pagination uses signed opaque cursors binding entity, normalized filters, ordering, and a fixed read boundary.

```json
{
  "reversal_linkage":{"original_allocation_id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","reversal_allocation_id":"4aa6dc20-f314-40da-ae3e-0e66e1496da2","reversed_at":"2026-07-21T09:00:00Z"},
  "approval_response":{"approval":{"id":"e491d07a-365e-4318-9991-107889b48595","status":"pending","command":"CONFIGURED_SETTLEMENT_COMMAND","resource_id":null,"maker_id":"8e1cc916-3312-4f15-a1d7-9b46ab95722d","entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee","version":1,"submitted_at":"2026-07-20T10:00:00Z"}},
  "page":{"limit":50,"next_cursor":"SIGNED_OPAQUE_CURSOR"}
}
```

## 3. Receipt and Payment Endpoints

### 3.1 `POST /v1/receipts`

**Purpose and authorization:** Record cash received from one Customer, allocate it to one or many issued invoices, and/or retain an unapplied customer credit. Authentication plus `settlement.receipts.create` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id` and `Idempotency-Key` are required; correlation follows §1. `If-Match` and pagination do not apply. Each link requires the current invoice `expected_version`. `party_credit_expected_version` is required when `unapplied_amount` is positive, using `0` when no balance exists. Configured policy may return the standard `202` approval response; no threshold is defined here. Before approved execution no Allocation, document mutation, journal, number, credit, audit business record, or business outbox event exists.

**Request:** Required fields are `customer_id`, `settlement_date`, `bank_account_id`, `gross_amount`, `bank_amount`, `withholding_amount`, `unapplied_amount`, `withholding_lines`, `allocations`, and nullable `rate_record_id`. `party_credit_expected_version` is conditionally required as above. At least one allocation or a positive unapplied amount is required. Unknown fields are rejected.

**Validation:** Amounts satisfy §2.1; line withholding sums exactly; all currencies agree. The Customer and bank account are active and entity-owned. Every invoice is issued, belongs to that Customer and entity, uses the settlement currency, and has sufficient versioned open balance. Foreign currency requires an exact applicable immutable RateRecord; functional currency rejects a rate ID. Withholding must resolve through §2.3. Period, Numbering, posting-account, rounding, and FX configuration must resolve without defaults.

**Response and stable errors:** Successful execution returns `201` with `receipt` and updated `party_credit` when affected. Additional stable rules are `customer_inactive`, `amount_equation_mismatch`, `withholding_total_mismatch`, `over_allocation`, `document_party_mismatch`, `invalid_document_state`, `credit_currency_mismatch`, `missing_rate_reference`, `invalid_rate_reference`, `missing_withholding_configuration`, `invalid_withholding_configuration`, `missing_numbering_configuration`, `missing_posting_configuration`, and `unbalanced_settlement`, all as `422 invariant_violation` details unless a common status applies.

**Audit and outbox:** Allocation, links, versioned Invoice applications, party credit, Numbering draw, balanced Ledger posting, immutable valuation references, audit, idempotency result, `ReceiptAllocated`, applicable `WithholdingCaptured`, applicable `RealisedFXRecognised`, applicable `CreditHeld`, Invoice status events, and Ledger events commit atomically. Any failure rolls everything back.

```json
{
  "request":{"customer_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","settlement_date":"2026-07-20","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","gross_amount":{"amount":"120.0000","currency":"USD"},"bank_amount":{"amount":"108.0000","currency":"USD"},"withholding_amount":{"amount":"12.0000","currency":"USD"},"unapplied_amount":{"amount":"20.0000","currency":"USD"},"rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","party_credit_expected_version":3,"withholding_lines":[{"withholding_code":"CONFIGURED_WITHHOLDING","amount":{"amount":"12.0000","currency":"USD"}}],"allocations":[{"invoice_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","applied_amount":{"amount":"100.0000","currency":"USD"},"expected_version":2}]},
  "response":{"receipt":{"id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","allocation_number":"RCPT-CONFIGURED","gross_amount":{"amount":"120.0000","currency":"USD"},"bank_amount":{"amount":"108.0000","currency":"USD"},"withholding_amount":{"amount":"12.0000","currency":"USD"},"allocated_amount":{"amount":"100.0000","currency":"USD"},"unapplied_amount":{"amount":"20.0000","currency":"USD"},"state":"posted","version":1},"party_credit":{"available_balance":{"amount":"40.0000","currency":"USD"},"version":4}}
}
```

### 3.2 `POST /v1/payments`

**Purpose and authorization:** Record cash paid for one Vendor, allocate it to one or many approved bills, and/or retain an unapplied vendor advance. Authentication plus `settlement.payments.create` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** The receipt command protocol applies, with per-bill `expected_version`; `party_credit_expected_version` is required for positive unapplied amount. Configured policy may return `202`; no approval threshold is defined.

**Request:** Required fields are `vendor_id`, `settlement_date`, `bank_account_id`, all four §2.1 amounts, `withholding_lines`, `allocations`, and nullable `rate_record_id`. Conditional party-credit version and at-least-one-destination rules mirror receipts. Unknown fields are rejected.

**Validation:** The Vendor and bank account are active and entity-owned. Bills are approved, belong to the Vendor and entity, use the settlement currency, and have sufficient versioned open balances. Amount, withholding, Tax, FX, Period, Numbering, posting, currency, and configuration rules mirror receipts. No tax rate, source, account mapping, approval threshold, or rounding rule is inferred.

**Response and stable errors:** Successful execution returns `201` with `payment` and updated `party_credit` when affected. Receipt error rules apply with `vendor_inactive`; document rules refer to bills.

**Audit and outbox:** The single Unit of Work commits Allocation, links, Bill applications, credit, number, balanced posting, audit, idempotency, `PaymentAllocated`, applicable withholding/FX/credit events, Bill status events, and Ledger events. Any failure leaves no partial effect or consumed usable number except the already-frozen used-and-voided Numbering behavior.

```json
{
  "request":{"vendor_id":"8bdf810a-f3e5-4078-ac85-9a762543ed0d","settlement_date":"2026-07-20","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","gross_amount":{"amount":"100.0000","currency":"BDT"},"bank_amount":{"amount":"90.0000","currency":"BDT"},"withholding_amount":{"amount":"10.0000","currency":"BDT"},"unapplied_amount":{"amount":"0.0000","currency":"BDT"},"rate_record_id":null,"withholding_lines":[{"withholding_code":"CONFIGURED_VDS","amount":{"amount":"10.0000","currency":"BDT"}}],"allocations":[{"bill_id":"ee3195b1-2b7b-411f-92fa-67c1ce9350f2","applied_amount":{"amount":"100.0000","currency":"BDT"},"expected_version":2}]},
  "response":{"payment":{"id":"d8e6bdc7-e29b-41f8-a76c-fcd122b820dd","allocation_number":"PAY-CONFIGURED","gross_amount":{"amount":"100.0000","currency":"BDT"},"bank_amount":{"amount":"90.0000","currency":"BDT"},"withholding_amount":{"amount":"10.0000","currency":"BDT"},"allocated_amount":{"amount":"100.0000","currency":"BDT"},"unapplied_amount":{"amount":"0.0000","currency":"BDT"},"state":"posted","version":1}}
}
```

## 4. Party Credit Endpoints

### 4.1 `POST /v1/credits/{party}/apply`

**Purpose and authorization:** Apply existing unapplied party credit to one or many open documents without bank movement or new withholding. Authentication plus `settlement.credits.apply` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` for the PartyCredit version are required. Each document link also requires `expected_version`. Correlation follows §1; pagination does not apply. Configured policy may return `202`; no threshold is defined.

**Request:** Required fields are `party_type`, `currency`, `application_date`, and nonempty `allocations`. `party_type` is `customer` or `vendor`; Customer credit targets only invoices and Vendor credit targets only bills. No bank account, withholding, rate, gross, bank, or unapplied field is accepted. Unknown fields are rejected.

**Validation:** The party is active, entity-owned, and matches the credit and every document. Each applied amount is positive and the sum does not exceed available credit. Currency equals the PartyCredit and target-document currencies. Documents pass versioned open-balance checks. Period and posting configuration resolve. No FX calculation occurs because no conversion is permitted by this command.

**Response and stable errors:** `201` returns `allocation` and updated `party_credit`. Additional rules are `insufficient_party_credit`, `credit_currency_mismatch`, `over_allocation`, `document_party_mismatch`, `invalid_document_state`, `missing_posting_configuration`, and `unbalanced_settlement` as `422 invariant_violation` details; common errors apply.

**Audit and outbox:** Credit draw, document applications, balanced posting, allocation record, audit, idempotency, `CreditApplied`, document status events, and Ledger events commit atomically. No bank, withholding, `ReceiptAllocated`, or `PaymentAllocated` event is produced.

```json
{
  "request":{"party_type":"customer","currency":"USD","application_date":"2026-07-21","allocations":[{"invoice_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","applied_amount":{"amount":"15.0000","currency":"USD"},"expected_version":3}]},
  "response":{"allocation":{"id":"bc35144f-1813-4760-8e12-9432695b6910","operation":"credit_application","state":"posted","version":1},"party_credit":{"available_balance":{"amount":"25.0000","currency":"USD"},"version":5}}
}
```

### 4.2 `POST /v1/credits/{party}/refund`

**Purpose and authorization:** Refund some or all available party credit through an entity-owned bank/cash account. Authentication plus `settlement.credits.refund` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id`, `Idempotency-Key`, and PartyCredit `If-Match` are required. Correlation follows §1; pagination does not apply. Configured policy may return `202`; no threshold is defined.

**Request:** Required fields are `party_type`, `refund_date`, `bank_account_id`, positive `refund_amount`, and `expected_available_balance`. Nullable `rate_record_id` is required to be nonnull only when the refund currency differs from functional currency. The expected balance must exactly equal the current balance before the refund. No document allocation or withholding is accepted. Unknown fields are rejected.

**Validation:** Party, bank, credit, currency, and entity match. Refund does not exceed the available balance. Exact immutable RateRecord rules apply for foreign currency; no rate is inferred. Period, posting, Numbering, FX, rounding, and account configuration resolve. No approval threshold is inferred.

**Response and stable errors:** `201` returns the refund `allocation` and updated `party_credit`. Additional rules are `insufficient_party_credit`, `credit_balance_mismatch`, `credit_currency_mismatch`, `missing_rate_reference`, `invalid_rate_reference`, `missing_numbering_configuration`, `missing_posting_configuration`, and `unbalanced_settlement` as `422 invariant_violation` details; common errors apply.

**Audit and outbox:** Credit refund, bank posting, exact rate reference, audit, idempotency, `CreditRefunded`, applicable `RealisedFXRecognised`, and Ledger events commit atomically. It creates no document mutation, new withholding, or cash-receipt/payment allocation event.

```json
{
  "request":{"party_type":"customer","refund_date":"2026-07-22","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","refund_amount":{"amount":"10.0000","currency":"USD"},"expected_available_balance":{"amount":"25.0000","currency":"USD"},"rate_record_id":"670aa770-074c-4556-9c09-f81ea68cfe96"},
  "response":{"allocation":{"id":"79331f48-c7b1-4330-b154-ed9b0b91b82c","operation":"credit_refund","state":"posted","version":1},"party_credit":{"available_balance":{"amount":"15.0000","currency":"USD"},"version":6}}
}
```

### 4.3 `GET /v1/credits/{party}`

**Purpose and authorization:** Return the available unapplied balance and immutable source entries for one party. Authentication plus `settlement.credits.read` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id` is required; correlation follows §1. Idempotency, `If-Match`, and approval do not apply. Optional `limit` defaults to 50 and is 1–100; optional `cursor` continues the signed snapshot.

**Request:** Required query field `party_type` is `customer` or `vendor`. Optional fields are `limit` and `cursor`; all others are rejected. The path party and type must resolve to one active or deactivated entity-owned master. Cross-entity and unknown resources return `404`.

**Response and ordering:** `200` returns `party_credit` with current available balance, version, source entries, and `page`. Entries order by `occurred_at DESC, id DESC`. Cursor contents bind entity, party, type, currency, ordering, and read boundary. Invalid or altered cursors return `400 validation`.

**Audit and outbox:** This read creates no business audit or outbox event unless an already-configured read-audit policy requires access logging.

```json
{
  "request_query":{"party_type":"customer","limit":50,"cursor":null},
  "response":{"party_credit":{"party_type":"customer","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","available_balance":{"amount":"15.0000","currency":"USD"},"version":6,"source_entries":[{"id":"79331f48-c7b1-4330-b154-ed9b0b91b82c","entry_type":"refunded","amount":{"amount":"10.0000","currency":"USD"},"allocation_id":"79331f48-c7b1-4330-b154-ed9b0b91b82c","occurred_at":"2026-07-22T09:00:00Z"}]},"page":{"limit":50,"next_cursor":null}}
}
```

## 5. Allocation Endpoints

### 5.1 `POST /v1/allocations/{id}/reverse`

**Purpose and authorization:** Create a linked posted reversal of one posted Allocation. Authentication plus `settlement.allocations.reverse` is required. The original is never edited except for its state, version, and immutable reversal linkage; neither record is deleted.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id`, `Idempotency-Key`, and original Allocation `If-Match` are required. Correlation follows §1; pagination does not apply. Reversal uses the configured durable approval policy and may return `202`; no approval threshold or bypass is defined.

**Request:** Body is empty. Unknown fields are rejected. The service obtains current document and PartyCredit versions through their owning contracts and applies optimistic guards inside the transaction; a conflicting change returns `409 concurrency_conflict` without retrying against a semantically different balance.

**Validation:** The original is posted, entity-owned, not previously reversed, and reversible in the requested execution period under frozen Period rules. The reversal uses original amounts, allocation links, withholding snapshots/configuration, document and settlement RateRecord references, and posting linkage. It restores document open amounts and PartyCredit consistently without creating a negative balance or over-opening beyond the original document total.

**Response and stable errors:** `201` returns `original_allocation`, `reversal_allocation`, and `reversal_linkage`. Additional rules are `allocation_already_reversed`, `reversal_not_allowed`, `credit_balance_conflict`, `missing_posting_configuration`, and `unbalanced_reversal` as `422 invariant_violation` details; common errors apply. Transactional uniqueness permits only one successful reversal even under concurrency.

**Audit and outbox:** Reversal Allocation, original linkage/state, restored document balances/statuses, credit restoration, reversing Ledger entry, immutable audit, idempotency, `AllocationReversed`, applicable document status and credit events, and Ledger reversal events commit atomically. Original `ReceiptAllocated`, `PaymentAllocated`, withholding, and realised-FX events are not re-emitted.

```json
{
  "request":{},
  "response":{"original_allocation":{"id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","state":"reversed","version":2},"reversal_allocation":{"id":"4aa6dc20-f314-40da-ae3e-0e66e1496da2","operation":"reversal","state":"posted","version":1},"reversal_linkage":{"original_allocation_id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","reversal_allocation_id":"4aa6dc20-f314-40da-ae3e-0e66e1496da2","reversed_at":"2026-07-21T09:00:00Z"}}
}
```

### 5.2 `GET /v1/allocations`

**Purpose and authorization:** Return entity-scoped Allocation summaries for settlement operational views. Authentication plus `settlement.allocations.read` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id` is required; correlation follows §1. Idempotency, `If-Match`, and approval do not apply. `limit` defaults to 50 and is 1–100; `cursor` is optional.

**Request and filters:** Optional query fields are `operation`, `state`, `party_type`, `party`, `document`, `bank_account_id`, `from`, `to`, `limit`, and `cursor`. Operation and state use §2.5 enums. `party_type` is required when `party` is supplied. `from` and `to` filter settlement date inclusively and must form a valid range. All identifiers are entity-scoped; unknown fields are rejected.

**Response and ordering:** `200` returns `allocations` and `page`. Stable ordering is `settlement_date DESC, posted_at DESC, id DESC`. A signed cursor binds entity, normalized filters, ordering tuple, and fixed read boundary, so inserts after the first page cannot move or duplicate records across that traversal. Invalid, altered, or filter-mismatched cursors return the inherited `400 validation`.

**Audit and outbox:** This read creates no business audit or outbox event unless an already-configured read-audit policy requires access logging.

```json
{
  "request_query":{"operation":"receipt","state":"posted","party_type":"customer","party":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","from":"2026-07-01","to":"2026-07-31","limit":50,"cursor":null},
  "response":{"allocations":[{"id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","allocation_number":"RCPT-CONFIGURED","operation":"receipt","party_type":"customer","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","settlement_date":"2026-07-20","gross_amount":{"amount":"120.0000","currency":"USD"},"bank_amount":{"amount":"108.0000","currency":"USD"},"withholding_amount":{"amount":"12.0000","currency":"USD"},"allocated_amount":{"amount":"100.0000","currency":"USD"},"unapplied_amount":{"amount":"20.0000","currency":"USD"},"state":"posted","version":1,"posted_at":"2026-07-20T10:00:00Z"}],"page":{"limit":50,"next_cursor":null}}
}
```

## 6. Internal Contracts and Transaction Guarantees

`GetOpenReceivable`, `GetOpenPayable`, and versioned `ApplySettlement` remain Receivables/Payables-owned internal contracts. Settlement supplies the expected document version; the owning service conditionally changes only open balance, status, and version. No Settlement component reads or writes Receivables or Payables tables directly.

Applicable RateRecord lookup and realised-FX calculation remain FX-owned internal contracts. Inputs are the applied transaction tranche, functional currency, and exact immutable document and settlement rate references. Settlement stores their immutable references and result; it never accepts or calculates a client-provided rate.

Withholding determination and TaxSnapshot/configuration validation remain Tax-owned internal contracts. Posting remains Ledger-owned `PostingService`; Numbering remains the approved atomic shared-kernel contract; postability remains Period-owned. No internal service is exposed through this proposal.

For every successful command, Settlement state, document applications, PartyCredit changes, numbering result, balanced Ledger posting, audit, idempotency result, and outbox messages commit in the approved single Unit of Work. A technical error, stale document, invalid configuration, failed posting, failed number draw, or failed internal valuation rolls back all business effects. Approval execution failure follows the frozen recoverable pending lifecycle.

## 7. Exclusions and Configurable Policy Boundaries

This proposal excludes Credit Notes, Debit Notes, full Period Close, bank reconciliation, migration, ageing and later reporting, cash forecasting, automatic matching, receipts/payments from external banks, and every M4/M5 endpoint.

It defines no withholding rate or legal treatment, TaxPack value, FX source, currency precision, rounding mode, bank mapping, AR/AP/credit/withholding/FX account mapping, numbering format, sequence policy, approval threshold, reversal approval policy, period policy, or automatic allocation policy. Missing, ambiguous, inactive, or non-unique required configuration fails safely with a stable `422 invariant_violation` rule and no partial business mutation.

There are no unresolved governance decisions in this proposal. The positive-value settlement equations in §2.1 are explicit, party type is explicit on otherwise ambiguous credit operations, foreign conversion is reference-only, and all approval thresholds and legal/configuration values remain external policy dependencies.
