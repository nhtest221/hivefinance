# Proposed Governance Amendment — M5 Reporting and Cash View

**Status:** PROPOSED — NOT FROZEN — NOT APPROVED FOR IMPLEMENTATION

**Authority requested:** Incorporation into the frozen artifacts listed in §16 only after explicit approval

**Scope:** M5A Reporting read models and financial statements; M5B Cash View and Close-Gate Evidence

**Non-scope:** M6 Reconciliation implementation, migration, automatic bank matching, consolidation/CTA (ADR-010, deferred), XLSX export, application code of any kind

**Revision note:** this revision resolves all seven items previously listed as open governance
decisions, using explicit Product Owner decisions supplied for this revision. §0 is updated to
reflect what those decisions changed; §16.3 replaces the prior "Unresolved governance decisions"
list with a record of how each was resolved and its source.

---

## 0. Research summary (what already exists)

Before drafting, the repository, `docs/`, prior completion reports, and
`/Users/hello/Documents/hive_finance/governance-proposals/` were searched for existing
Reporting material. Findings that shape this proposal:

1. **`GET /v1/reports/general-ledger` is already frozen**, in full, at `HiveFin_API_Contracts.md`
   §7.5 (part of the M0 Walking Skeleton amendment) — exact query params, response schema,
   ordering, and cross-page running-balance continuation rules already exist. This proposal
   **reuses it verbatim** and proposes only two small additive fields (§5).
2. **`GET /v1/reports/trial-balance` and `GET /v1/accounts/{id}/balance` already exist as
   working, tested code** (`app/Ledger/Application/LedgerReportService.php`,
   `app/Http/Controllers/Reports/LedgerReportController.php`, routed since M1 commit `f40daa2`,
   covered by `LedgerCoreTest.php`), even though neither ever received its own numbered
   contract subsection the way General Ledger did. `GET /v1/accounts/{id}/balance` **is** frozen
   (§8.3.4). `GET /v1/reports/trial-balance` is **not** — this proposal formalizes its existing,
   already-shipped shape (§4) rather than replacing it.
3. Both existing report services live inside `App\Ledger`, not a `App\Reporting` context (which
   does not exist yet anywhere in `backend/app/`). This sits against `HiveFin_Repository_Contracts.md`
   §3 ("Reporting — Query Interfaces") and `HiveFin_Aggregate_Design.md` §16 ("Reporting owns read
   models... materialised by consuming domain events"), both of which describe Trial Balance and
   General Ledger as Reporting-owned. **Per explicit Product Owner decision this revision
   incorporates (§13, §16.3 item 7), this is not migrated or rewritten.** Ledger keeps owning
   Trial Balance and General Ledger and their established read contracts; Reporting consumes them
   through explicit adapters. A future context extraction remains possible only through a separate
   governance decision.
4. **The Notionhive Chart of Accounts is already in the frozen SRS** (`HiveFin_SRS_v3.0.md`
   §5.10, lines 96–105): a `Class` column groups every seed account into `1 Assets`,
   `2 Liabilities`, `3 Equity`, `4 Revenue`, `5 Cost of Sales`, `6 Operating Exp.`. This is real,
   frozen, citable material — but it is a **seed-data convention**, not an enforced schema rule:
   `ledger_accounts.type` (verified in `app/Models/Ledger/LedgerAccount.php` and its migration)
   is a plain unconstrained string application-validated to exactly five values
   (`asset|liability|equity|revenue|expense`). It **cannot** distinguish `5 Cost of Sales` from
   `6 Operating Exp.` — both are `type=expense`. This is the concrete, evidenced reason §6
   proposes an explicit `AccountClassificationMap` rather than inferring COGS/OPEX from
   `type` or from account names.
5. **ADR-001's cash-view algorithm is already LOCKED**, not fully open, as the earlier blocker
   report characterized it. `HiveFin_Decision_Log.md` lines 8–58 show: consequence #6 states
   the algorithm plainly — *"re-time to payment date, pro-rate by paid proportion, value at
   receipt-date rate, net of withholding"* — and lock rider #2 additionally requires *"strip VAT
   portion."* The one remaining sub-decision ADR-001 itself flagged as open — *"MVP scope boundary
   of the cash layer"* — is **now resolved by explicit Product Owner decision this revision
   incorporates**: Cash View ships as a derived management report only; a cash-basis Profit and
   Loss restatement is excluded from M5 MVP. §10 implements the locked algorithm plus the
   Product Owner's additional approved behavior for partial settlement, unapplied credit, refunds,
   reversals, and SBU allocation.
6. **The M4-built `CloseGateProvider` v1 interface is live code**, not just documentation:
   `app/Period/Application/CloseGateProvider.php`, `app/Period/Domain/CloseGateResult.php`,
   `app/Period/Infrastructure/UnavailableCloseGateProvider.php`, and
   `config/period.php:close_gates` (mapping `trial_balance_reviewed`, `profit_and_loss_approved`,
   `balance_sheet_approved`, `vat_outputs_approved` → `'reporting'`, and
   `bank_reconciliation_completed` → `'reconciliation'`) are already merged on `main`. §11 defines
   the exact `ReportingCloseGateProvider` this proposal must plug into that interface —
   field-for-field identical to the already-shipped `CloseGateResult` shape.
7. **VAT return-box mapping is already a solved, reusable pattern**: `tax_code_versions`
   already carries `return_box_mapping(json)`, validated against configured
   `valuation.tax.return_box_keys` (`app/Tax/Application/TaxService.php`). §9 reuses this
   directly for the Tax/VAT Summary report rather than inventing a new filing-form structure.
8. **`journal_lines.sbu_tag` already exists** (nullable string, `create_ledger_tables`
   migration), populated when Bill/Expense's decimal-weighted `sbu_allocations` recognition
   splits into per-SBU journal lines. This is the existing mechanism SBU filtering in §4/§5/§6
   reuses — not a new dimension invented for M5.
9. No `PROPOSED_API_CONTRACT_AMENDMENT_M{n}` or completion report anywhere in the repository or
   in `governance-proposals/` defined a P&L layout, Balance Sheet layout, ageing buckets, or a
   cash-view MVP scope decision at the time of the prior revision. `HiveFin_SRS_v3.0.md:109`'s
   *"exact Notionhive layout with computed lines and % columns"* was referenced but its content
   was not present in any frozen or archived document searched. **This revision resolves that gap
   using explicit Product Owner decisions (§6, §7, §8) rather than inferring a layout from the
   repository search.** §16.4 records the provenance of those decisions precisely.
10. **Source handling.** The Product Owner decisions incorporated by this revision (the P&L
    computed-line skeleton, the detailed ageing buckets, SBU-filtered reports, and PDF/CSV export
    expectations) are grounded in — and cite — the same SRS §5.10/§5.11 evidence already found in
    items 4 and 9 above: the Chart-of-Accounts class table and the report/export inventory
    sentence. That older evidence is cited as **supporting material for the Product Owner's
    decision**, not as a frozen authority in its own right — it was never itself an approved
    layout or bucket specification. These choices become authoritative for implementation only
    once this proposal is approved and incorporated into the frozen artifacts listed in §16.1;
    until then they remain proposed, exactly like every other section of this document.

---

## 1. Canonical Milestone

Canonical name: **M5 — Reporting and Cash View**.

Delivery is conceptually split without creating new roadmap milestones, mirroring the
already-approved M4A/M4B pattern (`HiveFin_Implementation_Roadmap.md` §"M4 delivery slices"):

- **M5A — Reporting read models and financial statements:** Trial Balance, General Ledger,
  Profit and Loss, Balance Sheet, AR/AP Ageing, Tax/VAT Summary, FX Revaluation summary; the
  `ReportRun` immutable-evidence lifecycle; configuration providers (`ReportLayout`,
  `AccountClassificationMap`, `AgeingBucketSet`); persistence, repository contracts, tests.
- **M5B — Cash View and Close-Gate Evidence:** the Cash View report and its `CashViewPolicy`
  configuration provider; the `ReportingCloseGateProvider` implementation of M4's
  `CloseGateProvider` v1 for `trial_balance_reviewed`, `profit_and_loss_approved`,
  `balance_sheet_approved`, and `vat_outputs_approved`; frontend review/approval flows;
  close-gate integration tests.

M5 depends on completed M1 Ledger + Valuation, M2 Documents, M3 Settlement, and M4 Corrections/
Notes/Period Close. M5 delivers the evidence M4's Hard Close already knows how to consume
(`config/period.php:close_gates`) but until this proposal is approved and implemented, those four
gates correctly remain `unmet` via `UnavailableCloseGateProvider` — M5 introduces no bypass and
fabricates no evidence. M5 implements no M6 Reconciliation behavior and does not satisfy
`bank_reconciliation_completed`.

The smallest roadmap amendment is to use the canonical name in the roadmap table, show M5A/M5B
as conceptual slices analogous to M4A/M4B, and record that M5 supplies the four Reporting-owned
Hard Close gates while `bank_reconciliation_completed` remains M6's.

---

## 2. Existing Public Report Endpoints — Common Protocol

### 2.1 Inherited protocol

All public endpoints inherit the frozen M0–M4 protocol exactly (`HiveFin_API_Contracts.md` §1–3,
§7.1, §8.1, and the M2/M3/M4 amendments' identical restatements):

- TLS and authentication required. `X-Entity-Id` required; entity scope is default-deny; unknown
  or cross-entity resources return `404 not_found`.
- `X-Correlation-Id` optional; a UUID is generated when absent; a malformed supplied value
  returns `400 validation`. The effective value is echoed and propagated to audit, outbox, and
  approval replay.
- State-changing endpoints (`POST /v1/report-runs`, `POST /v1/report-runs/{id}/approve`) require
  UUID `Idempotency-Key`. Exact replay returns the original status/body with
  `Idempotent-Replay: true`; changed input returns `409 idempotency_conflict`.
- `If-Match` is required on `POST /v1/report-runs/{id}/approve` (guards the `ReportRun` version).
  Missing required `If-Match` returns `428 precondition_required`; stale versions return
  `409 concurrency_conflict` with `required_version`.
- Report **query** endpoints (`GET /v1/reports/*`) and `GET /v1/report-runs/{id}/export` are
  read-only: no `Idempotency-Key`, no `If-Match`, no approval, no audit write, no outbox event.
  **A `GET` request never creates business state** — every `GET /v1/reports/*` endpoint below
  computes and returns a value; it never creates a `ReportRun` row, and export never creates or
  mutates a `ReportRun` either (§14.1). Only the explicit `POST /v1/report-runs` command creates
  immutable evidence.
- Unknown body or query fields return `400 validation`.
- Money, UUIDs, ISO dates, UTC timestamps, exact decimal strings, TaxSnapshot,
  ExchangeRateReference, durable approval responses, and opaque signed cursors reuse the frozen
  representations exactly (§2.2).
- Configured approval returns the frozen `202` approval resource on `POST /v1/report-runs/{id}/approve`
  and commits no originating effect until approved. Errors use the frozen envelope; additional
  rule names appear in `details.rule` and create no second error envelope.

Header profiles reused from M4 (`PROPOSED_GOVERNANCE_AMENDMENT_M4...` §2.1):

| Profile | Required | Optional |
|---|---|---|
| `R` | `Authorization`, `X-Entity-Id` | `X-Correlation-Id` |
| `W0` | `Authorization`, `X-Entity-Id`, `Idempotency-Key` | `X-Correlation-Id` |
| `W1` | `Authorization`, `X-Entity-Id`, `Idempotency-Key`, `If-Match` | `X-Correlation-Id` |

Every `GET /v1/reports/*` endpoint uses profile `R`.

### 2.2 Money and reproducibility references

Money is `{ "amount":"1250.0000", "currency":"BDT" }` — an exact decimal string, at most four
fractional digits, never a JSON number; `currency` is uppercase ISO-style three letters. Every
report is presented in the entity's functional currency (`HiveFin_Decision_Log.md` ADR-007);
Reporting introduces no new presentation-currency conversion (deferred to the Multi-Entity ADR
per `docs/README.md` Governance Notes).

Reports never accept a client-supplied figure, rate, classification, or bucket boundary. Every
computed value derives from already-posted, already-immutable facts (`JournalLine`,
`TaxSnapshot`, `RateRecord`, `Allocation`) plus versioned Reporting-owned configuration
(§6, §7, §8, §10).

### 2.3 Unknown-field behavior, correlation, audit

- Unknown query fields on any `GET /v1/reports/*` endpoint return `400 validation` (§2.1).
- `X-Correlation-Id` is echoed on every response and propagated to any audit/outbox writes made
  by the two `ReportRun` commands.
- `GET /v1/reports/*` produces **no audit record** (read-only, matching `ledger.reports.read`'s
  existing behavior — `LedgerReportService` writes no audit today, and this proposal does not
  change that). `POST /v1/report-runs` and `POST /v1/report-runs/{id}/approve` **do** write audit
  records, following the exact pattern already used by every M2–M4 command
  (`$this->audit->record(...)`), and emit outbox events (§15).

### 2.4 Endpoint inventory

```
GET  /v1/reports/trial-balance?asOf=&period_ref=&sbu=&limit=&cursor=
GET  /v1/reports/general-ledger?account=&range=&sbu=&limit=&cursor=      (already frozen §7.5; additive only)
GET  /v1/reports/profit-loss?period=&sbu=&basis=accrual&compare_to=      (basis accepts accrual only; basis=cash returns 422 unsupported_basis, §10.4)
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

14 endpoints total: 9 report queries (all pre-existing in the thin §4 sketch, `API_Contracts.md`
lines 130–137), 4 `ReportRun` lifecycle endpoints (§3), and 1 export endpoint (§14.1) — Product
Owner decision, §16.3 item 6.

---

## 3. Immutable Report Runs and Approval Evidence

### 3.1 Why a ReportRun is required

M4's Hard Close gate contract (`API_Contracts.md` §12.6.4, `close_gate_evidence` table, already
implemented) requires *"immutable Trial Balance output"* with *"approved reviewer evidence"* per
gate. A live `GET /v1/reports/trial-balance` call is a **preview** — it recomputes from current
data every time and proves nothing was reviewed or approved. Satisfying Hard Close therefore
needs an explicit, separately-approved, frozen snapshot distinct from the query endpoints. This
is the smallest structure that supplies that: a single `ReportRun` resource type, reused across
all report types, rather than one lifecycle per report.

### 3.2 Minimum command surface

Exactly two write commands are proposed, plus two reads:

1. `POST /v1/report-runs` — **generate** an immutable snapshot of a named report for a named
   entity/period/basis/filter combination.
2. `POST /v1/report-runs/{id}/approve` — **approve** (durable four-eyes) a generated run,
   making it eligible as Hard Close evidence.
3. `GET /v1/report-runs/{id}` — retrieve one run (its frozen content, hash, and lifecycle state).
4. `GET /v1/report-runs?...` — list runs for an entity, needed so the frontend and the Period
   close-gate UI can discover whether a current, approved run already exists for a period before
   asking someone to regenerate one.

No separate "review" command is proposed. **Decided by explicit Product Owner decision this
revision incorporates (§16.3 item 5):** one public `ReportRun` approval command is used for MVP;
there is no separate public review endpoint. Generation (`POST /v1/report-runs`) and approval
(`POST /v1/report-runs/{id}/approve`) must be performed by different actors — the checker who
approves performs the review and the approval in the same durable action. `reviewed_by` and
`approved_by` may therefore identify the same checker, and `reviewed_at`/`approved_at` are
recorded atomically from that one act. This is consistent with the already-shipped
`CloseGateResult` (`app/Period/Domain/CloseGateResult.php`), which exposes exactly one reviewer
pair — `reviewedBy`/`reviewedAt` — not a distinct preparer-then-reviewer-then-approver chain, so
no new consumer-side field is needed. **The generator (maker) may not approve their own run** —
the approval command enforces maker/checker separation exactly as every M2–M4 approval command
already does, returning `403 sod_exception_required` on a same-actor attempt rather than a silent
allow. Splitting review and approval into two distinct human steps in a future revision remains
possible but is not part of this proposal.

A rejected run follows the existing durable approval lifecycle's decline outcome — no separate
M5 rejection endpoint is added. It sets `state=Rejected`; it satisfies no gate and blocks
nothing — the preparer may generate a new run.

### 3.3 Lifecycle

```
Generated ──(approve, no configured maker-checker)──▶ Approved ──▶ (superseded by a later Approved run for the same key)──▶ Superseded
Generated ──(approve, configured maker-checker)──▶ PendingApproval ──(durable approval commits)──▶ Approved ──▶ Superseded
                                                    PendingApproval ──(durable approval rejects)──▶ Rejected
```

- **Generated**: an immutable content snapshot exists; not yet gate-eligible.
- **PendingApproval**: only reached when the entity's Approval Policy requires maker-checker for
  `report_run_approve` (reusing the exact configured-policy mechanism M1–M4 already use — no new
  threshold is hardcoded, per `HiveFinance-Engineering-Constitution-v1.0.md` and every prior
  amendment's non-negotiable rule).
- **Approved**: gate-eligible. Sets `approved_by`/`approved_at` and, per §3.2,
  `reviewed_by`/`reviewed_at` identically.
- **Rejected**: terminal; not gate-eligible; not superseded (nothing to supersede).
- **Superseded**: automatic and atomic the instant a *different* run for the identical
  reproducibility key — `(entity_id, report_type, basis, period_ref-or-as_of, filters)`, deliberately
  excluding the version/watermark fields so a re-run triggered by new data or configuration still
  counts as "the same report" — reaches `Approved`. The **previously Approved** run for that key
  (if any) transitions to `Superseded` in the same transaction. A merely `Generated` (never
  approved) run for the same key is left untouched — it was never gate-eligible, so nothing needs
  superseding, and no orphaned business state results.

This means a Hard Close gate is satisfied by, at most, exactly one `Approved` run per key at any
time — matching the task's *"A superseded, stale, rejected or unapproved ReportRun cannot satisfy
Hard Close"* requirement directly.

### 3.4 ReportRun schema

| Field | Type | Notes |
|---|---|---|
| `id` | UUID | `report_run_id` |
| `entity_id` | UUID | entity isolation |
| `report_type` | string | `trial_balance \| general_ledger \| profit_and_loss \| balance_sheet \| ar_ageing \| ap_ageing \| tax_summary \| fx_revaluation \| cash_view` |
| `period_ref` | string, nullable | for period-scoped reports (P&L, tax summary, cash view, FX revaluation) |
| `as_of` | date, nullable | for point-in-time reports (Trial Balance, Balance Sheet, ageing); exactly one of `period_ref`/`as_of` is populated per `report_type` |
| `range` | `{from,to}`, nullable | General Ledger only |
| `basis` | string | `accrual` for every report type except Cash View, which is always `cash`; Profit and Loss `basis=cash` is explicitly excluded from M5 MVP by decision (§10.4) |
| `functional_currency` | string | copied from the entity at generation time |
| `filters` | JSON | `sbu`, `customer`/`vendor`, `account`, or empty; exact caller-supplied filter, never inferred |
| `layout_version` | int, nullable | P&L/BS only — pins the `ReportLayout` version used |
| `classification_version` | int, nullable | P&L/BS only — pins the `AccountClassificationMap` version used |
| `policy_version` | int, nullable | Cash View → `CashViewPolicy`; AR/AP ageing → `AgeingBucketSet`; null for reports needing no extra policy |
| `source_data_watermark` | string (UTC timestamp or monotonic sequence) | the latest posted-fact cutoff included; §3.5 |
| `content` | JSONB | the exact frozen response body of the corresponding `GET` report, byte-identical to what a live call would have returned at generation time |
| `content_hash` | string | lowercase SHA-256 of the canonicalized `content` |
| `generated_by` | UUID | actor who ran `POST /v1/report-runs` |
| `generated_at` | UTC timestamp | |
| `reviewed_by` | UUID, nullable | set by `/approve`, §3.2 |
| `reviewed_at` | UTC timestamp, nullable | |
| `approved_by` | UUID, nullable | set by `/approve` |
| `approved_at` | UTC timestamp, nullable | |
| `state` | string | `Generated \| PendingApproval \| Approved \| Rejected \| Superseded` |
| `version` | int | optimistic concurrency for `/approve`'s `If-Match` |
| `superseded_by_report_run_id` | UUID, nullable | supersession linkage, §3.3 |

### 3.5 Source-data watermark

The watermark is the **maximum `posted_at` of any `JournalEntry` (and, for Tax Summary, any
posted `TaxSnapshot`-bearing document) included in the computed content**, captured at generation
time. It exists so (a) two runs generated moments apart can be told apart even if no visible
figure changed, and (b) the `ReportingCloseGateProvider` (§11) can detect staleness: if new
qualifying postings exist in the period *after* an Approved run's watermark, that run can no
longer satisfy the gate — it is stale, not merely old.

### 3.6 Endpoint contracts

**`POST /v1/report-runs`** — Capability `reporting.report_runs.generate`. Headers `W0`.

```json
{"request":{"report_type":"trial_balance","as_of":"2026-07-31","filters":{}},
 "response":{"report_run":{"id":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d","report_type":"trial_balance","as_of":"2026-07-31","basis":"accrual","state":"Generated","version":1,"content_hash":"1f3d...af02","source_data_watermark":"2026-07-31T18:04:22.000Z"}}}
```

Validation: `report_type` must be one of §3.4's enum; exactly one of `period_ref`/`as_of` is
required per type; `filters` unknown keys rejected; entity-owned `account`/`customer`/`vendor`
filter UUIDs, if present, must resolve. Missing `ReportLayout`/`AccountClassificationMap`/
`AgeingBucketSet`/`CashViewPolicy` configuration for a report type that needs one fails safely
(§15) and creates **no** `ReportRun` row — generation is all-or-nothing.

`201` returns the created run (`state=Generated`). Rules: `report_source_not_ready`,
`missing_report_layout`, `missing_account_classification`, `unclassified_account`,
`missing_ageing_bucket_set`, `missing_cash_view_policy`, `report_unbalanced` (defensive; §4/§7),
and `423 period_locked` is **not** applicable (generation never posts).

**`GET /v1/report-runs/{id}`** — Capability `reporting.report_runs.read`. Headers `R`. Returns the
full row including `content`. `404 not_found` for a cross-entity or unknown id.

**`GET /v1/report-runs`** — Capability `reporting.report_runs.read`. Headers `R`. Query:
`report_type`, `period` (matches `period_ref` or `as_of`), `state`, `limit`/`cursor` (§2.1
pagination). `200` returns summaries (no `content`) plus `page`.

**`POST /v1/report-runs/{id}/approve`** — Capability `reporting.report_runs.approve`. Headers
`W1`. Empty body. Configured maker-checker may return `202` (§3.3); maker/original-generator
separation applies exactly as M2–M4's approval commands already enforce.

```json
{"request":{},"response":{"report_run":{"id":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d","state":"Approved","approved_by":"<uuid>","approved_at":"2026-08-01T09:12:00.000Z","reviewed_by":"<uuid>","reviewed_at":"2026-08-01T09:12:00.000Z","version":2}}}
```

Rules: `report_run_not_found`, `report_run_already_approved`, `report_run_rejected`,
`sod_exception_required` (maker/checker are the same actor).

---

## 4. Trial Balance

`GET /v1/reports/trial-balance` **formalizes the existing, tested M1 implementation**
(`LedgerReportService::trialBalance()`) rather than replacing it — every field it already
returns is preserved. Additive changes are marked *(new)*.

- **As-of behavior:** `asOf` (optional; defaults to the entity's current accounting date, exactly
  as today) selects the closing point. `200` always returns a closing balance per account.
- **Opening, movement, closing values** *(new, additive)*: an optional `period_ref` selects a
  fiscal period; when present, the response additionally includes `opening` (balance strictly
  before the period start) and `movement` (net debit/credit turnover within the period) per row,
  alongside the existing closing balance — directly supporting the SRS §5.13 month-end gate step
  *"review Trial Balance."* When `period_ref` is omitted, behavior is unchanged from today
  (closing balance only).
- **Debit and credit presentation:** unchanged — each row splits its signed balance into
  non-negative `debit`/`credit` strings (never both nonzero), exactly as implemented today.
- **Zero-balance handling:** unchanged existing behavior — every active-or-inactive account in
  the entity's chart of accounts appears as a row, including zero-balance accounts; no account is
  silently dropped. (No `include_zero_balances` toggle is proposed; the existing default is
  preserved rather than adding an untested new flag.)
- **Account ordering:** unchanged — ascending by `code`.
- **Functional-currency basis:** unchanged — entity functional currency only; `basis` is fixed to
  `accrual` (SRS §5.11: Trial Balance is accrual-only, no cash toggle).
- **SBU filtering** *(new)*: optional `sbu` query parameter restricts summed `journal_lines` to
  those carrying the matching `sbu_tag` (§0.8). Omitted `sbu` sums all lines regardless of tag,
  unchanged from today.
- **Source watermark and reproducibility:** a live `GET` call has no persisted watermark (it is a
  preview); an `Approved` `ReportRun` of `report_type=trial_balance` freezes both the content and
  `source_data_watermark` (§3.5), making the exact figures reproducible after later postings.
- **Balancing invariant:** `totals.debit` exactly equals `totals.credit` and `totals.balanced` is
  `true` by construction, since every summed line comes from already-balanced Posted
  `JournalEntry` rows (Ledger's own `balanced_journal` invariant, unchanged and un-re-derived
  here). This report never changes Ledger ownership of that invariant — it only reads it.

```json
{"request_query":{"asOf":"2026-07-31","sbu":null,"limit":50,"cursor":null},
 "response":{"as_of":"2026-07-31","rows":[{"account_id":"<uuid>","code":"1010","name":"Cash","debit":"12500.0000","credit":"0.0000"},{"account_id":"<uuid>","code":"4010","name":"Client Service Revenue","debit":"0.0000","credit":"12500.0000"}],"totals":{"debit":"12500.0000","credit":"12500.0000","balanced":true},"page":{"limit":50,"next_cursor":null}}}
```

---

## 5. General Ledger

`GET /v1/reports/general-ledger` **is already frozen** (`API_Contracts.md` §7.5) and is reused
**exactly**, including: required `account` and `range` query parameters; fixed `basis=accrual`;
opening/closing balance semantics (opening = posted activity strictly before `range.from`;
closing = posted activity through `range.to`, independent of page size); "only Posted entries are
included"; stable order (accounting date, journal-entry stable key, line number); and running
balances that continue correctly across cursor pages without a page-local restart.

Two small, additive fields close the remaining task requirements without touching the frozen
shape:

- **SBU filters** *(new, optional)*: `sbu` query parameter, identical semantics to §4.
- **Reversal link representation** *(new field on each entry)*: `reversal_of_entry_id` (nullable
  UUID) is added to each `entries[]` item, copied from the already-existing
  `journal_entries.reversal_of_entry_id` column (`ADR-002`; unchanged Ledger ownership) — so a
  reader can see, without a second call, that a line belongs to a reversing entry.

Journal and source-document references (`journal_entry_id`, `reference`), transaction and
functional Money, debit/credit fields, and running-balance behavior are all already exactly
specified in §7.5 and unchanged here. The report continues to read Ledger's own approved
`JournalLine`/`JournalEntry` projections and defines no new posting rule.

```json
{"request_query":{"account":"<uuid>","range":"2026-07-01..2026-07-31","sbu":null,"limit":50,"cursor":null},
 "response":{"account":{"id":"<uuid>","code":"1010","name":"Cash","normal_balance":"debit"},"basis":"accrual","range":{"from":"2026-07-01","to":"2026-07-31"},"opening_balance":{"amount":"0.0000","currency":"BDT"},"entries":[{"journal_entry_id":"<uuid>","line_id":4101,"entry_date":"2026-07-15","reference":"BANK-TRANSFER-001","description":"Debit cash","debit":{"amount":"1000.0000","currency":"BDT"},"credit":null,"reversal_of_entry_id":null,"running_balance":{"amount":"1000.0000","currency":"BDT"}}],"closing_balance":{"amount":"1000.0000","currency":"BDT"},"page":{"limit":50,"next_cursor":null}}}
```

---

## 6. Profit and Loss

The literal *"exact Notionhive layout"* referenced at `HiveFin_SRS_v3.0.md:109` was searched for
across the full repository, `docs/`, every completion report, and
`/Users/hello/Documents/hive_finance/governance-proposals/` and does not exist verbatim in any
frozen or archived document (§0 item 9). **This section now uses the explicit Product Owner
decision this revision incorporates** (§16.3 item 2), grounded in — and citing — the SRS §5.10
Chart-of-Accounts class table already found (§0 item 4) as supporting evidence. That decision, not
the older evidence by itself, is what this proposal treats as authoritative once approved.

### 6.1 Approved computed-line skeleton (P&L layout v1)

| # | Line | Computation |
|---|---|---|
| 1 | Sales Revenue | sum of accounts classified `sales_revenue` |
| 2 | Total Cost of Sales | sum of accounts classified `cost_of_sales` |
| 3 | Gross Profit | `Sales Revenue − Total Cost of Sales` |
| 4 | Gross Profit % | `Gross Profit ÷ Sales Revenue × 100` |
| 5 | Total Operating Expenses | sum of accounts classified `operating_expense` |
| 6 | Operating Profit | `Gross Profit − Total Operating Expenses` |
| 7 | Total Non-Operating Income | sum of accounts classified `non_operating_income` (net of `non_operating_expense`, if configured, per §6.2) |
| 8 | Net Profit | `Operating Profit + Total Non-Operating Income` |
| 9 | Net Profit % | `Net Profit ÷ Sales Revenue × 100` |

**A zero denominator (`Sales Revenue = 0.0000`) returns a `null` percentage for lines 4 and 9,
never a division-by-zero error and never a fabricated `0%`.**

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

This is the approved P&L layout v1's exact `ReportLayout` encoding — no longer illustrative. Row
ordering, the two computed percentage lines, and every formula match the approved skeleton
verbatim. A frozen `ReportRun` of `report_type=profit_and_loss` references this exact
`layout_version` (§3.4), so the nine lines above are reproducible regardless of later layout
changes. Comparison periods: `compare_to` (optional query param, a second `period_ref`) returns a
second parallel `values` object per line, computed with the same pinned layout/classification
versions.

### 6.2 `AccountClassificationMap` (versioned configuration)

Approved classification groups: `sales_revenue`, `cost_of_sales`, `operating_expense`,
`non_operating_income`, and, only if explicitly configured, `non_operating_expense` and
`tax_or_other_configured_group`. **Account inclusion and row ordering come from this versioned
map and the §6.1 `ReportLayout` — accounts are never classified from their name or code during
report generation.**

```json
{"account_classification_map":{"id":"<uuid>","version":1,"entries":[
  {"account_id":"<uuid>","code":"4010","classification":"sales_revenue"},
  {"account_id":"<uuid>","code":"5010","classification":"cost_of_sales"},
  {"account_id":"<uuid>","code":"6010","classification":"operating_expense"},
  {"account_id":"<uuid>","code":"4050","classification":"non_operating_income"},
  {"account_id":"<uuid>","code":"6220","classification":"non_operating_expense"}
],"effective_from":"2026-07-01","effective_to":null}}
```

The existing Chart-of-Accounts class structure (SRS §5.10) **may seed the first version** of this
configuration — its `4 Revenue`/`5 Cost of Sales`/`6 Operating Exp.` groupings are a reasonable
starting point and the entries above illustrate that seeding — but the frozen `ReportRun` always
references the **exact classification and layout versions actually used**, never the seed data
implicitly; a later re-classification produces a new version, and old `ReportRun`s keep citing
the version that was effective when they were generated.

Every account referenced by a Posted `JournalLine` inside the requested period **must** have an
explicit entry in the effective `AccountClassificationMap`, or generation fails with the approved
stable configuration error, `422 unclassified_account`, naming the account — never a name-pattern
or `type`-based guess (§0.4 already shows why `type` alone is insufficient: `4050 FX Gain` is
`type=revenue` but the seed CoA places it under *"Other Income"*, and `6220 FX Loss` is
`type=expense` but under *"Operating Exp."* labeled separately as FX Loss — exactly the kind of
placement `type` cannot express and this map exists to capture explicitly).

### 6.3 Balancing and safety

No formula in the `ReportLayout` may reference a classification group through anything but the
same `classification_keys` used for `AccountClassificationMap` entries — the report engine never
free-forms arithmetic outside the approved layout's declared `computed_line.formula` set. Missing
`ReportLayout` or `AccountClassificationMap` for the requested effective date fails safely with
`422 missing_report_layout` / `422 missing_account_classification` and produces no partial
figures.

```json
{"request_query":{"period":"2026-07","sbu":null,"basis":"accrual","compare_to":null},
 "response":{"period_ref":"2026-07","basis":"accrual","layout_version":1,"classification_version":1,"lines":[{"section_id":"sales_revenue","label":"Sales Revenue","amount":{"amount":"12500.0000","currency":"BDT"}},{"section_id":"total_cost_of_sales","label":"Total Cost of Sales","amount":{"amount":"4000.0000","currency":"BDT"}},{"section_id":"gross_profit","label":"Gross Profit","amount":{"amount":"8500.0000","currency":"BDT"}},{"section_id":"gross_profit_pct","label":"Gross Profit %","percentage":"68.0000"},{"section_id":"total_operating_expense","label":"Total Operating Expenses","amount":{"amount":"3000.0000","currency":"BDT"}},{"section_id":"operating_profit","label":"Operating Profit","amount":{"amount":"5500.0000","currency":"BDT"}},{"section_id":"total_non_operating_income","label":"Total Non-Operating Income","amount":{"amount":"0.0000","currency":"BDT"}},{"section_id":"net_profit","label":"Net Profit","amount":{"amount":"5500.0000","currency":"BDT"}},{"section_id":"net_profit_pct","label":"Net Profit %","percentage":"44.0000"}]}}
```

---

## 7. Balance Sheet

**Approved by explicit Product Owner decision this revision incorporates (§16.3 item 3).**

### 7.1 Approved top-level structure (Balance Sheet layout v1)

| Section | Content |
|---|---|
| Assets | rows for every account classified under an approved asset classification |
| Liabilities | rows for every account classified under an approved liability classification |
| Equity | rows for every account classified under an approved equity classification, including retained earnings and current-period result (§7.3) |
| Total Assets | sum of the Assets section |
| Total Liabilities | sum of the Liabilities section |
| Total Equity | sum of the Equity section |
| Total Liabilities and Equity | `Total Liabilities + Total Equity` |
| Difference | `Total Assets − Total Liabilities and Equity` |

**The invariant is Assets = Liabilities + Equity** — i.e. `Difference` must be exactly
`0.0000`. **Any non-zero `Difference` makes the report unbalanced and prevents approval**: `POST
/v1/report-runs` fails with `422 report_unbalanced` and creates no `ReportRun` row; an unbalanced
snapshot is never generated, let alone approved as Hard Close evidence.

### 7.2 Classification and layout — no inference

Current/non-current and any other subgroup presentation come from the same versioned
`AccountClassificationMap` (§6.2) and `ReportLayout` (§6.1) mechanism as Profit and Loss, using
Balance-Sheet-specific classification keys (e.g. `asset_current`, `asset_non_current`,
`liability_current`, `liability_non_current`, `equity`) as additional configured values. **These
classifications are never inferred from account names, codes, dates, or balances** — an account
with no effective classification entry fails generation with `422 unclassified_account`, exactly
as §6.2.

### 7.3 Retained earnings and current-period result

Retained earnings and the current-period profit/loss roll-up are represented through **explicit
configured classifications** (e.g. an `equity` entry for the existing `3020 Retained Earnings`
seed account and a distinct configured entry for `3030 Current-Year P&L`, SRS §5.10) and **exact
report-run references** — the current period's Net Profit (§6.1 line 8) feeds the Balance Sheet's
current-period-result equity row by reference to the same period's classification/layout
versions, never by an independent recomputation that could drift from the P&L figure.

```json
{"request_query":{"asOf":"2026-07-31","sbu":null,"compare_to":null},
 "response":{"as_of":"2026-07-31","layout_version":1,"classification_version":1,"sections":[{"section_id":"assets","label":"Assets","rows":[{"code":"1010","name":"Cash","amount":{"amount":"12500.0000","currency":"BDT"}}],"subtotal":{"amount":"12500.0000","currency":"BDT"}},{"section_id":"liabilities","label":"Liabilities","rows":[],"subtotal":{"amount":"0.0000","currency":"BDT"}},{"section_id":"equity","label":"Equity","rows":[{"code":"3030","name":"Current-Year P&L","amount":{"amount":"12500.0000","currency":"BDT"}}],"subtotal":{"amount":"12500.0000","currency":"BDT"}}],"total_assets":{"amount":"12500.0000","currency":"BDT"},"total_liabilities":{"amount":"0.0000","currency":"BDT"},"total_equity":{"amount":"12500.0000","currency":"BDT"},"total_liabilities_and_equity":{"amount":"12500.0000","currency":"BDT"},"difference":{"amount":"0.0000","currency":"BDT"}}}
```

---

## 8. Receivables and Payables Ageing

**Approved by explicit Product Owner decision this revision incorporates (§16.3 item 4).**

### 8.1 `AgeingBucketSet` (approved versioned default configuration)

```json
{"ageing_bucket_set":{"id":"<uuid>","version":1,"buckets":[
  {"bucket_id":"not_due","label":"Not Due","lower_days":null,"upper_days":-1,"order":1},
  {"bucket_id":"overdue_0_30","label":"0–30 Days Overdue","lower_days":0,"upper_days":30,"order":2},
  {"bucket_id":"overdue_31_60","label":"31–60 Days Overdue","lower_days":31,"upper_days":60,"order":3},
  {"bucket_id":"overdue_61_90","label":"61–90 Days Overdue","lower_days":61,"upper_days":90,"order":4},
  {"bucket_id":"overdue_90_plus","label":"91+ Days Overdue","lower_days":91,"upper_days":null,"order":5}
],"effective_from":"2026-07-01","effective_to":null}}
```

Five approved buckets, identical for Receivables and Payables: `not_due` (due date is after the
report `asOf` date), `overdue_0_30` (0 through 30 days overdue), `overdue_31_60` (31 through 60
days overdue), `overdue_61_90` (61 through 90 days overdue), and `overdue_90_plus` (91 or more
days overdue). This is the **approved default** `AgeingBucketSet` version 1 — still versioned
configuration, frozen into every `ReportRun` that references it (§3.4 `policy_version`), not a
hardcoded constant in the report engine; a future version may supersede it through the same
configuration mechanism, but no request may proceed without an effective version. Missing
configuration for the requested `asOf` fails safely with `422 missing_ageing_bucket_set`.

- **Ageing date basis:** ageing is based on **contractual due date versus the report `asOf`
  date** — `asOf` (required) is the evaluation date; `days_overdue = asOf − due_date`.
- **Document due-date basis:** each open Invoice/Bill's `due_date` (already an existing,
  immutable field once issued/approved) determines its bucket; `due_date > asOf` falls in
  `not_due`.
- **Partial settlement behavior:** **only the remaining open document value is aged** — ageing
  amount is the document's current `open_balance` (already exact and versioned, M2/M3), never the
  original total; partial settlement reduces the aged balance accordingly.
- **Unapplied credit display:** a party's unapplied Credit/Debit Note `undisposed_amount` and M3
  `PartyCreditBalance` are **displayed separately** and are **not silently netted against
  individual documents** — matching the no-hidden-allocation principle already binding on M3/M4
  (`CLAUDE.md` "no client-calculated... no hidden allocation").
- **Credit and negative balances:** a document or party position that is itself a credit or
  negative balance appears in a **separate credit section**, never silently absorbed into a
  positive-day bucket's total.
- **Dashboard aggregation:** the dashboard (SRS §5.1 "Receivables Ageing donut") **may aggregate
  `overdue_61_90` and `overdue_90_plus` into a single visual 60+ segment** for compact display;
  **detailed reports always retain the full approved five categories** — the aggregation is a
  presentation-layer grouping applied on top of the five-bucket detail, never a different
  underlying computation, and never replaces the detailed bucket set in `GET /v1/reports/ar-ageing`/
  `ap-ageing` or in a frozen `ReportRun`.
- **Customer/vendor totals:** one row per open document plus one summary row per party, plus a
  grand total; `currency` and `functional_currency` are both shown per row for foreign documents
  (their exact original currency, never converted silently).
- **Detail vs. summary:** `GET /v1/reports/ar-ageing` (and `ap-ageing`) returns both `detail`
  (one row per open document) and `summary` (one row per party per bucket) in the same response;
  no separate summary-only endpoint is proposed (minimality).

```json
{"request_query":{"asOf":"2026-07-31","customer":null},
 "response":{"as_of":"2026-07-31","bucket_set_version":1,"detail":[{"customer_id":"<uuid>","invoice_id":"<uuid>","document_number":"INV-1","due_date":"2026-07-10","open_balance":{"amount":"500.0000","currency":"BDT"},"bucket_id":"overdue_0_30"}],"summary":[{"customer_id":"<uuid>","totals_by_bucket":[{"bucket_id":"not_due","amount":{"amount":"0.0000","currency":"BDT"}},{"bucket_id":"overdue_0_30","amount":{"amount":"500.0000","currency":"BDT"}},{"bucket_id":"overdue_31_60","amount":{"amount":"0.0000","currency":"BDT"}},{"bucket_id":"overdue_61_90","amount":{"amount":"0.0000","currency":"BDT"}},{"bucket_id":"overdue_90_plus","amount":{"amount":"0.0000","currency":"BDT"}}],"credit_balances":{"amount":"0.0000","currency":"BDT"},"unapplied_credit":{"amount":"0.0000","currency":"BDT"},"total_open":{"amount":"500.0000","currency":"BDT"}}]}}
```

---

## 9. Tax and VAT Outputs

`GET /v1/reports/tax-summary` (accrual only, SRS §5.11/§6.1) aggregates already-immutable
`TaxSnapshot` records persisted on posted Invoice/Bill/Credit-Note/Debit-Note lines (ADR-006;
`app/Tax` — unchanged, existing) grouped by their **already-frozen** `return_box_mapping` keys
(§0.7) — this report invents no filing form and no statutory rate; it reads what Tax already
recorded.

Covers, per the task:

- **Output VAT**: sum of `TaxSnapshot` amounts where `treatment=standard|zero_rated` and the
  snapshot is on a customer-facing document, grouped by `return_box_mapping.output` (or
  equivalent configured key).
- **Recoverable/input VAT**: sum where `recoverable=true` on vendor-facing documents, grouped by
  `return_box_mapping.input`.
- **Withholding/AIT/VDS summaries**: reads the already-frozen `Allocation.withholding` records
  (M3) — no new withholding rule.
- **Jurisdiction, TaxPack, TaxCodeVersion references**: every aggregated figure carries its
  contributing `tax_code_id`/`tax_code_version_id`/`jurisdiction`, reproducing exactly (ADR-006
  consequence #3 — "every posted line persists its applied tax snapshot").
- **Period and document references**: each summary line links back to the contributing document
  IDs for drill-down.
- **Adjustments and reversals**: Credit/Debit Note VAT corrections (already posted "in the note's
  period," ADR-003/M4) and Invoice/Bill void reversals net naturally because the report sums
  posted `TaxSnapshot`-bearing facts for the period — no separate adjustment logic is introduced.
- **Totals and reconciliation**: `net_vat = output_vat - recoverable_input_vat` (ADR-006
  consequence #2, already the frozen formula) is returned as a top-level total.

Missing `return_box_mapping` configuration for a referenced `TaxCodeVersion` already fails safely
today (`app/Tax/Application/TaxService.php`'s existing `422 invariant_violation` check) — this
report performs no new invention here, only aggregation of what Tax already validated at posting
time.

```json
{"request_query":{"period":"2026-07"},
 "response":{"period_ref":"2026-07","jurisdiction":"BD","output_vat":{"amount":"1875.0000","currency":"BDT"},"recoverable_input_vat":{"amount":"450.0000","currency":"BDT"},"net_vat":{"amount":"1425.0000","currency":"BDT"},"boxes":[{"return_box_key":"CONFIGURED_VALUE","amount":{"amount":"1875.0000","currency":"BDT"},"document_ids":["<uuid>"]}]}}
```

---

## 10. Cash View

**All Cash View decisions are resolved by explicit Product Owner decision this revision
incorporates (§16.3 item 1).** Nothing in this section is open.

### 10.1 What is frozen and decided

Cash View is a **derived, rebuildable management report**. It is never a second Ledger, never a
duplicate journal entry, never an alteration of statutory (accrual) figures, and it is **not
presented as a statutory cash-basis Profit and Loss statement** — that remains excluded from M5
MVP (§10.4). This restates ADR-001's already-**LOCKED** decision (`HiveFin_Decision_Log.md` lines
15–17, 32) plus the Product Owner's MVP-scope resolution of ADR-001's one previously open item.

ADR-001's already-locked algorithm, implemented exactly:

1. Re-time eligible economic activity to its **settlement date**.
2. Pro-rate by the **settled proportion**.
3. Value foreign amounts using the **exact settlement-date RateRecord**.
4. **Exclude VAT** from revenue and expense presentation.
5. Report **actual bank cash net of withholding**.
6. **Preserve exact source, Settlement, document, TaxSnapshot, RateRecord, and SBU-allocation
   references** on every derived row — nothing is presented without its originating fact traceable.

### 10.2 Source events and computation

Cash View is built from the same Settlement facts already frozen by M3: `Allocation`
(`gross_amount`, `bank_amount`, `withholding_amount`, `allocated_amount`, `settlement_date`,
`rate_record_id`) and `AllocationLink` (`applied` Money per target document, and the source
document's `TaxSnapshot`/`sbu_allocations` references). For each Allocation in the requested
period:

- **Partially settled documents contribute only their settled proportion** — a document settled
  40% this period contributes exactly 40% of its economic value to Cash View, computed from
  `AllocationLink.applied` versus the document's total, never the full document value.
- **Recognition date:** `Allocation.settlement_date` (rule 1, §10.1).
- **Withholding:** `withholding_amount` is excluded from cash revenue/expense and shown
  separately in reconciliation metadata, never treated as bank cash (rule 5, §10.1).
- **Foreign currency:** valued at the Allocation's own `rate_record_id` — the exact
  settlement-date RateRecord (rule 3, §10.1); no client-supplied or re-derived rate is accepted.
- **Unapplied party credit:** appears in the cash-movement reconciliation as `unapplied_cash`
  (the bank receipt/payment genuinely happened) but is **not** counted as Cash View revenue or
  expense until it is applied to a document.
- **Later credit application:** when previously unapplied credit is later applied to a document,
  the derived economic row is attributed to the **original settlement date** (re-timing rule 1
  applies to the original cash event, not the later application) — no second bank movement is
  created, and no double-count results.
- **Refunds:** appear as a **negative cash movement on the refund date** (the refund's own
  `Allocation.settlement_date`), preserving the same exact-reference rule 6.
- **Reversals:** negate or rebuild the exact original derived rows using the original references
  and values — a reversed Allocation's Cash View contribution is exactly and traceably undone,
  never recomputed from a different basis.
- **SBU allocation:** follows the source document's already-frozen `sbu_allocations`, pro-rated
  using the existing exact-sum allocation rule (`Σ=1.0000`, M2) — the same weights as the accrual
  side, no re-derivation and no separate Cash View SBU rule.
- **Missing source allocation, RateRecord, or required policy fails safely** — `422
  missing_cash_view_policy` (or the underlying dependency's own existing safe-failure rule, e.g.
  `missing_rate_reference`) and produces no partial or guessed figure.
- **Cash View creates no journals, postings, audit events, or business outbox events merely by
  being queried or rebuilt** — a live `GET /v1/reports/cash-view` call, and rebuilding it, are
  both pure reads of already-posted Settlement facts (§2.1, §2.3).

### 10.3 `CashViewPolicy` (versioned configuration)

```json
{"cash_view_policy":{"id":"<uuid>","version":1,
  "recognition_date_source":"settlement_date",
  "proration_basis":"settled_proportion",
  "valuation_rate_source":"settlement_date_rate",
  "vat_treatment":"excluded",
  "withholding_treatment":"net_of_withholding_shown_separately",
  "unapplied_credit_treatment":"reconciliation_only_not_revenue_or_expense",
  "later_credit_application_treatment":"attributed_to_original_settlement_date",
  "refund_treatment":"negative_movement_on_refund_date",
  "reversal_treatment":"negate_or_rebuild_original_rows",
  "sbu_allocation_treatment":"follows_source_document_exact_sum_allocation",
  "rounding_scale":4,"rounding_mode":"CONFIGURED_MODE",
  "effective_from":"2026-07-01","effective_to":null}}
```

Every key above encodes an already-decided rule from §10.1/§10.2 as a fixed, versioned value —
none is a deployer choice; the policy is versioned for auditability and reproducibility (so a
`ReportRun` can pin exactly which rule set produced its figures), not because these rules may
vary by entity. `rounding_mode` alone reuses the existing `valuation.fx.rounding_scale`/
`rounding_mode` configuration (already governs M1/M3 FX rounding) and is the only field a
deployment sets independently, exactly matching the entity's already-configured FX rounding
policy — no new rounding rule is invented for Cash View.

### 10.4 Cash-basis Profit and Loss — excluded from M5 MVP (decided)

A full cash-basis Profit and Loss restatement (re-timing every revenue/expense line item, matching
ADR-001's originally-flagged "heavier... candidate to defer" option) is **excluded from M5 MVP**
by explicit Product Owner decision — the dedicated Cash View report (§10.1–§10.3) satisfies the
management requirement instead. `GET /v1/reports/profit-loss` therefore supports `basis=accrual`
only; a request with `basis=cash` returns `422 unsupported_basis`. No M5 endpoint computes a
cash-basis P&L. Including one remains possible only through a future, separate governance
amendment.

### 10.5 Rebuildability

Like every other report, Cash View is computed fresh from Settlement's immutable `Allocation`/
`AllocationLink` facts on every `GET` call and is fully rebuildable; an `Approved` `ReportRun`
freezes one point-in-time snapshot plus its `source_data_watermark`, exactly as §3.

```json
{"request_query":{"period":"2026-07","sbu":null},
 "response":{"period_ref":"2026-07","basis":"cash","policy_version":1,"cash_in_bank":{"amount":"9000.0000","currency":"BDT"},"collections":{"amount":"6000.0000","currency":"BDT"},"payments":{"amount":"3500.0000","currency":"BDT"},"net_cash_flow":{"amount":"2500.0000","currency":"BDT"},"withheld_excluded":{"amount":"400.0000","currency":"BDT"},"unapplied_cash":{"amount":"0.0000","currency":"BDT"},"refunds":{"amount":"0.0000","currency":"BDT"},"residual":{"amount":"0.0000","currency":"BDT"}}}
```

---

## 11. CloseGateProvider Integration

### 11.1 Reuse of the exact existing interface

M4 already shipped `App\Period\Application\CloseGateProvider` (interface),
`App\Period\Domain\CloseGateResult` (value object), and
`config('period.close_gates')` mapping the four Reporting gates to source context `'reporting'`.
This proposal's `ReportingCloseGateProvider` is a **new implementation of that unchanged
interface** — no interface change, no `CloseGateResult` field change:

```php
public function evaluate(
    int $contractVersion,      // = 1, unchanged
    string $entityId,
    string $periodId,
    string $periodRef,
    string $gateType,          // trial_balance_reviewed | profit_and_loss_approved | balance_sheet_approved | vat_outputs_approved
    string $correlationId,
    Carbon $evaluatedAt,
): CloseGateResult
```

### 11.2 Gate-to-report_type mapping

| Gate type | `report_type` looked up | `basis` |
|---|---|---|
| `trial_balance_reviewed` | `trial_balance` | `accrual` |
| `profit_and_loss_approved` | `profit_and_loss` | `accrual` |
| `balance_sheet_approved` | `balance_sheet` | `accrual` |
| `vat_outputs_approved` | `tax_summary` | `accrual` |

`bank_reconciliation_completed` is never handled by this provider; `config('period.close_gates')`
already maps it to `'reconciliation'` and this proposal does not touch that line.

### 11.3 Evaluation logic

For the mapped `report_type`, find the current `Approved`, non-`Superseded` `ReportRun` for
`(entityId, report_type, basis, periodRef-or-period-end-as_of)`. If none exists: `status=unmet`,
`sourceReference=null`, all other evidence fields `null` (identical to
`UnavailableCloseGateProvider`'s honest-absence shape, reused verbatim). If one exists:

1. **Staleness check** — recompute the latest qualifying posted-fact timestamp for that
   entity/period (the same watermark definition as §3.5) at evaluation time; if it exceeds the
   run's `source_data_watermark`, treat as `status=unmet` (stale evidence — new postings exist the
   approved run never saw). This directly implements *"stale... cannot satisfy Hard Close."*
2. Otherwise `status=satisfied`, and:

```json
{"gate_type":"profit_and_loss_approved","status":"satisfied","source_context":"reporting","source_reference":"7e4c2b0a-1a3e-4b7a-9c2e-3f5d6a7b8c9d","produced_at":"2026-08-01T09:00:00.000Z","reviewed_by":"<uuid>","reviewed_at":"2026-08-01T09:12:00.000Z","evidence_version":2,"evidence_hash":"1f3d...af02"}
```

`source_reference` = `ReportRun.id`; `produced_at` = `ReportRun.generated_at`; `reviewed_by`/
`reviewed_at` = `ReportRun.reviewed_by`/`reviewed_at` (§3.2's folded review-is-approval design);
`evidence_version` = `ReportRun.version`; `evidence_hash` = `ReportRun.content_hash`. This is a
direct, lossless field mapping — no new evidence shape, no fabrication path, matching the
already-frozen §7.2 example in the M4 proposal exactly.

A `Superseded`, `Rejected`, or still-`Generated`/`PendingApproval` run is never selected by the
lookup in step 1 at all — supersession/rejection/pending-ness make a run invisible to gate
evaluation by construction, not by an extra check.

---

## 12. Read Models and Persistence

| Structure | Nature | Notes |
|---|---|---|
| `report_runs` | **Immutable-once-approved append-mostly record** | One generic table for all report types (§3.4); `content` JSONB freezes the exact response body; state transitions are the only mutation, guarded by `version`; `Approved`/`Superseded`/`Rejected` rows never have `content`/`content_hash`/`source_data_watermark` altered after `Generated`. |
| `report_layout_versions` | **Configuration** | Effective-dated, versioned, append-only (new version, never edit-in-place — mirrors `TaxCodeVersion`'s existing pattern). |
| `account_classification_versions` | **Configuration** | Same pattern. |
| `ageing_bucket_set_versions` | **Configuration** | Same pattern. |
| `cash_view_policy_versions` | **Configuration** | Same pattern. |
| Trial Balance / General Ledger / P&L / Balance Sheet / Ageing / Tax Summary / Cash View **live queries** | **Rebuildable, not persisted** | Trial Balance and General Ledger are computed on demand by the existing Ledger-owned `LedgerReportService`, reached through Reporting's adapter (§13) — unchanged from today. P&L, Balance Sheet, Ageing, Tax Summary, and Cash View are new Reporting-owned live queries computed on demand from `JournalLine`-derived classified figures, `TaxSnapshot`-bearing document lines, and `Allocation`/`AllocationLink`. No `trial_balance_mv`/`gl_detail_mv`/etc. materialized-view tables are proposed; `report_runs.content` is the only persisted report body, and only for `Approved` evidence. This narrows `HiveFin_Database_Design.md:108-109`'s original seven-table sketch to one generic table plus live queries (§16.1 amendment). |

Every table carries `entity_id`, UUID `id`, UTC actor/timestamps, and `version` where mutable,
matching the established M0–M4 convention. Indexes: `report_runs(entity_id, report_type, basis,
period_ref, state)`, `report_runs(entity_id, report_type, basis, as_of, state)` for the §3.3
reproducibility-key lookup; configuration tables index `(entity_id_or_global, effective_from)`.

`report_runs` is Reporting-owned; no cross-context foreign key exists to Ledger/Receivables/
Payables/Settlement rows (AP-001 — UUIDs only, no shared-store joins).

---

## 13. Repository and Internal Contracts

**Reporting/Ledger context boundary — decided (§16.3 item 7):** `HiveFin_Repository_Contracts.md`
§3 ("Reporting — Query Interfaces... no aggregates") and `HiveFin_Aggregate_Design.md` §16
("materialised by consuming domain events... CQRS read side") describe Trial Balance and General
Ledger as Reporting-owned, but the existing, working, tested implementation lives in `App\Ledger`
(§0.3). **This proposal does not migrate or rewrite the existing Trial Balance, General Ledger, or
account-balance implementation merely to rename ownership.** Frozen by this decision:

- Existing public paths and compatible behavior remain unchanged (§4, §5).
- **Ledger continues to own Ledger facts and its established read contracts** —
  `LedgerReportService::trialBalance()`/`generalLedger()`/`accountBalance()` and the
  `LedgerAccount`/`JournalLine`/`JournalEntry` tables they read stay exactly where they are.
- **Reporting consumes Ledger-owned query contracts through explicit adapters** — new
  `TrialBalanceQuery`/`GeneralLedgerQuery` implementations in `App\Reporting\Infrastructure` call
  Ledger's existing `LedgerReportService` (an in-process, application-service call, not a direct
  table read) rather than re-implementing the computation, and rather than Reporting querying
  `journal_lines`/`ledger_accounts` itself.
- Every genuinely new M5 structure — `ReportRun`s, `ReportLayout`, `AccountClassificationMap`,
  `AgeingBucketSet`, `CashViewPolicy`, Cash View, and Close-Gate evidence — belongs to Reporting
  outright; only Trial Balance/General Ledger/account-balance remain Ledger-owned.
- **Reporting must not directly read Ledger tables where an approved query contract already
  exists** — the adapter above is the only path; Profit and Loss and Balance Sheet (§6, §7), which
  need account-level detail Ledger's existing queries do not expose, gain their own new
  `ProfitAndLossQuery`/`BalanceSheetQuery` implementations that read `JournalLine` directly,
  because no existing Ledger query contract covers classified/laid-out figures — that is new
  Reporting-owned surface, not a duplicate of Trial Balance/General Ledger.
- A future context extraction (moving Trial Balance/General Ledger fully into Reporting) may occur
  only through a separate governance decision — not implied or pre-approved by this proposal.

```text
TrialBalanceQuery(entityId, asOf, periodRef?, sbu?): TrialBalanceResult          // adapter over LedgerReportService::trialBalance()
GeneralLedgerQuery(entityId, accountId, dateRange, sbu?, cursor, limit): GeneralLedgerPage  // adapter over LedgerReportService::generalLedger()
ProfitAndLossQuery(entityId, periodRef, sbu?, basis, compareTo?): ProfitAndLossResult        // new, Reporting-owned
BalanceSheetQuery(entityId, asOf, sbu?, compareTo?): BalanceSheetResult                      // new, Reporting-owned
ARAgeingQuery(entityId, asOf, customerId?): AgeingResult
APAgeingQuery(entityId, asOf, vendorId?): AgeingResult
TaxSummaryQuery(entityId, periodRef): TaxSummaryResult          // accrual only
FXRevaluationQuery(entityId, periodRef): FXRevaluationResult
CashViewQuery(entityId, periodRef, sbu?): CashViewResult

ReportRunRepository
  GetById(reportRunId): ReportRun|null
  AddGenerated(run): void
  CommitApproval(run, expectedVersion): void            // sets Approved + supersession, one UoW
  CommitRejection(run, expectedVersion): void
  Search(entityId, filters, cursor, limit): ReportRunPage
  FindCurrentApproved(entityId, reportType, basis, periodKey): ReportRun|null   // §3.3, §11.3

ReportLayoutProvider        GetEffective(entityId|global, reportType, atDate): ReportLayout|null
AccountClassificationProvider  GetEffective(entityId|global, atDate): AccountClassificationMap|null
AgeingBucketProvider        GetEffective(entityId|global, atDate): AgeingBucketSet|null
CashViewPolicyProvider      GetEffective(entityId|global, atDate): CashViewPolicy|null

ReportingCloseGateProvider implements App\Period\Application\CloseGateProvider   // §11, unchanged interface
```

Query interfaces return read-model DTOs, never a write-context aggregate or ORM model (unchanged
rule, `Repository_Contracts.md` §3). Beyond the frozen Trial Balance/General Ledger adapter above,
Reporting reads Receivables/Payables/Settlement/Tax data by consuming their already-published
domain events into its own projections wherever feasible, and by read-only query calls to those
contexts' own frozen internal contracts (`AccountReferenceQuery`-style, already the established
M1–M4 pattern) where no event yet carries the needed fact — never a direct cross-context table
read (AP-001).

---

## 14. Frontend and Export Boundaries

Required M5 frontend flows, matching the density/style already established in
`frontend/src/features/{documents,settlement,notes}`:

- **Report selection:** a Reports page with one panel per report type (§2.4).
- **Filters:** period/as-of, SBU, party, account inputs per report type (§4–§10).
- **Preview:** a live `GET` call rendered read-only, clearly labeled "preview — not evidence" to
  avoid the exact confusion §3.1 exists to prevent.
- **Immutable generation:** a "Generate" action calling `POST /v1/report-runs`, capability-gated
  per report type (§3.6).
- **Review and approval:** an "Approve" action calling `POST /v1/report-runs/{id}/approve`, with
  approval-pending display matching the existing `outcome()` pattern in
  `settlement-page.tsx`/`notes-page.tsx`.
- **Comparison display:** rendering the optional `compare_to` parallel column (§6.1, §7).
- **Drill-down:** linking ageing/tax-summary/P&L rows to their contributing document IDs.
- **Close-gate status:** a panel on the Period page (not yet built — §16.1 notes no M4 Period
  frontend page exists today) showing each gate's `status`/`source_reference`, reusing §11.3's
  evidence shape directly.
- **Export:** a "Download" action per `ReportRun`, calling §14.1, offering `pdf`/`csv`, with the
  run's `state` visibly shown next to the download so a stale/superseded export is never mistaken
  for current evidence.

### 14.1 Export — decided (§16.3 item 6)

**PDF and CSV export are included in M5. XLSX is excluded and deferred.** One minimum read
endpoint is added:

`GET /v1/report-runs/{id}/export?format=pdf|csv` — Capability `reporting.report_runs.read`
(same capability as retrieving the run itself, §3.6). Headers `R`.

Rules:

- **Export is produced only from the immutable `ReportRun` snapshot** (`content`, §3.4) — it never
  reruns the underlying report calculation, and therefore never diverges from what was actually
  generated or approved.
- Output identifies, at minimum: `entity_id`, `report_type`, `filters`, `basis`, `period_ref`/
  `as_of`, `generated_at`, `content_hash`, `layout_version`, `classification_version` (where
  applicable), and `state` (`Generated`/`PendingApproval`/`Approved`/`Rejected`/`Superseded`) —
  every field needed to identify exactly which run, and what its evidentiary status was, without
  a second lookup.
- **PDF** is suitable for management review and the monthly closing file (SRS §5.13's month-end
  gate). **CSV** provides exact machine-readable report rows, one row per `content` line item,
  preserving exact decimal strings (never re-rendered as a locale-formatted or rounded number).
- **Export generation creates no accounting mutation and no business event** — it is a pure read
  of an already-immutable `content` value, exactly like every other `GET` in this proposal (§2.1).
- Unauthorized or cross-entity `id` values return the frozen safe response (`404 not_found`,
  unchanged entity-isolation rule, §2.1).
- `format` values other than `pdf`/`csv` return `400 validation` (unknown-field/invalid-value
  handling, unchanged §2.1 rule) — `xlsx` is explicitly one such rejected value, not a silently
  ignored one.
- **Exporting a stale or superseded run remains allowed, for historical audit** — a `Superseded`
  or otherwise non-current run may still be exported, but the export **must visibly state its
  `state`** (§14.1 output fields above), so a reader can never mistake a historical export for
  current Hard Close evidence.
- **Hard Close evidence still requires an `Approved` and non-`Superseded` run** (§11) — the
  existence of an export, of any state, has no bearing on gate satisfaction; only §11.3's lookup
  against the current `Approved` run matters there.

```json
{"request_query":{"format":"pdf"},
 "response_headers":{"Content-Type":"application/pdf","Content-Disposition":"attachment; filename=\"trial_balance_2026-07-31.pdf\""},
 "response_metadata_embedded_in_export":{"entity_id":"<uuid>","report_type":"trial_balance","filters":{},"basis":"accrual","as_of":"2026-07-31","generated_at":"2026-07-31T18:04:22.000Z","content_hash":"1f3d...af02","state":"Approved"}}
```

---

## 15. Stable Configuration Errors

All reused from the frozen shared envelope (`API_Contracts.md` §2: `{error_code, message,
details, doc_id?, required_version?}`); new `details.rule` values introduced by this proposal:

| Rule | HTTP | Meaning |
|---|---|---|
| `missing_report_layout` | `422` | No effective `ReportLayout` for the requested report type/date |
| `missing_account_classification` | `422` | No effective `AccountClassificationMap` for the requested date |
| `unclassified_account` | `422` | An account posted-to in the period has no entry in the effective map |
| `missing_ageing_bucket_set` | `422` | No effective `AgeingBucketSet` for the requested date |
| `missing_cash_view_policy` | `422` | No effective `CashViewPolicy` for the requested period |
| `report_source_not_ready` | `422` | A dependent context (e.g. Tax return-box config) is itself unconfigured |
| `report_unbalanced` | `422` | Balance Sheet fails Assets = Liabilities + Equity (§7) |
| `report_run_stale` | `422` | (used internally by §11.3; not directly client-facing — surfaces as an `unmet` gate, not an error, since gate evaluation never errors per M4's existing rule) |
| `report_run_not_approved` | `422` | `/approve` called on a run not in `Generated`/`PendingApproval` state |
| `report_run_already_approved` | `422` | Idempotent-looking but non-identical re-approval attempt |
| `report_run_rejected` | `422` | Action attempted on a `Rejected` run |
| `report_run_superseded` | `422` | Action attempted on a `Superseded` run |
| `unsupported_basis` | `422` | `basis=cash` requested on `GET /v1/reports/profit-loss` — excluded from M5 MVP by decision (§10.4) |
| `close_gate_evidence_invalid` | *(internal only)* | Reused unchanged from M4 — Period's own validation of a provider's returned evidence completeness, not a Reporting-raised error |

No stack trace or internal detail is ever exposed (unchanged rule).

---

## 16. Required Governance Amendments

**Nothing is modified by this proposal.** The following are the smallest changes required, listed
per frozen file, only to be made after explicit approval:

### 16.1 Smallest affected frozen artifacts

1. `docs/HiveFin_Implementation_Roadmap.md` — canonical M5 name, M5A/M5B conceptual slices, and
   the fact that M5 supplies four of the five baseline Hard Close gates.
2. `docs/HiveFin_API_Contracts.md` — the 14 endpoint contracts (§2–§11, §14.1), the `ReportRun`
   shared schema, the approved P&L layout v1 (§6.1), Balance Sheet layout v1 (§7.1), and default
   `AgeingBucketSet` v1 (§8.1), the export contract (§14.1), the `ReportingCloseGateProvider` v1
   registration (no interface change), and closure of the `GET /v1/reports/trial-balance`
   documentation gap noted in §0.2.
3. `docs/HiveFin_Aggregate_Design.md` — §16 gains the `ReportRun` lifecycle as Reporting's one
   owned aggregate (everything else in Reporting remains read-only projections, unchanged from
   today's "no aggregate roots" framing — `ReportRun` is the sole exception, and is itself a thin
   evidence/lifecycle record, not a financial-figures aggregate). §16 is also amended to state the
   frozen Trial Balance/General Ledger boundary (§13): those two reports remain Ledger-owned;
   Reporting consumes them through an adapter.
4. `docs/HiveFin_Database_Design.md` — §"reporting (read models)" replaced with §12's single
   `report_runs` table plus four configuration-version tables, narrowing the original seven-`mv`
   sketch (§12 table).
5. `docs/HiveFin_Repository_Contracts.md` — §3 gains the explicit query signatures and
   `ReportRunRepository`/provider interfaces from §13, and explicitly records that Trial
   Balance/General Ledger query contracts are Ledger-owned adapters, not new Reporting
   computations (§13, §16.3 item 7 — decided, not a migration).
6. `docs/HiveFin_Domain_Events.md` — four new Reporting-owned events (§16.2).
7. `docs/HiveFin_Decision_Log.md` — a new Governance Approval Record (`M5-GOV-001`, mirroring
   `M4-GOV-001`'s format) recording all seven Product Owner decisions this revision incorporates
   (§16.3), closing ADR-001's lock rider #2 (§10) and the Reports-decision open item, with
   traceability to this proposal and to the SRS §5.10/§5.11 evidence cited as supporting material
   (§0 item 10).

No SRS, ADR text itself (only the Decision Log's *new* GAR entry, not an edit to ADR-001's
existing text), Architecture Principle, Engineering Constitution, or M0–M4 public contract is
changed — `GET /v1/reports/general-ledger` (§7.5) is reused verbatim plus two additive fields,
`GET /v1/accounts/{id}/balance` (§8.3.4) is untouched, `CloseGateProvider` v1
(`API_Contracts.md` §12.7, M4) is unchanged, and the Trial Balance/General Ledger implementation
in `App\Ledger` is not migrated or rewritten (§13).

### 16.2 New domain events (Reporting-owned)

| Event | Aggregate | Trigger | Key fields | Consumers |
|---|---|---|---|---|
| `ReportRunGenerated` | ReportRun | `POST /v1/report-runs` | reportRunId, reportType, entityId, periodRef/asOf, basis, sourceDataWatermark, contentHash | Audit |
| `ReportRunApproved` | ReportRun | `/approve` commits | reportRunId, approvedBy, evidenceVersion, evidenceHash | Audit |
| `ReportRunRejected` | ReportRun | durable approval rejects | reportRunId, rejectedBy, reason | Audit |
| `ReportRunSuperseded` | ReportRun | a later run for the same key is approved | reportRunId, supersededByReportRunId | Audit |

None of these four events is subscribed to by Period — `CloseGateProvider` remains a **pull**
contract (Period calls `evaluate()` synchronously at Hard Close time, §11), exactly as M4 already
built it; this proposal adds no new event-driven coupling between Reporting and Period, preserving
AP-001's existing, already-approved integration shape. No change is needed to any already-frozen
event (`JournalPosted`, `InvoiceIssued`, `PeriodSoftClosed`, etc.) — Reporting's consumption of
them is already listed in `HiveFin_Domain_Events.md`'s existing consumer columns (§0 research).

**Export introduces no event.** Per the decided export rules (§14.1), `GET
/v1/report-runs/{id}/export` creates no accounting mutation and no business event — it is a pure
read of an already-immutable `content` value, so no `ReportRunExported` event (or equivalent) is
proposed.

### 16.3 Product Owner decisions incorporated (previously unresolved; now resolved)

All seven items previously listed as open governance decisions are resolved in this revision by
explicit Product Owner decision. Nothing listed here remains an M5 implementation decision.

1. **Cash View MVP** — resolved (§10). Cash View implements ADR-001's already-locked algorithm
   plus the Product Owner's additional approved rules for partial settlement, unapplied credit,
   later credit application, refunds, reversals, withholding presentation, and SBU-allocation
   references (§10.1–§10.2); a `CashViewPolicy` (§10.3) versions and freezes the rule set into
   every `ReportRun`. Cash-basis Profit and Loss is excluded from M5 MVP (§10.4) — the dedicated
   Cash View satisfies the management requirement instead.
2. **Profit and Loss layout v1** — resolved (§6.1–§6.2). The nine-line computed skeleton (Sales
   Revenue through Net Profit %), its exact calculations, the zero-denominator-returns-null rule,
   and the approved classification groups (`sales_revenue`, `cost_of_sales`, `operating_expense`,
   `non_operating_income`, and, if configured, `non_operating_expense`/
   `tax_or_other_configured_group`) are all frozen into `ReportLayout`/`AccountClassificationMap`
   version 1. Accounts are never classified from names or codes during generation (§6.2);
   unclassified accounts fail with `422 unclassified_account`.
3. **Balance Sheet layout v1** — resolved (§7.1–§7.3). The approved top-level structure (Assets,
   Liabilities, Equity, the three totals, Total Liabilities and Equity, and Difference), the
   Assets = Liabilities + Equity invariant, and the rule that any non-zero Difference blocks
   generation (`422 report_unbalanced`) are frozen. Current/non-current and other subgroups come
   from the same versioned classification/layout mechanism as P&L, never inferred from names,
   codes, dates, or balances. Retained earnings and current-period result are represented through
   explicit configured classifications and exact report-run references (§7.3).
4. **Ageing buckets** — resolved (§8.1). The approved default `AgeingBucketSet` version 1 —
   `not_due`, `overdue_0_30`, `overdue_31_60`, `overdue_61_90`, `overdue_90_plus` — applies
   identically to Receivables and Payables, based on contractual due date versus `asOf`. Dashboard
   display may aggregate the two 60+ buckets into one visual segment without altering the detailed
   five-bucket reports (§8.1). Still versioned configuration, frozen into each `ReportRun`, not a
   hardcoded application constant.
5. **Review and approval** — resolved (§3.2, §11.3). One public `ReportRun` approval command for
   MVP; no separate public review endpoint. Generation and approval must be performed by different
   actors; the approving checker performs review and approval in one durable action;
   `reviewed_by`/`approved_by` may identify the same checker, recorded atomically. The generator
   may not approve their own run. Approval uses the existing durable four-eyes lifecycle;
   rejection follows the existing approval lifecycle rather than a new M5 endpoint. Only an
   Approved, current, non-superseded `ReportRun` may satisfy a Close Gate (§11.3).
6. **Export scope** — resolved (§14.1). PDF and CSV export are included in M5; XLSX is excluded
   and deferred. One minimum endpoint, `GET /v1/report-runs/{id}/export?format=pdf|csv`, is added
   (§2.4), bringing the endpoint total to 14. Export reads only the immutable `ReportRun` snapshot,
   never reruns the calculation, creates no mutation or event, and a stale/superseded export
   remains allowed for historical audit but must visibly state its `state`.
7. **Reporting/Ledger context boundary** — resolved (§13). The existing Trial Balance, General
   Ledger, and account-balance implementation is **not** migrated or rewritten. Ledger keeps
   owning Ledger facts and its established read contracts; Reporting consumes them through
   explicit adapters and must not read Ledger tables directly where an approved query contract
   exists. New M5 statements, `ReportRun`s, layouts, classifications, ageing, Cash View, and
   Close-Gate evidence belong to Reporting. A future context extraction requires a separate
   governance decision.

**Source handling (§0 item 10, applies to items 2, 4, and 6 above):** the P&L computed-line
skeleton, the detailed ageing buckets, SBU-filtered reports, and the PDF/CSV export expectation
are grounded in the older HiveFin requirements evidence already found and cited in this proposal
— `HiveFin_SRS_v3.0.md` §5.10 (Chart-of-Accounts class table) and §5.11 (report/export inventory,
"exact Notionhive layout," "PDF + CSV export"). **That older evidence is cited as Product Owner
supporting material for the decisions above; it is not itself the current frozen authority.**
These choices become authoritative for implementation **only** through approval and incorporation
of this proposal into the frozen artifacts listed in §16.1 — until then, exactly like every other
section of this document, they remain proposed, not frozen.

This proposal deliberately adds no M6 Reconciliation endpoint, no consolidation/CTA behavior, no
XLSX export, no hardcoded ageing bucket outside versioned configuration, no inferred account
classification, and no Hard Close bypass. Any future change to those boundaries requires separate
approval.
