# M5 — Reporting and Cash View: Implementation Completion Report

**Branch:** `claude/m5-reporting-cash-view`
**Base:** `main` @ `61314f3` (docs(governance): freeze report_source_not_ready trigger definition, #18)
**Governance authority:** `docs/HiveFin_API_Contracts.md` §13; Governance Approval Record `M5-GOV-001` and Governance Clarification Record `M5-GOV-002` (`docs/HiveFin_Decision_Log.md`)
**Latest commit:** `afadc5a`

## Scope delivered

All 14 frozen M5 public endpoints, across both conceptual slices:

**M5A — Reporting read models and financial statements**
- `GET /v1/reports/trial-balance` — unchanged M1 implementation plus additive `period_ref` opening/movement and `sbu` filter (§13.5)
- `GET /v1/reports/general-ledger` — unchanged M1 implementation plus additive `sbu` and `reversal_of_entry_id` (§13.6)
- `GET /v1/reports/profit-loss` — frozen 9-line skeleton, versioned `ReportLayout`/`AccountClassificationMap`, `basis=cash` rejected as `unsupported_basis` (§13.7)
- `GET /v1/reports/balance-sheet` — Assets = Liabilities + Equity invariant, fiscal-YTD current-period result computed live (§13.8)
- `GET /v1/reports/ar-ageing`, `GET /v1/reports/ap-ageing` — frozen five-bucket default `AgeingBucketSet` v1 (§13.9)
- `GET /v1/reports/tax-summary` — VAT output/input summary from tax snapshots (§13.10)
- `GET /v1/reports/fx-revaluation`, `GET /v1/reports/cash-view` — Cash View always `basis=cash`, ADR-001 algorithm reused unchanged (§13.11)

**M5B — Cash View and Close-Gate Evidence**
- `POST /v1/report-runs` (generate), `GET /v1/report-runs/{id}`, `GET /v1/report-runs`, `POST /v1/report-runs/{id}/approve` — immutable snapshot lifecycle, durable four-eyes approval, generator-cannot-self-approve, automatic supersession by reproducibility key (§13.4)
- `GET /v1/report-runs/{id}/export?format=pdf|csv` — export-only from the immutable snapshot, never recomputes; XLSX rejected `400`; superseded/stale runs remain exportable with visible state (§13.13)
- `ReportingCloseGateProvider` wired into M4's unchanged `CloseGateProvider` v1 interface for `trial_balance_reviewed`, `profit_and_loss_approved`, `balance_sheet_approved`, `vat_outputs_approved`; staleness detected via source-data watermark; `bank_reconciliation_completed` remains `UnavailableCloseGateProvider` (M6 does not exist) (§13.12)

**Frontend** — new `frontend/src/features/reporting/` module: report selection/filters/preview for all 9 types, ReportRun generate/approve sign-off, PDF/CSV export, and a read-only Close-Gate status view reusing the already-frozen M4 Periods API (no new backend endpoint required for that view). Pre-M5 placeholder screens and their dead mock-data fixtures were removed.

## Defects found and fixed during validation

1. `ReportRunService::preflightApprove()` originally collapsed the `Approved`, `Rejected`, and `Superseded` states into one generic `report_run_not_approved` rule. API Contracts §13.4 specifies the approve endpoint's exact rule set as `report_run_not_found`, `report_run_already_approved`, `report_run_rejected`, `sod_exception_required` — no more, no less. Fixed to branch on state and emit the correct frozen rule per state (`Superseded` maps to `report_run_already_approved` since that state is only reachable from a prior `Approved` run). Two new tests cover the `Rejected` and `Superseded` cases; the pre-existing test asserting the old generic code was updated. Commit `54bee63`.

2. `ReportRunService::generate()` let `trial_balance`, `balance_sheet`, `ar_ageing`, and `ap_ageing` proceed with no `as_of`, and `profit_and_loss`, `tax_summary`, `fx_revaluation`, and `cash_view` proceed with no `period_ref` — the scope value the report's own source-data watermark must be bounded to. A scope-less Trial Balance silently computed an **unbounded** sum across all posted history ever (mislabeled `"as_of": null` in the response), with a correspondingly unbounded watermark. Fixed as part of implementing `report_source_not_ready` (below) — this is a real pre-existing defect the new rule now closes, not merely new-rule compliance theater. Commit `afadc5a`.

3. `FXRevaluationQuery` was the only one of its four period_ref-scoped siblings (`ProfitAndLossQuery`, `TaxSummaryQuery`, `CashViewQuery` all had it) that didn't validate the requested period exists before proceeding — a nonexistent `period_ref` silently returned a "successful" empty report with a blank `currency` field instead of `404 not_found`. Fixed to match the established sibling pattern exactly. Commit `afadc5a`.

## `report_source_not_ready` — resolved

The Product Owner supplied the missing trigger definition, frozen as Governance Clarification Record `M5-GOV-002` (`docs/HiveFin_Decision_Log.md`; API Contracts §13.4/§13.15/§13.16) and merged into `main` at `61314f3` (PR #18) before this implementation began.

**Implementation, grounded in this codebase's actual architecture** (a direct-read modular monolith over already-posted Ledger/Document/Settlement facts — no separate rebuildable read-model projections, no external/async source adapters): the frozen rule's "the source watermark is missing … for the requested report" and "the requested … as-of date … cannot be reproduced from a complete source snapshot" triggers map exactly onto the pre-existing defect above. `ReportRunService::requireSourceReady()` now rejects `POST /v1/report-runs` with `422 report_source_not_ready` when a report type's required scope value (`as_of` for `trial_balance`/`balance_sheet`/`ar_ageing`/`ap_ageing`; `period_ref` for `profit_and_loss`/`tax_summary`/`fx_revaluation`/`cash_view`) is absent — the one case where the watermark genuinely cannot be bounded to a reproducible snapshot. `general_ledger` keeps its pre-existing, unrelated `400 validation` for missing `account`/`range` (a different, already-established scope-shape check, not touched).

**Deliberately not reinterpreted as `report_source_not_ready`:** a *supplied but nonexistent* `period_ref` (e.g., `period_ref=2099-12`) remains `404 not_found`, matching the pre-existing, already-tested sibling-query behavior and the universal API resource-identity convention (§2) used everywhere else in this API — including by three of `report_source_not_ready`'s own four period_ref-scoped report types before this change. Reinterpreting an already-correct, already-consistent 404 as a new 422 rule would have been unnecessary scope creep with real regression risk, not a requirement of M5-GOV-002's text. This distinction is enforced by a dedicated test.

Error `details` are limited to `{rule, source_category, readiness_state}` — no SQL, file paths, or infrastructure detail, per M5-GOV-002. No `ReportRun`, audit event, or outbox event is created on this path (the check runs before any of those are touched); idempotency replay of the same failing request is inherently side-effect-free since nothing was ever stored to replay. A report with a complete scope but zero matching activity (a brand-new entity, an FX Revaluation period with no runs yet) remains a successful report with valid zero totals — explicitly tested.

14 new tests in `backend/tests/Feature/M5ReportSourceReadinessTest.php`, covering: each of the 6 scoped report types' missing-scope rejection; the `general_ledger` non-change; `not_found` vs `report_source_not_ready` disambiguation for a nonexistent period; two successful-empty-report cases; exact idempotency replay staying side-effect free (identical response body, zero `ReportRun`/`AuditLog`/`OutboxMessage` rows across two identical requests); and safe error details (no SQL/path leakage). Commit `afadc5a`.

## Validation evidence

- **Backend tests, SQLite:** full suite — 1 pre-existing failure (`M1LedgerValuationTest > it fails revaluation safely when policy configuration is absent`), all else green (125 passed after the `report_source_not_ready` implementation). Confirmed via an isolated worktree at the pre-M5 freeze commit (`a3de098`) that this failure **pre-dates M5** and is unrelated to this branch's changes; out of M5 scope to fix.
- **Backend tests, PostgreSQL:** full unfiltered suite green except the same pre-existing M1 failure (131/132 passed); a scoped M5+M4A run on a separate clean database passed 59/59, including all 14 new `report_source_not_ready` tests and every PostgreSQL-only immutability trigger (`protect_report_runs`, `protect_report_layout_versions`, `protect_account_classification_versions`, `protect_ageing_bucket_set_versions`, `protect_cash_view_policy_versions`) confirmed installed and exercised for real, not skipped.
- **Migrations:** clean `migrate:fresh` plus rollback/forward round-trip verified on both a fresh SQLite file and a fresh PostgreSQL database; all check constraints (`report_type`, `basis`, `state` enums) and both lookup indexes confirmed via `\d report_runs`. No migration changes in this final phase (governance-clarification implementation touched only application code and tests).
- **Static analysis:** PHPStan (`phpstan.neon`, full app) — 0 errors, including after the `report_source_not_ready` implementation.
- **Formatting:** Pint — passed, no diffs.
- **Refactor-safety:** Rector dry run — 0 findings after the `report_source_not_ready` implementation (the 4 earlier mechanical strict-typing fixes were applied and committed separately, `34653f9`).
- **Context boundaries:** `scripts/check-boundaries.php` — passed. AP-001 preserved via the new `AccountMovementQuery` internal Ledger contract rather than direct `journal_lines` reads from Reporting.
- **Composer:** `composer validate --strict` — valid.
- **Frontend:** `tsc -b` (typecheck), `eslint .` (lint), `vite build` (production build) — all clean, including after merging the governance-clarification docs into this branch (no frontend files changed by that merge or by `report_source_not_ready`; error handling was already generic via `error_code`/`message`, no rule-specific frontend logic to update).
- **Contract conformance:** all 14 endpoint paths/verbs cross-checked against §13.3's endpoint inventory; query parameter names cross-checked against §13.5–§13.11 and §7.5; all `details.rule` values, including `report_source_not_ready`, now cross-checked against §13.15 and M5-GOV-002.
- **`git diff --check`:** clean across the full branch diff.
- **Working tree:** clean.
- **Frozen documents:** zero changes under `docs/` on this branch beyond the clean merge of the already-separately-reviewed-and-merged `M5-GOV-002` clarification from `main` (`git diff --stat main...HEAD -- docs/` empty, since this branch's `main` merge-base already includes it).

## Recommendation

**MERGE.** All 14 frozen M5 endpoints are implemented, tested on both SQLite and PostgreSQL, and contract-conformant, including the previously-open `report_source_not_ready` rule, now fully specified by Governance Clarification Record `M5-GOV-002` and implemented exactly as frozen. No known open items remain. The one pre-existing, out-of-scope M1 test failure does not block this milestone and should be tracked separately.
