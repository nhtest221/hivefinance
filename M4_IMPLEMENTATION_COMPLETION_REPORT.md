# M4 — Corrections, Notes and Period Close Foundations
## Implementation Completion Report

**Branch:** `claude/m4-notes-period-close`
**Base:** `main` @ `6d7f320` (docs(governance): freeze M4 notes and close foundations, #14)
**Status:** Implementation complete. PR open, unmerged, awaiting owner review.

---

## 1. Scope delivered

M4 is split, per the frozen `docs/HiveFin_Decision_Log.md` (M4-GOV-001), into two independent
sub-scopes. Both are complete.

### M4B — Period lifecycle and close-gate foundations
- `AccountingPeriod` state machine: `Open → SoftClosed → HardClosed → Reopened → SoftClosed → HardClosed`,
  no other transition and no Hard Close bypass.
- Hard Close and Reopen are both mandatory four-eyes maker-checker approvals; Soft Close is not.
- Close-gate evaluation is honest: `reporting` and `reconciliation` gates report `unmet` via an
  `UnavailableCloseGateProvider` because M5 Reporting and M6 Reconciliation do not exist yet — no
  gate evidence is fabricated.
- 5 new public endpoints: `GET /v1/periods`, `GET /v1/periods/{id}`,
  `POST /v1/periods/{id}/soft-close`, `POST /v1/periods/{id}/hard-close`,
  `POST /v1/periods/{id}/reopen`. The pre-existing `GET /v1/periods/postable` is unchanged and
  correctly excluded from this count (frozen API Contracts §12.6 says so explicitly).

### M4A — Credit Notes, Debit Notes, and Invoice/Bill void
- **Credit Note** (customer-side correction against a posted Invoice) and **Debit Note**
  (vendor-side correction against a posted Bill): draft → edit → post, then disposition via
  hold / apply / refund / reverse.
- The five-field invariant `posted_amount = applied_amount + refunded_amount +
  held_remaining_amount + undisposed_amount` (all non-negative) is enforced by a PostgreSQL CHECK
  constraint on both `receivables_credit_notes` and `payables_debit_notes`, and exercised
  end-to-end by tests.
- Note-originated credit holds reuse M3 Settlement's `CreditTranche` / `CreditConsumption` /
  `Allocation` mechanics through a new `CreditTrancheLedger` service and an additive,
  nullable-FK schema change (`settlement_credit_tranches.source_note_id`) — no fork of the
  Settlement ledger, no duplicated FX logic.
- Invoice and Bill void: direct void for drafts (no journal, no number consumed); safe-window
  void for issued/approved documents, requiring no downstream settlement activity, an open
  current period, and filed/locked VAT, reusing the pre-existing general-purpose
  `JournalReversalExecutor` for the linked reversal — no new reversal mechanism invented.
- 18 new public endpoints (9 credit-notes + 9 debit-notes: store/update/show/index/post/apply/
  hold/refund/reverse each) + 2 void endpoints (`POST /v1/invoices/{id}/void`,
  `POST /v1/bills/{id}/void`) = 20 M4A routes, plus the 5 M4B period routes = **25 frozen M4
  routes total**, verified against the running route table.
- All maker-checker-gated commands (note post, note disposition, void) throw on failure so a
  failed execution leaves the approval `Pending` rather than marking it `Approved` — the same
  pattern already established by `SettlementApprovalCommandHandler`.

### Frontend
- Credit Note / Debit Note workflows: draft creation, post, and hold/apply/refund/reverse
  disposition, with capability gating (`hasPermission`), approval-pending messaging, and stable
  API error display.
- Invoice/Bill void actions added to the existing Receivables/Payables pages, gated by
  `receivables.invoices.void` / `payables.bills.void`.
- New `/notes` route and "Notes" navigation entry under the existing Documents group.
- Typecheck, lint, and production build all pass clean.

---

## 2. What was intentionally *not* built

Per explicit governance boundaries, this branch does **not**:
- Implement M5 Reporting or M6 Reconciliation (their close-gates honestly report `unmet`).
- Invent any accounting, tax, VAT, FX, approval, or period-close rule not already specified in
  the frozen docs.
- Add automatic FIFO/LIFO/weighted-average/pro-rata or any hidden allocation — every credit
  application and note disposition is explicit, caller-specified, and version-guarded.
- Accept a client-calculated realised FX figure — all FX is recomputed server-side from named
  RateRecords via `RealisedFxCalculator`.
- Add destructive mutation to any posted/immutable fact — void of a posted document is always a
  linked reversal, never an edit; PostgreSQL triggers (`protect_*`) enforce this at the database
  layer, not just in application code.
- Modify any frozen governance document (`docs/HiveFin_*`, `HiveFinance-Engineering-Constitution-v1.0.md`).

---

## 3. Validation performed (Phase 6)

| Check | SQLite | PostgreSQL |
|---|---|---|
| Clean migrate from zero | ✅ | ✅ |
| Full rollback → forward | ✅ | ✅ |
| Full feature+unit test suite | 86 passed, 1 pre-existing failure, 5 Postgres-only tests skipped | 91 passed, 1 pre-existing failure |
| CHECK constraints (5-field equation, state enum, uniqueness, disposition/target, reversal-per-note) | — | ✅ verified via `pg_constraint` |
| Immutability triggers on all 10 M4A tables | — | ✅ verified via `information_schema.triggers` |
| `settlement_credit_tranches` additive CHECK (`source_allocation_id IS NOT NULL OR source_note_id IS NOT NULL`) | — | ✅ verified |

Additional checks, all passing:
- `composer validate --strict`
- Pint (`--test`) — no formatting violations
- PHPStan (level configured in `phpstan.neon`, `--memory-limit=1G`) — no errors across 216 files
- Rector (`--dry-run`) — no refactors pending
- `scripts/check-boundaries.php` (AP-001 context-ownership guard) — passed
- Frontend `tsc -b`, `eslint .`, `vite build` — all clean
- `git diff --check` against `main` — no whitespace errors, no conflict markers
- All 25 frozen M4 routes confirmed present via `route:list`
- `M2DocumentsTest` "exactly 24 M2 routes" and `M3SettlementTest` "exactly 7 M3 routes" both
  still pass unchanged — M4 added new routes without disturbing earlier milestones' contracts

### The one known, pre-existing, unrelated failure
`M1LedgerValuationTest > it fails revaluation safely when policy configuration is absent`
expects `422 missing_period_end_rate` but receives a PostgreSQL `SQLSTATE[22P02]` (empty-string
UUID cast) / SQLite `invalid_revaluation_account` depending on engine. This is a pre-existing M1
defect, **not** introduced by this branch:
- Reproduced identically on `main` @ `6d7f320` (verified in an isolated `git worktree`, vendor
  reused via the identical, unmodified `composer.lock`, both directly against SQLite and
  PostgreSQL).
- Nothing in this branch touches `FxService::revalue`'s account-resolution path in a way that
  would explain the failure; the branch's own two edits to `FxService.php` are unrelated
  Period-state-enum casing fixes (`'soft_closed'` → `'SoftClosed'`, `'open'` → `'Open'`), matching
  the frozen API Contracts' canonical Period state vocabulary introduced by M4B, not the FX
  account lookup this test exercises.
- Left unfixed, as required (out of M4 scope; fixing it would mean guessing at an M1 rule this
  milestone doesn't own).

---

## 4. Frozen governance integrity

- `git diff main...HEAD -- docs/` is empty — **zero** changes to any frozen governance document.
- `.claude/settings.local.json` and the rest of `.claude/` remain untracked, never staged or
  committed, on every commit in this branch.
- The one new repository file outside `backend/`/`frontend/` is `CLAUDE.md` (project-level
  contributor guidance), created at the user's explicit request before implementation began.

---

## 5. Commits on this branch (oldest → newest)

1. `93b59aa` — docs: add project-level CLAUDE.md for M4 implementation
2. `9231193` — feat(m4b): implement Period lifecycle and close-gate foundations
3. `4c5ed44` — feat(m4a): add notes persistence schema
4. `ab2628c` — feat(m4a): note persistence models and repositories
5. `c1af485` — feat(m4a): note draft and posting lifecycle
6. `7ef09cb` — feat(m4a): note application, hold, refund, and reversal
7. `eee9cc8` — feat(m4a): invoice and bill void
8. `2f5f431` — feat(m4a): frontend workflows for notes and invoice/bill void

85 files changed, 6,884 insertions(+), 50 deletions(-) relative to `main`.

---

## 6. Recommendation

**MERGE**, subject to the PR's CI (Backend, Frontend) passing green and normal owner code
review. All implementation, validation, and governance-integrity checks available to this agent
have passed. The single known test failure is pre-existing on `main`, unrelated to M4, and
explicitly out of this milestone's scope to fix.

This PR is left **open and unmerged** per governance boundaries — merging is an owner decision.
