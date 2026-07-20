# Proposed API Contract Amendment — M2 Documents

> **Status:** PROPOSED — not frozen and not approved for implementation
> **Scope:** M2 Receivables and Payables public HTTP contracts only
> **Authority:** Frozen SRS, ADRs, Aggregate Design, Domain Events, Repository Contracts, Database Design, and existing M0/M1 protocol

## 1. Scope and exclusions

This proposal defines the public contracts needed for Customer, Invoice, Vendor, Bill, and Expense flows in M2. It does not redefine the approved M0/M1 protocol. Settlement, receipts, payments, Credit Notes, Debit Notes, Period Close, ageing, reconciliation, migration, and later reporting are excluded. Posting, tax determination, and applicable-rate lookup remain internal application contracts.

Public draft editing and soft deactivation are included by approved governance decision. No delete, reactivate, posted-document edit, or attachment transport is introduced.

## 2. Shared protocol and schemas

### 2.1 Inherited protocol

All endpoints inherit the frozen M0/M1 conventions for TLS, authentication, entity isolation, `X-Entity-Id`, optional `X-Correlation-Id`, correlation validation and propagation, errors, exact decimal strings, UUIDs, UTC timestamps, unknown-field rejection, idempotency, `Idempotent-Replay: true`, `If-Match`, durable approval responses, and stable cursors. A malformed supplied correlation ID returns `400 validation`; a UUID is generated only when the header is absent.

State-changing endpoints require `Idempotency-Key`. Identical replay returns the original status, body, and headers without repeating audit, outbox, numbering, posting, or business effects. Key reuse with different canonical input returns `409 idempotency_conflict`. Unknown request or query fields return `400 validation`.

Cursor lists use `limit` from 1 to 100, default 50. Opaque cursors bind the entity, normalized filters, ordering, and read boundary. Cross-entity identifiers return `404 not_found`.

### 2.2 Money, TaxSnapshot, and ExchangeRateReference

Money and valuation snapshots reuse the frozen M1 representations. A document request never supplies a tax rate, legal treatment, numeric FX rate, or mutable snapshot. It may supply a TaxCode identifier when overriding a configured default and an exact `rate_record_id` when selecting an approved RateRecord; internal Tax and FX contracts validate the inputs and produce the immutable snapshots and exact reference.

```json
{
  "money": {"amount":"1250.0000","currency":"BDT"},
  "tax_snapshot": {
    "tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379",
    "tax_code_version_id":"5b597b92-5ba8-45a5-acce-166172b32a51",
    "code":"BD-EXAMPLE",
    "jurisdiction":"BD",
    "treatment":"zero_rated",
    "rate":"0.00000000",
    "recoverable":true,
    "calculation_method":"CONFIGURED_METHOD",
    "gl_mapping":{"output_account_id":"098d6884-8199-483d-940c-f90d919d15e3","input_account_id":null},
    "return_box_mapping":{"configured_box_key":"CONFIGURED_VALUE"},
    "effective_from":"2026-07-01",
    "effective_to":null
  },
  "exchange_rate_reference": {
    "rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e",
    "base_currency":"USD",
    "quote_currency":"BDT",
    "rate":"100.00000000",
    "effective_date":"2026-07-15"
  }
}
```

### 2.3 Address and contact details

Address and contact fields are optional and nullable. Country is an ISO 3166-1 alpha-2 code. Email and phone are validated but do not imply delivery, CRM, or notification behavior.

```json
{
  "contact":{"email":"finance@example.test","phone":"+8801000000000"},
  "address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"}
}
```

### 2.4 Customer and Vendor

Customer type is `local` or `foreign`; status is `active` or `deactivated`. `payment_terms` is a configured policy key, never an inferred number of days. Display names are not unique. `tax_identifier` is optional; when supplied, `jurisdiction` is required and the normalized identifier is unique within entity + jurisdiction + master type. Normalization is Unicode NFKC, outer-whitespace trim, and uppercase letters. Punctuation and internal characters are preserved; no transliteration, punctuation stripping, or fuzzy matching is applied. Customer and Vendor uniqueness scopes are independent. Without a tax identifier, duplicate names are allowed and UUID is identity. Vendor bank identifiers are write-only sensitive values; responses expose masked values only. They are encrypted at rest and omitted from logs, audit values, and events.

```json
{
  "customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","type":"foreign","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","contact":{"email":"finance@example.test","phone":null},"address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"},"status":"active","version":1,"created_at":"2026-07-20T10:00:00Z","updated_at":"2026-07-20T10:00:00Z"},
  "vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","contact":{"email":"accounts@example.test","phone":null},"address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"},"bank_details":{"account_name":"Example Vendor","institution_name":"Configured Bank","account_identifier_masked":"****1234","routing_identifier_masked":"****5678"},"status":"active","version":1,"created_at":"2026-07-20T10:00:00Z","updated_at":"2026-07-20T10:00:00Z"}
}
```

### 2.5 Document line and SBU allocation

Quantity, unit price, and weights are exact decimal strings. Server-derived amounts use Money. Invoice lines use configured revenue mapping; bill and expense inputs identify entity-owned active posting accounts where stated. `tax_code_id` is optional only when configured customer/vendor/entity policy resolves exactly one applicable code. Persisted snapshots are immutable.

```json
{
  "invoice_line":{"id":"9f38c020-cfb9-4904-a025-55933ab3637a","description":"Configured service","quantity":"1.0000","unit_price":{"amount":"100.0000","currency":"USD"},"tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379","tax_snapshot":null,"line_amount":{"amount":"100.0000","currency":"USD"},"tax_amount":{"amount":"0.0000","currency":"USD"},"total_amount":{"amount":"100.0000","currency":"USD"}},
  "bill_line":{"id":"12a6388c-a6b3-42d0-8e64-2efcbd8b08a7","description":"Configured service","quantity":"1.0000","unit_price":{"amount":"100.0000","currency":"BDT"},"expense_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","tax_code_id":null,"tax_snapshot":null,"line_amount":{"amount":"100.0000","currency":"BDT"},"tax_amount":{"amount":"0.0000","currency":"BDT"},"total_amount":{"amount":"100.0000","currency":"BDT"}},
  "sbu_allocation":{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}
}
```

### 2.6 Invoice and Bill representations

Invoice statuses are `draft`, `sent`, `partially_paid`, `paid`, `overdue`, and `void`. Bill statuses are `draft`, `awaiting_payment`, `partially_paid`, `paid`, `overdue`, and `void`. M2 creates only draft and recognized states; later milestones own settlement and correction transitions. A draft has a non-statutory provisional token and no document number. Issuance or approval atomically draws the configured number and persists valuation references.

```json
{
  "invoice_summary":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","provisional_token":null,"customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","total":{"amount":"100.0000","currency":"USD"},"open_balance":{"amount":"100.0000","currency":"USD"},"status":"sent","version":2},
  "invoice_detail":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","provisional_token":null,"customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","reference":null,"notes":null,"payment_instructions_ref":"CONFIGURED_INSTRUCTIONS","lines":[],"subtotal":{"amount":"100.0000","currency":"USD"},"tax_total":{"amount":"0.0000","currency":"USD"},"total":{"amount":"100.0000","currency":"USD"},"open_balance":{"amount":"100.0000","currency":"USD"},"exchange_rate_reference":null,"journal_entry_id":null,"status":"draft","version":1,"created_at":"2026-07-20T10:00:00Z","updated_at":"2026-07-20T10:00:00Z"},
  "bill_summary":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":"CONFIGURED-NUMBER","provisional_token":null,"vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","total":{"amount":"100.0000","currency":"BDT"},"open_balance":{"amount":"100.0000","currency":"BDT"},"status":"awaiting_payment","version":2},
  "bill_detail":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":"CONFIGURED-NUMBER","provisional_token":null,"vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","vendor_reference":null,"notes":null,"bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","lines":[],"sbu_allocations":[],"ait":null,"vds":null,"subtotal":{"amount":"100.0000","currency":"BDT"},"tax_total":{"amount":"0.0000","currency":"BDT"},"total":{"amount":"100.0000","currency":"BDT"},"open_balance":{"amount":"100.0000","currency":"BDT"},"exchange_rate_reference":null,"journal_entry_id":null,"status":"draft","version":1,"created_at":"2026-07-20T10:00:00Z","updated_at":"2026-07-20T10:00:00Z"}
}
```

### 2.7 Expense representation

Settlement type is `cash` or `accrued`; status is `recorded`. `bank_account_id` is required only for cash settlement. `vendor_id` is required only for accrued settlement. Category and SBU references must resolve to active entity-owned Ledger records. Tax and FX values are internally determined and snapshotted.

```json
{
  "expense":{"id":"427709ca-dab1-43f0-b4d0-2b4616d50233","expense_date":"2026-07-15","description":"Configured operating expense","vendor_id":null,"category_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","settlement_type":"cash","bank_account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","currency":"BDT","amount":{"amount":"100.0000","currency":"BDT"},"tax_code_id":null,"tax_snapshot":null,"ait":null,"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}],"exchange_rate_reference":null,"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","status":"recorded","version":1,"recorded_at":"2026-07-20T10:00:00Z"}
}
```

### 2.8 Approval response and PDF metadata

The approval resource reuses the frozen durable approval schema. No originating command payload or sensitive value is exposed. PDF metadata documents the binary response; it does not prescribe layout, branding, numbering format, or filename policy.

```json
{
  "approval_response":{"approval":{"id":"3530ca0e-4201-4ab1-8521-20f851defd44","status":"pending","command":"CONFIGURED_COMMAND","resource_id":"5c98deec-3920-46d6-a763-d13e44208a76","maker_id":"b7447cf1-adf8-439b-bf4c-34c5752cfdd7","entity_id":"2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d","version":1,"submitted_at":"2026-07-20T10:30:00Z"}},
  "pdf_response_metadata":{"media_type":"application/pdf","filename":"CONFIGURED_FILENAME.pdf","document_id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","content_length":1024,"etag":"CONFIGURED_ETAG"}
}
```

## 3. Receivables endpoints

### 3.1 `POST /v1/customers`

**Purpose and access:** Create an active Customer. Authentication plus `receivables.customers.manage` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match` or pagination. Durable approval does not apply.

**Request:** Required: `name`, `type`, `default_currency`, `payment_terms`. Optional nullable: `jurisdiction`, `tax_identifier`, `contact`, `address`. Unknown fields are rejected. Name is nonblank but not unique. Jurisdiction is required when tax identifier is nonnull. The approved normalization and independently scoped uniqueness rule in §2.4 applies.

**Response and errors:** `201` returns Customer. Stable additional errors are `409 duplicate_resource` and `422 missing_customer_configuration`; common errors apply.

**Audit and outbox:** Customer, immutable audit creation record, idempotency result, and `CustomerCreated` outbox event commit atomically. Sensitive contact values are omitted from event payload and operational logs.

```json
{"request":{"name":"Example Customer","type":"foreign","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","contact":{"email":"finance@example.test","phone":null},"address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"}},"response":{"customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","type":"foreign","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","status":"active","version":1}}}
```

### 3.2 `PATCH /v1/customers/{id}`

**Purpose and access:** Update an active Customer profile without changing historical document snapshots. Authentication plus `receivables.customers.manage` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. No pagination or durable approval.

**Request:** At least one of `name`, `type`, nullable `jurisdiction`, nullable `tax_identifier`, `default_currency`, `payment_terms`, nullable `contact`, or nullable `address` is required. Creation validation and §2.4 normalization apply. Status, entity, version, balances, and activity are rejected. Unknown fields are rejected.

**Response and errors:** `200` returns the updated Customer and incremented version. Additional errors are `409 duplicate_resource`, `422 customer_deactivated`, and `422 missing_customer_configuration`; common concurrency errors apply.

**Audit and outbox:** Conditional update, before/after audit excluding sensitive contact values, idempotency result, and `CustomerUpdated` commit atomically.

```json
{"request":{"name":"Example Customer Updated","payment_terms":"CONFIGURED_TERMS"},"response":{"customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer Updated","type":"foreign","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","status":"active","version":2}}}
```

### 3.3 `POST /v1/customers/{id}/deactivate`

**Purpose and access:** Soft-deactivate an active Customer while preserving all history and document references. Authentication plus `receivables.customers.manage` is required. No delete or reactivate operation is provided.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. Body must be empty. No pagination or durable approval. Exact replay returns the original result.

**Validation and errors:** Customer must be active and the supplied version current. Unknown body fields are rejected. A new command against a deactivated Customer returns `422 invariant_violation` with rule `customer_already_deactivated`; common concurrency and idempotency errors apply.

**Response:** `200` returns the deactivated Customer with incremented version.

**Audit and outbox:** Conditional soft deactivation, immutable audit record, idempotency result, and `CustomerDeactivated` outbox event commit atomically. Historical documents and references are unchanged.

```json
{"request":{},"response":{"customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","status":"deactivated","version":2}}}
```

### 3.4 `GET /v1/customers/{id}`

**Purpose and access:** Return one entity-scoped Customer profile. Authentication plus `receivables.customers.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Customer. Common read errors apply. Reads create no audit or outbox event unless configured read-audit policy requires one.

```json
{"response":{"customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","type":"foreign","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","status":"active","version":1}}}
```

### 3.5 `GET /v1/customers`

**Purpose and access:** List Customer summaries. Authentication plus `receivables.customers.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `search`, `type`, `status`, `limit`, and `cursor`. Status defaults to `active`; callers may explicitly request `deactivated`. Search applies to display name and normalized tax identifier; type and status use Customer enums. Unknown fields are rejected.

**Response and ordering:** `200` returns `customers` and `page`. Stable ordering is normalized `name ASC, id ASC`. Common read errors apply.

```json
{"request_query":{"status":"active","limit":50,"cursor":null},"response":{"customers":[{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","type":"foreign","default_currency":"USD","status":"active","version":1}],"page":{"limit":50,"next_cursor":null}}}
```

### 3.6 `POST /v1/invoices`

**Purpose and access:** Create an editable Invoice draft with a provisional token. Authentication plus `receivables.invoices.create` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match`, pagination, or durable approval.

**Request:** Required: active `customer_id`, ISO `invoice_date`, supported `currency`, and nonempty `lines`. Optional: `due_date`, nullable `reference`, nullable `notes`, nullable `payment_instructions_ref`, and nullable `rate_record_id`. Notes are plain document text and create no workflow or posting rule. Each line requires nonblank `description`, positive exact `quantity`, positive `unit_price`, and optional `tax_code_id`. Due date must not precede invoice date; if absent it is resolved from configured Customer terms. All line Money currencies equal document currency. A supplied rate ID must resolve through the internal FX contract and must match the document pair/date. Unknown fields, client totals, document numbers, mutable snapshots, numeric rates, status, open balance, journal IDs, and versions are rejected.

**Response and errors:** `201` returns Invoice detail in `draft`, version 1. Additional errors are `422 customer_inactive`, `422 missing_payment_terms_configuration`, `422 missing_tax_configuration`, and `422 invalid_document_currency`; common errors apply.

**Audit and outbox:** Draft, lines, audit, and idempotency result commit atomically. No recognition posting, statutory number, financial projection, or business outbox event is created.

```json
{"request":{"customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","reference":null,"payment_instructions_ref":"CONFIGURED_INSTRUCTIONS","rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e","lines":[{"description":"Configured service","quantity":"1.0000","unit_price":{"amount":"100.0000","currency":"USD"},"tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379"}]},"response":{"invoice":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":null,"provisional_token":"CONFIGURED_PROVISIONAL_TOKEN","status":"draft","version":1}}}
```

### 3.7 `PATCH /v1/invoices/{id}`

**Purpose and access:** Replace approved fields on an editable Invoice draft. Authentication plus `receivables.invoices.create` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. No pagination or durable approval. Exact replay returns the original result.

**Request:** At least one approved draft field from §3.6 is required: `customer_id`, `invoice_date`, nullable `due_date`, `currency`, nullable `reference`, nullable `notes`, nullable `payment_instructions_ref`, nullable `rate_record_id`, or the complete `lines` array. Collections are replaced as a whole, not merged. Line `tax_code_id` is the only TaxSnapshot input; client snapshots and numeric rates remain rejected. Changing party, dates, currency, TaxCode input, rate selection, notes, or lines triggers complete revalidation. Unknown fields are rejected.

**Validation and errors:** Only status `draft` may change. The §3.6 validation rules apply to the resulting complete draft. Issued or otherwise non-draft documents return `422 invariant_violation` with rule `invoice_not_draft`; common concurrency and idempotency errors apply.

**Response:** `200` returns the updated draft with incremented version.

**Audit and outbox:** Conditional draft replacement, immutable before/after audit, and idempotency result commit atomically. No issue, posting, numbering, recognition, or approval event is emitted; no number or JournalEntry is created.

```json
{"request":{"due_date":"2026-08-31","lines":[{"description":"Updated configured service","quantity":"2.0000","unit_price":{"amount":"50.0000","currency":"USD"},"tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379"}]},"response":{"invoice":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","status":"draft","version":2}}}
```

### 3.8 `GET /v1/invoices/{id}`

**Purpose and access:** Return a complete Invoice and immutable valuation data when recognized. Authentication plus `receivables.invoices.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Invoice detail. Draft lines may have null snapshots; recognized lines and foreign documents contain immutable snapshots/references. Common read errors apply. No audit or outbox event unless configured read-audit policy applies.

```json
{"response":{"invoice":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":null,"provisional_token":"CONFIGURED_PROVISIONAL_TOKEN","customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","lines":[],"exchange_rate_reference":null,"journal_entry_id":null,"status":"draft","version":1}}}
```

### 3.9 `GET /v1/invoices`

**Purpose and access:** Search Invoice summaries. Authentication plus `receivables.invoices.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `customer`, `status`, `overdue`, `from`, `to`, `limit`, and `cursor`. IDs are entity-scoped UUIDs; dates are ordered ISO dates; overdue is boolean and evaluated at the entity accounting date. Unknown fields are rejected.

**Response and ordering:** `200` returns `invoices` and `page`. Stable ordering is `invoice_date DESC, id DESC`. Drafts and recognized documents are returned only when matching filters. Common read errors apply.

```json
{"request_query":{"customer":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","status":"sent","overdue":false,"limit":50,"cursor":null},"response":{"invoices":[{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","total":{"amount":"100.0000","currency":"USD"},"open_balance":{"amount":"100.0000","currency":"USD"},"status":"sent","version":2}],"page":{"limit":50,"next_cursor":null}}}
```

### 3.10 `POST /v1/invoices/{id}/issue`

**Purpose and access:** Recognize a draft Invoice, allocate its configured number, persist immutable TaxSnapshots and RateRecord reference, and post the balanced recognition journal. Authentication plus `receivables.invoices.issue` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. Body must be empty. No pagination. Durable approval does not apply under the currently frozen high-risk list.

**Validation:** Invoice must be draft and complete; customer active; issue date postable; configured number sequence, revenue mapping, tax determination, functional-currency rounding, and applicable FX rate must resolve. Foreign documents require an exact applicable RateRecord. Recognition uses invoice date and must produce a balanced journal. Unknown body fields are rejected.

**Response and errors:** `201` returns recognized Invoice detail with status `sent`, number, version 2, immutable valuation data, and journal ID. Additional errors are `422 invoice_not_draft`, `422 customer_inactive`, `422 missing_numbering_configuration`, `422 missing_posting_configuration`, `422 missing_tax_configuration`, `422 missing_rate_reference`, `422 unbalanced_recognition`, and `423 period_locked`; common concurrency errors apply.

**Audit and outbox:** Number draw, Invoice transition, immutable lines/snapshots/references, recognition JournalEntry, audit, idempotency result, `InvoiceIssued`, `TaxDetermined` where required, and `JournalPosted` commit in the approved recognition Unit of Work. Failure leaves no partial recognition; numbering follows ADR-009 used-and-voided handling.

```json
{"request":{},"response":{"invoice":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","provisional_token":null,"status":"sent","open_balance":{"amount":"100.0000","currency":"USD"},"exchange_rate_reference":{"rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15"},"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","version":2}}}
```

### 3.11 `GET /v1/invoices/{id}/pdf`

**Purpose and access:** Retrieve the generated PDF for a recognized Invoice. Authentication plus `receivables.invoices.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; optional correlation and standard conditional `If-None-Match` are accepted. No idempotency, aggregate `If-Match`, approval, or pagination. No query fields or body are accepted.

**Validation and response:** Invoice must exist in entity scope and be recognized. `200` returns binary `application/pdf` with safe `Content-Disposition`, `Content-Length`, `ETag`, `X-Document-Id`, and `X-Document-Number` metadata; matching ETag returns `304` without a body. Additional error is `422 invoice_not_issued`; common read errors apply.

**Audit and outbox:** No financial audit or outbox event. Configured document-access auditing may record safe metadata only. PDF content and design follow separately configured presentation policy.

```json
{"response_metadata":{"status":200,"media_type":"application/pdf","filename":"CONFIGURED_FILENAME.pdf","document_id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","content_length":1024,"etag":"CONFIGURED_ETAG"}}
```

## 4. Payables endpoints

### 4.1 `POST /v1/vendors`

**Purpose and access:** Create an active Vendor. Authentication plus `payables.vendors.manage` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match`, pagination, or durable approval.

**Request:** Required: `name`, `default_currency`, `payment_terms`. Optional nullable: `jurisdiction`, `tax_identifier`, `contact`, `address`, and `bank_details`. Bank details accept `account_name`, `institution_name`, `account_identifier`, and nullable `routing_identifier`; raw identifiers are never returned. Unknown fields are rejected. Name is nonblank but not unique. Jurisdiction is required when tax identifier is nonnull. The approved normalization and independently scoped uniqueness rule in §2.4 applies.

**Response and errors:** `201` returns Vendor with masked bank details. Additional errors are `409 duplicate_resource` and `422 missing_vendor_configuration`; common errors apply.

**Audit and outbox:** Vendor, encrypted sensitive fields, redacted audit creation, idempotency result, and `VendorCreated` outbox event commit atomically. Sensitive values never enter logs or events.

```json
{"request":{"name":"Example Vendor","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","contact":{"email":"accounts@example.test","phone":null},"address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"},"bank_details":{"account_name":"Example Vendor","institution_name":"Configured Bank","account_identifier":"CONFIGURED_ACCOUNT_IDENTIFIER","routing_identifier":null}},"response":{"vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","bank_details":{"account_name":"Example Vendor","institution_name":"Configured Bank","account_identifier_masked":"****1234","routing_identifier_masked":null},"status":"active","version":1}}}
```

### 4.2 `PATCH /v1/vendors/{id}`

**Purpose and access:** Update an active Vendor profile without changing historical documents. Authentication plus `payables.vendors.manage` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. No pagination or durable approval.

**Request:** At least one of `name`, nullable `jurisdiction`, nullable `tax_identifier`, `default_currency`, `payment_terms`, nullable `contact`, nullable `address`, or nullable `bank_details` is required. Creation validation and §2.4 normalization apply. Status, entity, version, balances, and activity are rejected. Unknown fields are rejected.

**Response and errors:** `200` returns updated Vendor and incremented version. Additional errors are `409 duplicate_resource`, `422 vendor_deactivated`, and `422 missing_vendor_configuration`; common concurrency errors apply.

**Audit and outbox:** Conditional update, redacted before/after audit, idempotency result, and `VendorUpdated` commit atomically. Sensitive bank changes are recorded as changed/not-changed only.

```json
{"request":{"name":"Example Vendor Updated","payment_terms":"CONFIGURED_TERMS"},"response":{"vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor Updated","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","status":"active","version":2}}}
```

### 4.3 `POST /v1/vendors/{id}/deactivate`

**Purpose and access:** Soft-deactivate an active Vendor while preserving all history and document references. Authentication plus `payables.vendors.manage` is required. No delete or reactivate operation is provided.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. Body must be empty. No pagination or durable approval. Exact replay returns the original result.

**Validation and errors:** Vendor must be active and the supplied version current. Unknown body fields are rejected. A new command against a deactivated Vendor returns `422 invariant_violation` with rule `vendor_already_deactivated`; common concurrency and idempotency errors apply.

**Response:** `200` returns the deactivated Vendor with incremented version.

**Audit and outbox:** Conditional soft deactivation, immutable audit record, idempotency result, and `VendorDeactivated` outbox event commit atomically. Historical documents and references are unchanged.

```json
{"request":{},"response":{"vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","status":"deactivated","version":2}}}
```

### 4.4 `GET /v1/vendors/{id}`

**Purpose and access:** Return one entity-scoped Vendor profile with masked sensitive fields. Authentication plus `payables.vendors.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Vendor. Common read errors apply. No audit or outbox event unless configured read-audit policy requires safe metadata.

```json
{"response":{"vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","bank_details":{"account_name":"Example Vendor","institution_name":"Configured Bank","account_identifier_masked":"****1234","routing_identifier_masked":null},"status":"active","version":1}}}
```

### 4.5 `GET /v1/vendors`

**Purpose and access:** List Vendor summaries. Authentication plus `payables.vendors.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `search`, `status`, `limit`, and `cursor`. Status defaults to `active`; callers may explicitly request `deactivated`. Search applies to display name and normalized tax identifier. Unknown fields are rejected.

**Response and ordering:** `200` returns `vendors` and `page`. Stable ordering is normalized `name ASC, id ASC`. Sensitive bank fields are omitted. Common read errors apply.

```json
{"request_query":{"status":"active","limit":50,"cursor":null},"response":{"vendors":[{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","default_currency":"BDT","status":"active","version":1}],"page":{"limit":50,"next_cursor":null}}}
```

### 4.6 `POST /v1/bills`

**Purpose and access:** Create an editable Bill draft with a provisional token. Authentication plus `payables.bills.create` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match`, pagination, or durable approval.

**Request:** Required: active `vendor_id`, ISO `bill_date`, supported `currency`, nonempty `lines`, and nonempty `sbu_allocations`. Optional: `due_date`, nullable `vendor_reference`, nullable `notes`, nullable `ait`, nullable `vds`, and nullable `rate_record_id`. Notes are plain document text and create no workflow or posting rule. Each line requires description, positive quantity/unit price, active entity expense account, and optional TaxCode. SBU weights are positive exact decimals and total exactly `1.0000`. Due date cannot precede bill date; missing due date uses configured Vendor terms. All Money currencies equal document currency. A supplied rate ID must resolve through the internal FX contract and match the document pair/date. Unknown fields and client-derived totals, numbers, mutable snapshots, numeric rates, status, balance, journal IDs, and versions are rejected.

**Response and errors:** `201` returns Bill detail in `draft`, version 1. Additional errors are `422 vendor_inactive`, `422 missing_payment_terms_configuration`, `422 missing_tax_configuration`, `422 invalid_document_currency`, `422 invalid_expense_account`, and `422 sbu_allocation_invalid`; common errors apply.

**Audit and outbox:** Draft, lines, allocations, audit, and idempotency result commit atomically. No recognition posting, statutory/internal number, projection, or business outbox event is created.

```json
{"request":{"vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","vendor_reference":"CONFIGURED-REFERENCE","bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","rate_record_id":null,"lines":[{"description":"Configured service","quantity":"1.0000","unit_price":{"amount":"100.0000","currency":"BDT"},"expense_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","tax_code_id":null}],"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}],"ait":null,"vds":null},"response":{"bill":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":null,"provisional_token":"CONFIGURED_PROVISIONAL_TOKEN","status":"draft","version":1}}}
```

### 4.7 `PATCH /v1/bills/{id}`

**Purpose and access:** Replace approved fields on an editable Bill draft. Authentication plus `payables.bills.create` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. No pagination or durable approval. Exact replay returns the original result.

**Request:** At least one approved draft field from §4.6 is required: `vendor_id`, nullable `vendor_reference`, nullable `notes`, `bill_date`, nullable `due_date`, `currency`, nullable `rate_record_id`, complete `lines`, complete `sbu_allocations`, nullable `ait`, or nullable `vds`. Collections and allocations are replaced as a whole, not merged. Line `tax_code_id` is the only TaxSnapshot input; client snapshots and numeric rates remain rejected. Changing party, dates, currency, TaxCode input, RateRecord selection, allocations, notes, or amounts triggers complete revalidation. Unknown fields are rejected.

**Validation and errors:** Only status `draft` may change. The §4.6 validation rules apply to the resulting complete draft. Approved or otherwise non-draft Bills return `422 invariant_violation` with rule `bill_not_draft`; common concurrency and idempotency errors apply.

**Response:** `200` returns the updated draft with incremented version.

**Audit and outbox:** Conditional draft replacement, immutable before/after audit, and idempotency result commit atomically. No approval, posting, numbering, recognition, or business event is emitted; no number or JournalEntry is created.

```json
{"request":{"due_date":"2026-08-31","sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}]},"response":{"bill":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","status":"draft","version":2}}}
```

### 4.8 `GET /v1/bills/{id}`

**Purpose and access:** Return a complete Bill and immutable valuation data when approved. Authentication plus `payables.bills.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Bill detail. Draft lines may have null snapshots; approved lines and foreign documents contain immutable valuation data. Common read errors apply. No audit or outbox event unless configured read-audit policy applies.

```json
{"response":{"bill":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":null,"provisional_token":"CONFIGURED_PROVISIONAL_TOKEN","vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","lines":[],"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}],"exchange_rate_reference":null,"journal_entry_id":null,"status":"draft","version":1}}}
```

### 4.9 `GET /v1/bills`

**Purpose and access:** Search Bill summaries. Authentication plus `payables.bills.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `vendor`, `status`, `overdue`, `from`, `to`, `limit`, and `cursor`. IDs are entity-scoped UUIDs; dates are ordered ISO dates; overdue is evaluated at entity accounting date. Unknown fields are rejected.

**Response and ordering:** `200` returns `bills` and `page`. Stable ordering is `bill_date DESC, id DESC`. Common read errors apply.

```json
{"request_query":{"vendor":"136cf19a-601f-4f2a-99a0-43276750bd1f","status":"awaiting_payment","overdue":false,"limit":50,"cursor":null},"response":{"bills":[{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":"CONFIGURED-NUMBER","vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","total":{"amount":"100.0000","currency":"BDT"},"open_balance":{"amount":"100.0000","currency":"BDT"},"status":"awaiting_payment","version":2}],"page":{"limit":50,"next_cursor":null}}}
```

### 4.10 `POST /v1/bills/{id}/approve`

**Purpose and access:** Approve a Bill, allocate its configured number, persist immutable TaxSnapshots and RateRecord reference, and post the balanced recognition journal. Authentication plus `payables.bills.approve` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. Body must be empty. `X-SoD-Justification` is accepted only by the frozen compensating-control flow. No pagination.

**Approval behavior:** Bill creator and direct approver must differ. A maker attempt returns `403 sod_exception_required` unless an authorized, audited compensating-control exception applies. If configured Approval Policy independently requires durable approval, the command returns the frozen `202` approval response and commits no Bill or Ledger mutation before approved replay. No monetary threshold is defined here.

**Validation:** Bill must be draft and complete; vendor active; bill date postable; number sequence, tax, expense/AP mappings, SBU records, rounding, and applicable FX rate must resolve. SBU total is exactly `1.0000`; AIT/VDS use configured TaxPack rules. Recognition uses bill date and balances exactly. Unknown body fields are rejected.

**Response and errors:** Direct/approved execution returns `201` with status `awaiting_payment`, number, version 2, snapshots/reference, and journal ID; configured approval returns `202`. Additional errors are `422 bill_not_draft`, `422 vendor_inactive`, `422 missing_numbering_configuration`, `422 missing_posting_configuration`, `422 missing_tax_configuration`, `422 missing_rate_reference`, `422 sbu_allocation_invalid`, `422 unbalanced_recognition`, and `423 period_locked`; common concurrency/approval errors apply.

**Audit and outbox:** Number draw, Bill transition, immutable valuation data, JournalEntry, audit, idempotency result, `BillApproved`, `TaxDetermined` where required, and `JournalPosted` commit atomically. ApprovalRequest creation follows the frozen Identity transaction; successful replay performs the business Unit of Work once.

```json
{"request":{},"response":{"bill":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":"CONFIGURED-NUMBER","provisional_token":null,"status":"awaiting_payment","open_balance":{"amount":"100.0000","currency":"BDT"},"exchange_rate_reference":null,"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","version":2}}}
```

### 4.11 `POST /v1/expenses`

**Purpose and access:** Record a cash-settled or accrued Expense and its balanced Ledger recognition. Authentication plus `payables.expenses.create` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match`, pagination, or durable approval under the frozen high-risk list.

**Request:** Required: ISO `expense_date`, nonblank `description`, active `category_account_id`, `settlement_type`, supported `currency`, positive `amount`, and SBU allocations totaling exactly `1.0000`. Cash requires active entity bank `bank_account_id` and rejects `vendor_id`; accrued requires active `vendor_id` and rejects `bank_account_id`. Optional nullable: `tax_code_id`, `ait`. Unknown fields, client snapshots/rates/journal IDs/status/version are rejected.

**Validation and errors:** Date must be postable. Tax, FX, category, bank/AP mapping, rounding, and SBU configuration must resolve; all Money currencies match. `201` returns recorded Expense. Additional errors are `422 invalid_settlement_type`, `422 invalid_expense_account`, `422 invalid_bank_account`, `422 vendor_inactive`, `422 sbu_allocation_invalid`, `422 missing_tax_configuration`, `422 missing_rate_reference`, `422 missing_posting_configuration`, `422 unbalanced_recognition`, and `423 period_locked`; common errors apply.

**Audit and outbox:** Expense, immutable valuation data, JournalEntry, audit, idempotency result, `ExpenseRecorded`, `TaxDetermined` where required, and `JournalPosted` commit atomically. Failure creates no partial business effect.

```json
{"request":{"expense_date":"2026-07-15","description":"Configured operating expense","category_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","settlement_type":"cash","bank_account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","vendor_id":null,"currency":"BDT","amount":{"amount":"100.0000","currency":"BDT"},"tax_code_id":null,"ait":null,"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}]},"response":{"expense":{"id":"427709ca-dab1-43f0-b4d0-2b4616d50233","expense_date":"2026-07-15","settlement_type":"cash","amount":{"amount":"100.0000","currency":"BDT"},"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","status":"recorded","version":1}}}
```

### 4.12 `GET /v1/expenses/{id}`

**Purpose and access:** Return one recorded Expense with immutable valuation data. Authentication plus `payables.expenses.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Expense. Common read errors apply. No audit or outbox event unless configured read-audit policy applies.

```json
{"response":{"expense":{"id":"427709ca-dab1-43f0-b4d0-2b4616d50233","expense_date":"2026-07-15","description":"Configured operating expense","category_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","settlement_type":"cash","bank_account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","vendor_id":null,"amount":{"amount":"100.0000","currency":"BDT"},"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}],"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","status":"recorded","version":1}}}
```

### 4.13 `GET /v1/expenses`

**Purpose and access:** Search recorded Expenses. Authentication plus `payables.expenses.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `vendor`, `category_account_id`, `sbu_code`, `settlement_type`, `from`, `to`, `limit`, and `cursor`. IDs/codes are entity-scoped; dates are ordered ISO dates; unknown fields are rejected.

**Response and ordering:** `200` returns `expenses` and `page`. Stable ordering is `expense_date DESC, id DESC`. Common read errors apply.

```json
{"request_query":{"settlement_type":"cash","from":"2026-07-01","to":"2026-07-31","limit":50,"cursor":null},"response":{"expenses":[{"id":"427709ca-dab1-43f0-b4d0-2b4616d50233","expense_date":"2026-07-15","description":"Configured operating expense","category_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","settlement_type":"cash","amount":{"amount":"100.0000","currency":"BDT"},"status":"recorded","version":1}],"page":{"limit":50,"next_cursor":null}}}
```

## 5. Internal contracts

### 5.1 Recognition posting

Not HTTP. Receivables and Payables send entity, source Document ID, accounting date, functional-currency balanced lines, immutable tax/rate references, SBU dimensions where applicable, actor, correlation, and causation to the Ledger-owned PostingService. Invoice/Bill/Expense and JournalEntry commit in the approved recognition Unit of Work. No context reads or writes another context's tables.

### 5.2 Tax and FX determination

Not HTTP. M2 consumes the frozen M1 applicable-tax and applicable-rate contracts. Tax input is entity, TaxCode/default policy, jurisdiction, tax-point date, direction, and pricing mode when configured; output is immutable TaxSnapshot. FX input is entity, currency pair, accounting date, and configured purpose; output is exact RateRecord reference. Missing or ambiguous configuration fails safely. No inverse rate, cross rate, tax treatment, legal rule, or default is inferred.

### 5.3 Number allocation

Not HTTP. Drafts receive non-statutory provisional tokens. Issue/approval invokes the Numbering shared kernel's atomic scoped draw. Series, prefix, format, reset, and fiscal policy are configuration. Failure follows ADR-009's used-and-voided audit behavior; a number is never reused.

## 6. Configurable policy boundaries

This contract defines no tax rate, legal treatment, numbering format, approval threshold, SBU allocation policy beyond the frozen exact-sum invariant, payment-term value, revenue/AP/bank mapping, FX source, rounding mode, PDF layout, or PDF filename. Required missing or ambiguous configuration fails safely with a stable `422` rule and no partial business mutation.

Expense attachment support is deferred. M2 defines no attachment endpoint or request field. The optional receipt attachment requires a separately approved shared upload, storage, authorization, malware-scanning, encryption, retention, and retrieval contract. This deferral does not block Expense creation.

## 7. Governance decisions resolved

No unresolved governance decision remains in this proposal. Draft editing, public soft deactivation, attachment deferral, and exact master tax-identifier uniqueness normalization are defined above. Approval of this proposal would freeze those decisions for M2 implementation without introducing delete, reactivate, attachment, Settlement, correction, close, or reporting behavior.
