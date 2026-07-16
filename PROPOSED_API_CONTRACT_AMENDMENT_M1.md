# M1 Ledger + Valuation API Contract Amendment

> Status: Proposed; not frozen  
> Scope: Public `/v1` contract for M1 Ledger, Tax, and Currency & FX  
> Authority: No implementation authority until approved

## 1. Common Protocol

### 1.1 Access and headers

All endpoints require TLS, authentication, and `X-Entity-Id`. Authorization is default-deny and entity-scoped. Cross-entity resources return `404 not_found`.

`X-Correlation-Id` is optional. The server replaces an absent or invalid value with a UUID, echoes the effective value, and propagates it to logs, audit, outbox metadata, and internal calls.

Every state-changing endpoint requires a UUID `Idempotency-Key`. The same key and canonical request return the original result without repeating state, audit, or events. Reuse with different input returns `409 idempotency_conflict`. Replays return `Idempotency-Replayed: true`.

`If-Match` is required only where stated. Missing and stale versions return `428 precondition_required` and `409 concurrency_conflict`. `X-SoD-Justification` is accepted only for an authorized compensating-control flow.

### 1.2 Formats and errors

UUIDs are canonical strings; dates are `YYYY-MM-DD`; timestamps are UTC RFC 3339; decimals are JSON strings. Money is:

```json
{"amount":"1250.0000","currency":"BDT"}
```

Unknown request fields return `400 validation`.

```json
{"error_code":"validation","message":"The request is invalid.","details":{"field":["A stable explanation."]}}
```

Errors: `400 validation`, `401 unauthenticated`, `403 authorization`, `403 sod_exception_required`, `404 not_found`, `409 concurrency_conflict`, `409 idempotency_conflict`, `409 duplicate_resource`, `422 invariant_violation`, `423 period_locked`, and `428 precondition_required`. Errors expose no stack traces or internal details.

### 1.3 Approval and pagination

Configured maker-checker commands may return `202` as a successful outcome:

```json
{"approval":{"id":"3530ca0e-4201-4ab1-8521-20f851defd44","status":"pending","command":"reverse_journal","resource_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa"}}
```

Cursor lists use opaque `cursor`; `limit` defaults to 50 and ranges from 1 to 100. Cursors bind entity, filters, ordering, and read boundary.

```json
{"limit":50,"next_cursor":null}
```

## 2. Shared Schemas

### 2.1 LedgerAccount

```json
{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","code":"1060","name":"Accounts Receivable","description":"Trade receivables","type":"asset","normal_balance":"debit","status":"active","version":1,"created_at":"2026-07-15T10:30:00Z","updated_at":"2026-07-15T10:30:00Z"}
```

Types are `asset`, `liability`, `equity`, `revenue`, and `expense`. Status is `active` or `deactivated`.

### 2.2 TaxCode and TaxSnapshot

```json
{"tax_code":{"id":"fb861bea-a516-4546-b92e-2a96a19a3379","code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD","status":"active","version":3,"versions":[]},"tax_snapshot":{"tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379","tax_code_version_id":"5b597b92-5ba8-45a5-acce-166172b32a51","code":"BD-EXAMPLE","jurisdiction":"BD","treatment":"zero_rated","rate":"0.00000000","recoverable":true,"calculation_method":"CONFIGURED_METHOD","gl_mapping":{"output_account_id":"098d6884-8199-483d-940c-f90d919d15e3","input_account_id":null},"return_box_mapping":{"configured_box_key":"CONFIGURED_VALUE"},"effective_from":"2026-07-01","effective_to":null}}
```

Treatments are `standard`, `zero_rated`, and `exempt`. A persisted TaxSnapshot is immutable.

### 2.3 RateRecord and reference

```json
{"rate_record":{"id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15","source":"CONFIGURED_SOURCE","is_override":false,"override_reason":null,"referenced":false},"exchange_rate_reference":{"rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15"}}
```

Example rates are structural test data, not approved rates.

## 3. Ledger Endpoints

### 3.1 `POST /v1/accounts`

Authorization: `ledger.accounts.manage`. Idempotency required; no `If-Match`; no pagination.

Request requires unique `code` (1–32), `name` (1–255), optional nullable `description` (max 2000), and account `type`. Entity, normal balance, status, balance, and version are rejected.

```json
{"request":{"code":"1060","name":"Accounts Receivable","description":"Trade receivables","type":"asset"},"response":{"account":{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","code":"1060","name":"Accounts Receivable","description":"Trade receivables","type":"asset","normal_balance":"debit","status":"active","version":1}}}
```

Returns `201`; errors include `409 duplicate_resource`. Creation audit and `AccountCreated` commit atomically.

### 3.2 `PATCH /v1/accounts/{id}`

Authorization: `ledger.accounts.manage`. Idempotency and `If-Match` required; no pagination.

Request contains at least one of `name`, nullable `description`, or `type`; creation field limits apply. Code is immutable. Type cannot change after posting history exists.

```json
{"request":{"name":"Trade Accounts Receivable","description":"Entity trade receivables"},"response":{"account":{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","code":"1060","name":"Trade Accounts Receivable","description":"Entity trade receivables","type":"asset","normal_balance":"debit","status":"active","version":2}}}
```

Returns `200`; prohibited type change returns `422` rule `account_type_immutable_once_posted`. The conditional write increments version. Audit stores before and after; no outbox event.

### 3.3 `POST /v1/accounts/{id}/deactivate`

Authorization: `ledger.accounts.manage`. Idempotency and `If-Match` required; no pagination.

The account must be active. A new command on a deactivated account returns `422` rule `account_already_deactivated`; exact replay returns the original result.

```json
{"request":{},"response":{"account":{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","status":"deactivated","version":2}}}
```

Returns `200`. Conditional deactivation, audit, and `AccountDeactivated` commit atomically.

### 3.4 `GET /v1/accounts/{id}/balance`

Authorization: `ledger.reports.read`. Optional `asOf` ISO date defaults to current entity accounting date. No idempotency, concurrency header, or pagination.

Only posted entries dated on or before `asOf` are included; drafts are excluded.

```json
{"request_query":{"asOf":"2026-07-15"},"response":{"account":{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","code":"1060","name":"Accounts Receivable","normal_balance":"debit"},"as_of":"2026-07-15","balance":{"amount":"1250.0000","currency":"BDT"}}}
```

Returns `200`; errors are `400`, `401`, `403`, and `404`. No event; read auditing follows configured policy.

### 3.5 `GET /v1/journals`

Authorization: `ledger.journals.read`. Cursor pagination applies.

Filters: entity-scoped `account`, `period`, `status` (`draft`, `posted`, `reversed`), `entry_type` (`manual`, `system`, `adjusting`, `reversal`, `revaluation`, `conversion`), ISO `from`/`to`, and entity-scoped `source_document_id`. Dates must be ordered and agree with period. Ordering is `entry_date DESC, id DESC`.

```json
{"request_query":{"status":"posted","period":"2026-07","limit":50,"cursor":null},"response":{"journals":[{"id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","journal_number":"JRN-2026-0001","entry_date":"2026-07-15","entry_type":"manual","state":"posted","total_debit":{"amount":"100.0000","currency":"BDT"},"total_credit":{"amount":"100.0000","currency":"BDT"},"version":2}],"page":{"limit":50,"next_cursor":null}}}
```

Returns `200`; common read errors apply. No audit or outbox event.

### 3.6 `POST /v1/journals/{id}/reverse`

Authorization: `ledger.journals.reverse`; configured approval and SoD may apply. Idempotency required; no `If-Match` or pagination.

Request requires postable ISO `entry_date` and nonblank `reason` (max 2000). Source must be posted, unreversed, and non-system. System entries reverse through their source. One effective reversal is permitted. Lines swap debit/credit and preserve foreign references.

```json
{"request":{"entry_date":"2026-07-16","reason":"Correction of posting classification"},"response":{"journal":{"id":"0967aad7-12eb-45c3-9afd-aacb91d6d0f3","entry_date":"2026-07-16","entry_type":"reversal","state":"posted","reversal_of_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","version":1}}}
```

Returns `201` or `202`. Errors include `journal_not_posted`, `journal_already_reversed`, `system_entry_source_reversal_required`, and `423 period_locked`. Audit, reversal, `JournalReversed`, and posting events commit atomically.

### 3.7 `POST /v1/journals` foreign-currency extension

The approved M0 authorization and response remain in force. Idempotency required; no `If-Match` or pagination.

Functional debit/credit uses entity functional currency. A foreign line requires foreign Money and an exact applicable RateRecord reference; functional lines omit both. Draft creation cannot create or override rates. Functional totals must balance after configured rounding.

```json
{"request":{"entry_date":"2026-07-15","entry_type":"manual","lines":[{"account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","debit":{"amount":"100.0000","currency":"BDT"},"credit":null,"foreign_amount":{"amount":"1.0000","currency":"USD"},"exchange_rate_reference":{"rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15"}},{"account_id":"77daa856-059f-4a5f-a00f-5aba155d9379","debit":null,"credit":{"amount":"100.0000","currency":"BDT"},"foreign_amount":null,"exchange_rate_reference":null}]},"response":{"journal":{"id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","state":"draft","version":1}}}
```

Returns `201`. Additional `422` rules are `missing_rate_reference`, `rate_reference_mismatch`, `currency_pair_mismatch`, and `functional_balance_mismatch`. Audit/outbox follows the approved M0 contract.

## 4. Tax Endpoints

### 4.1 `POST /v1/tax/codes`

Authorization: `tax.codes.manage`; four-eyes required. Idempotency required; no `If-Match` or pagination.

Request requires unique `code`, nonblank `name`, and configured `jurisdiction`. Rate and legal behavior fields are rejected.

```json
{"request":{"code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD"},"response":{"tax_code":{"id":"fb861bea-a516-4546-b92e-2a96a19a3379","code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD","status":"active","version":1,"versions":[]}}}
```

Returns `201` or `202`; `409 duplicate_resource` applies. Definition and approval are audited; no public event.

### 4.2 `GET /v1/tax/codes`

Authorization: `tax.codes.read`. Cursor pagination applies.

Optional filters: configured `jurisdiction`, `status` (`active`, `inactive`), and ISO `effective_on`. Ordering is `jurisdiction, code, id`. Applicable version ID appears only when `effective_on` is supplied.

```json
{"request_query":{"jurisdiction":"BD","status":"active","effective_on":"2026-07-15","limit":50,"cursor":null},"response":{"tax_codes":[{"id":"fb861bea-a516-4546-b92e-2a96a19a3379","code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD","status":"active","version":3,"applicable_version_id":"5b597b92-5ba8-45a5-acce-166172b32a51"}],"page":{"limit":50,"next_cursor":null}}}
```

Returns `200`; common read errors apply. No audit or outbox event.

### 4.3 `GET /v1/tax/codes/{id}`

Authorization: `tax.codes.read`. No idempotency, `If-Match`, or pagination. ID is an entity-scoped UUID. Versions order by effective date and version number.

```json
{"request_path":{"id":"fb861bea-a516-4546-b92e-2a96a19a3379"},"response":{"tax_code":{"id":"fb861bea-a516-4546-b92e-2a96a19a3379","code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD","status":"active","version":3,"versions":[{"id":"5b597b92-5ba8-45a5-acce-166172b32a51","version_number":2,"treatment":"zero_rated","rate":"0.00000000","recoverable":true,"calculation_method":"CONFIGURED_METHOD","effective_from":"2026-07-01","effective_to":null,"referenced":false}]}}}
```

Returns `200`; errors are `400`, `401`, `403`, and `404`. No audit or outbox event.

### 4.4 `POST /v1/tax/codes/{id}/versions`

Authorization: `tax.codes.manage`; four-eyes required. Idempotency and `If-Match` required; no pagination.

Request requires treatment, nonnegative exact rate, recoverability, configured calculation method/mappings, and ordered non-overlapping effective dates. Accounts must belong to entity. Referenced versions cannot change or be deleted.

```json
{"request":{"treatment":"zero_rated","rate":"0.00000000","recoverable":true,"calculation_method":"CONFIGURED_METHOD","gl_mapping":{"output_account_id":"098d6884-8199-483d-940c-f90d919d15e3","input_account_id":null},"return_box_mapping":{"configured_box_key":"CONFIGURED_VALUE"},"effective_from":"2026-07-01","effective_to":null},"response":{"tax_code_version":{"id":"5b597b92-5ba8-45a5-acce-166172b32a51","version_number":2,"treatment":"zero_rated","rate":"0.00000000","recoverable":true,"effective_from":"2026-07-01","effective_to":null},"resource_version":4}}
```

Returns `201` or `202`. Errors include `effective_dates_overlap`, `invalid_tax_mapping`, and `tax_treatment_mismatch`. Conditional commit increments version. Audit diff and `TaxCodeVersioned` commit atomically.

### 4.5 `POST /v1/tax/packs`

Authorization: `tax.packs.manage`; four-eyes required. Idempotency required. `If-Match` is omitted for creation and required for revision. No pagination.

One pack exists per entity/jurisdiction. Codes share entity/jurisdiction. Configuration must match an approved schema; the server supplies no legal defaults.

```json
{"request":{"jurisdiction":"BD","name":"Bangladesh Tax Pack","tax_code_ids":["fb861bea-a516-4546-b92e-2a96a19a3379"],"return_template":{"schema_key":"CONFIGURED_TEMPLATE"},"policy":{"advance_tax_point":"CONFIGURED_VALUE","evidence_rules":"CONFIGURED_VALUE"}},"response":{"tax_pack":{"id":"2f0b9bf4-a45c-4c0a-8704-fbd907125adb","jurisdiction":"BD","name":"Bangladesh Tax Pack","version":1}}}
```

Returns `201` for creation, `200` for revision, or `202`. Invalid configuration returns `422` rule `invalid_tax_pack_configuration`. Audit diff and `TaxPackConfigured` commit atomically.

## 5. Currency and FX Endpoints

### 5.1 `POST /v1/fx/rates`

Authorization: `fx.rates.manage`; configured approval may apply. Idempotency required; no `If-Match` or pagination.

Request requires distinct supported currencies, positive exact rate, ISO date, and configured source. Overrides require a nonblank reason. Conflicts are not replaced; inverse/cross rates are not generated without policy.

```json
{"request":{"base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15","source":"CONFIGURED_SOURCE","is_override":false,"override_reason":null},"response":{"rate_record":{"id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15","source":"CONFIGURED_SOURCE","is_override":false,"override_reason":null,"referenced":false}}}
```

Example rate is test data, not approved policy. Returns `201` or `202`. Errors include `invalid_currency_pair`, `override_reason_required`, and `409 duplicate_resource`. Audit and `RateRecordAdded` commit atomically.

### 5.2 `GET /v1/fx/rates`

Authorization: `fx.rates.read`. Cursor pagination applies.

Optional base/quote currencies appear together. Optional filters are ordered ISO effective dates, configured source, and boolean referenced status. Ordering is `effective_date DESC, id DESC`.

```json
{"request_query":{"base_currency":"USD","quote_currency":"BDT","effective_from":"2026-07-01","effective_to":"2026-07-31","limit":50,"cursor":null},"response":{"rate_records":[{"id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15","source":"CONFIGURED_SOURCE","referenced":false}],"page":{"limit":50,"next_cursor":null}}}
```

Returns `200`; common read errors apply. No audit or outbox event.

### 5.3 `POST /v1/fx/revaluation`

Authorization: `fx.revaluation.run`; configured approval and SoD may apply. Idempotency required; no `If-Match` or pagination. Entity/period uniqueness prevents duplicates.

Request accepts only `period_ref`. Period must be Soft Close. Period-end selection policy and every required rate must exist. Client-supplied rates, figures, accounts, or items are rejected. Generated journals balance; failure is atomic.

```json
{"request":{"period_ref":"2026-07"},"response":{"revaluation_run":{"id":"36be830b-b86e-408a-a2ff-b354a5f9196b","period_ref":"2026-07","status":"posted","rate_record_ids":["6871052e-8b8c-44fb-b356-0582d48a305e"],"journal_entry_ids":["df654e88-f1cb-41f2-996c-1ee8826bf6aa"],"version":1,"posted_at":"2026-07-31T18:00:00Z"}}}
```

Returns `201` or `202`. Errors include `423 period_locked`, `missing_period_end_rate`, `revaluation_already_exists`, and `unbalanced_revaluation`. Run, journals, audit, `UnrealisedFXRevalued`, and posting events commit atomically.

### 5.4 `GET /v1/fx/revaluation`

Authorization: `fx.revaluation.read`. Required filter is entity PeriodRef; optional status is `pending_approval`, `posted`, or `reversed`. No pagination because entity/period bounds the result.

```json
{"request_query":{"period":"2026-07","status":"posted"},"response":{"revaluation_runs":[{"id":"36be830b-b86e-408a-a2ff-b354a5f9196b","period_ref":"2026-07","status":"posted","figures":[{"account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","amount":{"amount":"25.0000","currency":"BDT"}}],"journal_entry_ids":["df654e88-f1cb-41f2-996c-1ee8826bf6aa"],"reversal":{"status":"scheduled","target_period_ref":"2026-08","reversal_run_id":null,"journal_entry_ids":[],"reversed_at":null},"version":1}]}}
```

Returns `200`; common read errors apply. Query emits no event. Reversal is internal, idempotent, uses original figures, and atomically records links, audit, `RevaluationReversed`, and posting events.

## 6. Internal Contracts

### 6.1 Applicable tax lookup

Not HTTP. Inputs are entity, TaxCode ID, jurisdiction, tax-point date, and configured direction/pricing when required. Output is the immutable TaxSnapshot. Exactly one active version in the active TaxPack must cover the date. No legal treatment is inferred. Lookup emits no event; the consuming command persists the snapshot and records `TaxDetermined` when required. Correlation propagates.

### 6.2 Applicable FX rate lookup

Not HTTP. Inputs are entity, currency pair, effective date, and configured purpose when required. Output is RateRecord plus immutable reference. Exactly one authoritative record must resolve under configured source policy. Missing or ambiguous configuration fails without invented source, inverse, or cross rate. Lookup emits no event. Correlation propagates.

### 6.3 Realised FX calculation

Not HTTP. Inputs are applied transaction tranche, functional currency, and exact document and settlement rate references. Output is document functional Money, settlement functional Money, signed realised-FX Money, and classification. Arithmetic is exact and per tranche. References match immutable records. The calculation itself creates no audit or event.

## 7. Configurable Policy Boundaries

No defaults are defined for approval thresholds, tax rates or legal rules, TaxPack policy, FX sources or precedence, period-end rate selection, inverse/cross-rate policy, precision, rounding, revaluation reruns, or sequence formatting.

Missing required configuration returns a documented validation or invariant error and never activates a hardcoded fallback.
