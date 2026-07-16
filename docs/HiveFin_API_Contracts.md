# HiveFin — API Contracts (Public / Frontend-Facing)

**Frozen inputs (immutable):** SRS v3.0 · ADR register (`HiveFin_Decision_Log.md`, ADR-001…ADR-009) · AP-001 · Domain Model · Context Interaction Matrix · Context Map · Aggregate Design · Domain Events · Repository Contracts · Database Design · Implementation Roadmap.
**Scope:** the **public API** consumed by the frontend. **Internal cross-context application services** (`ApplySettlement`, `PostingService`, migration import, reconciliation entry requests) are **in-process, not HTTP** — they are not exposed here (AP-001; they remain internal contracts from the Aggregate/Repository docs).
**Rule:** API conforms to the domain. **No domain weakness discovered** during API design (maker-checker, SoD, and period-lock all expressed cleanly as protocol semantics — §3).

---

## 1. Conventions

- **Style:** resource + command endpoints. Commands are `POST /…/{action}`; reads are `GET`.
- **Identity in payloads:** always the immutable **DocumentId (UUID)**; the business **DocumentNumber** is returned for display, never used as a key.
- **Entity scope:** every request carries the active **entity** (header `X-Entity-Id`); all data is entity-isolated.
- **Actor & authz:** every request is authenticated; the server resolves the actor and enforces RBAC+ABAC. No capability is assumed.
- **Optimistic concurrency:** mutating a versioned aggregate requires `If-Match: <version>`; mismatch → `409`.
- **Idempotency:** financial commands require an `Idempotency-Key` header; replays return the original result (prevents double-posting).
- **Money:** `{ amount, currency }`; amounts are exact decimals per the rounding policy.
- **Versioned API:** `/v1/…`; event/webhook exposure is out of MVP scope.

---

## 2. Error Taxonomy (uniform)

| Code | Meaning | Example |
|---|---|---|
| `400` validation | malformed request | missing required field |
| `403` authorization | capability denied | staff posting a journal |
| `403` sod_exception_required | duty conflict; needs justification | same user maker+checker |
| `409` concurrency_conflict | version mismatch | two receipts on one invoice |
| `422` invariant_violation | domain rule broken | unbalanced journal; over-allocation; SBU Σ≠1.0; void-window failed |
| `423` period_locked | date in soft/hard-closed period | posting into a hard-closed month |
| `404` not_found | unknown id | — |
| `202` pending_approval | maker-checker awaiting approver | high-value journal |

Body: `{ error_code, message, details, doc_id?, required_version? }`. **No stack traces; no cross-context leakage.**

---

## 3. High-Risk Protocol Semantics (expressed cleanly — no domain change)

- **Maker-Checker:** a high-risk command (journal above policy, reversal, note, hard close, tax/CoA/role change) returns **`202 pending_approval`** with an `approval_id`; a separate `POST /approvals/{id}/approve` (by a different, authorized actor) commits it. Four-eyes for hard close/reopen/tax config.
- **SoD Exception:** if the actor would breach SoD, the command returns **`403 sod_exception_required`**; the client re-submits with `X-SoD-Justification`; the server records an **SoD Exception** (audited, queued for review) and proceeds — never a silent block.
- **No-over-allocation:** `RecordReceipt/Payment` internally calls `ApplySettlement(amount, version)`; on document-version change it retries; if truly exceeded → `422 invariant_violation (over_allocation)`.
- **Void window:** `POST /invoices/{id}/void` returns `422` if any of the 4 conditions fails, directing the client to issue a credit note.
- **Period lock:** any post/settlement dated in a locked period → `423 period_locked`.

---

## 4. Endpoints by Context (commands ⚡ = high-risk / maker-checker eligible)

### Identity & Access
```
POST /v1/users/invite            POST /v1/users/{id}/deactivate
POST /v1/users/{id}/roles        POST /v1/roles  (custom ≤ granter)
POST /v1/entities/{id}/approval-policy
GET  /v1/users   GET /v1/roles   GET /v1/entities
POST /v1/auth/login (MFA for Owner/Finance Manager)   POST /v1/auth/mfa
```

### Period & Close
```
POST /v1/periods/{ref}/soft-close      POST /v1/periods/{ref}/hard-close ⚡(four-eyes)
POST /v1/periods/{ref}/reopen ⚡(approval+notify)   POST /v1/periods/{ref}/lock-vat
POST /v1/fiscal-year/{yr}/roll ⚡
GET  /v1/periods/{ref}   GET /v1/periods/postable?date=…
```

### Ledger
```
POST /v1/journals (draft)   POST /v1/journals/{id}/post ⚡   POST /v1/journals/{id}/reverse ⚡
POST /v1/accounts   PATCH /v1/accounts/{id}   POST /v1/accounts/{id}/deactivate
GET  /v1/accounts   GET /v1/accounts/{id}/balance?asOf=…   GET /v1/journals?account=&period=&status=
```

### Tax
```
POST /v1/tax/codes   POST /v1/tax/codes/{id}/versions ⚡   POST /v1/tax/packs
GET  /v1/tax/codes   GET /v1/tax/codes/{id}
```

### Currency & FX
```
POST /v1/fx/rates   POST /v1/fx/revaluation ⚡(Soft Close)
GET  /v1/fx/rates?pair=&date=   GET /v1/fx/revaluation?period=
```

### Receivables
```
POST /v1/invoices (draft)   POST /v1/invoices/{id}/issue   POST /v1/invoices/{id}/void
POST /v1/credit-notes   POST /v1/credit-notes/{id}/disposition
GET  /v1/invoices/{id}   GET /v1/invoices?customer=&status=&overdue=
GET  /v1/invoices/{id}/pdf   POST /v1/customers   PATCH /v1/customers/{id}
GET  /v1/customers/{id}/statement
```

### Payables
```
POST /v1/bills (draft)   POST /v1/bills/{id}/approve   POST /v1/bills/{id}/void
POST /v1/debit-notes   POST /v1/expenses
GET  /v1/bills/{id}   GET /v1/bills?vendor=&status=   POST /v1/vendors   PATCH /v1/vendors/{id}
```

### Settlement
```
POST /v1/receipts   (Idempotency-Key; allocates to ≥1 invoice; withholding; rate)
POST /v1/payments   (allocates to ≥1 bill)
POST /v1/credits/{party}/apply   POST /v1/credits/{party}/refund
POST /v1/allocations/{id}/reverse ⚡
GET  /v1/allocations?document=&party=   GET /v1/credits/{party}
```

### Reconciliation
```
POST /v1/reconciliations/import (CSV)   POST /v1/reconciliations/{id}/match
POST /v1/reconciliations/{id}/complete
GET  /v1/reconciliations/{id}   GET /v1/reconciliations/{id}/unmatched
```

### Migration
```
POST /v1/migration/stage   POST /v1/migration/{batch}/dry-run   POST /v1/migration/{batch}/reset
POST /v1/migration/{batch}/execute ⚡(four-eyes)
GET  /v1/migration/{batch}/validation   GET /v1/migration/{batch}/report
```

### Reporting (queries; `basis=accrual|cash` where allowed)
```
GET /v1/reports/trial-balance?asOf=            GET /v1/reports/general-ledger?account=&range=
GET /v1/reports/profit-loss?period=&sbu=&basis=  GET /v1/reports/balance-sheet?asOf=
GET /v1/reports/ar-ageing   GET /v1/reports/ap-ageing
GET /v1/reports/tax-summary?period=  (accrual only)   GET /v1/reports/cash-view?period=
GET /v1/reports/fx-revaluation?period=
```

---

## 5. Representative Request/Response

**Record a foreign receipt net of AIT**
```
POST /v1/receipts
X-Entity-Id: <uuid>   Idempotency-Key: <uuid>
{
  "receipt_date": "2026-07-10",
  "amount": { "amount": 9000, "currency": "USD" },
  "rate_record_id": "<uuid>",            // invoice-date/settlement rate
  "bank_account_id": "<uuid>",
  "allocations": [ { "invoice_id": "<uuid>", "applied": { "amount": 10000, "currency": "USD" },
                     "expected_version": 7 } ],
  "withholding": [ { "type": "AIT", "amount": { "amount": 1000, "currency": "USD" } } ]
}
→ 201 { "allocation_id":"<uuid>", "realised_fx": {…}, "invoice_status":"Paid",
        "invoice_open_balance": {"amount":0,"currency":"USD"} }
→ 409 concurrency_conflict { required_version: 8 }   // another receipt landed first
→ 422 invariant_violation { error:"over_allocation" }
→ 423 period_locked
```

**Issue an invoice**
```
POST /v1/invoices/{id}/issue   If-Match: 3
→ 201 { "document_number":"NH-3930", "status":"Sent", "journal_entry_id":"<uuid>" }
→ 423 period_locked
```

---

## 6. Validation

- **Public API only; internal services not exposed** (AP-001) — the frontend never calls another context's internals; the Partnership `ApplySettlement` stays in-process behind `/receipts`/`/payments`.
- **Domain fully expressible:** maker-checker (`202`+approve), SoD (`403`+justification), no-over-allocation (`If-Match`/`422`), period lock (`423`), reproducibility (rate/tax refs in payloads) — **no domain rule bent to fit the API.**
- **Concurrency & idempotency** first-class (`If-Match`, `Idempotency-Key`) — protects the financial invariants.
- **Entity isolation & authz** on every endpoint.
- **No business rule introduced or modified; no contradiction; no AP-001 violation; no weakness discovered.**

**API Contracts complete.**

---

## 7. Approved Amendment — M0 Manual-Journal Walking Skeleton

**Approved:** 15 July 2026.
**Governance record:** `PROPOSED_API_CONTRACT_AMENDMENT_M0.md` records the evidence comparison, rationale, conditional-approval corrections, and traceability.
**Scope:** This amendment freezes the previously unspecified public shapes for `GET /v1/accounts`, `POST /v1/journals`, `POST /v1/journals/{id}/post`, and `GET /v1/reports/general-ledger`. It changes no aggregate, invariant, bounded-context ownership, or accounting rule.

### 7.1 Common protocol

All four endpoints require `Authorization: Bearer <token>` and UUID header `X-Entity-Id`. Missing/invalid authentication returns `401 authentication_required`; inaccessible or invalid entity scope and denied capabilities return `403 authorization` without cross-entity disclosure.

Clients may send UUID header `X-Correlation-Id`; the server generates one when absent and echoes it on every response. It propagates through structured logs, audit/outbox metadata, dispatch, and consumer logs. Malformed values return `400 validation`.

Money is `{ "amount":"1250.0000", "currency":"BDT" }`: `amount` is an exact decimal string with at most four fractional digits; JSON floating-point amounts are rejected; `currency` is an uppercase ISO-style three-letter code. M0 manual-journal lines use the active entity's functional currency only.

Collection endpoints use opaque cursor pagination: optional `limit` defaults to 50 and is constrained to 1–100; optional `cursor` is the preceding response's opaque token. Responses include `{ "page": { "limit":50, "next_cursor":null } }`. Invalid pagination returns `400 validation`.

Errors use `{ "error_code", "message", "details", "doc_id"?, "required_version"? }`. Stable outcomes are `400 validation`, `401 authentication_required`, `403 authorization`, `403 sod_exception_required`, `404 not_found`, `409 concurrency_conflict`, `409 idempotency_conflict`, `422 invariant_violation`, and `423 period_locked`. No internal detail or stack trace is exposed.

### 7.2 `GET /v1/accounts`

Capability: `ledger.accounts.read`. Query: `status=active|deactivated` (optional; defaults to `active`), `cursor` (optional), `limit` (optional, 1–100).

`200`:

```json
{
  "accounts": [{
    "id":"<uuid>", "code":"1010", "name":"Cash", "type":"asset",
    "normal_balance":"debit", "status":"active", "version":1
  }],
  "page":{"limit":50,"next_cursor":null}
}
```

Balances and redundant `entity_id` are not returned. Errors: `400`, `401`, `403` using §7.1.

### 7.3 `POST /v1/journals`

Creates one editable manual JournalEntry Draft with its lines; it does not post. Capability: `ledger.journals.create`. `Idempotency-Key: <uuid>` is required.

```json
{
  "entry_date":"2026-07-15",
  "entry_type":"manual",
  "narration":"Transfer operating cash",
  "reference":"BANK-TRANSFER-001",
  "lines":[
    {"account_id":"<uuid>","description":"Debit cash","debit":{"amount":"1000.0000","currency":"BDT"},"credit":null},
    {"account_id":"<uuid>","description":"Credit clearing","debit":null,"credit":{"amount":"1000.0000","currency":"BDT"}}
  ]
}
```

Validation: reject unknown fields; `entry_date` is an ISO date belonging to a valid fiscal period but draft creation does **not** require the period to be currently postable; `entry_type`, if present, is `manual`; narration max 2,000; reference max 255; at least two lines; each account UUID identifies an active account in the entity; descriptions max 2,000; exactly one positive Money side per line; all currencies equal functional currency; exact debits equal exact credits.

`201`:

```json
{
  "journal": {
    "id":"<uuid>", "period_ref":"2026-07", "entry_type":"manual",
    "entry_date":"2026-07-15", "state":"Draft", "narration":"Transfer operating cash",
    "reference":"BANK-TRANSFER-001", "reversal_of_entry_id":null,
    "posted_at":null, "posted_by":null, "version":1,
    "lines":[{
      "id":4101, "line_no":1, "account_id":"<uuid>", "description":"Debit cash",
      "debit":{"amount":"1000.0000","currency":"BDT"}, "credit":null
    }]
  }
}
```

Idempotency is scoped to actor + entity + endpoint. Key, canonical request hash, status, and response commit atomically with creation. Identical replay returns the original status/response and `Idempotent-Replay: true`; changed payload returns `409 idempotency_conflict`. Errors: `400`, `401`, `403`, `409 idempotency_conflict`, `422`. Draft creation does not return `423` merely because its valid fiscal period is not currently postable.

### 7.4 `POST /v1/journals/{id}/post`

Transitions a balanced Draft to immutable Posted state. Capability: `ledger.journals.post`. Required headers: `Idempotency-Key: <uuid>` and `If-Match: <current-integer-version>`. Body is empty. Maker-checker and SoD follow the entity Approval Policy; no threshold is hardcoded.

`200` returns the §7.3 journal representation with `state:"Posted"`, posting attribution/time, and incremented version.

`202` pending approval is a successful command outcome, not an error envelope:

```json
{
  "status":"PendingApproval",
  "approval_id":"<uuid>",
  "journal_id":"<uuid>",
  "journal_version":1,
  "submitted_at":"2026-07-15T08:40:10.000Z"
}
```

No posting, `JournalPosted` event, or posted-state audit record exists until approval commits the command.

Idempotency scope is actor + entity + endpoint + journal ID. The canonical identity includes journal ID, `If-Match`, and applicable SoD-justification hash. Identical retries return the original status/response with `Idempotent-Replay: true`; conflicting reuse returns `409 idempotency_conflict`. Version mismatch returns `409 concurrency_conflict` with `required_version` and no writes. Reposting with a new key returns `422`. Posting may return `423 period_locked`.

Other errors: `400`, `401`, `403 authorization`, `403 sod_exception_required`, `404`, `409`, and `422` using §7.1.

### 7.5 `GET /v1/reports/general-ledger`

Capability: `ledger.reports.read`. Required query parameters: entity-owned account UUID `account`; inclusive range `range=YYYY-MM-DD..YYYY-MM-DD` with start ≤ end. Optional `cursor` and `limit` follow §7.1. Basis is fixed to `accrual`.

`200`:

```json
{
  "account":{"id":"<uuid>","code":"1010","name":"Cash","normal_balance":"debit"},
  "basis":"accrual",
  "range":{"from":"2026-07-01","to":"2026-07-31"},
  "opening_balance":{"amount":"0.0000","currency":"BDT"},
  "entries":[{
    "journal_entry_id":"<uuid>", "line_id":4101, "entry_date":"2026-07-15",
    "reference":"BANK-TRANSFER-001", "description":"Debit cash",
    "debit":{"amount":"1000.0000","currency":"BDT"}, "credit":null,
    "running_balance":{"amount":"1000.0000","currency":"BDT"}
  }],
  "closing_balance":{"amount":"1000.0000","currency":"BDT"},
  "page":{"limit":50,"next_cursor":null}
}
```

Only Posted entries are included. Opening balance is posted activity strictly before `range.from`; closing balance is posted activity through `range.to`, independent of page size. Stable order is accounting date, journal-entry stable key, then line number/stable line key.

Running balances continue across cursor pages: the first item of every subsequent page includes the opening balance and all earlier activity in the requested range, never a page-local restart. The read model evaluates the opaque cursor against the same immutable query boundary and deterministic sort tuple as the preceding page, deriving the page starting balance from the range opening balance plus activity preceding the cursor. Cursor internals are never exposed.

Errors: `400`, `401`, `403`, and entity-scoped `404 not_found`.

### 7.6 Traceability

| Amendment rule | Frozen source |
|---|---|
| Balanced, exact functional-currency manual journal | SRS §5.2; DOM-02/05; Aggregate Design JournalEntry |
| Draft valid-period membership; posting postability guard | ADR-004; Period OHS contract; API `423` semantics |
| Posted immutability | ADR-002; DOM-04; DB-03 |
| Version check and idempotency | API Contracts §1; DOM-09; API-03; CODE-09 |
| Entity/authz/SoD | ADR-005; SEC-01/02; API Contracts §§1–3 |
| Account list excludes balances | Aggregate Design LedgerAccount; Repository Contracts Reporting Queries |
| GL as accrual read model with deterministic balance | ADR-001; REPO-05; GeneralLedgerQuery |
| Correlation propagation | LOG-01/02; TASK-M0 T-07/API-T-05 |
| Stable errors | API-04/05; ERR-01/04 |

---

## 8. Approved Amendment — M1 Ledger + Valuation

**Approved:** 16 July 2026.
**Approved artifact:** `PROPOSED_API_CONTRACT_AMENDMENT_M1.md`.
**Approved SHA-256:** `5952d79cca49dcbdef0ee684bf579ce28856730bc474ebe899cbba9ec43260bf`.
**Scope:** The following text freezes the public M1 Ledger, Tax, and Currency & FX contract. Applicable tax/rate lookup and realised FX calculation remain internal and are not public HTTP endpoints.

### 8.1 Common Protocol

#### 8.1.1 Access and headers

All endpoints require TLS, authentication, and `X-Entity-Id`. Authorization is default-deny and entity-scoped. Cross-entity resources return `404 not_found`.

`X-Correlation-Id` is optional. The server replaces an absent or invalid value with a UUID, echoes the effective value, and propagates it to logs, audit, outbox metadata, and internal calls.

Every state-changing endpoint requires a UUID `Idempotency-Key`. The same key and canonical request return the original result without repeating state, audit, or events. Reuse with different input returns `409 idempotency_conflict`. Replays return `Idempotency-Replayed: true`.

`If-Match` is required only where stated. Missing and stale versions return `428 precondition_required` and `409 concurrency_conflict`. `X-SoD-Justification` is accepted only for an authorized compensating-control flow.

#### 8.1.2 Formats and errors

UUIDs are canonical strings; dates are `YYYY-MM-DD`; timestamps are UTC RFC 3339; decimals are JSON strings. Money is:

```json
{"amount":"1250.0000","currency":"BDT"}
```

Unknown request fields return `400 validation`.

```json
{"error_code":"validation","message":"The request is invalid.","details":{"field":["A stable explanation."]}}
```

Errors: `400 validation`, `401 unauthenticated`, `403 authorization`, `403 sod_exception_required`, `404 not_found`, `409 concurrency_conflict`, `409 idempotency_conflict`, `409 duplicate_resource`, `422 invariant_violation`, `423 period_locked`, and `428 precondition_required`. Errors expose no stack traces or internal details.

#### 8.1.3 Approval and pagination

Configured maker-checker commands may return `202` as a successful outcome:

```json
{"approval":{"id":"3530ca0e-4201-4ab1-8521-20f851defd44","status":"pending","command":"reverse_journal","resource_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa"}}
```

Cursor lists use opaque `cursor`; `limit` defaults to 50 and ranges from 1 to 100. Cursors bind entity, filters, ordering, and read boundary.

```json
{"limit":50,"next_cursor":null}
```

### 8.2 Shared Schemas

#### 8.2.1 LedgerAccount

```json
{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","code":"1060","name":"Accounts Receivable","description":"Trade receivables","type":"asset","normal_balance":"debit","status":"active","version":1,"created_at":"2026-07-15T10:30:00Z","updated_at":"2026-07-15T10:30:00Z"}
```

Types are `asset`, `liability`, `equity`, `revenue`, and `expense`. Status is `active` or `deactivated`.

#### 8.2.2 TaxCode and TaxSnapshot

```json
{"tax_code":{"id":"fb861bea-a516-4546-b92e-2a96a19a3379","code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD","status":"active","version":3,"versions":[]},"tax_snapshot":{"tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379","tax_code_version_id":"5b597b92-5ba8-45a5-acce-166172b32a51","code":"BD-EXAMPLE","jurisdiction":"BD","treatment":"zero_rated","rate":"0.00000000","recoverable":true,"calculation_method":"CONFIGURED_METHOD","gl_mapping":{"output_account_id":"098d6884-8199-483d-940c-f90d919d15e3","input_account_id":null},"return_box_mapping":{"configured_box_key":"CONFIGURED_VALUE"},"effective_from":"2026-07-01","effective_to":null}}
```

Treatments are `standard`, `zero_rated`, and `exempt`. A persisted TaxSnapshot is immutable.

#### 8.2.3 RateRecord and reference

```json
{"rate_record":{"id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15","source":"CONFIGURED_SOURCE","is_override":false,"override_reason":null,"referenced":false},"exchange_rate_reference":{"rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15"}}
```

Example rates are structural test data, not approved rates.

### 8.3 Ledger Endpoints

#### 8.3.1 `POST /v1/accounts`

Authorization: `ledger.accounts.manage`. Idempotency required; no `If-Match`; no pagination.

Request requires unique `code` (1–32), `name` (1–255), optional nullable `description` (max 2000), and account `type`. Entity, normal balance, status, balance, and version are rejected.

```json
{"request":{"code":"1060","name":"Accounts Receivable","description":"Trade receivables","type":"asset"},"response":{"account":{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","code":"1060","name":"Accounts Receivable","description":"Trade receivables","type":"asset","normal_balance":"debit","status":"active","version":1}}}
```

Returns `201`; errors include `409 duplicate_resource`. Creation audit and `AccountCreated` commit atomically.

#### 8.3.2 `PATCH /v1/accounts/{id}`

Authorization: `ledger.accounts.manage`. Idempotency and `If-Match` required; no pagination.

Request contains at least one of `name`, nullable `description`, or `type`; creation field limits apply. Code is immutable. Type cannot change after posting history exists.

```json
{"request":{"name":"Trade Accounts Receivable","description":"Entity trade receivables"},"response":{"account":{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","code":"1060","name":"Trade Accounts Receivable","description":"Entity trade receivables","type":"asset","normal_balance":"debit","status":"active","version":2}}}
```

Returns `200`; prohibited type change returns `422` rule `account_type_immutable_once_posted`. The conditional write increments version. Audit stores before and after; no outbox event.

#### 8.3.3 `POST /v1/accounts/{id}/deactivate`

Authorization: `ledger.accounts.manage`. Idempotency and `If-Match` required; no pagination.

The account must be active. A new command on a deactivated account returns `422` rule `account_already_deactivated`; exact replay returns the original result.

```json
{"request":{},"response":{"account":{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","status":"deactivated","version":2}}}
```

Returns `200`. Conditional deactivation, audit, and `AccountDeactivated` commit atomically.

#### 8.3.4 `GET /v1/accounts/{id}/balance`

Authorization: `ledger.reports.read`. Optional `asOf` ISO date defaults to current entity accounting date. No idempotency, concurrency header, or pagination.

Only posted entries dated on or before `asOf` are included; drafts are excluded.

```json
{"request_query":{"asOf":"2026-07-15"},"response":{"account":{"id":"4d938a29-72bf-4284-9c42-b509df7799bc","code":"1060","name":"Accounts Receivable","normal_balance":"debit"},"as_of":"2026-07-15","balance":{"amount":"1250.0000","currency":"BDT"}}}
```

Returns `200`; errors are `400`, `401`, `403`, and `404`. No event; read auditing follows configured policy.

#### 8.3.5 `GET /v1/journals`

Authorization: `ledger.journals.read`. Cursor pagination applies.

Filters: entity-scoped `account`, `period`, `status` (`draft`, `posted`, `reversed`), `entry_type` (`manual`, `system`, `adjusting`, `reversal`, `revaluation`, `conversion`), ISO `from`/`to`, and entity-scoped `source_document_id`. Dates must be ordered and agree with period. Ordering is `entry_date DESC, id DESC`.

```json
{"request_query":{"status":"posted","period":"2026-07","limit":50,"cursor":null},"response":{"journals":[{"id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","journal_number":"JRN-2026-0001","entry_date":"2026-07-15","entry_type":"manual","state":"posted","total_debit":{"amount":"100.0000","currency":"BDT"},"total_credit":{"amount":"100.0000","currency":"BDT"},"version":2}],"page":{"limit":50,"next_cursor":null}}}
```

Returns `200`; common read errors apply. No audit or outbox event.

#### 8.3.6 `POST /v1/journals/{id}/reverse`

Authorization: `ledger.journals.reverse`; configured approval and SoD may apply. Idempotency required; no `If-Match` or pagination.

Request requires postable ISO `entry_date` and nonblank `reason` (max 2000). Source must be posted, unreversed, and non-system. System entries reverse through their source. One effective reversal is permitted. Lines swap debit/credit and preserve foreign references.

```json
{"request":{"entry_date":"2026-07-16","reason":"Correction of posting classification"},"response":{"journal":{"id":"0967aad7-12eb-45c3-9afd-aacb91d6d0f3","entry_date":"2026-07-16","entry_type":"reversal","state":"posted","reversal_of_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","version":1}}}
```

Returns `201` or `202`. Errors include `journal_not_posted`, `journal_already_reversed`, `system_entry_source_reversal_required`, and `423 period_locked`. Audit, reversal, `JournalReversed`, and posting events commit atomically.

#### 8.3.7 `POST /v1/journals` foreign-currency extension

The approved M0 authorization and response remain in force. Idempotency required; no `If-Match` or pagination.

Functional debit/credit uses entity functional currency. A foreign line requires foreign Money and an exact applicable RateRecord reference; functional lines omit both. Draft creation cannot create or override rates. Functional totals must balance after configured rounding.

```json
{"request":{"entry_date":"2026-07-15","entry_type":"manual","lines":[{"account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","debit":{"amount":"100.0000","currency":"BDT"},"credit":null,"foreign_amount":{"amount":"1.0000","currency":"USD"},"exchange_rate_reference":{"rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15"}},{"account_id":"77daa856-059f-4a5f-a00f-5aba155d9379","debit":null,"credit":{"amount":"100.0000","currency":"BDT"},"foreign_amount":null,"exchange_rate_reference":null}]},"response":{"journal":{"id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","state":"draft","version":1}}}
```

Returns `201`. Additional `422` rules are `missing_rate_reference`, `rate_reference_mismatch`, `currency_pair_mismatch`, and `functional_balance_mismatch`. Audit/outbox follows the approved M0 contract.

### 8.4 Tax Endpoints

#### 8.4.1 `POST /v1/tax/codes`

Authorization: `tax.codes.manage`; four-eyes required. Idempotency required; no `If-Match` or pagination.

Request requires unique `code`, nonblank `name`, and configured `jurisdiction`. Rate and legal behavior fields are rejected.

```json
{"request":{"code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD"},"response":{"tax_code":{"id":"fb861bea-a516-4546-b92e-2a96a19a3379","code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD","status":"active","version":1,"versions":[]}}}
```

Returns `201` or `202`; `409 duplicate_resource` applies. Definition and approval are audited; no public event.

#### 8.4.2 `GET /v1/tax/codes`

Authorization: `tax.codes.read`. Cursor pagination applies.

Optional filters: configured `jurisdiction`, `status` (`active`, `inactive`), and ISO `effective_on`. Ordering is `jurisdiction, code, id`. Applicable version ID appears only when `effective_on` is supplied.

```json
{"request_query":{"jurisdiction":"BD","status":"active","effective_on":"2026-07-15","limit":50,"cursor":null},"response":{"tax_codes":[{"id":"fb861bea-a516-4546-b92e-2a96a19a3379","code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD","status":"active","version":3,"applicable_version_id":"5b597b92-5ba8-45a5-acce-166172b32a51"}],"page":{"limit":50,"next_cursor":null}}}
```

Returns `200`; common read errors apply. No audit or outbox event.

#### 8.4.3 `GET /v1/tax/codes/{id}`

Authorization: `tax.codes.read`. No idempotency, `If-Match`, or pagination. ID is an entity-scoped UUID. Versions order by effective date and version number.

```json
{"request_path":{"id":"fb861bea-a516-4546-b92e-2a96a19a3379"},"response":{"tax_code":{"id":"fb861bea-a516-4546-b92e-2a96a19a3379","code":"BD-EXAMPLE","name":"Configured tax code","jurisdiction":"BD","status":"active","version":3,"versions":[{"id":"5b597b92-5ba8-45a5-acce-166172b32a51","version_number":2,"treatment":"zero_rated","rate":"0.00000000","recoverable":true,"calculation_method":"CONFIGURED_METHOD","effective_from":"2026-07-01","effective_to":null,"referenced":false}]}}}
```

Returns `200`; errors are `400`, `401`, `403`, and `404`. No audit or outbox event.

#### 8.4.4 `POST /v1/tax/codes/{id}/versions`

Authorization: `tax.codes.manage`; four-eyes required. Idempotency and `If-Match` required; no pagination.

Request requires treatment, nonnegative exact rate, recoverability, configured calculation method/mappings, and ordered non-overlapping effective dates. Accounts must belong to entity. Referenced versions cannot change or be deleted.

```json
{"request":{"treatment":"zero_rated","rate":"0.00000000","recoverable":true,"calculation_method":"CONFIGURED_METHOD","gl_mapping":{"output_account_id":"098d6884-8199-483d-940c-f90d919d15e3","input_account_id":null},"return_box_mapping":{"configured_box_key":"CONFIGURED_VALUE"},"effective_from":"2026-07-01","effective_to":null},"response":{"tax_code_version":{"id":"5b597b92-5ba8-45a5-acce-166172b32a51","version_number":2,"treatment":"zero_rated","rate":"0.00000000","recoverable":true,"effective_from":"2026-07-01","effective_to":null},"resource_version":4}}
```

Returns `201` or `202`. Errors include `effective_dates_overlap`, `invalid_tax_mapping`, and `tax_treatment_mismatch`. Conditional commit increments version. Audit diff and `TaxCodeVersioned` commit atomically.

#### 8.4.5 `POST /v1/tax/packs`

Authorization: `tax.packs.manage`; four-eyes required. Idempotency required. `If-Match` is omitted for creation and required for revision. No pagination.

One pack exists per entity/jurisdiction. Codes share entity/jurisdiction. Configuration must match an approved schema; the server supplies no legal defaults.

```json
{"request":{"jurisdiction":"BD","name":"Bangladesh Tax Pack","tax_code_ids":["fb861bea-a516-4546-b92e-2a96a19a3379"],"return_template":{"schema_key":"CONFIGURED_TEMPLATE"},"policy":{"advance_tax_point":"CONFIGURED_VALUE","evidence_rules":"CONFIGURED_VALUE"}},"response":{"tax_pack":{"id":"2f0b9bf4-a45c-4c0a-8704-fbd907125adb","jurisdiction":"BD","name":"Bangladesh Tax Pack","version":1}}}
```

Returns `201` for creation, `200` for revision, or `202`. Invalid configuration returns `422` rule `invalid_tax_pack_configuration`. Audit diff and `TaxPackConfigured` commit atomically.

### 8.5 Currency and FX Endpoints

#### 8.5.1 `POST /v1/fx/rates`

Authorization: `fx.rates.manage`; configured approval may apply. Idempotency required; no `If-Match` or pagination.

Request requires distinct supported currencies, positive exact rate, ISO date, and configured source. Overrides require a nonblank reason. Conflicts are not replaced; inverse/cross rates are not generated without policy.

```json
{"request":{"base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15","source":"CONFIGURED_SOURCE","is_override":false,"override_reason":null},"response":{"rate_record":{"id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15","source":"CONFIGURED_SOURCE","is_override":false,"override_reason":null,"referenced":false}}}
```

Example rate is test data, not approved policy. Returns `201` or `202`. Errors include `invalid_currency_pair`, `override_reason_required`, and `409 duplicate_resource`. Audit and `RateRecordAdded` commit atomically.

#### 8.5.2 `GET /v1/fx/rates`

Authorization: `fx.rates.read`. Cursor pagination applies.

Optional base/quote currencies appear together. Optional filters are ordered ISO effective dates, configured source, and boolean referenced status. Ordering is `effective_date DESC, id DESC`.

```json
{"request_query":{"base_currency":"USD","quote_currency":"BDT","effective_from":"2026-07-01","effective_to":"2026-07-31","limit":50,"cursor":null},"response":{"rate_records":[{"id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15","source":"CONFIGURED_SOURCE","referenced":false}],"page":{"limit":50,"next_cursor":null}}}
```

Returns `200`; common read errors apply. No audit or outbox event.

#### 8.5.3 `POST /v1/fx/revaluation`

Authorization: `fx.revaluation.run`; configured approval and SoD may apply. Idempotency required; no `If-Match` or pagination. Entity/period uniqueness prevents duplicates.

Request accepts only `period_ref`. Period must be Soft Close. Period-end selection policy and every required rate must exist. Client-supplied rates, figures, accounts, or items are rejected. Generated journals balance; failure is atomic.

```json
{"request":{"period_ref":"2026-07"},"response":{"revaluation_run":{"id":"36be830b-b86e-408a-a2ff-b354a5f9196b","period_ref":"2026-07","status":"posted","rate_record_ids":["6871052e-8b8c-44fb-b356-0582d48a305e"],"journal_entry_ids":["df654e88-f1cb-41f2-996c-1ee8826bf6aa"],"version":1,"posted_at":"2026-07-31T18:00:00Z"}}}
```

Returns `201` or `202`. Errors include `423 period_locked`, `missing_period_end_rate`, `revaluation_already_exists`, and `unbalanced_revaluation`. Run, journals, audit, `UnrealisedFXRevalued`, and posting events commit atomically.

#### 8.5.4 `GET /v1/fx/revaluation`

Authorization: `fx.revaluation.read`. Required filter is entity PeriodRef; optional status is `pending_approval`, `posted`, or `reversed`. No pagination because entity/period bounds the result.

```json
{"request_query":{"period":"2026-07","status":"posted"},"response":{"revaluation_runs":[{"id":"36be830b-b86e-408a-a2ff-b354a5f9196b","period_ref":"2026-07","status":"posted","figures":[{"account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","amount":{"amount":"25.0000","currency":"BDT"}}],"journal_entry_ids":["df654e88-f1cb-41f2-996c-1ee8826bf6aa"],"reversal":{"status":"scheduled","target_period_ref":"2026-08","reversal_run_id":null,"journal_entry_ids":[],"reversed_at":null},"version":1}]}}
```

Returns `200`; common read errors apply. Query emits no event. Reversal is internal, idempotent, uses original figures, and atomically records links, audit, `RevaluationReversed`, and posting events.

### 8.6 Internal Contracts

#### 8.6.1 Applicable tax lookup

Not HTTP. Inputs are entity, TaxCode ID, jurisdiction, tax-point date, and configured direction/pricing when required. Output is the immutable TaxSnapshot. Exactly one active version in the active TaxPack must cover the date. No legal treatment is inferred. Lookup emits no event; the consuming command persists the snapshot and records `TaxDetermined` when required. Correlation propagates.

#### 8.6.2 Applicable FX rate lookup

Not HTTP. Inputs are entity, currency pair, effective date, and configured purpose when required. Output is RateRecord plus immutable reference. Exactly one authoritative record must resolve under configured source policy. Missing or ambiguous configuration fails without invented source, inverse, or cross rate. Lookup emits no event. Correlation propagates.

#### 8.6.3 Realised FX calculation

Not HTTP. Inputs are applied transaction tranche, functional currency, and exact document and settlement rate references. Output is document functional Money, settlement functional Money, signed realised-FX Money, and classification. Arithmetic is exact and per tranche. References match immutable records. The calculation itself creates no audit or event.

### 8.7 Configurable Policy Boundaries

No defaults are defined for approval thresholds, tax rates or legal rules, TaxPack policy, FX sources or precedence, period-end rate selection, inverse/cross-rate policy, precision, rounding, revaluation reruns, or sequence formatting.

Missing required configuration returns a documented validation or invariant error and never activates a hardcoded fallback.

---

## 9. Approved Amendment — Durable Approval Lifecycle

**Approved:** 16 July 2026.
**Approved artifact:** `PROPOSED_API_CONTRACT_AMENDMENT_APPROVAL_LIFECYCLE.md`.
**Approved SHA-256:** `9edd79b9b181eaab8f99836ae5faf02bf09f307803d587645b837e94182de06f`.
**Scope:** This section freezes the durable pending-to-approved maker-checker contract required by ADR-005. No rejection or cancellation transition is authorized.

### 9.1 Scope

This amendment defines the durable approval request created when an originating command requires maker-checker review and the public command that approves it.

Only `pending` and `approved` are proposed because those are the only lifecycle outcomes currently expressed by the frozen artifacts. Rejection and cancellation are not defined and are excluded pending separate approval.

### 9.2 Common requirements

- TLS, authentication, and UUID `X-Entity-Id` are required.
- UUID `X-Correlation-Id` is optional. The server generates one when absent or invalid, echoes it, and propagates it to audit, outbox, logs, and replayed command causation metadata.
- UUID `Idempotency-Key` is required for approval.
- Integer `If-Match` is required for approval.
- Entity access and approval capability are default-deny.
- The approver must differ from the originating maker.
- Approval is scoped to the same entity as the originating command.
- Approval never accepts a replacement command payload. The durable canonical payload captured by the originating command is replayed.
- Error envelope:

```json
{
  "error_code": "concurrency_conflict",
  "message": "The approval version is stale.",
  "details": {},
  "required_version": 2
}
```

Stable errors are `400 validation`, `401 unauthenticated`, `403 authorization`, `403 maker_cannot_approve`, `404 not_found`, `409 concurrency_conflict`, `409 idempotency_conflict`, `409 approval_already_decided`, `422 originating_command_invalid`, and `428 precondition_required`.

### 9.3 Pending approval outcome from an originating command

When configured policy requires approval, the originating command returns `202` and makes no originating business-state change.

```json
{
  "approval": {
    "id": "3530ca0e-4201-4ab1-8521-20f851defd44",
    "status": "pending",
    "command": "tax_code_version_create",
    "resource_id": "fb861bea-a516-4546-b92e-2a96a19a3379",
    "maker_id": "b7447cf1-adf8-439b-bf4c-34c5752cfdd7",
    "entity_id": "2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d",
    "version": 1,
    "submitted_at": "2026-07-16T10:30:00Z"
  }
}
```

The approval request durably stores:

- Approval ID, status, and approval version.
- Command type and command schema version.
- Entity ID and maker ID.
- Canonical immutable command payload and original concurrency precondition.
- Originating idempotency key and request scope.
- Originating correlation ID and causation metadata.
- SHA-256 payload hash computed from the canonical payload bytes.
- UTC created timestamp.

#### 9.3.1 Secure originating-command storage

- The stored payload is the canonical immutable payload accepted by the originating application service. It cannot be replaced or edited after approval submission.
- Sensitive payload fields are encrypted at rest using the platform's approved encryption facility and managed encryption keys. This proposal does not define a key provider or rotation policy.
- Before replay, the application service decrypts the payload, canonicalizes it using the stored schema version, recomputes its SHA-256 hash, and performs constant-time comparison with the stored payload hash.
- An unsupported command type or schema version, decryption failure, or hash mismatch fails safely with `422 originating_command_invalid`. No originating business mutation or approval transition occurs.
- Only registered application services may read or decrypt the payload. Controllers, repositories exposed outside Identity, frontend clients, and event consumers have no payload access.
- The payload, sensitive fields, encryption material, and payload hash are never returned by an API or written to logs, audit details, or domain-event payloads/metadata.
- Audit and events identify the command by approval ID, command type, entity ID, resource ID when applicable, and schema version only.
- Retention is configurable by policy. Expiry or secure erasure must preserve non-sensitive approval/audit evidence while making an unreplayable pending request fail safely.
- Replay always uses the stored payload. Mutable client input is never accepted as a replacement.

Identical replay of the originating command returns the original `202` response without creating another approval request, audit record, or outbox event. Reuse of its idempotency key with different input returns `409 idempotency_conflict`.

Creation commits the pending approval, audit record, idempotency result, and `ApprovalRequested` outbox event atomically.

### 9.4 Approve command

#### 9.4.1 Method and path

`POST /v1/approvals/{id}/approve`

#### 9.4.2 Authorization and headers

- Capability: `identity.approvals.approve` plus any command-specific approval capability required by configured policy.
- Required headers: `X-Entity-Id`, `Idempotency-Key`, and `If-Match`.
- Optional header: `X-Correlation-Id`.
- The authenticated actor cannot be the maker.

#### 9.4.3 Request

The body is empty.

```json
{}
```

#### 9.4.4 Validation

- `{id}` is a UUID for a pending approval in the active entity.
- `If-Match` equals the current approval version.
- The approver has current entity access and every required capability.
- The approver differs from the maker.
- The approval is still `pending`.
- The captured originating command, payload, and handler version are supported.
- The originating command is replayed using the captured payload and original precondition; no client payload substitution is allowed.
- All originating command invariants are re-evaluated at approval time.
- If the originating resource changed after submission, approval fails without changing approval status.

#### 9.4.5 Success response

`200`:

```json
{
  "approval": {
    "id": "3530ca0e-4201-4ab1-8521-20f851defd44",
    "status": "approved",
    "command": "tax_code_version_create",
    "resource_id": "fb861bea-a516-4546-b92e-2a96a19a3379",
    "maker_id": "b7447cf1-adf8-439b-bf4c-34c5752cfdd7",
    "approver_id": "1b8f3c2f-4e62-4fa9-a924-77848017a9a6",
    "entity_id": "2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d",
    "version": 2,
    "submitted_at": "2026-07-16T10:30:00Z",
    "approved_at": "2026-07-16T11:00:00Z"
  },
  "command_result": {
    "status": 201,
    "body": {
      "tax_code_version": {
        "id": "5b597b92-5ba8-45a5-acce-166172b32a51",
        "version_number": 2
      }
    }
  }
}
```

`command_result.status` and `command_result.body` are the originating command's approved success response. They are stored durably for safe idempotent replay.

#### 9.4.6 Errors

- Missing `If-Match`: `428 precondition_required`.
- Stale approval version: `409 concurrency_conflict` with `required_version`.
- Maker attempts approval: `403 maker_cannot_approve`.
- Missing entity/capability: `403 authorization`.
- Unknown or cross-entity approval: `404 not_found`.
- Approval is no longer pending: `409 approval_already_decided`.
- Approval idempotency key reused with different input: `409 idempotency_conflict`.
- Captured originating command is no longer valid: `422 originating_command_invalid`; approval remains pending and no originating business change occurs.

For any failure while verifying or executing the originating command:

- Approval remains `pending` at its current version.
- No originating business mutation commits.
- No `ApprovalGranted` event is written.
- An immutable failed-attempt audit record is written with approval ID, actor ID, entity ID, safe failure code, correlation ID, and UTC timestamp. It contains no stored payload or sensitive values.
- A retry is allowed using a new approval idempotency key and the current `If-Match`, or by replaying the exact failed request under the existing idempotency rules. A failed idempotency result never permits two successful executions.

#### 9.4.7 Idempotency and concurrency

- Approval idempotency scope is actor, entity, endpoint, and approval ID.
- Identical replay returns the original `200` response and `Idempotency-Replayed: true`.
- Approval uses an optimistic conditional transition from `pending` at the supplied version to `approved` at the next version.
- Concurrent or duplicate approval produces at most one originating command execution.

#### 9.4.8 Transaction, audit, and outbox

The following commit atomically:

- Originating command business changes.
- Approval transition and approver attribution.
- Approval and originating-command audit records.
- Approval idempotency result and originating command result.
- Originating command outbox events.
- `ApprovalGranted` outbox event.

`ApprovalGranted` carries approval ID, entity ID, maker ID, approver ID, command name, resource ID, approval version, and UTC approval time. Metadata carries correlation ID and the `ApprovalRequested` event ID as causation ID.

### 9.5 Proposed event schemas

These events are proposals and do not become frozen until this amendment is approved through governance.

#### 9.5.1 ApprovalRequested

- **Version:** `1`.
- **Owning context:** Identity & Access.
- **Trigger:** A configured maker-checker policy accepts an originating command for durable approval and commits its pending request.
- **Idempotency:** One event per approval ID. Replay of the originating idempotency key returns the existing request and emits no additional event.
- **Causation:** The originating command request ID when available; otherwise the originating idempotency key's durable record ID.
- **Correlation:** The effective originating `X-Correlation-Id`.

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

The event contains no command payload, payload hash, idempotency key, sensitive field, or encryption detail.

#### 9.5.2 ApprovalGranted

- **Version:** `1`.
- **Owning context:** Identity & Access.
- **Trigger:** The approval transition and originating command execute successfully in the approved atomic transaction.
- **Idempotency:** One event per approval ID. Duplicate or concurrent approval cannot emit another event or repeat the originating command.
- **Causation:** The `ApprovalRequested` event ID.
- **Correlation:** The effective approval-command `X-Correlation-Id`; the originating correlation ID remains linked in durable approval metadata.

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

The event contains no command payload, command result body, payload hash, idempotency key, sensitive field, or encryption detail.

### 9.6 Excluded lifecycle operations

No public reject or cancel endpoint is proposed. No `rejected` or `cancelled` state may be implemented until its transition authority, request schema, reason requirements, idempotency, audit, and events are separately approved.
