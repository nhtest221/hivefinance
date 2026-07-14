# HiveFin — API Contracts (Public / Frontend-Facing)

**Frozen inputs (immutable):** all prior artifacts through the Implementation Roadmap.
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

*(Stopping per instruction — awaiting your review before continuing.)*
