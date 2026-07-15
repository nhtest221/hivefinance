# Proposed API Contract Amendment — M0 Walking Skeleton

**Status:** Conditionally approved on 15 July 2026 after the corrections recorded in §9; approved content is incorporated into the frozen API Contract through its M0 amendment.  
**Target artifact if approved:** `docs/HiveFin_API_Contracts.md`  
**Scope:** The four public endpoints needed by the M0 manual-journal walking skeleton.  
**Evidence source:** Existing M2 backend request/response shapes on `main` at `bd4cddb`; those shapes are evidence, not authority.

## 1. Governing frozen artifacts

This proposal is constrained by:

- SRS v3.0 §3, §4.3, §5.2: entity isolation, RBAC/ABAC, maker-checker policy, balanced manual journals, Draft → Posted lifecycle, and posted immutability.
- Aggregate Design §1 (`JournalEntry`) and §6 (`LedgerAccount`): whole journal aggregate, balanced functional-currency lines, versioned Draft mutations, immutable Posted entries, and balances outside the Account aggregate.
- Repository Contracts §1 (`JournalEntryRepository`) and §3 (`GeneralLedgerQuery`): whole-aggregate persistence, optimistic concurrency for Draft state, posted immutability, and separate read-model queries.
- Database Design §1 (`ledger`) and §2: exact numeric storage, entity scope, version columns, posted-row immutability, and indexed ledger reads.
- API Contracts §§1–3: `/v1`, `X-Entity-Id`, authenticated actor, RBAC+ABAC, `If-Match`, `Idempotency-Key`, Money objects, stable errors, maker-checker, and period locking.
- Engineering Constitution: ARCH-01–05, DOM-02/04/05/09, API-01–07, REPO-01–06, SEC-01/02/06/08, LOG-01/02, ERR-01–05, and TEST-03–05.

No frozen artifact is changed by this proposal.

## 2. Common protocol

### 2.1 Authentication and entity scope

All four endpoints require:

```http
Authorization: Bearer <access-token>
X-Entity-Id: <uuid>
```

- The token must identify an active user and session.
- `X-Entity-Id` must be a UUID for an entity granted to the actor.
- Authentication failure returns `401 authentication_required`.
- Missing, malformed, inaccessible, or inactive entity scope returns `403 authorization` without revealing whether another entity's record exists.
- Reads require the capability stated per endpoint. Commands are default-deny and require the stated capability in the active entity.

### 2.2 Correlation ID

Clients may send:

```http
X-Correlation-Id: <uuid>
```

- If supplied, it must be a UUID; otherwise return `400 validation`.
- If omitted, the server generates a UUID.
- Every success and error response echoes `X-Correlation-Id`.
- The same value propagates through structured request logs, the application command, audit metadata, outbox metadata, event dispatch, and consumer logs.
- The ID is diagnostic only and must not be used as an idempotency key.

### 2.3 Money and decimals

Money is serialized as:

```json
{ "amount": "1250.0000", "currency": "BDT" }
```

- `amount` is an exact base-10 string matching `^-?\d+(\.\d{1,4})?$`.
- JSON floating-point amounts are rejected.
- `currency` is an uppercase three-letter code.
- For M0 manual journals, every line currency must equal the active entity's functional currency. Foreign-currency journal lines require the later frozen FX capability and are outside this amendment.

### 2.4 Error envelope

All errors use:

```json
{
  "error_code": "validation",
  "message": "The request is invalid.",
  "details": {
    "fields": {
      "lines.1.credit.amount": ["Must be an exact decimal with at most four fractional digits."]
    }
  },
  "doc_id": null,
  "required_version": null
}
```

Optional fields may be omitted when irrelevant. No stack trace, SQL detail, internal table name, or another entity's identifier may be returned.

| HTTP | `error_code` | Use |
|---:|---|---|
| 400 | `validation` | Malformed headers, query parameters, or body |
| 401 | `authentication_required` | Missing, expired, or invalid authentication |
| 403 | `authorization` | Capability or entity access denied |
| 403 | `sod_exception_required` | Policy requires explicit compensating-control justification |
| 404 | `not_found` | Entity-scoped aggregate not found |
| 409 | `concurrency_conflict` | `If-Match` does not equal current version |
| 409 | `idempotency_conflict` | Key was already used with a different canonical request |
| 422 | `invariant_violation` | Balanced-journal, line, state, or domain invariant failed |
| 423 | `period_locked` | No eligible/postable period for the accounting date |

### 2.5 Pagination

Collection endpoints use opaque cursor pagination:

- `limit`: optional integer, default `50`, minimum `1`, maximum `100`.
- `cursor`: optional opaque token returned by the preceding response.
- Invalid cursors return `400 validation`.
- Stable ordering and cursor contents are implementation-private.

Response metadata:

```json
{
  "page": {
    "limit": 50,
    "next_cursor": "opaque-token-or-null"
  }
}
```

## 3. `GET /v1/accounts`

Lists Ledger-owned accounts available to the actor in the active entity. It does not return balances; balances are a separate query per Aggregate and Repository Contracts.

### Request

Headers: common authentication, entity, and correlation headers.

Query parameters:

| Name | Required | Validation |
|---|---:|---|
| `status` | No | `active` or `deactivated`; default `active` for journal-entry selection |
| `cursor` | No | Opaque pagination cursor |
| `limit` | No | Integer `1..100`; default `50` |

Authorization capability: `ledger.accounts.read`.

### Success — `200 OK`

```json
{
  "accounts": [
    {
      "id": "9d6f89da-e3fd-44db-8ef7-1de6eaa2d8f0",
      "code": "1010",
      "name": "Cash",
      "type": "asset",
      "normal_balance": "debit",
      "status": "active",
      "version": 1
    }
  ],
  "page": {
    "limit": 50,
    "next_cursor": null
  }
}
```

The response intentionally omits `entity_id`; entity scope is already established by the request and returning it adds no frontend value.

### Errors

`400 validation`, `401 authentication_required`, and `403 authorization`.

## 4. `POST /v1/journals`

Creates one editable manual `JournalEntry` Draft and all its lines as one aggregate operation. It does not post the journal.

### Request

Required headers:

```http
Authorization: Bearer <access-token>
X-Entity-Id: <uuid>
Idempotency-Key: <uuid>
Content-Type: application/json
```

Optional: `X-Correlation-Id`.

Authorization capability: `ledger.journals.create`.

```json
{
  "entry_date": "2026-07-15",
  "entry_type": "manual",
  "narration": "Transfer operating cash",
  "reference": "BANK-TRANSFER-001",
  "lines": [
    {
      "account_id": "9d6f89da-e3fd-44db-8ef7-1de6eaa2d8f0",
      "description": "Debit cash",
      "debit": { "amount": "1000.0000", "currency": "BDT" },
      "credit": null
    },
    {
      "account_id": "f95a760b-cd75-4635-99d3-20a9a4773a50",
      "description": "Credit clearing",
      "debit": null,
      "credit": { "amount": "1000.0000", "currency": "BDT" }
    }
  ]
}
```

### Validation

- Body must be a JSON object; unknown fields are rejected.
- `entry_date`: required ISO `YYYY-MM-DD`; must belong to a valid fiscal period returned by the Period context. Draft creation does **not** require that period to be currently postable. Postability is checked only by the posting command.
- `entry_type`: optional; if supplied must be exactly `manual`. Adjusting/system/reversal types are outside this M0 endpoint.
- `narration`: optional string or `null`, maximum 2,000 characters.
- `reference`: optional string or `null`, maximum 255 characters.
- `lines`: required array with at least two items.
- Each `account_id`: required UUID for an active Ledger account in the active entity.
- `description`: optional string or `null`, maximum 2,000 characters.
- Exactly one of `debit` and `credit` must be non-null and strictly greater than zero on each line.
- All Money currencies must equal the active entity's functional currency.
- Sum of debit amounts must exactly equal sum of credit amounts after the defined fixed-precision boundary; imbalance returns `422 invariant_violation`.
- No float/double coercion is permitted.

### Success — `201 Created`

```json
{
  "journal": {
    "id": "bcde89a4-f5d1-49ca-8e14-3a2d13f7d293",
    "period_ref": "2026-07",
    "entry_type": "manual",
    "entry_date": "2026-07-15",
    "state": "Draft",
    "narration": "Transfer operating cash",
    "reference": "BANK-TRANSFER-001",
    "reversal_of_entry_id": null,
    "posted_at": null,
    "posted_by": null,
    "version": 1,
    "lines": [
      {
        "id": 4101,
        "line_no": 1,
        "account_id": "9d6f89da-e3fd-44db-8ef7-1de6eaa2d8f0",
        "description": "Debit cash",
        "debit": { "amount": "1000.0000", "currency": "BDT" },
        "credit": null
      },
      {
        "id": 4102,
        "line_no": 2,
        "account_id": "f95a760b-cd75-4635-99d3-20a9a4773a50",
        "description": "Credit clearing",
        "debit": null,
        "credit": { "amount": "1000.0000", "currency": "BDT" }
      }
    ]
  }
}
```

### Idempotency

- `Idempotency-Key` is mandatory because draft creation is a state-changing financial command.
- Scope is actor + entity + endpoint.
- The server stores the key, canonical request hash, status, and response atomically with aggregate creation.
- An identical retry returns the original `201` status and byte-equivalent semantic response with `Idempotent-Replay: true`.
- Reuse with a different canonical request returns `409 idempotency_conflict`.

### Errors

`400 validation`, `401 authentication_required`, `403 authorization`, `409 idempotency_conflict`, and `422 invariant_violation`.

## 5. `POST /v1/journals/{id}/post`

Transitions a balanced manual journal from Draft to Posted. The transaction must persist the immutable posting, audit record, and `JournalPosted` outbox event atomically. Event dispatch occurs only after commit and performs no strong posting work.

### Request

Required headers:

```http
Authorization: Bearer <access-token>
X-Entity-Id: <uuid>
Idempotency-Key: <uuid>
If-Match: 1
```

Optional: `X-Correlation-Id` and, only after a `sod_exception_required` response, `X-SoD-Justification`.

- `{id}` must be the JournalEntry UUID.
- Request body must be empty.
- `If-Match` is the current integer aggregate version returned by draft creation.
- Authorization capability: `ledger.journals.post`.
- Maker-checker and SoD behavior follows the active entity's Approval Policy. Architecture defines no monetary threshold; the endpoint must not hardcode one.

### Success — `200 OK`

```json
{
  "journal": {
    "id": "bcde89a4-f5d1-49ca-8e14-3a2d13f7d293",
    "period_ref": "2026-07",
    "entry_type": "manual",
    "entry_date": "2026-07-15",
    "state": "Posted",
    "narration": "Transfer operating cash",
    "reference": "BANK-TRANSFER-001",
    "reversal_of_entry_id": null,
    "posted_at": "2026-07-15T08:40:10.000Z",
    "posted_by": "3dfb60df-a763-443d-ab47-d61bead09484",
    "version": 2,
    "lines": [
      {
        "id": 4101,
        "line_no": 1,
        "account_id": "9d6f89da-e3fd-44db-8ef7-1de6eaa2d8f0",
        "description": "Debit cash",
        "debit": { "amount": "1000.0000", "currency": "BDT" },
        "credit": null
      },
      {
        "id": 4102,
        "line_no": 2,
        "account_id": "f95a760b-cd75-4635-99d3-20a9a4773a50",
        "description": "Credit clearing",
        "debit": null,
        "credit": { "amount": "1000.0000", "currency": "BDT" }
      }
    ]
  }
}
```

### Pending approval command outcome — `202 Accepted`

```json
{
  "status": "PendingApproval",
  "approval_id": "14b16944-7d98-4ad6-b684-3872fd5e4a21",
  "journal_id": "bcde89a4-f5d1-49ca-8e14-3a2d13f7d293",
  "journal_version": 1,
  "submitted_at": "2026-07-15T08:40:10.000Z"
}
```

This is a successful command outcome, not an error and not an instance of the common error envelope. No posting, `JournalPosted` event, or posted-state audit record exists until approval commits the command.

### Idempotency and concurrency

- Idempotency scope is actor + entity + endpoint + journal ID.
- The key, canonical request identity (`journal ID`, `If-Match`, and applicable SoD justification hash), final status, and response are persisted with command processing.
- Identical retries return the original status and response with `Idempotent-Replay: true`.
- Reusing a key for another request returns `409 idempotency_conflict`.
- A nonmatching `If-Match` returns `409 concurrency_conflict` with `required_version`; no state, audit, or outbox write occurs.
- Once Posted, the aggregate is immutable. A new key cannot repost it and returns `422 invariant_violation`.

### Errors

`400 validation`, `401 authentication_required`, `403 authorization`, `403 sod_exception_required`, `404 not_found`, `409 concurrency_conflict`, `409 idempotency_conflict`, `422 invariant_violation`, and `423 period_locked`.

## 6. `GET /v1/reports/general-ledger`

Returns the accrual-basis General Ledger detail for one Ledger account in the active entity. It is a read-model query and never loads or returns JournalEntry aggregates.

### Request

Headers: common authentication, entity, and correlation headers.

Query parameters:

| Name | Required | Validation |
|---|---:|---|
| `account` | Yes | UUID of an account in the active entity |
| `range` | Yes | Inclusive `YYYY-MM-DD..YYYY-MM-DD`; start must not exceed end |
| `cursor` | No | Opaque pagination cursor |
| `limit` | No | Integer `1..100`; default `50` |

Authorization capability: `ledger.reports.read`.

The basis is fixed to accrual for this endpoint; a caller cannot request a cash basis.

### Success — `200 OK`

```json
{
  "account": {
    "id": "9d6f89da-e3fd-44db-8ef7-1de6eaa2d8f0",
    "code": "1010",
    "name": "Cash",
    "normal_balance": "debit"
  },
  "basis": "accrual",
  "range": {
    "from": "2026-07-01",
    "to": "2026-07-31"
  },
  "opening_balance": { "amount": "0.0000", "currency": "BDT" },
  "entries": [
    {
      "journal_entry_id": "bcde89a4-f5d1-49ca-8e14-3a2d13f7d293",
      "line_id": 4101,
      "entry_date": "2026-07-15",
      "reference": "BANK-TRANSFER-001",
      "description": "Debit cash",
      "debit": { "amount": "1000.0000", "currency": "BDT" },
      "credit": null,
      "running_balance": { "amount": "1000.0000", "currency": "BDT" }
    }
  ],
  "closing_balance": { "amount": "1000.0000", "currency": "BDT" },
  "page": {
    "limit": 50,
    "next_cursor": null
  }
}
```

- `opening_balance` is the posted balance strictly before `range.from`.
- `running_balance` includes the opening balance and every posted line through that item in stable accounting order.
- `closing_balance` is the balance through the last date in the requested range, independent of page size.
- Ordering must be deterministic: accounting date, journal-entry stable key, then line number/stable line key.
- Running balances continue across cursor pages. The first entry of every subsequent page includes the opening balance and **all** prior activity in the requested range; it never restarts from `opening_balance`.
- The read model evaluates a cursor against the same immutable query boundary and deterministic sort tuple used for the preceding page. It derives each page's starting balance from the range opening balance plus all posted activity preceding that cursor. The cursor is opaque and exposes no storage or implementation detail.
- Only Posted entries are included.

### Errors

`400 validation`, `401 authentication_required`, `403 authorization`, and `404 not_found`.

## 7. Existing implementation comparison

The implementation observations below are evidence from `main`; they are not incorporated into the proposed contract unless the proposal explicitly says so.

| Area | Existing implementation | Frozen requirement / proposed correction | Assessment |
|---|---|---|---|
| Entity/auth scope | Sanctum authentication, `X-Entity-Id`, entity-scoped queries, capability checks | Authenticated, entity-isolated RBAC+ABAC | Broadly aligned; malformed/missing entity-header behavior needs stable contract mapping |
| Account list | Returns every entity account ordered by code, including `entity_id`; no filters or pagination | Paginated, active-default selector; omit redundant entity ID | Contract gap and scalability mismatch |
| Draft request | `entry_date`, optional `manual|adjusting`, strings for debit/credit plus separate currency | M0 only `manual`; exact Money objects; FX/adjusting behavior deferred | Existing API exposes later-scope `adjusting` and does not use frozen Money shape |
| Draft idempotency | No `Idempotency-Key`; retries create duplicate drafts | API-03/DOM-09 require state-changing financial commands to be idempotent | Material mismatch |
| Draft balance | Minimum two lines, active account, one-sided nonzero line, exact equality | Aggregate must always be balanced | Aligned in intent |
| Currency invariant | Each line carries a currency, but different currencies may balance as raw numbers | No implicit conversion; M0 must use functional currency only | Material accounting mismatch |
| Draft response | Raw decimal strings plus separate currency; lowercase state; includes `entity_id` | Money objects and ubiquitous lifecycle names | Shape mismatch |
| Post idempotency | Key is searched in outbox JSON metadata; replay returns `{event, idempotent_replay}` instead of original response | Dedicated atomic command result; identical replay returns original semantic response/status; conflicting reuse rejected | Material API-03/ERR-05 mismatch |
| Post concurrency | Version increments, but `If-Match` is neither accepted nor checked | API convention requires `If-Match`; repository contract requires optimistic version checks | Material mismatch |
| Post authorization | Capability check exists | Approval Policy, maker-checker, and SoD must be enforced where configured | Partial; no policy/approval behavior is visible in the posting path |
| Post transaction | State, outbox record, and audit record are written in one DB transaction | Strong posting work atomic; dispatch after commit | Aligned for persistence; dispatcher remains a separate M0 gap |
| Posted immutability | Store trigger foundation exists | Posted rows immutable at application and database levels | Broadly aligned; contract tests still required |
| Correlation ID | No endpoint convention, response echo, or outbox propagation is evident | LOG-01 and M0 task require end-to-end propagation | Missing |
| GL validation | Missing account becomes an empty-string filter; malformed/missing `range` silently becomes unbounded | Required UUID/date-range validation and stable errors | Material API-04 mismatch |
| GL not found | Unknown account returns `200` with an empty list | Entity-scoped unknown account returns `404 not_found` | Mismatch |
| GL ordering | Orders by auto-increment line ID only | Deterministic accounting order | Mismatch |
| GL running balance | Starts at zero for filtered entries; ignores balance before range | Opening balance plus in-range activity | Accounting/reporting mismatch |
| GL pagination | Loads all matching lines | Cursor pagination | Missing |
| GL response | Raw amounts and line currency; omits account metadata, opening/closing balance, line/reference identifiers | Money objects, basis label, reproducible range summary | Shape and completeness mismatch |
| Layering | Application services query Eloquent models directly; Account/Journal models are returned through persistence-aware presenters | ARCH-01 and REPO-01–05 require domain aggregates, repository contracts, and separated query DTOs | Existing architecture mismatch beyond merely documenting HTTP shapes |
| Period access | Ledger injects `App\Ledger\Application\PeriodService`, which queries Period persistence | AP-001 requires a Period-owned public `IsDatePostable` contract; Ledger must not access Period repositories/tables | Existing context-boundary mismatch already identified by M0 alignment review |

## 8. Decisions requiring human approval

Approval of this proposal would explicitly decide:

1. Exact decimal strings inside Money objects for JSON transport.
2. Mandatory idempotency for both draft creation and posting.
3. Mandatory `If-Match` on posting.
4. Cursor pagination with default 50 and maximum 100.
5. Required bounded date range for General Ledger.
6. `X-Correlation-Id` as the public correlation header.
7. M0 manual journals accept functional currency only; FX journal lines remain deferred.
8. Approval-policy/SoD protocol applies to posting without inventing thresholds.
9. Account-list default status is `active` for the journal selector.
10. The General Ledger response includes opening, running, and closing balances.

## 9. Conditional-approval corrections and traceability

The approving instruction dated 15 July 2026 required and this revision applies:

1. Draft creation validates fiscal-period membership but does not check current postability; `423 period_locked` was removed from `POST /v1/journals`.
2. `202 PendingApproval` is defined as a dedicated command-outcome schema without `error_code` or the common error envelope.
3. General Ledger running balances explicitly continue across cursor pages through an opaque, deterministic query boundary and carry-forward balance calculation.

The approved contract text is frozen only where incorporated into `docs/HiveFin_API_Contracts.md`. This proposal remains the governance rationale and implementation-comparison record.
