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
GET  /v1/tax/codes/applicable?jurisdiction=&date=
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
