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
POST /v1/periods/{id}/soft-close      POST /v1/periods/{id}/hard-close ⚡(four-eyes+gates)
POST /v1/periods/{id}/reopen ⚡(approval+notify)
POST /v1/fiscal-year/{yr}/roll ⚡
GET  /v1/periods   GET /v1/periods/{id}   GET /v1/periods/postable?date=…
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
POST /v1/credit-notes   PATCH /v1/credit-notes/{id}   GET /v1/credit-notes/{id}   GET /v1/credit-notes
POST /v1/credit-notes/{id}/post   POST /v1/credit-notes/{id}/apply   POST /v1/credit-notes/{id}/hold
POST /v1/credit-notes/{id}/refund   POST /v1/credit-notes/{id}/reverse
GET  /v1/invoices/{id}   GET /v1/invoices?customer=&status=&overdue=
GET  /v1/invoices/{id}/pdf   POST /v1/customers   PATCH /v1/customers/{id}
GET  /v1/customers/{id}/statement
```

### Payables
```
POST /v1/bills (draft)   POST /v1/bills/{id}/approve   POST /v1/bills/{id}/void
POST /v1/debit-notes   PATCH /v1/debit-notes/{id}   GET /v1/debit-notes/{id}   GET /v1/debit-notes
POST /v1/debit-notes/{id}/post   POST /v1/debit-notes/{id}/apply   POST /v1/debit-notes/{id}/hold
POST /v1/debit-notes/{id}/refund   POST /v1/debit-notes/{id}/reverse   POST /v1/expenses
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

### Reporting (queries; `basis=accrual` only except Cash View, which is always `basis=cash` — §13.1)
```
GET /v1/reports/trial-balance?asOf=&period_ref=&sbu=          GET /v1/reports/general-ledger?account=&range=&sbu=
GET /v1/reports/profit-loss?period=&sbu=&basis=accrual&compare_to=  GET /v1/reports/balance-sheet?asOf=&sbu=&compare_to=
GET /v1/reports/ar-ageing?asOf=&customer=   GET /v1/reports/ap-ageing?asOf=&vendor=
GET /v1/reports/tax-summary?period=  (accrual only)   GET /v1/reports/cash-view?period=&sbu=
GET /v1/reports/fx-revaluation?period=

POST /v1/report-runs   GET /v1/report-runs/{id}   GET /v1/report-runs?report_type=&period=&state=
POST /v1/report-runs/{id}/approve   GET /v1/report-runs/{id}/export?format=pdf|csv
```

Full contracts: §13 (Approved Amendment — M5 Reporting and Cash View).

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

`X-Correlation-Id` is optional. The server generates a UUID only when the header is absent, echoes the effective value, and propagates it to logs, audit, outbox metadata, and internal calls. A malformed caller-supplied value returns `400 validation` and is not silently replaced.

Every state-changing endpoint requires a UUID `Idempotency-Key`. The same key and canonical request return the original result without repeating state, audit, or events. Reuse with different input returns `409 idempotency_conflict`. Replays return `Idempotent-Replay: true`.

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

Authorization: `fx.revaluation.read`. Required filter is entity PeriodRef; optional status is `posted` or `reversed`. No pagination because entity/period bounds the result. A pending approval is an Identity-owned ApprovalRequest, not a RevaluationRun; the originating command's standard `202` response supplies the approval resource.

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
- UUID `X-Correlation-Id` is optional. The server generates one only when the header is absent, echoes it, and propagates it to audit, outbox, logs, and replayed command causation metadata. A malformed caller-supplied value returns `400 validation` and is not silently replaced.
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
- Identical replay returns the original `200` response and `Idempotent-Replay: true`.
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

---

## 10. Approved Amendment — M2 Documents

**Approved artifact:** `PROPOSED_API_CONTRACT_AMENDMENT_M2_DOCUMENTS.md`
**Approved SHA-256:** `801e5043a22f8d3556a9297b0cafbf86ea7ab92cbfe6eabb84bd595389bbde3c`

### 1. Scope and exclusions

This proposal defines the public contracts needed for Customer, Invoice, Vendor, Bill, and Expense flows in M2. It does not redefine the approved M0/M1 protocol. Settlement, receipts, payments, Credit Notes, Debit Notes, Period Close, ageing, reconciliation, migration, and later reporting are excluded. Posting, tax determination, and applicable-rate lookup remain internal application contracts.

Public draft editing and soft deactivation are included by approved governance decision. No delete, reactivate, posted-document edit, or attachment transport is introduced.

### 2. Shared protocol and schemas

#### 2.1 Inherited protocol

All endpoints inherit the frozen M0/M1 conventions for TLS, authentication, entity isolation, `X-Entity-Id`, optional `X-Correlation-Id`, correlation validation and propagation, errors, exact decimal strings, UUIDs, UTC timestamps, unknown-field rejection, idempotency, `Idempotent-Replay: true`, `If-Match`, durable approval responses, and stable cursors. A malformed supplied correlation ID returns `400 validation`; a UUID is generated only when the header is absent.

State-changing endpoints require `Idempotency-Key`. Identical replay returns the original status, body, and headers without repeating audit, outbox, numbering, posting, or business effects. Key reuse with different canonical input returns `409 idempotency_conflict`. Unknown request or query fields return `400 validation`.

Cursor lists use `limit` from 1 to 100, default 50. Opaque cursors bind the entity, normalized filters, ordering, and read boundary. Cross-entity identifiers return `404 not_found`.

#### 2.2 Money, TaxSnapshot, and ExchangeRateReference

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

#### 2.3 Address and contact details

Address and contact fields are optional and nullable. Country is an ISO 3166-1 alpha-2 code. Email and phone are validated but do not imply delivery, CRM, or notification behavior.

```json
{
  "contact":{"email":"finance@example.test","phone":"+8801000000000"},
  "address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"}
}
```

#### 2.4 Customer and Vendor

Customer type is `local` or `foreign`; status is `active` or `deactivated`. `payment_terms` is a configured policy key, never an inferred number of days. Display names are not unique. `tax_identifier` is optional; when supplied, `jurisdiction` is required and the normalized identifier is unique within entity + jurisdiction + master type. Normalization is Unicode NFKC, outer-whitespace trim, and uppercase letters. Punctuation and internal characters are preserved; no transliteration, punctuation stripping, or fuzzy matching is applied. Customer and Vendor uniqueness scopes are independent. Without a tax identifier, duplicate names are allowed and UUID is identity. Vendor bank identifiers are write-only sensitive values; responses expose masked values only. They are encrypted at rest and omitted from logs, audit values, and events.

```json
{
  "customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","type":"foreign","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","contact":{"email":"finance@example.test","phone":null},"address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"},"status":"active","version":1,"created_at":"2026-07-20T10:00:00Z","updated_at":"2026-07-20T10:00:00Z"},
  "vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","contact":{"email":"accounts@example.test","phone":null},"address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"},"bank_details":{"account_name":"Example Vendor","institution_name":"Configured Bank","account_identifier_masked":"****1234","routing_identifier_masked":"****5678"},"status":"active","version":1,"created_at":"2026-07-20T10:00:00Z","updated_at":"2026-07-20T10:00:00Z"}
}
```

#### 2.5 Document line and SBU allocation

Quantity, unit price, and weights are exact decimal strings. Server-derived amounts use Money. Invoice lines use configured revenue mapping; bill and expense inputs identify entity-owned active posting accounts where stated. `tax_code_id` is optional only when configured customer/vendor/entity policy resolves exactly one applicable code. Persisted snapshots are immutable.

```json
{
  "invoice_line":{"id":"9f38c020-cfb9-4904-a025-55933ab3637a","description":"Configured service","quantity":"1.0000","unit_price":{"amount":"100.0000","currency":"USD"},"tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379","tax_snapshot":null,"line_amount":{"amount":"100.0000","currency":"USD"},"tax_amount":{"amount":"0.0000","currency":"USD"},"total_amount":{"amount":"100.0000","currency":"USD"}},
  "bill_line":{"id":"12a6388c-a6b3-42d0-8e64-2efcbd8b08a7","description":"Configured service","quantity":"1.0000","unit_price":{"amount":"100.0000","currency":"BDT"},"expense_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","tax_code_id":null,"tax_snapshot":null,"line_amount":{"amount":"100.0000","currency":"BDT"},"tax_amount":{"amount":"0.0000","currency":"BDT"},"total_amount":{"amount":"100.0000","currency":"BDT"}},
  "sbu_allocation":{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}
}
```

#### 2.6 Invoice and Bill representations

Invoice statuses are `draft`, `sent`, `partially_paid`, `paid`, `overdue`, and `void`. Bill statuses are `draft`, `awaiting_payment`, `partially_paid`, `paid`, `overdue`, and `void`. M2 creates only draft and recognized states; later milestones own settlement and correction transitions. A draft has a non-statutory provisional token and no document number. Issuance or approval atomically draws the configured number and persists valuation references.

```json
{
  "invoice_summary":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","provisional_token":null,"customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","total":{"amount":"100.0000","currency":"USD"},"open_balance":{"amount":"100.0000","currency":"USD"},"status":"sent","version":2},
  "invoice_detail":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","provisional_token":null,"customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","reference":null,"notes":null,"payment_instructions_ref":"CONFIGURED_INSTRUCTIONS","lines":[],"subtotal":{"amount":"100.0000","currency":"USD"},"tax_total":{"amount":"0.0000","currency":"USD"},"total":{"amount":"100.0000","currency":"USD"},"open_balance":{"amount":"100.0000","currency":"USD"},"exchange_rate_reference":null,"journal_entry_id":null,"status":"draft","version":1,"created_at":"2026-07-20T10:00:00Z","updated_at":"2026-07-20T10:00:00Z"},
  "bill_summary":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":"CONFIGURED-NUMBER","provisional_token":null,"vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","total":{"amount":"100.0000","currency":"BDT"},"open_balance":{"amount":"100.0000","currency":"BDT"},"status":"awaiting_payment","version":2},
  "bill_detail":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":"CONFIGURED-NUMBER","provisional_token":null,"vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","vendor_reference":null,"notes":null,"bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","lines":[],"sbu_allocations":[],"ait":null,"vds":null,"subtotal":{"amount":"100.0000","currency":"BDT"},"tax_total":{"amount":"0.0000","currency":"BDT"},"total":{"amount":"100.0000","currency":"BDT"},"open_balance":{"amount":"100.0000","currency":"BDT"},"exchange_rate_reference":null,"journal_entry_id":null,"status":"draft","version":1,"created_at":"2026-07-20T10:00:00Z","updated_at":"2026-07-20T10:00:00Z"}
}
```

#### 2.7 Expense representation

Settlement type is `cash` or `accrued`; status is `recorded`. `bank_account_id` is required only for cash settlement. `vendor_id` is required only for accrued settlement. Category and SBU references must resolve to active entity-owned Ledger records. Tax and FX values are internally determined and snapshotted.

```json
{
  "expense":{"id":"427709ca-dab1-43f0-b4d0-2b4616d50233","expense_date":"2026-07-15","description":"Configured operating expense","vendor_id":null,"category_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","settlement_type":"cash","bank_account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","currency":"BDT","amount":{"amount":"100.0000","currency":"BDT"},"tax_code_id":null,"tax_snapshot":null,"ait":null,"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}],"exchange_rate_reference":null,"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","status":"recorded","version":1,"recorded_at":"2026-07-20T10:00:00Z"}
}
```

#### 2.8 Approval response and PDF metadata

The approval resource reuses the frozen durable approval schema. No originating command payload or sensitive value is exposed. PDF metadata documents the binary response; it does not prescribe layout, branding, numbering format, or filename policy.

```json
{
  "approval_response":{"approval":{"id":"3530ca0e-4201-4ab1-8521-20f851defd44","status":"pending","command":"CONFIGURED_COMMAND","resource_id":"5c98deec-3920-46d6-a763-d13e44208a76","maker_id":"b7447cf1-adf8-439b-bf4c-34c5752cfdd7","entity_id":"2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d","version":1,"submitted_at":"2026-07-20T10:30:00Z"}},
  "pdf_response_metadata":{"media_type":"application/pdf","filename":"CONFIGURED_FILENAME.pdf","document_id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","content_length":1024,"etag":"CONFIGURED_ETAG"}
}
```

### 3. Receivables endpoints

#### 3.1 `POST /v1/customers`

**Purpose and access:** Create an active Customer. Authentication plus `receivables.customers.manage` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match` or pagination. Durable approval does not apply.

**Request:** Required: `name`, `type`, `default_currency`, `payment_terms`. Optional nullable: `jurisdiction`, `tax_identifier`, `contact`, `address`. Unknown fields are rejected. Name is nonblank but not unique. Jurisdiction is required when tax identifier is nonnull. The approved normalization and independently scoped uniqueness rule in §2.4 applies.

**Response and errors:** `201` returns Customer. Stable additional errors are `409 duplicate_resource` and `422 missing_customer_configuration`; common errors apply.

**Audit and outbox:** Customer, immutable audit creation record, idempotency result, and `CustomerCreated` outbox event commit atomically. Sensitive contact values are omitted from event payload and operational logs.

```json
{"request":{"name":"Example Customer","type":"foreign","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","contact":{"email":"finance@example.test","phone":null},"address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"}},"response":{"customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","type":"foreign","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","status":"active","version":1}}}
```

#### 3.2 `PATCH /v1/customers/{id}`

**Purpose and access:** Update an active Customer profile without changing historical document snapshots. Authentication plus `receivables.customers.manage` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. No pagination or durable approval.

**Request:** At least one of `name`, `type`, nullable `jurisdiction`, nullable `tax_identifier`, `default_currency`, `payment_terms`, nullable `contact`, or nullable `address` is required. Creation validation and §2.4 normalization apply. Status, entity, version, balances, and activity are rejected. Unknown fields are rejected.

**Response and errors:** `200` returns the updated Customer and incremented version. Additional errors are `409 duplicate_resource`, `422 customer_deactivated`, and `422 missing_customer_configuration`; common concurrency errors apply.

**Audit and outbox:** Conditional update, before/after audit excluding sensitive contact values, idempotency result, and `CustomerUpdated` commit atomically.

```json
{"request":{"name":"Example Customer Updated","payment_terms":"CONFIGURED_TERMS"},"response":{"customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer Updated","type":"foreign","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","status":"active","version":2}}}
```

#### 3.3 `POST /v1/customers/{id}/deactivate`

**Purpose and access:** Soft-deactivate an active Customer while preserving all history and document references. Authentication plus `receivables.customers.manage` is required. No delete or reactivate operation is provided.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. Body must be empty. No pagination or durable approval. Exact replay returns the original result.

**Validation and errors:** Customer must be active and the supplied version current. Unknown body fields are rejected. A new command against a deactivated Customer returns `422 invariant_violation` with rule `customer_already_deactivated`; common concurrency and idempotency errors apply.

**Response:** `200` returns the deactivated Customer with incremented version.

**Audit and outbox:** Conditional soft deactivation, immutable audit record, idempotency result, and `CustomerDeactivated` outbox event commit atomically. Historical documents and references are unchanged.

```json
{"request":{},"response":{"customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","status":"deactivated","version":2}}}
```

#### 3.4 `GET /v1/customers/{id}`

**Purpose and access:** Return one entity-scoped Customer profile. Authentication plus `receivables.customers.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Customer. Common read errors apply. Reads create no audit or outbox event unless configured read-audit policy requires one.

```json
{"response":{"customer":{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","type":"foreign","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"USD","payment_terms":"CONFIGURED_TERMS","status":"active","version":1}}}
```

#### 3.5 `GET /v1/customers`

**Purpose and access:** List Customer summaries. Authentication plus `receivables.customers.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `search`, `type`, `status`, `limit`, and `cursor`. Status defaults to `active`; callers may explicitly request `deactivated`. Search applies to display name and normalized tax identifier; type and status use Customer enums. Unknown fields are rejected.

**Response and ordering:** `200` returns `customers` and `page`. Stable ordering is normalized `name ASC, id ASC`. Common read errors apply.

```json
{"request_query":{"status":"active","limit":50,"cursor":null},"response":{"customers":[{"id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","name":"Example Customer","type":"foreign","default_currency":"USD","status":"active","version":1}],"page":{"limit":50,"next_cursor":null}}}
```

#### 3.6 `POST /v1/invoices`

**Purpose and access:** Create an editable Invoice draft with a provisional token. Authentication plus `receivables.invoices.create` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match`, pagination, or durable approval.

**Request:** Required: active `customer_id`, ISO `invoice_date`, supported `currency`, and nonempty `lines`. Optional: `due_date`, nullable `reference`, nullable `notes`, nullable `payment_instructions_ref`, and nullable `rate_record_id`. Notes are plain document text and create no workflow or posting rule. Each line requires nonblank `description`, positive exact `quantity`, positive `unit_price`, and optional `tax_code_id`. Due date must not precede invoice date; if absent it is resolved from configured Customer terms. All line Money currencies equal document currency. A supplied rate ID must resolve through the internal FX contract and must match the document pair/date. Unknown fields, client totals, document numbers, mutable snapshots, numeric rates, status, open balance, journal IDs, and versions are rejected.

**Response and errors:** `201` returns Invoice detail in `draft`, version 1. Additional errors are `422 customer_inactive`, `422 missing_payment_terms_configuration`, `422 missing_tax_configuration`, and `422 invalid_document_currency`; common errors apply.

**Audit and outbox:** Draft, lines, audit, and idempotency result commit atomically. No recognition posting, statutory number, financial projection, or business outbox event is created.

```json
{"request":{"customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","reference":null,"payment_instructions_ref":"CONFIGURED_INSTRUCTIONS","rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e","lines":[{"description":"Configured service","quantity":"1.0000","unit_price":{"amount":"100.0000","currency":"USD"},"tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379"}]},"response":{"invoice":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":null,"provisional_token":"CONFIGURED_PROVISIONAL_TOKEN","status":"draft","version":1}}}
```

#### 3.7 `PATCH /v1/invoices/{id}`

**Purpose and access:** Replace approved fields on an editable Invoice draft. Authentication plus `receivables.invoices.create` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. No pagination or durable approval. Exact replay returns the original result.

**Request:** At least one approved draft field from §3.6 is required: `customer_id`, `invoice_date`, nullable `due_date`, `currency`, nullable `reference`, nullable `notes`, nullable `payment_instructions_ref`, nullable `rate_record_id`, or the complete `lines` array. Collections are replaced as a whole, not merged. Line `tax_code_id` is the only TaxSnapshot input; client snapshots and numeric rates remain rejected. Changing party, dates, currency, TaxCode input, rate selection, notes, or lines triggers complete revalidation. Unknown fields are rejected.

**Validation and errors:** Only status `draft` may change. The §3.6 validation rules apply to the resulting complete draft. Issued or otherwise non-draft documents return `422 invariant_violation` with rule `invoice_not_draft`; common concurrency and idempotency errors apply.

**Response:** `200` returns the updated draft with incremented version.

**Audit and outbox:** Conditional draft replacement, immutable before/after audit, and idempotency result commit atomically. No issue, posting, numbering, recognition, or approval event is emitted; no number or JournalEntry is created.

```json
{"request":{"due_date":"2026-08-31","lines":[{"description":"Updated configured service","quantity":"2.0000","unit_price":{"amount":"50.0000","currency":"USD"},"tax_code_id":"fb861bea-a516-4546-b92e-2a96a19a3379"}]},"response":{"invoice":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","status":"draft","version":2}}}
```

#### 3.8 `GET /v1/invoices/{id}`

**Purpose and access:** Return a complete Invoice and immutable valuation data when recognized. Authentication plus `receivables.invoices.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Invoice detail. Draft lines may have null snapshots; recognized lines and foreign documents contain immutable snapshots/references. Common read errors apply. No audit or outbox event unless configured read-audit policy applies.

```json
{"response":{"invoice":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":null,"provisional_token":"CONFIGURED_PROVISIONAL_TOKEN","customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","lines":[],"exchange_rate_reference":null,"journal_entry_id":null,"status":"draft","version":1}}}
```

#### 3.9 `GET /v1/invoices`

**Purpose and access:** Search Invoice summaries. Authentication plus `receivables.invoices.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `customer`, `status`, `overdue`, `from`, `to`, `limit`, and `cursor`. IDs are entity-scoped UUIDs; dates are ordered ISO dates; overdue is boolean and evaluated at the entity accounting date. Unknown fields are rejected.

**Response and ordering:** `200` returns `invoices` and `page`. Stable ordering is `invoice_date DESC, id DESC`. Drafts and recognized documents are returned only when matching filters. Common read errors apply.

```json
{"request_query":{"customer":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","status":"sent","overdue":false,"limit":50,"cursor":null},"response":{"invoices":[{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","customer_id":"c6ed5eb4-9335-4b72-914f-fab9ad9d7cb2","invoice_date":"2026-07-15","due_date":"2026-08-14","currency":"USD","total":{"amount":"100.0000","currency":"USD"},"open_balance":{"amount":"100.0000","currency":"USD"},"status":"sent","version":2}],"page":{"limit":50,"next_cursor":null}}}
```

#### 3.10 `POST /v1/invoices/{id}/issue`

**Purpose and access:** Recognize a draft Invoice, allocate its configured number, persist immutable TaxSnapshots and RateRecord reference, and post the balanced recognition journal. Authentication plus `receivables.invoices.issue` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. Body must be empty. No pagination. Durable approval does not apply under the currently frozen high-risk list.

**Validation:** Invoice must be draft and complete; customer active; issue date postable; configured number sequence, revenue mapping, tax determination, functional-currency rounding, and applicable FX rate must resolve. Foreign documents require an exact applicable RateRecord. Recognition uses invoice date and must produce a balanced journal. Unknown body fields are rejected.

**Response and errors:** `201` returns recognized Invoice detail with status `sent`, number, version 2, immutable valuation data, and journal ID. Additional errors are `422 invoice_not_draft`, `422 customer_inactive`, `422 missing_numbering_configuration`, `422 missing_posting_configuration`, `422 missing_tax_configuration`, `422 missing_rate_reference`, `422 unbalanced_recognition`, and `423 period_locked`; common concurrency errors apply.

**Audit and outbox:** Number draw, Invoice transition, immutable lines/snapshots/references, recognition JournalEntry, audit, idempotency result, `InvoiceIssued`, `TaxDetermined` where required, and `JournalPosted` commit in the approved recognition Unit of Work. Failure leaves no partial recognition; numbering follows ADR-009 used-and-voided handling.

```json
{"request":{},"response":{"invoice":{"id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","provisional_token":null,"status":"sent","open_balance":{"amount":"100.0000","currency":"USD"},"exchange_rate_reference":{"rate_record_id":"6871052e-8b8c-44fb-b356-0582d48a305e","base_currency":"USD","quote_currency":"BDT","rate":"100.00000000","effective_date":"2026-07-15"},"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","version":2}}}
```

#### 3.11 `GET /v1/invoices/{id}/pdf`

**Purpose and access:** Retrieve the generated PDF for a recognized Invoice. Authentication plus `receivables.invoices.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; optional correlation and standard conditional `If-None-Match` are accepted. No idempotency, aggregate `If-Match`, approval, or pagination. No query fields or body are accepted.

**Validation and response:** Invoice must exist in entity scope and be recognized. `200` returns binary `application/pdf` with safe `Content-Disposition`, `Content-Length`, `ETag`, `X-Document-Id`, and `X-Document-Number` metadata; matching ETag returns `304` without a body. Additional error is `422 invoice_not_issued`; common read errors apply.

**Audit and outbox:** No financial audit or outbox event. Configured document-access auditing may record safe metadata only. PDF content and design follow separately configured presentation policy.

```json
{"response_metadata":{"status":200,"media_type":"application/pdf","filename":"CONFIGURED_FILENAME.pdf","document_id":"cb49bb96-da45-493d-ab3e-781f141e9062","document_number":"CONFIGURED-NUMBER","content_length":1024,"etag":"CONFIGURED_ETAG"}}
```

### 4. Payables endpoints

#### 4.1 `POST /v1/vendors`

**Purpose and access:** Create an active Vendor. Authentication plus `payables.vendors.manage` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match`, pagination, or durable approval.

**Request:** Required: `name`, `default_currency`, `payment_terms`. Optional nullable: `jurisdiction`, `tax_identifier`, `contact`, `address`, and `bank_details`. Bank details accept `account_name`, `institution_name`, `account_identifier`, and nullable `routing_identifier`; raw identifiers are never returned. Unknown fields are rejected. Name is nonblank but not unique. Jurisdiction is required when tax identifier is nonnull. The approved normalization and independently scoped uniqueness rule in §2.4 applies.

**Response and errors:** `201` returns Vendor with masked bank details. Additional errors are `409 duplicate_resource` and `422 missing_vendor_configuration`; common errors apply.

**Audit and outbox:** Vendor, encrypted sensitive fields, redacted audit creation, idempotency result, and `VendorCreated` outbox event commit atomically. Sensitive values never enter logs or events.

```json
{"request":{"name":"Example Vendor","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","contact":{"email":"accounts@example.test","phone":null},"address":{"line_1":"Configured address","line_2":null,"city":"Dhaka","region":null,"postal_code":null,"country_code":"BD"},"bank_details":{"account_name":"Example Vendor","institution_name":"Configured Bank","account_identifier":"CONFIGURED_ACCOUNT_IDENTIFIER","routing_identifier":null}},"response":{"vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","bank_details":{"account_name":"Example Vendor","institution_name":"Configured Bank","account_identifier_masked":"****1234","routing_identifier_masked":null},"status":"active","version":1}}}
```

#### 4.2 `PATCH /v1/vendors/{id}`

**Purpose and access:** Update an active Vendor profile without changing historical documents. Authentication plus `payables.vendors.manage` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. No pagination or durable approval.

**Request:** At least one of `name`, nullable `jurisdiction`, nullable `tax_identifier`, `default_currency`, `payment_terms`, nullable `contact`, nullable `address`, or nullable `bank_details` is required. Creation validation and §2.4 normalization apply. Status, entity, version, balances, and activity are rejected. Unknown fields are rejected.

**Response and errors:** `200` returns updated Vendor and incremented version. Additional errors are `409 duplicate_resource`, `422 vendor_deactivated`, and `422 missing_vendor_configuration`; common concurrency errors apply.

**Audit and outbox:** Conditional update, redacted before/after audit, idempotency result, and `VendorUpdated` commit atomically. Sensitive bank changes are recorded as changed/not-changed only.

```json
{"request":{"name":"Example Vendor Updated","payment_terms":"CONFIGURED_TERMS"},"response":{"vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor Updated","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","status":"active","version":2}}}
```

#### 4.3 `POST /v1/vendors/{id}/deactivate`

**Purpose and access:** Soft-deactivate an active Vendor while preserving all history and document references. Authentication plus `payables.vendors.manage` is required. No delete or reactivate operation is provided.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. Body must be empty. No pagination or durable approval. Exact replay returns the original result.

**Validation and errors:** Vendor must be active and the supplied version current. Unknown body fields are rejected. A new command against a deactivated Vendor returns `422 invariant_violation` with rule `vendor_already_deactivated`; common concurrency and idempotency errors apply.

**Response:** `200` returns the deactivated Vendor with incremented version.

**Audit and outbox:** Conditional soft deactivation, immutable audit record, idempotency result, and `VendorDeactivated` outbox event commit atomically. Historical documents and references are unchanged.

```json
{"request":{},"response":{"vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","status":"deactivated","version":2}}}
```

#### 4.4 `GET /v1/vendors/{id}`

**Purpose and access:** Return one entity-scoped Vendor profile with masked sensitive fields. Authentication plus `payables.vendors.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Vendor. Common read errors apply. No audit or outbox event unless configured read-audit policy requires safe metadata.

```json
{"response":{"vendor":{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","jurisdiction":"BD","tax_identifier":"CONFIGURED-TAX-ID","default_currency":"BDT","payment_terms":"CONFIGURED_TERMS","bank_details":{"account_name":"Example Vendor","institution_name":"Configured Bank","account_identifier_masked":"****1234","routing_identifier_masked":null},"status":"active","version":1}}}
```

#### 4.5 `GET /v1/vendors`

**Purpose and access:** List Vendor summaries. Authentication plus `payables.vendors.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `search`, `status`, `limit`, and `cursor`. Status defaults to `active`; callers may explicitly request `deactivated`. Search applies to display name and normalized tax identifier. Unknown fields are rejected.

**Response and ordering:** `200` returns `vendors` and `page`. Stable ordering is normalized `name ASC, id ASC`. Sensitive bank fields are omitted. Common read errors apply.

```json
{"request_query":{"status":"active","limit":50,"cursor":null},"response":{"vendors":[{"id":"136cf19a-601f-4f2a-99a0-43276750bd1f","name":"Example Vendor","default_currency":"BDT","status":"active","version":1}],"page":{"limit":50,"next_cursor":null}}}
```

#### 4.6 `POST /v1/bills`

**Purpose and access:** Create an editable Bill draft with a provisional token. Authentication plus `payables.bills.create` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match`, pagination, or durable approval.

**Request:** Required: active `vendor_id`, ISO `bill_date`, supported `currency`, nonempty `lines`, and nonempty `sbu_allocations`. Optional: `due_date`, nullable `vendor_reference`, nullable `notes`, nullable `ait`, nullable `vds`, and nullable `rate_record_id`. Notes are plain document text and create no workflow or posting rule. Each line requires description, positive quantity/unit price, active entity expense account, and optional TaxCode. SBU weights are positive exact decimals and total exactly `1.0000`. Due date cannot precede bill date; missing due date uses configured Vendor terms. All Money currencies equal document currency. A supplied rate ID must resolve through the internal FX contract and match the document pair/date. Unknown fields and client-derived totals, numbers, mutable snapshots, numeric rates, status, balance, journal IDs, and versions are rejected.

**Response and errors:** `201` returns Bill detail in `draft`, version 1. Additional errors are `422 vendor_inactive`, `422 missing_payment_terms_configuration`, `422 missing_tax_configuration`, `422 invalid_document_currency`, `422 invalid_expense_account`, and `422 sbu_allocation_invalid`; common errors apply.

**Audit and outbox:** Draft, lines, allocations, audit, and idempotency result commit atomically. No recognition posting, statutory/internal number, projection, or business outbox event is created.

```json
{"request":{"vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","vendor_reference":"CONFIGURED-REFERENCE","bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","rate_record_id":null,"lines":[{"description":"Configured service","quantity":"1.0000","unit_price":{"amount":"100.0000","currency":"BDT"},"expense_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","tax_code_id":null}],"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}],"ait":null,"vds":null},"response":{"bill":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":null,"provisional_token":"CONFIGURED_PROVISIONAL_TOKEN","status":"draft","version":1}}}
```

#### 4.7 `PATCH /v1/bills/{id}`

**Purpose and access:** Replace approved fields on an editable Bill draft. Authentication plus `payables.bills.create` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. No pagination or durable approval. Exact replay returns the original result.

**Request:** At least one approved draft field from §4.6 is required: `vendor_id`, nullable `vendor_reference`, nullable `notes`, `bill_date`, nullable `due_date`, `currency`, nullable `rate_record_id`, complete `lines`, complete `sbu_allocations`, nullable `ait`, or nullable `vds`. Collections and allocations are replaced as a whole, not merged. Line `tax_code_id` is the only TaxSnapshot input; client snapshots and numeric rates remain rejected. Changing party, dates, currency, TaxCode input, RateRecord selection, allocations, notes, or amounts triggers complete revalidation. Unknown fields are rejected.

**Validation and errors:** Only status `draft` may change. The §4.6 validation rules apply to the resulting complete draft. Approved or otherwise non-draft Bills return `422 invariant_violation` with rule `bill_not_draft`; common concurrency and idempotency errors apply.

**Response:** `200` returns the updated draft with incremented version.

**Audit and outbox:** Conditional draft replacement, immutable before/after audit, and idempotency result commit atomically. No approval, posting, numbering, recognition, or business event is emitted; no number or JournalEntry is created.

```json
{"request":{"due_date":"2026-08-31","sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}]},"response":{"bill":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","status":"draft","version":2}}}
```

#### 4.8 `GET /v1/bills/{id}`

**Purpose and access:** Return a complete Bill and immutable valuation data when approved. Authentication plus `payables.bills.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Bill detail. Draft lines may have null snapshots; approved lines and foreign documents contain immutable valuation data. Common read errors apply. No audit or outbox event unless configured read-audit policy applies.

```json
{"response":{"bill":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":null,"provisional_token":"CONFIGURED_PROVISIONAL_TOKEN","vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","lines":[],"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}],"exchange_rate_reference":null,"journal_entry_id":null,"status":"draft","version":1}}}
```

#### 4.9 `GET /v1/bills`

**Purpose and access:** Search Bill summaries. Authentication plus `payables.bills.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `vendor`, `status`, `overdue`, `from`, `to`, `limit`, and `cursor`. IDs are entity-scoped UUIDs; dates are ordered ISO dates; overdue is evaluated at entity accounting date. Unknown fields are rejected.

**Response and ordering:** `200` returns `bills` and `page`. Stable ordering is `bill_date DESC, id DESC`. Common read errors apply.

```json
{"request_query":{"vendor":"136cf19a-601f-4f2a-99a0-43276750bd1f","status":"awaiting_payment","overdue":false,"limit":50,"cursor":null},"response":{"bills":[{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":"CONFIGURED-NUMBER","vendor_id":"136cf19a-601f-4f2a-99a0-43276750bd1f","bill_date":"2026-07-15","due_date":"2026-08-14","currency":"BDT","total":{"amount":"100.0000","currency":"BDT"},"open_balance":{"amount":"100.0000","currency":"BDT"},"status":"awaiting_payment","version":2}],"page":{"limit":50,"next_cursor":null}}}
```

#### 4.10 `POST /v1/bills/{id}/approve`

**Purpose and access:** Approve a Bill, allocate its configured number, persist immutable TaxSnapshots and RateRecord reference, and post the balanced recognition journal. Authentication plus `payables.bills.approve` is required.

**Headers and protocol:** `X-Entity-Id`, `Idempotency-Key`, and `If-Match` are required; correlation is inherited. Body must be empty. `X-SoD-Justification` is accepted only by the frozen compensating-control flow. No pagination.

**Approval behavior:** Bill creator and direct approver must differ. A maker attempt returns `403 sod_exception_required` unless an authorized, audited compensating-control exception applies. If configured Approval Policy independently requires durable approval, the command returns the frozen `202` approval response and commits no Bill or Ledger mutation before approved replay. No monetary threshold is defined here.

**Validation:** Bill must be draft and complete; vendor active; bill date postable; number sequence, tax, expense/AP mappings, SBU records, rounding, and applicable FX rate must resolve. SBU total is exactly `1.0000`; AIT/VDS use configured TaxPack rules. Recognition uses bill date and balances exactly. Unknown body fields are rejected.

**Response and errors:** Direct/approved execution returns `201` with status `awaiting_payment`, number, version 2, snapshots/reference, and journal ID; configured approval returns `202`. Additional errors are `422 bill_not_draft`, `422 vendor_inactive`, `422 missing_numbering_configuration`, `422 missing_posting_configuration`, `422 missing_tax_configuration`, `422 missing_rate_reference`, `422 sbu_allocation_invalid`, `422 unbalanced_recognition`, and `423 period_locked`; common concurrency/approval errors apply.

**Audit and outbox:** Number draw, Bill transition, immutable valuation data, JournalEntry, audit, idempotency result, `BillApproved`, `TaxDetermined` where required, and `JournalPosted` commit atomically. ApprovalRequest creation follows the frozen Identity transaction; successful replay performs the business Unit of Work once.

```json
{"request":{},"response":{"bill":{"id":"5c98deec-3920-46d6-a763-d13e44208a76","document_number":"CONFIGURED-NUMBER","provisional_token":null,"status":"awaiting_payment","open_balance":{"amount":"100.0000","currency":"BDT"},"exchange_rate_reference":null,"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","version":2}}}
```

#### 4.11 `POST /v1/expenses`

**Purpose and access:** Record a cash-settled or accrued Expense and its balanced Ledger recognition. Authentication plus `payables.expenses.create` is required.

**Headers and protocol:** `X-Entity-Id` and `Idempotency-Key` are required; correlation is inherited. No `If-Match`, pagination, or durable approval under the frozen high-risk list.

**Request:** Required: ISO `expense_date`, nonblank `description`, active `category_account_id`, `settlement_type`, supported `currency`, positive `amount`, and SBU allocations totaling exactly `1.0000`. Cash requires active entity bank `bank_account_id` and rejects `vendor_id`; accrued requires active `vendor_id` and rejects `bank_account_id`. Optional nullable: `tax_code_id`, `ait`. Unknown fields, client snapshots/rates/journal IDs/status/version are rejected.

**Validation and errors:** Date must be postable. Tax, FX, category, bank/AP mapping, rounding, and SBU configuration must resolve; all Money currencies match. `201` returns recorded Expense. Additional errors are `422 invalid_settlement_type`, `422 invalid_expense_account`, `422 invalid_bank_account`, `422 vendor_inactive`, `422 sbu_allocation_invalid`, `422 missing_tax_configuration`, `422 missing_rate_reference`, `422 missing_posting_configuration`, `422 unbalanced_recognition`, and `423 period_locked`; common errors apply.

**Audit and outbox:** Expense, immutable valuation data, JournalEntry, audit, idempotency result, `ExpenseRecorded`, `TaxDetermined` where required, and `JournalPosted` commit atomically. Failure creates no partial business effect.

```json
{"request":{"expense_date":"2026-07-15","description":"Configured operating expense","category_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","settlement_type":"cash","bank_account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","vendor_id":null,"currency":"BDT","amount":{"amount":"100.0000","currency":"BDT"},"tax_code_id":null,"ait":null,"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}]},"response":{"expense":{"id":"427709ca-dab1-43f0-b4d0-2b4616d50233","expense_date":"2026-07-15","settlement_type":"cash","amount":{"amount":"100.0000","currency":"BDT"},"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","status":"recorded","version":1}}}
```

#### 4.12 `GET /v1/expenses/{id}`

**Purpose and access:** Return one recorded Expense with immutable valuation data. Authentication plus `payables.expenses.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation is inherited. No idempotency, `If-Match`, approval, or pagination.

**Request and validation:** UUID path identifier only; no body or query fields. Unknown or cross-entity identifiers return `404`.

**Response and errors:** `200` returns Expense. Common read errors apply. No audit or outbox event unless configured read-audit policy applies.

```json
{"response":{"expense":{"id":"427709ca-dab1-43f0-b4d0-2b4616d50233","expense_date":"2026-07-15","description":"Configured operating expense","category_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","settlement_type":"cash","bank_account_id":"59b044b2-96e2-4cdd-a9b1-dca18be0a2d4","vendor_id":null,"amount":{"amount":"100.0000","currency":"BDT"},"sbu_allocations":[{"sbu_code":"CONFIGURED_SBU","weight":"1.0000"}],"journal_entry_id":"df654e88-f1cb-41f2-996c-1ee8826bf6aa","status":"recorded","version":1}}}
```

#### 4.13 `GET /v1/expenses`

**Purpose and access:** Search recorded Expenses. Authentication plus `payables.expenses.read` is required.

**Headers and protocol:** `X-Entity-Id` is required; correlation and cursor protocol are inherited. No idempotency, `If-Match`, approval, audit, or outbox event.

**Request:** Optional query fields are `vendor`, `category_account_id`, `sbu_code`, `settlement_type`, `from`, `to`, `limit`, and `cursor`. IDs/codes are entity-scoped; dates are ordered ISO dates; unknown fields are rejected.

**Response and ordering:** `200` returns `expenses` and `page`. Stable ordering is `expense_date DESC, id DESC`. Common read errors apply.

```json
{"request_query":{"settlement_type":"cash","from":"2026-07-01","to":"2026-07-31","limit":50,"cursor":null},"response":{"expenses":[{"id":"427709ca-dab1-43f0-b4d0-2b4616d50233","expense_date":"2026-07-15","description":"Configured operating expense","category_account_id":"8120c0dd-cb71-4631-b57e-9ca350817d79","settlement_type":"cash","amount":{"amount":"100.0000","currency":"BDT"},"status":"recorded","version":1}],"page":{"limit":50,"next_cursor":null}}}
```

### 5. Internal contracts

#### 5.1 Recognition posting

Not HTTP. Receivables and Payables send entity, source Document ID, accounting date, functional-currency balanced lines, immutable tax/rate references, SBU dimensions where applicable, actor, correlation, and causation to the Ledger-owned PostingService. Invoice/Bill/Expense and JournalEntry commit in the approved recognition Unit of Work. No context reads or writes another context's tables.

#### 5.2 Tax and FX determination

Not HTTP. M2 consumes the frozen M1 applicable-tax and applicable-rate contracts. Tax input is entity, TaxCode/default policy, jurisdiction, tax-point date, direction, and pricing mode when configured; output is immutable TaxSnapshot. FX input is entity, currency pair, accounting date, and configured purpose; output is exact RateRecord reference. Missing or ambiguous configuration fails safely. No inverse rate, cross rate, tax treatment, legal rule, or default is inferred.

#### 5.3 Number allocation

Not HTTP. Drafts receive non-statutory provisional tokens. Issue/approval invokes the Numbering shared kernel's atomic scoped draw. Series, prefix, format, reset, and fiscal policy are configuration. Failure follows ADR-009's used-and-voided audit behavior; a number is never reused.

### 6. Configurable policy boundaries

This contract defines no tax rate, legal treatment, numbering format, approval threshold, SBU allocation policy beyond the frozen exact-sum invariant, payment-term value, revenue/AP/bank mapping, FX source, rounding mode, PDF layout, or PDF filename. Required missing or ambiguous configuration fails safely with a stable `422` rule and no partial business mutation.

Expense attachment support is deferred. M2 defines no attachment endpoint or request field. The optional receipt attachment requires a separately approved shared upload, storage, authorization, malware-scanning, encryption, retention, and retrieval contract. This deferral does not block Expense creation.

### 7. Governance decisions resolved

No unresolved governance decision remains in this proposal. Draft editing, public soft deactivation, attachment deferral, and exact master tax-identifier uniqueness normalization are defined above. Approval of this proposal would freeze those decisions for M2 implementation without introducing delete, reactivate, attachment, Settlement, correction, close, or reporting behavior.

---

## 11. Approved Amendment — M3 Settlement

**Approved:** 20 July 2026.
**Approved artifact:** `PROPOSED_API_CONTRACT_AMENDMENT_M3_SETTLEMENT.md`
**Approved SHA-256:** `84f29614cf3b830c2c24867e83c3731667c37e622ef30b4aa244b407b23cba6f`
**Scope:** This amendment freezes the seven public M3 Settlement endpoints and their Receipt, Payment, Allocation, PartyCredit, withholding, FX-reference, reversal, approval, and pagination schemas. It preserves every approved M0/M1/M2 shared-protocol behavior and changes no existing endpoint.

**Foreign-credit tranche amendment approved:** 21 July 2026.
**Approved artifact:** `PROPOSED_GOVERNANCE_AMENDMENT_M3_FOREIGN_CREDIT_TRANCHES.md`
**Approved SHA-256:** `087de4a1c5613541111c8cb79f1b89d93ca199ad137cd516975484f1b103a74f`
**Effect:** The four party-credit application, refund, query, and reversal clauses below use explicit immutable source tranches, per-tranche concurrency, and backward-compatible v2 events. All other M3 clauses remain unchanged.

### 1. Scope and Common Protocol

This proposal covers Settlement-owned receipt, payment, party-credit, allocation-query, and allocation-reversal operations. Internal document-balance, Tax, FX, Numbering, Period, and Ledger services remain in-process contracts and are never exposed as HTTP endpoints.

Every endpoint inherits the frozen M0/M1/M2 shared protocol without change: TLS, authentication, entity isolation, `X-Entity-Id`, optional `X-Correlation-Id`, malformed-correlation handling, correlation echo and propagation, canonical UUID/date/timestamp/decimal formats, the common error envelope and codes, unknown-field rejection, `Idempotency-Key`, canonical request comparison, `Idempotent-Replay: true`, `If-Match`, durable approval responses, and opaque cursor behavior. This section states M3 applicability only and does not redefine any shared schema or behavior.

All endpoints require the stated entity-scoped capability and remain default-deny. Cross-entity resources return the inherited `404 not_found`. State-changing endpoints require the inherited idempotency protocol; read endpoints do not. `If-Match` is required only where an endpoint below states it. A missing required `If-Match` returns `428 precondition_required`; a stale value returns `409 concurrency_conflict`. Responses never expose internal stack traces, mutable approval payloads, raw rates, or cross-context data.

### 2. Shared Schemas and Accounting Invariants

#### 2.1 Money and Settlement Equations

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

#### 2.2 ExchangeRateReference and RealisedFXResult

An `ExchangeRateReference` is copied from the exact immutable M1 RateRecord. Clients provide only `rate_record_id`; the response supplies the reference. Functional amounts and realised FX are calculated by the internal FX contract using exact document and settlement RateRecord references. A signed realised-FX Money amount is positive for a gain, negative for a loss, and zero for none; `classification` is `gain`, `loss`, or `none` and must agree with the sign.

```json
{
  "exchange_rate_reference":{"rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","base_currency":"USD","quote_currency":"BDT","rate":"110.00000000","effective_date":"2026-07-20"},
  "realised_fx_result":{"document_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","settlement_rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","document_functional_amount":{"amount":"10000.0000","currency":"BDT"},"settlement_functional_amount":{"amount":"11000.0000","currency":"BDT"},"realised_fx":{"amount":"1000.0000","currency":"BDT"},"classification":"gain"}
}
```

#### 2.3 WithholdingLine

A WithholdingLine contains `withholding_code`, non-negative Money `amount`, and exactly one server-produced immutable reference: `tax_snapshot` under the frozen M1 schema or `withholding_configuration_reference` containing configured UUID `configuration_id`, positive integer `version`, and `code`. A command supplies only `withholding_code` and `amount`; the internal Tax contract resolves and persists the applicable immutable reference. AIT, VDS, and any other code are accepted only when active approved configuration determines the direction, accounts, treatment, date applicability, and legal metadata. Clients cannot provide a rate, GL account, legal treatment, snapshot, or configuration version. The sum of line amounts must equal `withholding_amount`.

```json
{
  "withholding_line":{"withholding_code":"CONFIGURED_WITHHOLDING","amount":{"amount":"12.0000","currency":"USD"},"tax_snapshot":null,"withholding_configuration_reference":{"configuration_id":"f3aa9ca9-7b21-435e-a68d-0226d952c232","version":3,"code":"CONFIGURED_WITHHOLDING"}}
}
```

#### 2.4 OpenDocumentReference and AllocationLink

An open-document reference is obtained through `GetOpenReceivable` or `GetOpenPayable`, never by reading another context's tables. The client supplies the document UUID, applied Money, and `expected_version`. A successful response includes immutable before/after references. Each applied amount is positive, uses the settlement currency, does not exceed the open balance, and belongs to the same party and entity. A request cannot mix invoices and bills.

```json
{
  "allocation_link":{"document_type":"invoice","document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","applied_amount":{"amount":"100.0000","currency":"USD"},"expected_version":2,"open_document":{"document_number":"INV-CONFIGURED","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","open_balance_before":{"amount":"100.0000","currency":"USD"},"open_balance_after":{"amount":"0.0000","currency":"USD"},"version_before":2,"version_after":3,"status_after":"paid"},"realised_fx_result":{"document_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","settlement_rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","document_functional_amount":{"amount":"10000.0000","currency":"BDT"},"settlement_functional_amount":{"amount":"11000.0000","currency":"BDT"},"realised_fx":{"amount":"1000.0000","currency":"BDT"},"classification":"gain"}}
}
```

#### 2.5 Allocation, Receipt, and Payment

An Allocation is a posted immutable Settlement record. `operation` is `receipt`, `payment`, `credit_application`, `credit_refund`, or `reversal`; `state` is `posted` or `reversed`. Receipt and Payment are Allocation representations whose operation is respectively `receipt` or `payment`. `party_type` is `customer` for a receipt and `vendor` for a payment. `allocation_number` is drawn through the configured Numbering contract; its format is not defined here.

Cash-origin records contain a bank account, gross/bank/withholding/unapplied amounts, zero or more withholding lines, and one or more document links unless the full gross amount is unapplied. `allocated_amount` is the server-derived sum of link amounts and is included in compact summaries so the second settlement equation remains visible when links are omitted. Records persist exact transaction and functional amounts, the settlement RateRecord reference when currencies differ, journal references, version, audit timestamps, and optional reversal linkage. Posted records are never edited or deleted.

```json
{
  "receipt":{"id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","allocation_number":"RCPT-CONFIGURED","operation":"receipt","party_type":"customer","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","settlement_date":"2026-07-20","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","gross_amount":{"amount":"120.0000","currency":"USD"},"bank_amount":{"amount":"108.0000","currency":"USD"},"withholding_amount":{"amount":"12.0000","currency":"USD"},"unapplied_amount":{"amount":"20.0000","currency":"USD"},"withholding_lines":[{"withholding_code":"CONFIGURED_WITHHOLDING","amount":{"amount":"12.0000","currency":"USD"},"tax_snapshot":null,"withholding_configuration_reference":{"configuration_id":"f3aa9ca9-7b21-435e-a68d-0226d952c232","version":3,"code":"CONFIGURED_WITHHOLDING"}}],"links":[{"document_type":"invoice","document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","applied_amount":{"amount":"100.0000","currency":"USD"},"expected_version":2}],"exchange_rate_reference":{"rate_record_id":"4cce8fde-b070-4dc5-ac9f-9458296cda62","base_currency":"USD","quote_currency":"BDT","rate":"110.00000000","effective_date":"2026-07-20"},"functional_gross_amount":{"amount":"13200.0000","currency":"BDT"},"journal_entry_ids":["be44f14a-2873-4d47-84b7-2ba6545925f8"],"state":"posted","version":1,"reversal":null,"posted_at":"2026-07-20T10:00:00Z"},
  "payment":{"id":"d8e6bdc7-e29b-41f8-a76c-fcd122b820dd","allocation_number":"PAY-CONFIGURED","operation":"payment","party_type":"vendor","party_id":"8bdf810a-f3e5-4078-ac85-9a762543ed0d","settlement_date":"2026-07-20","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","gross_amount":{"amount":"100.0000","currency":"BDT"},"bank_amount":{"amount":"100.0000","currency":"BDT"},"withholding_amount":{"amount":"0.0000","currency":"BDT"},"unapplied_amount":{"amount":"0.0000","currency":"BDT"},"withholding_lines":[],"links":[{"document_type":"bill","document_id":"ee3195b1-2b7b-411f-92fa-67c1ce9350f2","applied_amount":{"amount":"100.0000","currency":"BDT"},"expected_version":2}],"exchange_rate_reference":null,"functional_gross_amount":{"amount":"100.0000","currency":"BDT"},"journal_entry_ids":["94266065-346f-423c-bb9d-fad65a470ec3"],"state":"posted","version":1,"reversal":null,"posted_at":"2026-07-20T10:00:00Z"}
}
```

#### 2.6 CreditTranche, CreditConsumption, and PartyCredit projection

Foreign and functional party credit is persisted as immutable source tranches. Original transaction and functional Money, source Allocation/reference, party, entity, currency, and source RateRecord reference never change. Applications and refunds append consumption facts; reversals append restorations linked to the exact original consumption. Remaining values and PartyCreditBalance totals are rebuildable projections, not consumption authority.

Every application and refund requires a nonempty `credit_sources` array. Every selected source supplies its own `credit_tranche_id`, positive Money `amount`, and `expected_version`. The service never selects or substitutes a source: FIFO, LIFO, weighted-average, pro-rata, automatic selection, and single-tranche shortcuts are prohibited. Clients never supply calculated realised FX or functional carrying values.

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
  },
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

For functional-currency credit, source rate reference is null and transaction and functional Money are equal. For foreign credit, source functional Money is calculated once using the exact source RateRecord and frozen rounding policy. Partial consumption carries functional value within that one tranche; final consumption takes its exact remaining functional value so the tranche closes without drift.

#### 2.7 ReversalLinkage, ApprovalResponse, and Pagination

A reversal creates a new posted Allocation linked bidirectionally to the original. It never changes or deletes the original financial facts; the original state changes only from `posted` to `reversed` with an immutable linkage and incremented version. The standard durable approval response contains no originating payload. Pagination uses signed opaque cursors binding entity, normalized filters, ordering, and a fixed read boundary.

```json
{
  "reversal_linkage":{"original_allocation_id":"1204d0d4-3d0a-4c16-83ec-99f39802714c","reversal_allocation_id":"4aa6dc20-f314-40da-ae3e-0e66e1496da2","reversed_at":"2026-07-21T09:00:00Z"},
  "approval_response":{"approval":{"id":"e491d07a-365e-4318-9991-107889b48595","status":"pending","command":"CONFIGURED_SETTLEMENT_COMMAND","resource_id":null,"maker_id":"8e1cc916-3312-4f15-a1d7-9b46ab95722d","entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee","version":1,"submitted_at":"2026-07-20T10:00:00Z"}},
  "page":{"limit":50,"next_cursor":"SIGNED_OPAQUE_CURSOR"}
}
```

### 3. Receipt and Payment Endpoints

#### 3.1 `POST /v1/receipts`

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

#### 3.2 `POST /v1/payments`

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

### 4. Party Credit Endpoints

#### 4.1 `POST /v1/credits/{party}/apply`

**Purpose and authorization:** Apply existing unapplied party credit to one or many open documents without bank movement or new withholding. Authentication plus `settlement.credits.apply` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id` and `Idempotency-Key` are required. No `If-Match` header applies; every selected `credit_sources` entry requires its own `expected_version`, and every document link requires `expected_version`. Correlation follows §1; pagination does not apply. Configured policy may return `202`; no threshold is defined.

**Request:** Required fields are `party_type`, `currency`, `application_date`, nonempty `credit_sources`, and nonempty `allocations`. Every allocation line names exactly one selected `credit_tranche_id`; totals grouped by tranche equal the selected source amount. `party_type` is `customer` or `vendor`; Customer credit targets only invoices and Vendor credit targets only bills. No bank account, withholding, settlement rate, gross, bank, unapplied, client-calculated functional amount, or client-calculated realised FX field is accepted. Unknown fields are rejected.

**Validation:** The party is active and entity-owned. Every tranche belongs to the entity, party, party type, and request currency; IDs are unique; selected amounts are positive and do not exceed the named remainder; every source version matches transactionally. The sum of source amounts equals the sum of document allocations. Documents match the party/currency and pass versioned open-balance checks. For functional currency no realised FX is calculated. For foreign currency, the FX-owned internal contract compares each tranche's immutable source RateRecord carrying baseline with the target document's immutable RateRecord. Period and posting configuration resolve.

**Response and stable errors:** `201` returns `allocation`, `consumed_credit_sources`, and applicable `realised_fx_results`. Additional rules are `credit_tranche_not_found` (`404`), `credit_tranche_currency_mismatch`, `credit_tranche_party_mismatch`, `insufficient_credit_tranche_balance`, `over_allocation`, `document_party_mismatch`, `invalid_document_state`, `missing_credit_rate_reference`, `credit_fx_calculation_failed`, `missing_posting_configuration`, and `unbalanced_settlement` as `422 invariant_violation` details unless stated otherwise. A missing source version returns `428 precondition_required`; a stale source version returns `409 concurrency_conflict` with rule `credit_tranche_concurrency_conflict` and `required_version`. Common errors apply.

**Audit and outbox:** Exact tranche consumptions, projection changes, document applications, balanced posting, FX result/reference, allocation record, audit, idempotency, `CreditApplied` v2, applicable `RealisedFXRecognised`, document status events, and Ledger events commit atomically. No bank, withholding, `ReceiptAllocated`, or `PaymentAllocated` event is produced.

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

The example consumes USD 15 with BDT 1,550 carrying value and clears BDT 1,350 of receivable carrying value. The internal FX result is BDT 200 gain, so the BDT 1,550 customer-credit debit equals BDT 1,350 receivable credit plus BDT 200 FX-gain credit.

#### 4.2 `POST /v1/credits/{party}/refund`

**Purpose and authorization:** Refund some or all available party credit through an entity-owned bank/cash account. Authentication plus `settlement.credits.refund` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id` and `Idempotency-Key` are required. No `If-Match` header applies; every selected `credit_sources` entry requires its own `expected_version`. Correlation follows §1; pagination does not apply. Configured policy may return `202`; no threshold is defined.

**Request:** Required fields are `party_type`, `refund_date`, `bank_account_id`, positive `refund_amount`, `expected_available_balance`, and nonempty `credit_sources`. The sum of selected source amounts equals `refund_amount`. Nullable `rate_record_id` is nonnull only when refund currency differs from functional currency. No document allocation, withholding, client-calculated functional amount, or client-calculated realised FX is accepted. Unknown fields are rejected.

**Validation:** Party, bank, currency, entity, every tranche, and expected available projection agree. Tranche IDs are unique; selected amounts are positive and do not exceed named remainders; every source version matches transactionally. Functional currency rejects a RateRecord. For foreign currency, each tranche source RateRecord is the carrying baseline and the exact refund RateRecord is the comparison rate; the FX-owned internal contract calculates realised FX per tranche. Period, posting, Numbering, FX, rounding, and account configuration resolve. No rate or approval threshold is inferred.

**Response and stable errors:** `201` returns the refund `allocation`, `consumed_credit_sources`, and applicable `realised_fx_results`. The tranche/version errors in §4.1 apply, plus `credit_balance_mismatch`, `missing_rate_reference`, `invalid_rate_reference`, `missing_numbering_configuration`, `missing_posting_configuration`, and `unbalanced_settlement`; common errors apply.

**Audit and outbox:** Exact tranche consumptions, projection changes, bank posting, FX results/references, audit, idempotency, `CreditRefunded` v2, applicable `RealisedFXRecognised`, and Ledger events commit atomically. It creates no document mutation, new withholding, or cash-receipt/payment allocation event.

```json
{
  "request":{
    "party_type":"customer",
    "refund_date":"2026-07-22",
    "bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22",
    "refund_amount":{"amount":"10.0000","currency":"USD"},
    "expected_available_balance":{"amount":"25.0000","currency":"USD"},
    "rate_record_id":"670aa770-074c-4556-9c09-f81ea68cfe96",
    "credit_sources":[{"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","amount":{"amount":"10.0000","currency":"USD"},"expected_version":2}]
  },
  "response":{
    "allocation":{"id":"79331f48-c7b1-4330-b154-ed9b0b91b82c","operation":"credit_refund","state":"posted","version":1},
    "consumed_credit_sources":[{"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","amount":{"amount":"10.0000","currency":"USD"},"functional_amount":{"amount":"1000.0000","currency":"BDT"},"remaining_amount":{"amount":"0.0000","currency":"USD"},"remaining_functional_amount":{"amount":"0.0000","currency":"BDT"},"version":3}],
    "realised_fx_results":[{"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","source_functional_amount":{"amount":"1000.0000","currency":"BDT"},"comparison_functional_amount":{"amount":"1200.0000","currency":"BDT"},"realised_fx":{"amount":"-200.0000","currency":"BDT"},"classification":"loss","source_rate_record_id":"c30a6168-6cd8-41f7-be30-b604fc47d06c","comparison_rate_record_id":"670aa770-074c-4556-9c09-f81ea68cfe96"}]
  }
}
```

The example consumes USD 10 carrying BDT 1,000 and refunds bank cash valued at BDT 1,200. The BDT 1,000 customer-credit debit plus BDT 200 FX-loss debit equals the BDT 1,200 bank credit.

#### 4.3 `GET /v1/credits/{party}`

**Purpose and authorization:** Return per-currency available unapplied balances and immutable source tranches for one party. Authentication plus `settlement.credits.read` is required.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id` is required; correlation follows §1. Idempotency, `If-Match`, and approval do not apply. Optional `limit` defaults to 50 and is 1–100; optional `cursor` continues the signed snapshot.

**Request:** Required query field `party_type` is `customer` or `vendor`. Optional fields are `currency`, `limit`, and `cursor`; all others are rejected. Currency filters both balances and tranches. The path party and type must resolve to one active or deactivated entity-owned master. Cross-entity and unknown resources return `404`.

**Response and ordering:** `200` returns rebuildable `party_credit` balances and paginated immutable `credit_tranches`. Without `currency`, it returns one balance per currency and never sums currencies. Tranches order by `created_at DESC, credit_tranche_id DESC`. Cursor contents bind entity, party, type, currency, ordering, and read boundary. Invalid or altered cursors return `400 validation`.

**Audit and outbox:** This read creates no business audit or outbox event unless an already-configured read-audit policy requires access logging.

```json
{
  "request_query":{"party_type":"customer","currency":"USD","limit":50,"cursor":null},
  "response":{
    "party_credit":{"party_type":"customer","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","balances":[{"available_balance":{"amount":"25.0000","currency":"USD"},"functional_carrying_balance":{"amount":"2650.0000","currency":"BDT"}}],"projection_version":6},
    "credit_tranches":[{"credit_tranche_id":"a72ea97c-9ea4-45f1-a285-d365195069fc","currency":"USD","remaining_amount":{"amount":"20.0000","currency":"USD"},"remaining_functional_amount":{"amount":"2200.0000","currency":"BDT"},"source_exchange_rate_reference":{"rate_record_id":"8e6dc9a0-ce52-4451-943d-e94c333809a7","base_currency":"USD","quote_currency":"BDT","rate":"110.00000000","effective_date":"2026-07-15"},"version":1}],
    "page":{"limit":50,"next_cursor":null}
  }
}
```

### 5. Allocation Endpoints

#### 5.1 `POST /v1/allocations/{id}/reverse`

**Purpose and authorization:** Create a linked posted reversal of one posted Allocation. Authentication plus `settlement.allocations.reverse` is required. The original is never edited except for its state, version, and immutable reversal linkage; neither record is deleted.

**Headers, idempotency, concurrency, correlation, pagination, and approval:** `X-Entity-Id`, `Idempotency-Key`, and original Allocation `If-Match` are required. Correlation follows §1; pagination does not apply. Reversal uses the configured durable approval policy and may return `202`; no approval threshold or bypass is defined.

**Request:** Body is empty. Unknown fields are rejected. The service reads the original immutable CreditConsumption records and current versions of the exact source tranches; the client cannot select replacement tranches, rates, transaction values, or functional values. All optimistic guards are applied inside the transaction; a conflict returns `409 concurrency_conflict` without substituting a source or retrying against semantically different balances.

**Validation:** The original is posted, entity-owned, not previously reversed, and reversible in the requested execution period under frozen Period rules. The reversal uses original amounts, allocation links, withholding snapshots/configuration, document, settlement, source, and comparison RateRecord references, and posting linkage. Each original credit consumption is restored once to the exact same CreditTranche with its recorded transaction and functional values. It restores document open amounts and the PartyCreditBalance projection consistently without over-opening beyond the original document total.

**Response and stable errors:** `201` returns `original_allocation`, `reversal_allocation`, `restored_credit_sources` when applicable, and `reversal_linkage`. Additional rules are `allocation_already_reversed`, `reversal_not_allowed`, `credit_tranche_concurrency_conflict`, `missing_posting_configuration`, and `unbalanced_reversal` as `422 invariant_violation` details except the concurrency rule, which is `409`; common errors apply. Transactional uniqueness permits only one reversal for an Allocation and one restoration for each original consumption even under concurrency.

**Audit and outbox:** Reversal Allocation, original linkage/state, restored document balances/statuses, exact same-tranche restoration facts and projection changes, reversing Ledger/FX effects, immutable audit, idempotency, `AllocationReversed` v2, applicable document status and credit events, and Ledger reversal events commit atomically. Original `ReceiptAllocated`, `PaymentAllocated`, withholding, and realised-FX events are not re-emitted.

```json
{
  "request":{},
  "response":{
    "original_allocation":{"id":"bc35144f-1813-4760-8e12-9432695b6910","state":"reversed","version":2},
    "reversal_allocation":{"id":"4aa6dc20-f314-40da-ae3e-0e66e1496da2","operation":"reversal","state":"posted","version":1},
    "restored_credit_sources":[
      {"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","restored_amount":{"amount":"10.0000","currency":"USD"},"restored_functional_amount":{"amount":"1000.0000","currency":"BDT"},"source_rate_record_id":"c30a6168-6cd8-41f7-be30-b604fc47d06c","comparison_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","original_consumption_id":"819bc9d0-5515-4800-b2fc-93733344fc58","new_version":4},
      {"credit_tranche_id":"a72ea97c-9ea4-45f1-a285-d365195069fc","restored_amount":{"amount":"5.0000","currency":"USD"},"restored_functional_amount":{"amount":"550.0000","currency":"BDT"},"source_rate_record_id":"8e6dc9a0-ce52-4451-943d-e94c333809a7","comparison_rate_record_id":"0bec8435-6c17-445c-b10b-3fbd3a19b8e7","original_consumption_id":"b585413d-d07f-4a57-b906-4b3e66e15faa","new_version":3}
    ],
    "reversal_linkage":{"original_allocation_id":"bc35144f-1813-4760-8e12-9432695b6910","reversal_allocation_id":"4aa6dc20-f314-40da-ae3e-0e66e1496da2","reversed_at":"2026-07-23T09:00:00Z"}
  }
}
```

#### 5.2 `GET /v1/allocations`

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

### 6. Internal Contracts and Transaction Guarantees

`GetOpenReceivable`, `GetOpenPayable`, and versioned `ApplySettlement` remain Receivables/Payables-owned internal contracts. Settlement supplies the expected document version; the owning service conditionally changes only open balance, status, and version. No Settlement component reads or writes Receivables or Payables tables directly.

Applicable RateRecord lookup and realised-FX calculation remain FX-owned internal contracts. A foreign credit application supplies each selected CreditTranche's immutable source RateRecord carrying baseline and the target document's immutable RateRecord as comparison. A foreign credit refund supplies each selected tranche source rate and the exact refund RateRecord as comparison. Settlement persists the returned result and references; clients never supply calculated realised FX or functional carrying values.

Withholding determination and TaxSnapshot/configuration validation remain Tax-owned internal contracts. Posting remains Ledger-owned `PostingService`; Numbering remains the approved atomic shared-kernel contract; postability remains Period-owned. No internal service is exposed through this proposal.

For every successful command, Settlement state, document applications, exact CreditTranche source/consumption/restoration facts, PartyCreditBalance projection changes, numbering result, balanced Ledger posting, audit, idempotency result, and outbox messages commit in the approved single Unit of Work. A technical error, stale document or tranche, invalid configuration, failed posting, failed number draw, or failed internal valuation rolls back all business effects. Approval execution failure follows the frozen recoverable pending lifecycle.

### 7. Exclusions and Configurable Policy Boundaries

This proposal excludes Credit Notes, Debit Notes, full Period Close, bank reconciliation, migration, ageing and later reporting, cash forecasting, automatic matching, receipts/payments from external banks, and every M4/M5 endpoint.

It defines no withholding rate or legal treatment, TaxPack value, FX source, currency precision, rounding mode, bank mapping, AR/AP/credit/withholding/FX account mapping, numbering format, sequence policy, approval threshold, reversal approval policy, period policy, automatic allocation policy, or credit-source selection policy. FIFO, LIFO, weighted-average, pro-rata, automatic source selection, and shortcuts remain prohibited. Missing, ambiguous, inactive, or non-unique required configuration fails safely with a stable `422 invariant_violation` rule and no partial business mutation.

There are no unresolved governance decisions in this proposal. The positive-value settlement equations in §2.1 are explicit, party type is explicit on otherwise ambiguous credit operations, foreign conversion is reference-only, and all approval thresholds and legal/configuration values remain external policy dependencies.

## 12. Approved Amendment — M4 Corrections, Notes and Period Close Foundations

### 12.1 Scope and inherited protocol

This contract freezes **M4 — Corrections, Notes and Period Close Foundations**, with conceptual slices M4A Notes and Corrections and M4B Period Lifecycle and Close-Gate Foundations. It defines exactly 25 public endpoints: nine Credit Note, nine Debit Note, two document void/correction, and five Period endpoints. It implements no M5/M6 public endpoint.

All endpoints inherit the frozen M0–M3 protocol without redefinition. Authentication and TLS are required. `X-Entity-Id` is required and entity access is default-deny; unknown or cross-entity resources return `404 not_found`. Missing `X-Correlation-Id` is generated; a malformed supplied value returns `400 validation`. Every command requires UUID `Idempotency-Key`; exact replay returns the original response with `Idempotent-Replay: true`, while different canonical input returns `409 idempotency_conflict`. Every command marked `W1` also requires `If-Match`; missing is `428 precondition_required`, stale is `409 concurrency_conflict`. Unknown request/query fields return `400 validation`. Errors, exact decimal Money, TaxSnapshot, ExchangeRateReference, approval resources, UUIDs, UTC timestamps, and signed cursors are the inherited schemas.

Header profiles are: `R` = `Authorization`, `X-Entity-Id`, optional `X-Correlation-Id`; `W0` adds required `Idempotency-Key`; `W1` adds required `Idempotency-Key` and `If-Match`. Configured approval returns the frozen `202` approval resource and no originating business mutation. Four-eyes is mandatory for Hard Close and Reopen. Note, void, and reversal approval follows configured policy without a hardcoded threshold.

### 12.2 Shared note schemas and accounting rules

Money uses exact decimal strings:

```json
{"amount":"115.0000","currency":"BDT"}
```

A Credit Note has fixed direction `party_type="customer"`, `document_type="invoice"`; a Debit Note has `party_type="vendor"`, `document_type="bill"`. Mismatch returns `422 invariant_violation` with `details.rule="note_direction_mismatch"`. Note state is `draft`, `posted`, or `reversed`. Drafts are editable; Posted notes, line facts, TaxSnapshots, and RateRecord references are immutable.

For each Posted non-reversed note, all values are non-negative Money in note currency and:

`posted_amount = applied_amount + refunded_amount + held_remaining_amount + undisposed_amount`.

- `posted_amount`: original immutable posted note amount.
- `applied_amount`: cumulative amount currently applied to eligible documents, net of linked reversals.
- `refunded_amount`: cumulative amount refunded, net of linked reversals.
- `held_remaining_amount`: current unconsumed balance of CreditTranches created from this note.
- `undisposed_amount`: posted value not applied, transferred into a CreditTranche, or refunded.

Historical hold facts use `transferred_amount`; cumulative historical held value is not a current-state balance. Hold moves exact value from undisposed to held remaining. Applying/refunding a note-owned CreditTranche moves exact value from held remaining to applied/refunded. Direct application moves undisposed to applied. A linked reversal restores exact value to its immediately preceding category using original references and values. No category may become negative.

```json
{
  "note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","note_kind":"credit_note","party_type":"customer","document_type":"invoice","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","source_document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","document_number":"CN-CONFIGURED","note_date":"2026-07-21","currency":"BDT","reason_code":"CONFIGURED_REASON","posted_amount":{"amount":"115.0000","currency":"BDT"},"applied_amount":{"amount":"60.0000","currency":"BDT"},"refunded_amount":{"amount":"25.0000","currency":"BDT"},"held_remaining_amount":{"amount":"15.0000","currency":"BDT"},"undisposed_amount":{"amount":"15.0000","currency":"BDT"},"exchange_rate_reference":null,"state":"posted","version":5,"reversal":null}
}
```

The approved Customer Credit Note example reconciles BDT `115.0000 = 60.0000 + 25.0000 + 15.0000 + 15.0000`. The approved Vendor Debit Note example reconciles BDT `230.0000 = 100.0000 + 30.0000 + 50.0000 + 50.0000`. Reversing the Customer refund restores BDT 25.0000 from refunded to held remaining (`115.0000 = 60.0000 + 0.0000 + 40.0000 + 15.0000`); reversing the preceding hold then restores BDT 40.0000 from held remaining to undisposed (`115.0000 = 60.0000 + 0.0000 + 0.0000 + 55.0000`).

Clients submit no TaxSnapshot, numeric rate, functional carrying value, realised FX, calculated total, number, state, version, or disposition balance. A note line requires unique `source_line_id`, positive Money `net_amount`, and optional description. The owning context copies the source line TaxSnapshot and source document's exact RateRecord. Foreign disposition uses FX-owned calculation. Posting uses Ledger `PostingService`; number allocation uses Numbering; postability uses Period; tranche operations use M3 contracts. Every successful command commits all business state, posting/FX/Tax/tranche/document effects, audit, idempotency, and outbox atomically; failure commits none, except the already-frozen recoverable approval failed-attempt audit.

No operation selects a tranche or target automatically. FIFO, LIFO, weighted-average, pro-rata, source substitution, and automatic document matching are prohibited. Note concurrency uses `If-Match`, each affected document `expected_version`, and each relevant CreditTranche `expected_version`.

### 12.3 Credit Note endpoints

#### 12.3.1 `POST /v1/credit-notes`

Creates an editable customer/invoice correction Draft. Capability `receivables.credit_notes.create`; headers `W0`. Required: `party_type`, `document_type`, `party_id`, `source_document_id`, `source_document_expected_version`, `note_date`, configured `reason_code`, nonempty `lines`; nullable `narrative` optional. Source must be an entity-owned issued non-void invoice; party/currency/lines agree; prior effective corrections plus this draft cannot exceed source facts. Fiscal-period membership is checked without requiring postability. `201` returns Draft, provisional token, null number, zero disposition amounts, version 1. Stable rules include `invalid_source_document`, `source_document_version_conflict`, `correction_exceeds_source`, `missing_tax_configuration`, `note_direction_mismatch`. Draft, redacted audit, idempotency, and `CreditNoteCreated` v1 are atomic; no number/posting/Tax financial event.

```json
{"request":{"party_type":"customer","document_type":"invoice","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","source_document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","source_document_expected_version":3,"note_date":"2026-07-21","reason_code":"CONFIGURED_REASON","narrative":"Partial service correction","lines":[{"source_line_id":"9f38c020-cfb9-4904-a025-55933ab3637a","description":"Corrected service scope","net_amount":{"amount":"100.0000","currency":"BDT"}}]},"response":{"credit_note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","document_number":null,"state":"draft","version":1}}}
```

#### 12.3.2 `PATCH /v1/credit-notes/{id}`

Replaces approved Draft fields. Capability `receivables.credit_notes.create`; headers `W1`. At least one of party/source IDs, source expected version, note date, reason, nullable narrative, or complete lines is required; collections replace, never merge. Fixed direction values may only repeat their canonical values. Result must pass creation validation. Only Draft changes; otherwise `422 invariant_violation` rule `note_not_draft`. `200` returns incremented version. Conditional update, before/after audit, and idempotency are atomic; no financial event.

```json
{"request":{"reason_code":"CONFIGURED_REASON","narrative":"Revised correction narrative","source_document_expected_version":3},"response":{"credit_note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","state":"draft","version":2}}}
```

#### 12.3.3 `GET /v1/credit-notes/{id}`

Returns complete entity-scoped note, lines, five-field disposition summary, applications, held-source refs, valuation/posting refs, and reversal link. Capability `receivables.credit_notes.read`; headers `R`; no body/query/idempotency/concurrency/approval/pagination. `200` or `404`; no business audit/outbox.

```json
{"response":{"credit_note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","party_type":"customer","document_type":"invoice","source_document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","state":"posted","version":5,"lines":[],"applications":[],"held_credit_sources":[],"journal_entry_ids":["be44f14a-2873-4d47-84b7-2ba6545925f8"]}}}
```

#### 12.3.4 `GET /v1/credit-notes`

Stable search. Capability `receivables.credit_notes.read`; headers `R`. Optional `party`, `source_document`, `state`, `reason_code`, `from`, `to`, `limit`, `cursor`; no others. Inclusive valid date range. Order `note_date DESC, id DESC`; signed cursor binds entity, normalized filters, order tuple, and fixed read boundary. Returns `credit_notes` and inherited `page`; no business audit/outbox.

```json
{"request_query":{"state":"posted","from":"2026-07-01","to":"2026-07-31","limit":50,"cursor":null},"response":{"credit_notes":[{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","document_number":"CN-CONFIGURED","party_id":"2d692c41-a3b1-4d2f-8946-fbb56491d00b","note_date":"2026-07-21","posted_amount":{"amount":"115.0000","currency":"BDT"},"undisposed_amount":{"amount":"15.0000","currency":"BDT"},"state":"posted","version":5}],"page":{"limit":50,"next_cursor":null}}}
```

#### 12.3.5 `POST /v1/credit-notes/{id}/post`

Numbers, freezes, recognizes note-period VAT correction, and posts the balanced correction. Capability `receivables.credit_notes.post`; headers `W1`; empty body; configured approval may return `202`. Complete Draft, unchanged source version, postable date, Tax/Rate/rounding/Numbering/account mappings are required. Customer posting debits configured revenue/contra and output-VAT effects and credits configured Customer Credits control. `201` returns Posted note, number, immutable values, full undisposed amount, incremented version, journal IDs. Rules: `note_not_draft`, `correction_exceeds_source`, `missing_numbering_configuration`, `missing_posting_configuration`, `missing_tax_configuration`, `missing_rate_reference`, `unbalanced_note_posting`; locked period is `423 period_locked`. Numbering, note, posting, audit, idempotency, `CreditNoteIssued` v2, Tax, and Ledger events are atomic.

```json
{"request":{},"response":{"credit_note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","document_number":"CN-CONFIGURED","posted_amount":{"amount":"115.0000","currency":"BDT"},"applied_amount":{"amount":"0.0000","currency":"BDT"},"refunded_amount":{"amount":"0.0000","currency":"BDT"},"held_remaining_amount":{"amount":"0.0000","currency":"BDT"},"undisposed_amount":{"amount":"115.0000","currency":"BDT"},"state":"posted","version":2}}}
```

#### 12.3.6 `POST /v1/credit-notes/{id}/apply`

Applies undisposed value or named held tranches to one or more open invoices. Capability `receivables.credit_notes.apply`; headers `W1`; configured approval may return `202`. Required: `application_date`, `source` (`undisposed` or `held`), nonempty `allocations`; each allocation has document UUID, positive Money, `expected_version`. Held source additionally requires allocation tranche IDs and nonempty `credit_sources` with unique tranche UUID, exact positive amount, and `expected_version`; all sums match. Undisposed source rejects tranche fields. Documents must be eligible same-party/entity/currency invoices with sufficient versioned open balance. `201` returns note, applications, resulting document versions, consumed sources, server FX results. Rules include `note_not_posted`, `note_reversed`, `insufficient_note_remaining`, `insufficient_held_credit`, `over_allocation`, `document_party_mismatch`, `credit_tranche_concurrency_conflict`, `missing_rate_reference`, `credit_fx_calculation_failed`, `423 period_locked`. All note/document/tranche/posting/FX/audit/idempotency/outbox effects are atomic.

```json
{"request":{"application_date":"2026-07-22","source":"undisposed","allocations":[{"document_id":"89d72a92-c4f2-4805-89cc-4f4e99ab5598","amount":{"amount":"60.0000","currency":"BDT"},"expected_version":2}]},"response":{"credit_note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","applied_amount":{"amount":"60.0000","currency":"BDT"},"undisposed_amount":{"amount":"55.0000","currency":"BDT"},"version":3},"applications":[{"document_id":"89d72a92-c4f2-4805-89cc-4f4e99ab5598","amount":{"amount":"60.0000","currency":"BDT"},"version_after":3}],"consumed_credit_sources":[],"realised_fx_results":[]}}
```

#### 12.3.7 `POST /v1/credit-notes/{id}/hold`

Moves undisposed value into immutable M3 customer CreditTranches. Capability `receivables.credit_notes.hold`; headers `W1`; configured approval may return `202`. Requires `hold_date` and positive Money `amount`; client rate/functional value rejected. Note must be Posted/unreversed; amount cannot exceed undisposed; date/config/rate must resolve. `201` returns updated note and created source refs. Rules: `insufficient_note_remaining`, `missing_rate_reference`, `missing_credit_configuration`, `423 period_locked`. Note disposition, CreditTranche/projection, audit, idempotency, `CreditNoteHeld`, and `CreditHeld` v2 are atomic; no Ledger movement.

```json
{"request":{"hold_date":"2026-07-22","amount":{"amount":"40.0000","currency":"BDT"}},"response":{"credit_note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","held_remaining_amount":{"amount":"40.0000","currency":"BDT"},"undisposed_amount":{"amount":"15.0000","currency":"BDT"},"version":4},"credit_sources":[{"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","amount":{"amount":"40.0000","currency":"BDT"},"functional_amount":{"amount":"40.0000","currency":"BDT"},"source_rate_record_id":null,"version":1}]}}
```

#### 12.3.8 `POST /v1/credit-notes/{id}/refund`

Refunds named held note tranches through M3 Settlement. Capability `receivables.credit_notes.refund`; headers `W1`; configured approval may return `202`. Required: `refund_date`, bank account UUID, positive `refund_amount`, `expected_available_balance`, nonempty `credit_sources`; exact refund `rate_record_id` is nullable/foreign-required. Each unique source belongs to this note and carries amount and `expected_version`; sums equal refund. Direct refund of undisposed value is prohibited. `201` returns note, Settlement allocation, consumed sources, server FX results. Rules include `insufficient_held_credit`, `credit_balance_mismatch`, tranche conflicts, `missing_rate_reference`, `invalid_rate_reference`, `missing_posting_configuration`, `unbalanced_refund`, `423 period_locked`. All note/tranche/projection/bank/FX/Settlement/audit/idempotency/events are atomic.

```json
{"request":{"refund_date":"2026-07-23","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","refund_amount":{"amount":"25.0000","currency":"BDT"},"expected_available_balance":{"amount":"40.0000","currency":"BDT"},"rate_record_id":null,"credit_sources":[{"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","amount":{"amount":"25.0000","currency":"BDT"},"expected_version":1}]},"response":{"credit_note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","held_remaining_amount":{"amount":"15.0000","currency":"BDT"},"refunded_amount":{"amount":"25.0000","currency":"BDT"},"version":5},"allocation":{"id":"79331f48-c7b1-4330-b154-ed9b0b91b82c","operation":"credit_refund","state":"posted"},"realised_fx_results":[]}}
```

#### 12.3.9 `POST /v1/credit-notes/{id}/reverse`

Creates one linked immutable full reversal. Capability `receivables.credit_notes.reverse`; headers `W1`; configured approval and maker/original-poster separation apply. Requires reversal date, configured reason, nonblank narrative, complete `document_versions`, and complete `credit_source_versions`; empty arrays only when none apply. Server derives immutable impact graph; financial reversal values/replacement refs are rejected. Current/reopened approved adjustment period is required, with original-period link. Downstream activity preventing exact restoration returns `422 note_reversal_blocked_by_downstream_activity`. Other rules: `note_not_posted`, `note_already_reversed`, `incomplete_reversal_versions`, `unbalanced_reversal`, `423 period_locked`. `201` returns Reversed note, reversal link, restored documents/tranches, journal IDs; uniqueness and every restoration/posting/audit/event are atomic.

```json
{"request":{"reversal_date":"2026-07-24","reason_code":"CONFIGURED_REASON","narrative":"Approved full correction reversal","document_versions":[{"document_id":"89d72a92-c4f2-4805-89cc-4f4e99ab5598","expected_version":3}],"credit_source_versions":[{"credit_tranche_id":"555a1bee-36a8-40d2-a160-83982d410a53","expected_version":2}]},"response":{"credit_note":{"id":"efeb15bc-8a19-4afd-a406-783dd225db64","state":"reversed","version":6},"reversal":{"id":"334118be-6529-42c9-b268-7db1ecad23d2","original_note_id":"efeb15bc-8a19-4afd-a406-783dd225db64","reversal_date":"2026-07-24"},"journal_entry_ids":["4609984a-a77e-4ccb-9a56-8d87e4f05efb"]}}
```

### 12.4 Debit Note endpoints

Debit Note contracts mirror §12.3 only where directionally valid: vendor/bill direction, Payables capabilities, eligible bills, vendor CreditTranches, AP/vendor-credit control postings, and vendor cash refunds. They inherit every unknown-field, approval, error, version, exact-reference, no-selection, audit/outbox, and atomicity rule stated for their Credit Note counterpart.

#### 12.4.1 `POST /v1/debit-notes`

Creates editable vendor/bill correction Draft. Capability `payables.debit_notes.create`; headers `W0`. Schema and results mirror §12.3.1 with fixed vendor/bill direction; `201` version 1; emits `DebitNoteCreated` v1 without financial effect.

```json
{"request":{"party_type":"vendor","document_type":"bill","party_id":"8bdf810a-f3e5-4078-ac85-9a762543ed0d","source_document_id":"ee3195b1-2b7b-411f-92fa-67c1ce9350f2","source_document_expected_version":2,"note_date":"2026-07-21","reason_code":"CONFIGURED_REASON","narrative":"Vendor price correction","lines":[{"source_line_id":"12a6388c-a6b3-42d0-8e64-2efcbd8b08a7","net_amount":{"amount":"200.0000","currency":"BDT"}}]},"response":{"debit_note":{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","document_number":null,"state":"draft","version":1}}}
```

#### 12.4.2 `PATCH /v1/debit-notes/{id}`

Replaces approved fields on Draft only. Capability `payables.debit_notes.create`; headers `W1`. Contract mirrors §12.3.2; `200` increments version; `note_not_draft` otherwise; no financial event.

```json
{"request":{"narrative":"Revised vendor correction","source_document_expected_version":2},"response":{"debit_note":{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","state":"draft","version":2}}}
```

#### 12.4.3 `GET /v1/debit-notes/{id}`

Returns complete entity-scoped detail and immutable disposition refs. Capability `payables.debit_notes.read`; headers `R`; contract mirrors §12.3.3.

```json
{"response":{"debit_note":{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","party_type":"vendor","document_type":"bill","source_document_id":"ee3195b1-2b7b-411f-92fa-67c1ce9350f2","state":"posted","version":2,"lines":[],"applications":[],"held_credit_sources":[]}}}
```

#### 12.4.4 `GET /v1/debit-notes`

Stable search. Capability `payables.debit_notes.read`; headers `R`; filters and cursor contract mirror §12.3.4; order `note_date DESC, id DESC`.

```json
{"request_query":{"state":"posted","limit":50,"cursor":null},"response":{"debit_notes":[{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","document_number":"DN-CONFIGURED","party_id":"8bdf810a-f3e5-4078-ac85-9a762543ed0d","note_date":"2026-07-21","posted_amount":{"amount":"230.0000","currency":"BDT"},"undisposed_amount":{"amount":"230.0000","currency":"BDT"},"state":"posted","version":2}],"page":{"limit":50,"next_cursor":null}}}
```

#### 12.4.5 `POST /v1/debit-notes/{id}/post`

Posts vendor correction with number, immutable valuation, note-period Tax effect, and balanced Ledger effects. Capability `payables.debit_notes.post`; headers `W1`; empty body; configured approval may return `202`. Mirrors §12.3.5 with Payables mappings: debit configured Vendor Credits control, credit copied expense/asset and input-VAT effects. `201`; emits `DebitNoteIssued` v2 plus Tax/Ledger events atomically.

```json
{"request":{},"response":{"debit_note":{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","document_number":"DN-CONFIGURED","posted_amount":{"amount":"230.0000","currency":"BDT"},"applied_amount":{"amount":"0.0000","currency":"BDT"},"refunded_amount":{"amount":"0.0000","currency":"BDT"},"held_remaining_amount":{"amount":"0.0000","currency":"BDT"},"undisposed_amount":{"amount":"230.0000","currency":"BDT"},"state":"posted","version":3}}}
```

#### 12.4.6 `POST /v1/debit-notes/{id}/apply`

Applies undisposed value or named held vendor tranches to eligible open bills. Capability `payables.debit_notes.apply`; headers `W1`; approval possible. Schema/guards/results mirror §12.3.6; posting debits AP and credits Vendor Credits control. Emits `DebitNoteApplied` and, for held sources, M3 `CreditApplied` v2.

```json
{"request":{"application_date":"2026-07-22","source":"undisposed","allocations":[{"document_id":"a3eb17da-4050-4d81-908f-f0ccb63b17f3","amount":{"amount":"100.0000","currency":"BDT"},"expected_version":2}]},"response":{"debit_note":{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","applied_amount":{"amount":"100.0000","currency":"BDT"},"undisposed_amount":{"amount":"130.0000","currency":"BDT"},"version":4},"applications":[{"document_id":"a3eb17da-4050-4d81-908f-f0ccb63b17f3","amount":{"amount":"100.0000","currency":"BDT"},"version_after":3}]}}
```

#### 12.4.7 `POST /v1/debit-notes/{id}/hold`

Moves undisposed value into immutable vendor CreditTranches. Capability `payables.debit_notes.hold`; headers `W1`; approval possible. Mirrors §12.3.7 with vendor party type and emits `DebitNoteHeld` plus M3 `CreditHeld` v2. No Ledger movement.

```json
{"request":{"hold_date":"2026-07-22","amount":{"amount":"80.0000","currency":"BDT"}},"response":{"debit_note":{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","held_remaining_amount":{"amount":"80.0000","currency":"BDT"},"undisposed_amount":{"amount":"50.0000","currency":"BDT"},"version":5},"credit_sources":[{"credit_tranche_id":"86836460-2222-41a0-83c1-318f45bf8596","amount":{"amount":"80.0000","currency":"BDT"},"functional_amount":{"amount":"80.0000","currency":"BDT"},"source_rate_record_id":null,"version":1}]}}
```

#### 12.4.8 `POST /v1/debit-notes/{id}/refund`

Records cash received from vendor using named held tranches. Capability `payables.debit_notes.refund`; headers `W1`; approval possible. Mirrors §12.3.8; bank is debited and Vendor Credits control credited, with server FX result. Emits `DebitNoteRefunded` plus M3 `CreditRefunded` v2 atomically.

```json
{"request":{"refund_date":"2026-07-23","bank_account_id":"29691970-092e-46e2-831a-4accbbbe6a22","refund_amount":{"amount":"30.0000","currency":"BDT"},"expected_available_balance":{"amount":"80.0000","currency":"BDT"},"rate_record_id":null,"credit_sources":[{"credit_tranche_id":"86836460-2222-41a0-83c1-318f45bf8596","amount":{"amount":"30.0000","currency":"BDT"},"expected_version":1}]},"response":{"debit_note":{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","held_remaining_amount":{"amount":"50.0000","currency":"BDT"},"refunded_amount":{"amount":"30.0000","currency":"BDT"},"version":6},"allocation":{"id":"4adc9a87-0085-472d-84c6-2d93fca66ccd","operation":"credit_refund","state":"posted"}}}
```

#### 12.4.9 `POST /v1/debit-notes/{id}/reverse`

Creates one linked full reversal restoring exact Debit Note, bill, Settlement, tranche, Tax, FX, and Ledger effects. Capability `payables.debit_notes.reverse`; headers `W1`; approval and separation apply. Request/version/impact graph/errors/results mirror §12.3.9 with bill/vendor sources and `DebitNoteReversed`.

```json
{"request":{"reversal_date":"2026-07-24","reason_code":"CONFIGURED_REASON","narrative":"Approved debit note reversal","document_versions":[{"document_id":"a3eb17da-4050-4d81-908f-f0ccb63b17f3","expected_version":3}],"credit_source_versions":[{"credit_tranche_id":"86836460-2222-41a0-83c1-318f45bf8596","expected_version":2}]},"response":{"debit_note":{"id":"376df80e-30b4-4db4-b0a8-6093b6726e50","state":"reversed","version":7},"reversal":{"id":"e2da82d3-cf75-4813-9274-d3265f48bb5c","original_note_id":"376df80e-30b4-4db4-b0a8-6093b6726e50","reversal_date":"2026-07-24"}}}
```

### 12.5 Posted-document void and correction endpoints

Draft invoices and bills remain editable through frozen M2 PATCH endpoints or may be non-destructively voided below. Draft void preserves audit history, consumes no statutory number, creates no Ledger reversal, and cannot reactivate. Issued invoices and approved bills are never edited. Safe-window void creates an immutable linked reversal; otherwise use the applicable Note/reissue workflow. Number, source facts, TaxSnapshots, and RateRecord refs are preserved.

#### 12.5.1 `POST /v1/invoices/{id}/void`

Voids a Draft directly or creates a safe-window linked reversal of an issued invoice. Capability `receivables.invoices.void`; headers `W1`; configured approval and separation apply to Posted reversal. Requires `void_date`, configured `reason_code`, nonblank `narrative`. Draft returns `201` void, null number/reversal, incremented version. Issued invoice must be unpaid, current Open period, VAT unfiled/unlocked, and have no downstream allocation, note application, or settlement; failure is `422 invariant_violation` rule `void_window_failed` with safe condition IDs. Success retains number and returns linked reversal/journal; `202` possible. One void, status/linkage/posting/audit/idempotency, `InvoiceVoided` v2, VAT/Ledger events are atomic; number is never reused.

```json
{"request":{"void_date":"2026-07-22","reason_code":"CONFIGURED_REASON","narrative":"Duplicate issued invoice"},"response":{"invoice":{"id":"b57cd935-d096-4e9b-a86f-b2bd41f61063","document_number":"INV-CONFIGURED","status":"void","version":4},"reversal":{"journal_entry_id":"6a55f27b-3064-49ea-a00d-762306385e57","source_document_id":"b57cd935-d096-4e9b-a86f-b2bd41f61063"}}}
```

#### 12.5.2 `POST /v1/bills/{id}/void`

Voids a Draft directly or creates a safe-window linked reversal of an approved bill. Capability `payables.bills.void`; headers `W1`; configured approval/SoD for Posted reversal. Request, validation, errors, response, uniqueness, numbering, immutable refs, audit, and atomicity mirror §12.5.1 with `BillVoided` v2.

```json
{"request":{"void_date":"2026-07-22","reason_code":"CONFIGURED_REASON","narrative":"Duplicate approved bill"},"response":{"bill":{"id":"ee3195b1-2b7b-411f-92fa-67c1ce9350f2","document_number":"BILL-CONFIGURED","status":"void","version":4},"reversal":{"journal_entry_id":"78a3830e-2346-45e1-81e3-0ef103ff850f","source_document_id":"ee3195b1-2b7b-411f-92fa-67c1ce9350f2"}}}
```

No Expense reversal endpoint is introduced. Existing Journal and Allocation reversal contracts are not redefined.

### 12.6 Period lifecycle endpoints

Period resource `{id}` is immutable UUID; `period_ref` is a returned/filter business key. Existing `GET /v1/periods/postable` is unchanged. States are exactly `Open`, `SoftClosed`, `HardClosed`, `Reopened`. Valid transitions are Open→SoftClosed; SoftClosed→HardClosed; HardClosed→Reopened; Reopened→SoftClosed; then fresh SoftClosed→HardClosed. No other transition or Hard Close bypass exists. Reopened permits approved adjustments only; ordinary posting never resumes. Reopen requires reason, management approval, notification, and re-close.

Hard Close locks VAT atomically. Reopen with `vat_unlock_requested=false` preserves lock; true requires an explicit jurisdiction/entity policy and changes VAT only to `unlocked_for_approved_adjustments`. Missing/denied policy returns `422` rule `vat_unlock_policy_missing`/`vat_unlock_not_permitted` without mutation. Late correction posts in a currently allowed adjustment period and appends an original-period link; no HardClosed fact is backdated or changed.

#### 12.6.1 `GET /v1/periods`

Lists entity periods and close summaries. Capability `periods.read`; headers `R`. Optional `state`, `fiscal_year`, `from`, `to`, `limit`, `cursor`; no others. Order `starts_on DESC, id DESC`; signed cursor binds entity, filters, tuple, and read boundary. Returns `periods` and inherited `page`; no business audit/outbox.

```json
{"request_query":{"state":"SoftClosed","fiscal_year":"2026","limit":50,"cursor":null},"response":{"periods":[{"id":"45124343-5a6e-48c2-821d-6685cf1fd46e","period_ref":"2026-07","starts_on":"2026-07-01","ends_on":"2026-07-31","state":"SoftClosed","vat_lock_status":"unlocked","version":2,"close_gate_summary":{"satisfied":0,"required":5}}],"page":{"limit":50,"next_cursor":null}}}
```

#### 12.6.2 `GET /v1/periods/{id}`

Returns period, immutable transition history, VAT state, and close-gate evidence metadata. Capability `periods.read`; headers `R`; no body/query/idempotency/concurrency/approval/pagination. `200` or entity-hiding `404`; no business audit/outbox.

```json
{"response":{"period":{"id":"45124343-5a6e-48c2-821d-6685cf1fd46e","period_ref":"2026-07","starts_on":"2026-07-01","ends_on":"2026-07-31","state":"SoftClosed","vat_lock_status":"unlocked","version":2,"transitions":[],"close_gates":[{"gate_type":"trial_balance_reviewed","status":"unmet","source_context":"reporting","source_reference":null,"produced_at":null,"reviewed_by":null,"reviewed_at":null,"evidence_version":null,"evidence_hash":null}]}}}
```

#### 12.6.3 `POST /v1/periods/{id}/soft-close`

Moves Open or Reopened to SoftClosed. Capability `periods.soft_close`; headers `W1`; empty body; configured approval may return `202`. Required M4 Soft Close adjustment configuration must resolve; M5/M6 Hard Close evidence is not required. `200` increments version. Rules: `invalid_period_transition`, `period_already_soft_closed`, `missing_close_configuration`. Transition/audit/idempotency/`PeriodSoftClosed` v2 are atomic; no Ledger posting or VAT lock.

```json
{"request":{},"response":{"period":{"id":"45124343-5a6e-48c2-821d-6685cf1fd46e","period_ref":"2026-07","state":"SoftClosed","vat_lock_status":"unlocked","version":2}}}
```

#### 12.6.4 `POST /v1/periods/{id}/hard-close`

Moves SoftClosed to HardClosed only after mandatory immutable evidence. Capability `periods.hard_close`; headers `W1`; empty body; mandatory four-eyes returns `202` when request is created. Baseline gates are `trial_balance_reviewed`, `profit_and_loss_approved`, `balance_sheet_approved`, `vat_outputs_approved` from M5 Reporting and `bank_reconciliation_completed` from M6 Reconciliation. Configuration may add but not remove gates. Missing provider, error, timeout, malformed/stale/changed evidence is unmet, never skipped.

Direct unmet evaluation returns `422 invariant_violation`, rule `close_gate_unmet`, safe gate types, and no ApprovalRequest, Period, VAT, Ledger, business-audit, or business-outbox mutation. Regression during approved replay leaves approval pending with only the frozen safe failed-attempt audit. Successful approved execution re-evaluates the identical set, returns `200`, and atomically copies evidence, stores its deterministic hash, changes state/VAT, writes audit/idempotency, and emits `PeriodHardClosed` v2 and `VATPeriodLocked` v2. Other rules: `invalid_period_transition`, `close_evidence_changed`, `vat_lock_policy_missing`; inherited `428/409` concurrency. No year-end roll.

```json
{"request":{},"response":{"error_code":"invariant_violation","message":"Mandatory close gates are not satisfied.","details":{"rule":"close_gate_unmet","unmet_gates":["profit_and_loss_approved","balance_sheet_approved","vat_outputs_approved","bank_reconciliation_completed"]}}}
```

```json
{"request":{},"response":{"period":{"id":"45124343-5a6e-48c2-821d-6685cf1fd46e","period_ref":"2026-07","state":"HardClosed","vat_lock_status":"locked","version":3,"close_evidence_set_hash":"CONFIGURED_SHA256","hard_closed_at":"2026-09-15T12:00:00Z","hard_closed_by":"1b8f3c2f-4e62-4fa9-a924-77848017a9a6"}}}
```

#### 12.6.5 `POST /v1/periods/{id}/reopen`

Moves HardClosed to adjustment-only Reopened. Capability `periods.reopen`; headers `W1`; mandatory four-eyes. Requires configured `reason_code`, nonblank `narrative`, boolean `vat_unlock_requested`. Creates affected-user notification, never ordinary posting. `200` after replay returns state, VAT status, re-close requirement, approval attribution; initial `202` possible. Rules: `invalid_period_transition`, `reopen_reason_required`, `vat_unlock_policy_missing`, `vat_unlock_not_permitted`. Transition, optional policy-authorized VAT unlock, retained evidence refs, notification, audit, idempotency, `PeriodReopened` v2 and optional `VATPeriodUnlocked` v1 are atomic.

```json
{"request":{"reason_code":"CONFIGURED_REASON","narrative":"Approved late adjustment required","vat_unlock_requested":false},"response":{"period":{"id":"45124343-5a6e-48c2-821d-6685cf1fd46e","period_ref":"2026-07","state":"Reopened","vat_lock_status":"locked","reclose_required":true,"version":4},"notification":{"event":"PeriodReopened","audience":"affected_entity_users"}}}
```

There is no public standalone VAT-lock endpoint. Fiscal-year roll remains outside these foundations pending a separate complete contract.

### 12.7 Internal CloseGateProvider v1 and exclusions

`CloseGateProvider` is an internal versioned Period-owned consumer contract implemented by provider adapters, never HTTP and never cross-context repository access. Input is contract version 1, entity UUID, period UUID/ref, gate type, effective correlation UUID, and fixed evaluation timestamp. Output status is `satisfied` or `unmet`, with source context/reference, production/review metadata, positive immutable evidence version or lowercase SHA-256 hash. Period validates ownership, completeness, freshness, and stability and copies the exact accepted results into Period-owned immutable evidence rows on success.

```json
{"contract_version":1,"gate_type":"profit_and_loss_approved","entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee","period_id":"45124343-5a6e-48c2-821d-6685cf1fd46e","period_ref":"2026-07","status":"unmet","source_context":"reporting","source_reference":null,"produced_at":null,"reviewed_by":null,"reviewed_at":null,"evidence_version":null,"evidence_hash":null}
```

`CloseGateFailed` is not a business event. M4 introduces no M5/M6 endpoint, Reporting/Reconciliation evidence fabrication, automatic allocation, Expense reversal, standalone VAT lock, year-end roll, legal VAT rule, tax/rate/account value, FX source, rounding policy, numbering format/reset, approval threshold, reason catalog, or notification delivery policy. Missing/ambiguous required configuration fails safely with `422 invariant_violation` and no partial effect.

---

## 13. Approved Amendment — M5 Reporting and Cash View

**Governance Approval Record:** `M5-GOV-001` (`HiveFin_Decision_Log.md`). Source: `PROPOSED_GOVERNANCE_AMENDMENT_M5_REPORTING_AND_CASH_VIEW.md`, SHA-256 `0e3c513382636fb8dc8d81778fdc8ff269d344043111c3d2edc5a3f0e221f32e`, approved for incorporation.

### 13.1 Purpose, canonical name, and sequencing

Canonical milestone name: **M5 — Reporting and Cash View**. Delivery is conceptually split without creating new roadmap milestones: **M5A — Reporting read models and financial statements** (Trial Balance, General Ledger, Profit and Loss, Balance Sheet, AR/AP Ageing, Tax/VAT Summary, FX Revaluation, the `ReportRun` evidence lifecycle, configuration providers) and **M5B — Cash View and Close-Gate Evidence** (Cash View, `CashViewPolicy`, the `ReportingCloseGateProvider`).

M5 depends on completed M1 Ledger + Valuation, M2 Documents, M3 Settlement, and M4 Corrections/Notes/Period Close. M5 supplies the evidence M4's Hard Close already knows how to consume (`config/period.close_gates`); until this amendment is implemented, those four gates correctly remain `unmet` via `UnavailableCloseGateProvider`. M5 implements no M6 Reconciliation behavior and does not satisfy `bank_reconciliation_completed`.

**Trial Balance, General Ledger, and account-balance reads remain Ledger-owned** (§7.5, §8.3.4) and are **not** relocated or rewritten by this amendment — Reporting consumes them through an explicit adapter (§13.13.4). Every other structure in this section is Reporting-owned.

### 13.2 Common protocol and shared schemas

All M5 public endpoints inherit the frozen M0–M4 protocol exactly (§1–§3, §7.1, §8.1): TLS/authentication required; `X-Entity-Id` required, default-deny, cross-entity `404 not_found`; optional `X-Correlation-Id` (generated when absent, `400 validation` on malformed); Money `{amount, currency}` exact decimal strings only; unknown fields `400 validation`; frozen error envelope `{error_code, message, details, doc_id?, required_version?}`.

Header profiles: `R` (`Authorization`, `X-Entity-Id`; optional `X-Correlation-Id`) for every `GET`; `W0` (`R` + `Idempotency-Key`) for `POST /v1/report-runs`; `W1` (`W0` + `If-Match`) for `POST /v1/report-runs/{id}/approve`.

**Every `GET` in this section is read-only** — no `Idempotency-Key`, no `If-Match`, no approval, no audit write, no outbox event, and **no business-state mutation**, including `GET /v1/report-runs/{id}/export`. Only `POST /v1/report-runs` and `POST /v1/report-runs/{id}/approve` write state, following the exact audit/idempotency/outbox pattern already used by every M2–M4 command.

Every computed report value derives from already-posted, already-immutable facts (`JournalLine`, `TaxSnapshot`, `RateRecord`, `Allocation`) plus the versioned Reporting-owned configuration in §13.6–§13.10; no report accepts a client-supplied figure, rate, classification, or bucket boundary.

### 13.3 Endpoint inventory (14 endpoints)

```
GET  /v1/reports/trial-balance?asOf=&period_ref=&sbu=&limit=&cursor=
GET  /v1/reports/general-ledger?account=&range=&sbu=&limit=&cursor=      (§7.5, unchanged; sbu and reversal_of_entry_id are additive)
GET  /v1/reports/profit-loss?period=&sbu=&basis=accrual&compare_to=      (basis accepts accrual only; basis=cash returns 422 unsupported_basis)
GET  /v1/reports/balance-sheet?asOf=&sbu=&compare_to=
GET  /v1/reports/ar-ageing?asOf=&customer=
GET  /v1/reports/ap-ageing?asOf=&vendor=
GET  /v1/reports/tax-summary?period=
GET  /v1/reports/fx-revaluation?period=
GET  /v1/reports/cash-view?period=&sbu=

POST /v1/report-runs
GET  /v1/report-runs/{id}
GET  /v1/report-runs?report_type=&period=&state=&limit=&cursor=
POST /v1/report-runs/{id}/approve
GET  /v1/report-runs/{id}/export?format=pdf|csv
```

### 13.4 Immutable Report Runs and approval evidence

A live `GET /v1/reports/*` call is a preview: it recomputes from current data and proves nothing was reviewed or approved. Satisfying a Hard Close gate (§12.6.4) requires an explicit, separately-approved, frozen snapshot — the `ReportRun` resource, reused across every report type.

**Command surface:** `POST /v1/report-runs` (generate), `POST /v1/report-runs/{id}/approve` (durable four-eyes approve), `GET /v1/report-runs/{id}` (retrieve), `GET /v1/report-runs` (list). No separate public review endpoint exists.

**Lifecycle:**

```
Generated ──(approve, no configured maker-checker)──▶ Approved ──▶ (superseded by a later Approved run for the same key)──▶ Superseded
Generated ──(approve, configured maker-checker)──▶ PendingApproval ──(durable approval commits)──▶ Approved ──▶ Superseded
                                                    PendingApproval ──(durable approval rejects)──▶ Rejected
```

- **Generated**: an immutable content snapshot exists; not yet gate-eligible.
- **PendingApproval**: reached only when the entity's Approval Policy requires maker-checker for `report_run_approve` — no hardcoded threshold.
- **Approved**: gate-eligible. `POST /v1/report-runs/{id}/approve` is **one public command**: generation and approval must be performed by different actors — **the generator (maker) must not approve their own run**, enforced exactly as every M2–M4 approval command's maker/checker separation (`403 sod_exception_required` on a same-actor attempt). The approving checker performs review and approval in the same durable action: `reviewed_by`/`reviewed_at` and `approved_by`/`approved_at` are recorded atomically from that one act and may identify the same checker.
- **Rejected**: terminal via the existing durable approval decline outcome — no separate M5 rejection endpoint. Satisfies no gate; the preparer may generate a new run.
- **Superseded**: automatic and atomic the instant a different run for the identical reproducibility key — `(entity_id, report_type, basis, period_ref-or-as_of, filters)` — reaches `Approved`. The previously `Approved` run for that key (if any) transitions to `Superseded` in the same transaction. **Only a current, `Approved`, non-`Superseded` `ReportRun` may satisfy a Close Gate** (§13.11).

**ReportRun schema:** `id` (`report_run_id`), `entity_id`, `report_type` (`trial_balance | general_ledger | profit_and_loss | balance_sheet | ar_ageing | ap_ageing | tax_summary | fx_revaluation | cash_view`), `period_ref`/`as_of`/`range` (exactly the field the report type needs), `basis` (`accrual` for every type except Cash View, always `cash`), `functional_currency`, `filters`, `layout_version` (P&L/BS), `classification_version` (P&L/BS), `policy_version` (Cash View → `CashViewPolicy`; AR/AP ageing → `AgeingBucketSet`), `source_data_watermark`, `content` (JSONB, byte-identical to the corresponding live `GET`), `content_hash` (lowercase SHA-256), `generated_by`/`generated_at`, `reviewed_by`/`reviewed_at`, `approved_by`/`approved_at`, `state`, `version`, `superseded_by_report_run_id`.

The **source-data watermark** is the maximum `posted_at` of any qualifying posted fact included in the computed content, captured at generation time — it is what lets §13.11's `ReportingCloseGateProvider` detect that new postings exist after an `Approved` run's watermark and correctly report the gate `unmet` (stale evidence).

```json
{"request":{"report_type":"trial_balance","as_of":"2026-07-31","filters":{}},
 "response":{"report_run":{"id":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d","report_type":"trial_balance","as_of":"2026-07-31","basis":"accrual","state":"Generated","version":1,"content_hash":"1f3d...af02","source_data_watermark":"2026-07-31T18:04:22.000Z"}}}
```

`POST /v1/report-runs` (`reporting.report_runs.generate`, `W0`) rules: `report_source_not_ready`, `missing_report_layout`, `missing_account_classification`, `unclassified_account`, `missing_ageing_bucket_set`, `missing_cash_view_policy`, `report_unbalanced`; generation is all-or-nothing — a failed validation creates no `ReportRun` row.

**`report_source_not_ready` trigger definition (Governance Clarification Record `M5-GOV-002`):** returned only when the system cannot prove that a required source projection or source contract is complete and usable for the requested report scope and source-data watermark. Valid triggers: a required source projection has never been initialized or rebuilt; a required source adapter is unavailable; the source watermark is missing, invalid, or behind the minimum watermark required for the requested report; a required upstream projection reports failed, rebuilding, incomplete, or stale; the requested entity, period, as-of date, basis, or filter scope cannot be reproduced from a complete source snapshot; or a required source contract returns an explicit not-ready result. It must **not** be returned merely because the report contains zero rows, an entity has no transactions, all balances are zero, a filter produces an empty result, the requested period is open, a report has not previously been generated, or optional comparison data is unavailable — a normal empty but complete source result is a successful report with zero rows and valid zero totals, subject to the report's own accounting invariants. On trigger: no `ReportRun`, snapshot, content hash, approval request, audit business event, or business outbox event is created, and no accounting mutation occurs; exact idempotency replay of the same request remains side-effect free. `details` on this error identifies only the unavailable source category and readiness state — never infrastructure secrets, SQL, internal paths, or cross-entity data.

```json
{"request":{},"response":{"report_run":{"id":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d","state":"Approved","approved_by":"<uuid>","approved_at":"2026-08-01T09:12:00.000Z","reviewed_by":"<uuid>","reviewed_at":"2026-08-01T09:12:00.000Z","version":2}}}
```

`POST /v1/report-runs/{id}/approve` (`reporting.report_runs.approve`, `W1`) rules: `report_run_not_found`, `report_run_already_approved`, `report_run_rejected`, `sod_exception_required`. `GET /v1/report-runs/{id}` and `GET /v1/report-runs` (`reporting.report_runs.read`, `R`) follow the frozen pagination convention (§1).

### 13.5 Trial Balance

`GET /v1/reports/trial-balance` formalizes the existing, working M1 implementation (`LedgerReportService::trialBalance()`) unchanged, plus two additive fields: an optional `period_ref` adds `opening`/`movement` per row alongside the existing closing balance (SRS §5.13's "review Trial Balance" step); an optional `sbu` filter restricts summed `journal_lines` to matching `sbu_tag`. Zero-balance accounts, account ordering by `code`, and functional-currency-only presentation are unchanged from today. `totals.debit` exactly equals `totals.credit` by construction (Ledger's own `balanced_journal` invariant, read not re-derived).

```json
{"request_query":{"asOf":"2026-07-31","sbu":null,"limit":50,"cursor":null},
 "response":{"as_of":"2026-07-31","rows":[{"account_id":"<uuid>","code":"1010","name":"Cash","debit":"12500.0000","credit":"0.0000"},{"account_id":"<uuid>","code":"4010","name":"Client Service Revenue","debit":"0.0000","credit":"12500.0000"}],"totals":{"debit":"12500.0000","credit":"12500.0000","balanced":true},"page":{"limit":50,"next_cursor":null}}}
```

### 13.6 General Ledger

`GET /v1/reports/general-ledger` (§7.5) is reused **exactly** — required `account`/`range`, fixed `basis=accrual`, opening/closing balance semantics, Posted-only entries, stable order, cross-page running-balance continuation — plus two additive fields: optional `sbu` filter, and `reversal_of_entry_id` (nullable, copied from `journal_entries.reversal_of_entry_id`) on each `entries[]` item.

```json
{"request_query":{"account":"<uuid>","range":"2026-07-01..2026-07-31","sbu":null,"limit":50,"cursor":null},
 "response":{"account":{"id":"<uuid>","code":"1010","name":"Cash","normal_balance":"debit"},"basis":"accrual","range":{"from":"2026-07-01","to":"2026-07-31"},"opening_balance":{"amount":"0.0000","currency":"BDT"},"entries":[{"journal_entry_id":"<uuid>","line_id":4101,"entry_date":"2026-07-15","reference":"BANK-TRANSFER-001","description":"Debit cash","debit":{"amount":"1000.0000","currency":"BDT"},"credit":null,"reversal_of_entry_id":null,"running_balance":{"amount":"1000.0000","currency":"BDT"}}],"closing_balance":{"amount":"1000.0000","currency":"BDT"},"page":{"limit":50,"next_cursor":null}}}
```

### 13.7 Profit and Loss (layout v1, frozen)

**Approved computed-line skeleton:**

| # | Line | Computation |
|---|---|---|
| 1 | Sales Revenue | sum of accounts classified `sales_revenue` |
| 2 | Total Cost of Sales | sum of accounts classified `cost_of_sales` |
| 3 | Gross Profit | `Sales Revenue − Total Cost of Sales` |
| 4 | Gross Profit % | `Gross Profit ÷ Sales Revenue × 100`; `null` when Sales Revenue is `0.0000` |
| 5 | Total Operating Expenses | sum of accounts classified `operating_expense` |
| 6 | Operating Profit | `Gross Profit − Total Operating Expenses` |
| 7 | Total Non-Operating Income | sum of accounts classified `non_operating_income` (net of `non_operating_expense`, if configured) |
| 8 | Net Profit | `Operating Profit + Total Non-Operating Income` |
| 9 | Net Profit % | `Net Profit ÷ Sales Revenue × 100`; `null` when Sales Revenue is `0.0000` |

Approved classification groups: `sales_revenue`, `cost_of_sales`, `operating_expense`, `non_operating_income`, and, only if explicitly configured, `non_operating_expense` and `tax_or_other_configured_group`. **Account inclusion and row ordering come from the versioned `ReportLayout`/`AccountClassificationMap` below — accounts are never classified from name or code during report generation.** An account posted-to in the period with no effective classification entry fails generation with `422 unclassified_account`. Missing layout/classification configuration fails with `422 missing_report_layout`/`422 missing_account_classification`.

```json
{"report_layout":{"id":"<uuid>","report_type":"profit_and_loss","version":1,"sections":[
  {"section_id":"sales_revenue","label":"Sales Revenue","classification_keys":["sales_revenue"],"order":1,"visible":true},
  {"section_id":"total_cost_of_sales","label":"Total Cost of Sales","classification_keys":["cost_of_sales"],"order":2,"visible":true},
  {"section_id":"gross_profit","label":"Gross Profit","computed_line":{"formula":"sales_revenue - total_cost_of_sales"},"order":3,"visible":true},
  {"section_id":"gross_profit_pct","label":"Gross Profit %","computed_line":{"formula":"gross_profit / sales_revenue * 100","null_on_zero_denominator":true},"order":4,"visible":true,"percentage_of":"sales_revenue"},
  {"section_id":"total_operating_expense","label":"Total Operating Expenses","classification_keys":["operating_expense"],"order":5,"visible":true},
  {"section_id":"operating_profit","label":"Operating Profit","computed_line":{"formula":"gross_profit - total_operating_expense"},"order":6,"visible":true},
  {"section_id":"total_non_operating_income","label":"Total Non-Operating Income","classification_keys":["non_operating_income","non_operating_expense"],"order":7,"visible":true},
  {"section_id":"net_profit","label":"Net Profit","computed_line":{"formula":"operating_profit + total_non_operating_income"},"order":8,"visible":true},
  {"section_id":"net_profit_pct","label":"Net Profit %","computed_line":{"formula":"net_profit / sales_revenue * 100","null_on_zero_denominator":true},"order":9,"visible":true,"percentage_of":"sales_revenue"}
],"effective_from":"2026-07-01","effective_to":null}}
```

The existing Chart-of-Accounts class structure (SRS §5.10) may seed the first `AccountClassificationMap` version, but a frozen `ReportRun` always references the exact classification/layout versions actually used. **Cash-basis Profit and Loss is excluded from M5 MVP** — `basis=cash` on this endpoint returns `422 unsupported_basis`; Cash View (§13.10) is the dedicated derived management report instead.

```json
{"request_query":{"period":"2026-07","sbu":null,"basis":"accrual","compare_to":null},
 "response":{"period_ref":"2026-07","basis":"accrual","layout_version":1,"classification_version":1,"lines":[{"section_id":"sales_revenue","label":"Sales Revenue","amount":{"amount":"12500.0000","currency":"BDT"}},{"section_id":"total_cost_of_sales","label":"Total Cost of Sales","amount":{"amount":"4000.0000","currency":"BDT"}},{"section_id":"gross_profit","label":"Gross Profit","amount":{"amount":"8500.0000","currency":"BDT"}},{"section_id":"gross_profit_pct","label":"Gross Profit %","percentage":"68.0000"},{"section_id":"total_operating_expense","label":"Total Operating Expenses","amount":{"amount":"3000.0000","currency":"BDT"}},{"section_id":"operating_profit","label":"Operating Profit","amount":{"amount":"5500.0000","currency":"BDT"}},{"section_id":"total_non_operating_income","label":"Total Non-Operating Income","amount":{"amount":"0.0000","currency":"BDT"}},{"section_id":"net_profit","label":"Net Profit","amount":{"amount":"5500.0000","currency":"BDT"}},{"section_id":"net_profit_pct","label":"Net Profit %","percentage":"44.0000"}]}}
```

### 13.8 Balance Sheet (layout v1, frozen)

**Approved top-level structure:** Assets, Liabilities, Equity, Total Assets, Total Liabilities, Total Equity, Total Liabilities and Equity, Difference. **Invariant: Assets = Liabilities + Equity** — `Difference` must be exactly `0.0000`. **A non-zero `Difference` prevents approval**: `POST /v1/report-runs` fails with `422 report_unbalanced` and creates no `ReportRun` row.

Current/non-current and other subgroup presentation come from the same versioned `AccountClassificationMap`/`ReportLayout` mechanism as §13.7, using Balance-Sheet-specific classification keys (e.g. `asset_current`, `asset_non_current`, `liability_current`, `liability_non_current`, `equity`) — **never inferred from account names, codes, dates, or balances**. Retained earnings and the current-period result are represented through explicit configured classifications (e.g. `3020 Retained Earnings`, `3030 Current-Year P&L`, SRS §5.10) and exact report-run references to the same period's Net Profit (§13.7 line 8) — never an independent recomputation.

```json
{"request_query":{"asOf":"2026-07-31","sbu":null,"compare_to":null},
 "response":{"as_of":"2026-07-31","layout_version":1,"classification_version":1,"sections":[{"section_id":"assets","label":"Assets","rows":[{"code":"1010","name":"Cash","amount":{"amount":"12500.0000","currency":"BDT"}}],"subtotal":{"amount":"12500.0000","currency":"BDT"}},{"section_id":"liabilities","label":"Liabilities","rows":[],"subtotal":{"amount":"0.0000","currency":"BDT"}},{"section_id":"equity","label":"Equity","rows":[{"code":"3030","name":"Current-Year P&L","amount":{"amount":"12500.0000","currency":"BDT"}}],"subtotal":{"amount":"12500.0000","currency":"BDT"}}],"total_assets":{"amount":"12500.0000","currency":"BDT"},"total_liabilities":{"amount":"0.0000","currency":"BDT"},"total_equity":{"amount":"12500.0000","currency":"BDT"},"total_liabilities_and_equity":{"amount":"12500.0000","currency":"BDT"},"difference":{"amount":"0.0000","currency":"BDT"}}}
```

### 13.9 Receivables and Payables Ageing (default bucket set v1, frozen)

**Approved default `AgeingBucketSet`, identical for Receivables and Payables:** `not_due` (due date after `asOf`), `overdue_0_30` (0–30 days overdue), `overdue_31_60` (31–60), `overdue_61_90` (61–90), `overdue_90_plus` (91+). Ageing is based on **contractual due date versus the report `asOf` date**. Only the document's remaining `open_balance` is aged (partial settlement reduces the aged balance). Unapplied party credit and negative/credit balances are displayed **separately** and are **never silently netted against individual documents**. The dashboard (SRS §5.1) may visually aggregate `overdue_61_90` and `overdue_90_plus` into one 60+ segment; **detailed AR/AP reports always retain the full five categories**.

```json
{"ageing_bucket_set":{"id":"<uuid>","version":1,"buckets":[
  {"bucket_id":"not_due","label":"Not Due","lower_days":null,"upper_days":-1,"order":1},
  {"bucket_id":"overdue_0_30","label":"0–30 Days Overdue","lower_days":0,"upper_days":30,"order":2},
  {"bucket_id":"overdue_31_60","label":"31–60 Days Overdue","lower_days":31,"upper_days":60,"order":3},
  {"bucket_id":"overdue_61_90","label":"61–90 Days Overdue","lower_days":61,"upper_days":90,"order":4},
  {"bucket_id":"overdue_90_plus","label":"91+ Days Overdue","lower_days":91,"upper_days":null,"order":5}
],"effective_from":"2026-07-01","effective_to":null}}
```

```json
{"request_query":{"asOf":"2026-07-31","customer":null},
 "response":{"as_of":"2026-07-31","bucket_set_version":1,"detail":[{"customer_id":"<uuid>","invoice_id":"<uuid>","document_number":"INV-1","due_date":"2026-07-10","open_balance":{"amount":"500.0000","currency":"BDT"},"bucket_id":"overdue_0_30"}],"summary":[{"customer_id":"<uuid>","totals_by_bucket":[{"bucket_id":"not_due","amount":{"amount":"0.0000","currency":"BDT"}},{"bucket_id":"overdue_0_30","amount":{"amount":"500.0000","currency":"BDT"}},{"bucket_id":"overdue_31_60","amount":{"amount":"0.0000","currency":"BDT"}},{"bucket_id":"overdue_61_90","amount":{"amount":"0.0000","currency":"BDT"}},{"bucket_id":"overdue_90_plus","amount":{"amount":"0.0000","currency":"BDT"}}],"credit_balances":{"amount":"0.0000","currency":"BDT"},"unapplied_credit":{"amount":"0.0000","currency":"BDT"},"total_open":{"amount":"500.0000","currency":"BDT"}}]}}
```

### 13.10 Tax and VAT Outputs

`GET /v1/reports/tax-summary` (accrual only, SRS §5.11/§6.1) aggregates already-immutable `TaxSnapshot` records grouped by the already-frozen `return_box_mapping` keys (ADR-006; `app/Tax`). Output VAT and recoverable input VAT are summed by treatment/recoverability; withholding/AIT/VDS reads the already-frozen `Allocation.withholding`; `net_vat = output_vat − recoverable_input_vat` (ADR-006 consequence #2). No filing form, statutory rate, or jurisdiction rule is introduced — this report aggregates what Tax already validated at posting time.

```json
{"request_query":{"period":"2026-07"},
 "response":{"period_ref":"2026-07","jurisdiction":"BD","output_vat":{"amount":"1875.0000","currency":"BDT"},"recoverable_input_vat":{"amount":"450.0000","currency":"BDT"},"net_vat":{"amount":"1425.0000","currency":"BDT"},"boxes":[{"return_box_key":"CONFIGURED_VALUE","amount":{"amount":"1875.0000","currency":"BDT"},"document_ids":["<uuid>"]}]}}
```

### 13.11 Cash View

**Cash View is a derived, rebuildable management report — never a second Ledger, never presented as a statutory cash-basis Profit and Loss statement.** Frozen algorithm: (1) re-time eligible economic activity to its settlement date; (2) pro-rate by the settled proportion; (3) value foreign amounts using the exact settlement-date RateRecord; (4) exclude VAT from revenue/expense presentation; (5) report actual bank cash net of withholding; (6) preserve exact source, Settlement, document, TaxSnapshot, RateRecord, and SBU-allocation references on every derived row.

Approved additional behavior: partially settled documents contribute only their settled proportion; unapplied party credit appears in reconciliation as `unapplied_cash`, never as revenue/expense; later credit application is attributed to the **original settlement date**, creating no second bank movement; refunds appear as negative cash movement on the refund date; reversals negate or rebuild the exact original derived rows from original references; SBU allocation follows the source document's frozen `sbu_allocations` (`Σ=1.0000`); missing source allocation, RateRecord, or policy fails safely (`422 missing_cash_view_policy` or the underlying dependency's own error). **Cash View creates no journals, postings, audit events, or business outbox events merely by being queried or rebuilt.**

```json
{"cash_view_policy":{"id":"<uuid>","version":1,
  "recognition_date_source":"settlement_date","proration_basis":"settled_proportion","valuation_rate_source":"settlement_date_rate",
  "vat_treatment":"excluded","withholding_treatment":"net_of_withholding_shown_separately",
  "unapplied_credit_treatment":"reconciliation_only_not_revenue_or_expense","later_credit_application_treatment":"attributed_to_original_settlement_date",
  "refund_treatment":"negative_movement_on_refund_date","reversal_treatment":"negate_or_rebuild_original_rows",
  "sbu_allocation_treatment":"follows_source_document_exact_sum_allocation",
  "rounding_scale":4,"rounding_mode":"CONFIGURED_MODE","effective_from":"2026-07-01","effective_to":null}}
```

```json
{"request_query":{"period":"2026-07","sbu":null},
 "response":{"period_ref":"2026-07","basis":"cash","policy_version":1,"cash_in_bank":{"amount":"9000.0000","currency":"BDT"},"collections":{"amount":"6000.0000","currency":"BDT"},"payments":{"amount":"3500.0000","currency":"BDT"},"net_cash_flow":{"amount":"2500.0000","currency":"BDT"},"withheld_excluded":{"amount":"400.0000","currency":"BDT"},"unapplied_cash":{"amount":"0.0000","currency":"BDT"},"refunds":{"amount":"0.0000","currency":"BDT"},"residual":{"amount":"0.0000","currency":"BDT"}}}
```

### 13.12 M5 CloseGateProvider implementation

`ReportingCloseGateProvider` implements the unchanged `CloseGateProvider` v1 interface (§12.7) for `trial_balance_reviewed` → `report_type=trial_balance`, `profit_and_loss_approved` → `profit_and_loss`, `balance_sheet_approved` → `balance_sheet`, `vat_outputs_approved` → `tax_summary` (all `basis=accrual`). It never handles `bank_reconciliation_completed`, which remains M6-owned (`source_context="reconciliation"`).

For the mapped `report_type`, the provider finds the current `Approved`, non-`Superseded` `ReportRun` for `(entityId, report_type, basis, periodRef-or-period-end-as_of)`. None found → `status="unmet"`, all evidence fields `null` (§12.7's honest-absence shape, unchanged). Found → the provider recomputes the latest qualifying posted-fact watermark at evaluation time; if it exceeds the run's `source_data_watermark`, the gate is `unmet` (stale evidence). Otherwise `status="satisfied"` and:

```json
{"gate_type":"profit_and_loss_approved","status":"satisfied","source_context":"reporting","source_reference":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d","produced_at":"2026-08-01T09:00:00.000Z","reviewed_by":"<uuid>","reviewed_at":"2026-08-01T09:12:00.000Z","evidence_version":2,"evidence_hash":"1f3d...af02"}
```

`source_reference` = `ReportRun.id`; `produced_at` = `ReportRun.generated_at`; `reviewed_by`/`reviewed_at` = `ReportRun.reviewed_by`/`reviewed_at`; `evidence_version` = `ReportRun.version`; `evidence_hash` = `ReportRun.content_hash` — a direct, lossless field mapping, matching §12.7's example exactly. A `Superseded`, `Rejected`, or still-`Generated`/`PendingApproval` run is never selected — it is invisible to the lookup by construction.

### 13.13 Export

**PDF and CSV export are included in M5; XLSX is excluded and deferred.** `GET /v1/report-runs/{id}/export?format=pdf|csv` (`reporting.report_runs.read`, `R`). Export is produced **only** from the immutable `ReportRun.content` snapshot and **never reruns the underlying report calculation**. Output identifies at minimum `entity_id`, `report_type`, `filters`, `basis`, `period_ref`/`as_of`, `generated_at`, `content_hash`, `layout_version`, `classification_version` (where applicable), and `state`. Export creates no accounting mutation and no business event. Unauthorized/cross-entity `id` returns `404 not_found`; `format` values other than `pdf`/`csv` (including `xlsx`) return `400 validation`. **A stale or superseded run remains exportable for historical audit but its export must visibly state its `state`.** Hard Close evidence still requires a current `Approved`, non-`Superseded` run (§13.12) regardless of any export.

### 13.14 Reporting/Ledger context boundary

The existing Trial Balance, General Ledger, and account-balance implementation is **not** migrated or rewritten. Ledger continues to own Ledger facts and its established read contracts (`LedgerReportService`, `LedgerAccount`/`JournalLine`/`JournalEntry`). Reporting consumes them through explicit adapters (`TrialBalanceQuery`/`GeneralLedgerQuery` implementations that call Ledger's existing service in-process) and **must not read Ledger tables directly where an approved query contract already exists**. Every genuinely new M5 structure — `ReportRun`s, layouts, classifications, ageing, Cash View, and Close-Gate evidence — is Reporting-owned outright. A future context extraction requires a separate governance decision.

### 13.15 Stable errors

New `details.rule` values, using the frozen shared envelope (§2): `missing_report_layout` (422), `missing_account_classification` (422), `unclassified_account` (422), `missing_ageing_bucket_set` (422), `missing_cash_view_policy` (422), `report_source_not_ready` (422, exact trigger conditions defined in §13.4 per `M5-GOV-002`), `report_unbalanced` (422), `report_run_not_approved` (422), `report_run_already_approved` (422), `report_run_rejected` (422), `report_run_superseded` (422), `unsupported_basis` (422). `report_run_stale` surfaces as an `unmet` gate, never a client-facing error (§13.12).

### 13.16 Traceability

| Amendment rule | Frozen source |
|---|---|
| No cash-basis P&L; Cash View is the derived management report | ADR-001 (locked algorithm + `M5-GOV-001` MVP-scope resolution) |
| Assets = Liabilities + Equity | Accounting identity; `M5-GOV-001` |
| Trial Balance/General Ledger unchanged, Ledger-owned | §7.5, §8.3.4; `M5-GOV-001` item 7 |
| `CloseGateProvider` v1 interface unchanged | §12.7 (M4) |
| No account classification inferred from name/code | `M5-GOV-001` items 2–3 |
| Five-bucket ageing default, versioned | `M5-GOV-001` item 4 |
| One-step checker review/approval, generator cannot self-approve | `M5-GOV-001` item 5 |
| PDF/CSV export, XLSX excluded | `M5-GOV-001` item 6 |
| `report_source_not_ready` exact trigger/non-trigger conditions and side-effect-free failure | `M5-GOV-002` |

M5 introduces no M6 Reconciliation endpoint, no consolidation/CTA behavior, no XLSX export, no hardcoded ageing bucket outside versioned configuration, no inferred account classification, and no Hard Close bypass.

---

## 14. Approved Amendment — M6 Reconciliation

**Governance Approval Record:** `M6-GOV-001` (`HiveFin_Decision_Log.md`). Source: explicit Product Owner decisions on the nine open items raised during governance review, recorded verbatim in that record.

### 14.1 Purpose, canonical name, and sequencing

Canonical milestone name: **M6 — Reconciliation**. M6 depends on completed M1 Ledger + Valuation, M2 Documents, M3 Settlement, M4 Corrections/Notes/Period Close, and M5 Reporting and Cash View. M6 supplies the one evidence provider M4's Hard Close has awaited since M4: `bank_reconciliation_completed`. Until this amendment is implemented, that gate correctly remains `unmet` via `UnavailableCloseGateProvider`. M6 implements no M7 Migration behavior, no consolidation/CTA behavior, and satisfies no Reporting-owned gate (`trial_balance_reviewed`, `profit_and_loss_approved`, `balance_sheet_approved`, `vat_outputs_approved` remain exclusively `ReportingCloseGateProvider`-owned, unchanged).

**No second Ledger, no second bank-account master.** `ReconciliationAccount` (§14.4) is a thin, Reconciliation-owned configuration record that references exactly one existing asset-type `LedgerAccount`; it carries no balance, no posting capability, and duplicates no Ledger data. Every debit/credit this amendment can cause reaches the Ledger only through `PostingService`, in-process (AP-001) — Reconciliation never writes `journal_lines`/`ledger_accounts` directly.

### 14.2 Common protocol and shared schemas

All M6 public endpoints inherit the frozen M0–M5 protocol exactly (§1–§3): TLS/authentication required; `X-Entity-Id` required, default-deny, cross-entity `404 not_found`; optional `X-Correlation-Id`; Money `{amount, currency}` exact decimal strings only; unknown fields `400 validation`; frozen error envelope `{error_code, message, details, doc_id?, required_version?}`; every mutating command requires `Idempotency-Key`; every command against an existing versioned resource requires `If-Match: <version>`.

Header profiles: `R` (`Authorization`, `X-Entity-Id`; optional `X-Correlation-Id`) for every `GET`; `W0` (`R` + `Idempotency-Key`) for commands creating a new resource with no prior version to guard (`POST /v1/reconciliation-accounts`, `POST /v1/reconciliations`, `POST .../import`, `POST .../match-suggestions`); `W1` (`W0` + `If-Match`) for commands mutating an existing versioned resource (`PATCH /v1/reconciliation-accounts/{id}`, `POST .../lines/{lineId}/match`, `POST .../lines/{lineId}/confirm`, `POST .../lines/{lineId}/bank-entry`, `POST .../complete`, `POST .../reopen`).

**Mandatory durable four-eyes approval** (`W1`, `202 pending_approval` unconditionally — not gated by whether the entity has configured an approval policy for the capability, exactly matching M4 Hard Close's unconditional four-eyes, not M2–M5's policy-conditional pattern) applies to exactly three commands: `CreateEntryForBankLine`, `CompleteReconciliation`, `ReopenReconciliation`. All three commit through the existing, unchanged `POST /v1/approvals/{id}/approve` (§3) — no new approval endpoint is introduced. `ImportStatement`, `GenerateMatchSuggestions`, `MatchLine`, and `ConfirmMatch` are not maker-checker-eligible but still enforce capability checks, entity isolation, idempotency, optimistic concurrency, audit, and outbox recording where they mutate state.

Every computed match derives from already-posted, already-immutable `Allocation` facts (M3, `settlement_allocations`); no command accepts a client-supplied match confidence, tolerance, or write-off amount.

### 14.3 Endpoint inventory (16 endpoints)

```
POST  /v1/reconciliation-accounts
GET   /v1/reconciliation-accounts?limit=&cursor=
GET   /v1/reconciliation-accounts/{id}
PATCH /v1/reconciliation-accounts/{id}                                   ⚡ not maker-checker; version-guarded config edit

POST  /v1/reconciliations                                                (OpenReconciliation → Draft)
GET   /v1/reconciliations?bank_account_id=&state=&limit=&cursor=
GET   /v1/reconciliations/{id}
POST  /v1/reconciliations/{id}/import                                    (ImportStatement)
POST  /v1/reconciliations/{id}/match-suggestions                         (GenerateMatchSuggestions)
GET   /v1/reconciliations/{id}/unmatched                                 (GetUnmatchedLines)
POST  /v1/reconciliations/{id}/lines/{lineId}/match                      (MatchLine)
POST  /v1/reconciliations/{id}/lines/{lineId}/confirm                    (ConfirmMatch)
POST  /v1/reconciliations/{id}/lines/{lineId}/bank-entry     ⚡           (CreateEntryForBankLine)
POST  /v1/reconciliations/{id}/complete                      ⚡           (CompleteReconciliation)
POST  /v1/reconciliations/{id}/reopen                        ⚡           (ReopenReconciliation)
GET   /v1/reconciliations/{id}/statement?format=pdf|csv                  (export)
```

`⚡` = mandatory durable four-eyes (§14.2). Every other command/query follows the frozen pagination, versioning, and error conventions with no further exception.

### 14.4 `ReconciliationAccount` — bank-account configuration

A `ReconciliationAccount` is Reconciliation-owned metadata referencing exactly one asset-type `LedgerAccount`; it is **not** a second accounting account and carries no balance. Fields: `id`, `entity_id`, `ledger_account_id` (must reference an existing `LedgerAccount` with `type=asset` for the same entity; `422 invalid_ledger_account` otherwise), `currency` (ISO 4217, fixed at creation — a `ReconciliationAccount` is single-currency), `display_name`, `masked_bank_identifier`, `reconciliation_enabled` (boolean, default `true`), `version`. `PATCH` may change `display_name`, `masked_bank_identifier`, `reconciliation_enabled`, and the account's saved CSV `column_mapping` (§14.5); it may never change `ledger_account_id`, `entity_id`, or `currency` — those are immutable once created (a currency or ledger-account change is a new `ReconciliationAccount`, not an edit, to keep every existing `BankReconciliation`'s history meaningful). `Unique(entity_id, ledger_account_id)` — one `ReconciliationAccount` per Ledger account.

```json
{"request":{"ledger_account_id":"c9b1b6b0-1a2b-4c3d-8e9f-0a1b2c3d4e5f","currency":"BDT","display_name":"NRB Current","masked_bank_identifier":"****4821","reconciliation_enabled":true},
 "response":{"reconciliation_account":{"id":"a1b2c3d4-e5f6-4a5b-8c9d-0e1f2a3b4c5d","entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee","ledger_account_id":"c9b1b6b0-1a2b-4c3d-8e9f-0a1b2c3d4e5f","currency":"BDT","display_name":"NRB Current","masked_bank_identifier":"****4821","reconciliation_enabled":true,"version":1}}}
```

`POST /v1/reconciliation-accounts` (`reconciliation.accounts.configure`, `W0`) rules: `invalid_ledger_account` (422 — not found, wrong entity, or not asset-type), `duplicate_reconciliation_account` (422 — `ledger_account_id` already configured for this entity).

### 14.5 Statement import and duplicate detection

`ImportStatement` receives already-parsed statement lines (CSV-to-JSON parsing is a client/edge concern, not a domain one) plus a client-computed SHA-256 `file_hash` of the original source file, and an optional `column_mapping` to save on the `ReconciliationAccount` for reuse. Each line: `source_line_identity` (the line's position/identity within the source file, preserved for traceability), `transaction_date`, `narration`, `amount` (signed — positive inflow, negative outflow, in the `ReconciliationAccount`'s fixed `currency`), and optional `external_bank_reference`.

**Duplicate identity** (per line): `(entity_id, reconciliation_account_id, transaction_date, amount, currency, normalized_narration, external_bank_reference)` — `normalized_narration` is the narration case-folded and whitespace-collapsed; `external_bank_reference` participates in the identity tuple using SQL null-safe comparison (`IS NOT DISTINCT FROM`/`COALESCE`), never a naive nullable unique index, so two references both omitting a bank reference are still compared on their other fields, not silently treated as automatically distinct. Duplicate scope is **all history for that `ReconciliationAccount`**, not just the current import batch or the current `BankReconciliation`.

**Import behavior:** every line whose duplicate identity already exists is **rejected, not silently skipped** — it is omitted from the created `StatementLine` rows and returned in the response's `conflicts` array with its `source_line_identity` and the reason; every non-duplicate line in the same request is still imported as a new `StatementLine` in `Unreconciled` status. A whole-file exact re-import (identical `file_hash` against the same `ReconciliationAccount`) is rejected outright before per-line checking, as `duplicate_import_file`. **Exact idempotency replay** (same `Idempotency-Key` and identical request body) returns the original stored response — including its original `conflicts` — and creates no new rows; it is not a second import.

```json
{"request":{"file_hash":"3b1f...9c02","column_mapping":{"date":"Date","narration":"Description","amount":"Amount","reference":"Reference"},"lines":[{"source_line_identity":"row-1","transaction_date":"2026-07-05","narration":"NEFT Northstar Digital","amount":{"amount":"9000.0000","currency":"USD"},"external_bank_reference":"NEFT82231"}]},
 "response":{"reconciliation":{"id":"...","state":"InProgress","version":2},"imported":1,"conflicts":[]}}
```

`POST /v1/reconciliations/{id}/import` (`reconciliation.reconciliations.import`, `W0`) rules: `duplicate_import_file` (422), `reconciliation_not_editable` (422 — batch is `Completed` or `PendingCompletionApproval`; import requires `Draft`, `InProgress`, or `Reopened`), `currency_mismatch` (422 — a line's `currency` differs from the account's fixed currency; §14.10).

### 14.6 Matching — candidates, algorithm, and suggestions

**Match candidates are M3 Settlement `Allocation` rows** (`state=posted`) sharing the `ReconciliationAccount`'s `ledger_account_id` via `Allocation.bank_account_id`, compared on `Allocation.bank_amount` (the frozen "actual cash received or paid" field, API Contracts §11 — never `gross_amount`, which can include withholding never reflected in the bank movement). Reversed allocations are equally valid candidates on their own `bank_amount` and sign. Reconciliation never matches against raw `JournalLine`/`JournalEntry` rows directly, with exactly two exceptions: (a) a statement line already resolved by an **approved** `CreateEntryForBankLine` retains that entry's id for validation and drill-down display, and (b) audit/drill-down views may display the `JournalEntry` a matched `Allocation` itself posted, read-only, for context — neither is a matching *candidate*.

`GenerateMatchSuggestions` (`reconciliation.reconciliations.generate_suggestions`, `W0`) evaluates every `Unreconciled` or `Unexplained` line in the batch against unconsumed `Allocation` candidates for the same `ReconciliationAccount` and exact `currency` (§14.10 — no cross-currency matching):

- **Exact currency, exact amount, ±3 calendar days** between `StatementLine.transaction_date` and `Allocation.settlement_date`, are the mandatory filters.
- **Normalized reference comparison** (`external_bank_reference`/narration vs `Allocation.allocation_number`/party name, case-folded, whitespace-collapsed substring match) is a ranking signal only, never a filter — it orders multiple qualifying candidates but never excludes an amount/date/currency match.
- **One-to-one, one-to-many (one statement line ↔ several allocations), and many-to-one (several statement lines ↔ one allocation) suggestions only**, and only when the grouped total is **exactly** equal — no tolerance, no fuzzy amount matching, no partial/residual write-off. Many-to-many is never suggested.
- A suggestion is **always a proposal**: `GenerateMatchSuggestions` creates `Suggested` lines with one or more ranked candidate groups attached; it never transitions a line to `Matched` or `Reconciled`, and it creates no journal, audit business event beyond the suggestion record itself, or outbox business event.
- A line with zero qualifying candidates after generation is marked `Unexplained`. Re-running `GenerateMatchSuggestions` later (e.g., after new settlements post) re-evaluates every `Unreconciled`/`Unexplained` line and may move an `Unexplained` line back to `Suggested`.

```json
{"request":{},"response":{"suggested":3,"unexplained":1,"lines":[{"line_id":"...","status":"Suggested","suggestions":[{"suggestion_id":"...","allocation_ids":["1204d0d4-..."],"total_amount":{"amount":"9000.0000","currency":"USD"},"rank":1,"reference_match":true}]}]}}
```

`POST /v1/reconciliations/{id}/match-suggestions` rules: `reconciliation_not_editable` (422, same states as §14.5).

### 14.7 Manual matching

`MatchLine` (`reconciliation.reconciliations.match`, `W1`) selects one suggestion (from `GenerateMatchSuggestions`) or an explicit manual candidate set for one `Unreconciled`/`Suggested`/`Unexplained` line, moving it to `Matched`. Re-calling `MatchLine` on a `Matched` line (not yet `Reconciled`) replaces the selection. A manual candidate set is subject to the exact same constraints as an automatic suggestion (§14.6): same `ReconciliationAccount`, exact currency, exact total, one-to-one/one-to-many/many-to-one only — `MatchLine` re-validates these server-side regardless of what `GenerateMatchSuggestions` proposed; it never trusts a client-supplied total.

`ConfirmMatch` (`reconciliation.reconciliations.confirm`, `W1`) promotes a `Matched` line to `Reconciled` — the one explicit human confirmation step. `Reconciled` is immutable for that line except through `ReopenReconciliation` at the batch level (§14.9); confirming marks the underlying `Allocation`(s) as consumed by this reconciliation (an `Allocation` may be consumed by at most one `Reconciled` line-group, preventing double-reconciliation of the same cash movement).

`POST /v1/reconciliations/{id}/lines/{lineId}/match` rules: `line_not_matchable` (422 — line is `Matched` from a prior different reconciliation attempt in a state that disallows re-selection, or the batch is not editable), `match_total_mismatch` (422 — candidate total ≠ line/group total), `currency_mismatch` (422), `allocation_already_consumed` (422 — a selected `Allocation` is already `Reconciled` elsewhere).
`POST /v1/reconciliations/{id}/lines/{lineId}/confirm` rules: `line_not_matched` (422 — line is not currently `Matched`).

### 14.8 Bank-only entries

`CreateEntryForBankLine` (`reconciliation.reconciliations.create_bank_entry`, `W1`, ⚡ mandatory four-eyes) resolves an `Unreconciled` or `Unexplained` line with no viable existing-transaction match by posting a new, manually classified journal entry. The caller supplies the **offset `LedgerAccount` explicitly** — this amendment infers and defaults no accounting classification; there is no "bank fees"/"interest income" default. The bank leg is derived from the `ReconciliationAccount.ledger_account_id` and the line's signed `amount` (positive → debit bank/credit offset; negative → credit bank/debit offset); the entry posts through the existing Ledger `PostingService`, in-process (AP-001), exactly as every other M1–M5 posting path — no second posting mechanism. The posted `JournalEntry` id is linked back onto the `StatementLine` (`resolved_by_journal_entry_id`), preserving audit, outbox, idempotency, and the standard `reversal_of_entry_id` reversal trail identically to every other Ledger posting. On the approving checker's commit, the line moves directly to `Reconciled` (a bank-only entry both explains and reconciles its line in one durable action — there is no intermediate `Matched` state for this path).

```json
{"request":{"offset_account_id":"6a5b4c3d-2e1f-4a5b-8c9d-0e1f2a3b4c5d","narration":"NRB monthly service charge"},
 "response":{"approval":{"id":"...","status":"pending","version":1}}}
```

```json
{"request":{},"response":{"line":{"id":"...","status":"Reconciled","resolved_by_journal_entry_id":"..."}}}
```

Rules: `offset_account_required` (400 — missing/empty), `invalid_offset_account` (422 — not found, wrong entity, or inactive), `line_not_resolvable` (422 — line is already `Matched`/`Reconciled`, or the batch is not editable), `period_locked` (423 — inherited from `PostingService`, unchanged).

### 14.9 Reconciliation lifecycle and completion

**Batch states:** `Draft → InProgress → PendingCompletionApproval → Completed → Reopened`. `OpenReconciliation` (`POST /v1/reconciliations`) creates a `Draft` batch scoped to one `ReconciliationAccount` and a declared statement `opening_balance`/`closing_balance`/period (`period_ref`, matching the frozen Period reference format). The first `ImportStatement` moves `Draft → InProgress`; further imports, matches, confirms, and bank-only entries keep it `InProgress`. `Reopened` behaves identically to `InProgress` for command eligibility (further import/match/confirm/bank-only-entry activity is permitted) — it exists only as a distinct, auditable label, exactly mirroring `AccountingPeriod`'s `Reopened` state (M4), which likewise "requires re-close" rather than silently reverting to its prior state.

**`CompleteReconciliation`** (`reconciliation.reconciliations.complete`, `W1`, ⚡ mandatory four-eyes) requires, atomically re-validated at the approving checker's commit (not merely at request time): every `StatementLine` in the batch is `Reconciled` (none `Unreconciled`/`Suggested`/`Matched`/`Unexplained`); and `closing_balance = opening_balance + Σ(amount of every Reconciled line)`, i.e. the **unexplained difference is exactly `0.0000`**. **There is no force-close and no unexplained-balance bypass** — an `Unexplained` line can only be resolved through an approved existing-transaction match (`MatchLine` + `ConfirmMatch`) or an approved bank-only entry (§14.8); `CompleteReconciliation` itself never write-offs, forgives, or silently drops a difference. On the checker's commit, the batch moves `InProgress`/`Reopened` → `Completed`, freezing a `source_data_watermark` (§14.11) and an immutable snapshot content hash of the completed statement (mirroring `ReportRun.content_hash`, M5).

**`ReopenReconciliation`** (`reconciliation.reconciliations.reopen`, `W1`, ⚡ mandatory four-eyes) is available only from `Completed`. It does not revert already-`Reconciled` lines' status or un-consume their `Allocation`s — closed facts stay closed; it only re-admits the batch to further import/match/confirm/bank-only-entry activity and requires a fresh `CompleteReconciliation` to re-close. Reopening a `Completed` reconciliation whose evidence has already satisfied a Hard-Closed period's gate does not retroactively unlock that period (Period's own `Reopen` command, M4, is the only path back into a Hard-Closed period); it does make the gate **stale** for any *future* Hard Close evaluation of that account/period until re-completed (§14.11).

`POST /v1/reconciliations/{id}/complete` rules: `lines_not_fully_reconciled` (422, `details.unreconciled_line_ids`), `unexplained_difference` (422, `details.difference` — the exact non-zero amount), `reconciliation_not_completable` (422 — wrong state).
`POST /v1/reconciliations/{id}/reopen` rules: `reconciliation_not_reopenable` (422 — not `Completed`).

### 14.10 Multi-currency scope

Foreign-currency bank statements are **in scope for M6**. A `ReconciliationAccount`'s `currency` is fixed at creation; `GenerateMatchSuggestions` and `MatchLine` compare `StatementLine.amount.currency` against `Allocation.bank_amount.currency` for **exact equality only** — no FX amount tolerance and no cross-currency matching, ever (§14.6). A bank-only entry's foreign-currency conversion to functional currency, where the offset account requires one, uses an immutable `RateRecord` reference exactly as every other M1–M5 foreign posting does (ADR-007) — this amendment introduces no new FX source, rate lookup, or rounding policy. Period-end unrealised FX revaluation of foreign bank balances remains entirely M1/`CurrencyFx`-owned (§7 SRS, ADR-007) and is untouched by M6.

### 14.11 `ReconciliationCloseGateProvider` implementation

`ReconciliationCloseGateProvider` implements the unchanged `CloseGateProvider` v1 interface (§12.7) for `bank_reconciliation_completed` only. It never satisfies a Reporting-owned gate.

**Mandatory scope:** every `ReconciliationAccount` for the entity with `reconciliation_enabled=true` at evaluation time. If none exist, the gate is **vacuously satisfied** (the Product Owner's own definition of "mandatory" is "configured as `reconciliation_enabled`" — zero configured accounts means zero requirements, not a missing provider); `evidence_version=0`, `evidence_hash` is the SHA-256 of an empty set, `source_reference=null`, `produced_at`/`reviewed_by`/`reviewed_at` reflect the evaluation itself.

**Evaluation:** for each mandatory `ReconciliationAccount`, find the current `Completed` `BankReconciliation` covering the requested `period_ref`. Any mandatory account without one → `status=unmet`, all evidence fields `null` (§12.7's honest-absence shape). If every mandatory account has qualifying evidence, recompute a **staleness check**: the latest `posted_at`/`reversed_at` among (a) `JournalEntry` rows posted to the account's `ledger_account_id` within the period, (b) `Allocation` rows (post or reversal) referencing the account's `bank_account_id` within the period, and (c) the reconciliation's own last import/line-mutation timestamp — compared against each qualifying `BankReconciliation.source_data_watermark` (frozen at `Completed`, §14.9). Any later relevant event → `status=unmet` (stale), never a client-facing error, matching M5's `report_run_stale` precedent (§13.12) exactly.

**Composite evidence, single frozen `CloseGateResult` shape (§12.7 unchanged, not modified by this amendment):** because one gate can require multiple accounts' evidence but `CloseGateResult` carries exactly one `source_reference`/`evidence_hash`, `source_reference` is the `id` of the qualifying `BankReconciliation` with the latest `completed_at` (the freshest, "controlling" evidence); `produced_at`/`reviewed_by`/`reviewed_at` are that same reconciliation's; `evidence_version` is the **count** of qualifying `ReconciliationAccount`s bundled into this satisfied result; `evidence_hash` is the SHA-256 of the sorted, newline-joined list of `{reconciliation_account_id}:{bank_reconciliation_id}:{version}` for every qualifying reconciliation — tamper-evident across the whole set, verifiable, and reproducible.

```json
{"contract_version":1,"gate_type":"bank_reconciliation_completed","entity_id":"6503b7fb-6b03-4106-a7e7-b6c4692057ee","period_id":"45124343-5a6e-48c2-821d-6685cf1fd46e","period_ref":"2026-07","status":"satisfied","source_context":"reconciliation","source_reference":"9c1e5c2a-9d0e-4c8a-9c3a-1f9e7b0a2d3c","produced_at":"2026-08-01T09:00:00.000Z","reviewed_by":"1b8f3c2f-4e62-4fa9-a924-77848017a9a6","reviewed_at":"2026-08-01T09:12:00.000Z","evidence_version":2,"evidence_hash":"7ad2...c410"}
```

### 14.12 Export

**PDF and CSV export only; XLSX excluded**, matching M5-GOV-001's precedent. `GET /v1/reconciliations/{id}/statement?format=pdf|csv` (`reconciliation.reconciliations.read`, `R`) produces the reconciliation statement from the current persisted state (any batch state — a `Draft`/`InProgress` export is a live preview; a `Completed` export reads the frozen snapshot) and creates no accounting mutation, audit business event, or business outbox event. Output identifies at minimum: `reconciliation_account_id`, `period_ref`, `opening_balance`, `closing_balance`, every line with its status and (if resolved) its `Allocation`/`JournalEntry` reference, the unexplained difference, and the batch `state`. `format` values other than `pdf`/`csv` (including `xlsx`) return `400 validation`; unauthorized/cross-entity `id` returns `404 not_found`.

### 14.13 Reconciliation/Ledger/Settlement context boundary

Reconciliation reads M3 `Allocation` facts through Settlement's own existing read contracts and reads Ledger facts (for staleness evaluation, §14.11) through Ledger's own existing read contracts — never a direct table read of `settlement_allocations`/`journal_lines` bypassing an owning context's query contract (AP-001). Every write Reconciliation causes outside its own `reconciliation_accounts`/`bank_reconciliations`/`statement_line` tables happens through the owning context's application service: `CreateEntryForBankLine` invokes Ledger's `PostingService`; nothing in this amendment writes an `Allocation`, `JournalEntry`, or `JournalLine` row directly. `ReconciliationCloseGateProvider` is the only new consumer of the unchanged M4 `CloseGateProvider` v1 interface introduced by this amendment.

### 14.14 Stable errors

New `details.rule` values, using the frozen shared envelope (§2): `invalid_ledger_account` (422), `duplicate_reconciliation_account` (422), `duplicate_import_file` (422), `reconciliation_not_editable` (422), `currency_mismatch` (422), `line_not_matchable` (422), `match_total_mismatch` (422), `allocation_already_consumed` (422), `line_not_matched` (422), `offset_account_required` (400), `invalid_offset_account` (422), `line_not_resolvable` (422), `lines_not_fully_reconciled` (422), `unexplained_difference` (422), `reconciliation_not_completable` (422), `reconciliation_not_reopenable` (422). `bank_reconciliation_stale` surfaces as an `unmet` gate, never a client-facing error (§14.11), exactly matching `report_run_stale`'s M5 precedent (§13.12).

### 14.15 Traceability

| Amendment rule | Frozen source |
|---|---|
| `ReconciliationAccount` references, never duplicates, an existing asset-type `LedgerAccount` | `M6-GOV-001` item 1; Context Interaction Matrix §9 |
| Match candidates are M3 `Allocation.bank_amount`, never raw journal lines | `M6-GOV-001` item 2; API Contracts §11 (`bank_amount` = actual cash moved) |
| Exact currency/amount, ±3 day window, reference as ranking only, suggestion-only, no tolerance/fuzzy/hidden write-off | `M6-GOV-001` item 3 |
| Five-state line model, five-state batch model, no force-close, no unexplained bypass | `M6-GOV-001` item 4 |
| Manual offset account only, no inferred classification, mandatory four-eyes, posts via `PostingService` | `M6-GOV-001` item 5; AP-001 |
| Duplicate rejection (not silent skip), defined identity tuple, file-hash + source-line preservation | `M6-GOV-001` item 6 |
| Foreign-currency statements in scope, same-currency matching only, no FX tolerance | `M6-GOV-001` item 7; ADR-007 |
| `reconciliation_enabled` accounts mandatory, staleness on new/reversed bank activity, `bank_reconciliation_completed` only | `M6-GOV-001` item 8; §12.7 (M4) |
| Mandatory four-eyes scope: `CreateEntryForBankLine`, `CompleteReconciliation`, `ReopenReconciliation` only | `M6-GOV-001` item 9 |

M6 introduces no M7 Migration endpoint, no consolidation/CTA behavior, no XLSX export, no cross-currency or tolerance matching, no inferred accounting classification, no Hard Close bypass, and no change to the M4 `CloseGateProvider` v1 interface or any Reporting-owned gate.
