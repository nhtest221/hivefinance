# M5 — Reporting and Cash View: Implementation Completion Report

**Branch:** `claude/m5-reporting-cash-view`
**Base:** `main` @ `a3de098` (docs(governance): freeze M5 reporting and cash view foundations, #16)
**Governance authority:** `docs/HiveFin_API_Contracts.md` §13; Governance Approval Record `M5-GOV-001` (`docs/HiveFin_Decision_Log.md`)
**Latest commit:** `54bee63`

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

## Defect found and fixed during validation

`ReportRunService::preflightApprove()` originally collapsed the `Approved`, `Rejected`, and `Superseded` states into one generic `report_run_not_approved` rule. API Contracts §13.4 specifies the approve endpoint's exact rule set as `report_run_not_found`, `report_run_already_approved`, `report_run_rejected`, `sod_exception_required` — no more, no less. Fixed to branch on state and emit the correct frozen rule per state (`Superseded` maps to `report_run_already_approved` since that state is only reachable from a prior `Approved` run). Two new tests cover the `Rejected` and `Superseded` cases; the pre-existing test asserting the old generic code was updated. Commit `54bee63`.

## Known gap — requires Product Owner clarification, not resolved in code

`report_source_not_ready` is listed as a valid `details.rule` value for `POST /v1/report-runs` in both §13.3's generate-rules list and §13.15's stable-errors catalog, but **no frozen document defines the business condition that should trigger it** (checked API Contracts, the M5-GOV-001 Governance Approval Record, and the SRS — no elaboration anywhere). All other six generate-time rules (`missing_report_layout`, `missing_account_classification`, `unclassified_account`, `missing_ageing_bucket_set`, `missing_cash_view_policy`, `report_unbalanced`) are fully specified and implemented; this one is declared but its trigger is undefined. Per this repository's standing rule against inventing missing accounting/business rules, this was **not implemented** — implementing it would require guessing what "source not ready" means (an unposted period? missing FX rates? an unconfigured account?), each of which is a different rule with different consequences. **This is the one open item before M5 can be called fully contract-conformant; it needs an explicit Product Owner decision or governance amendment defining the trigger condition**, after which it is a small, well-scoped addition.

## Validation evidence

- **Backend tests, SQLite:** full suite — 1 pre-existing failure (`M1LedgerValuationTest > it fails revaluation safely when policy configuration is absent`), all else green. Confirmed via an isolated worktree at the pre-M5 freeze commit (`a3de098`) that this failure **pre-dates M5** and is unrelated to this branch's changes; out of M5 scope to fix.
- **Backend tests, PostgreSQL:** full suite green except the same pre-existing M1 failure (115/116 passed on the full-suite run; 45/45 M5+M4A-scoped tests passed on a final clean-database run after the approve-rule fix). PostgreSQL-only immutability triggers (`protect_report_runs`, `protect_report_layout_versions`, `protect_account_classification_versions`, `protect_ageing_bucket_set_versions`, `protect_cash_view_policy_versions`) confirmed installed via `pg_trigger` and exercised for real (not skipped, unlike under SQLite).
- **Migrations:** clean `migrate:fresh` plus rollback/forward round-trip verified on both a fresh SQLite file and a fresh PostgreSQL database; all check constraints (`report_type`, `basis`, `state` enums) and both lookup indexes confirmed via `\d report_runs`.
- **Static analysis:** PHPStan (`phpstan.neon`, full app) — 0 errors.
- **Formatting:** Pint — passed, no diffs.
- **Refactor-safety:** Rector dry run found 4 mechanical strict-typing fixes in M5-authored files (explicit string casts around `strtotime()`/`substr()`, an explicit `fputcsv()` escape argument for the PHP 8.4 default change); applied, re-verified clean, committed separately (`34653f9`).
- **Context boundaries:** `scripts/check-boundaries.php` — passed. AP-001 preserved via the new `AccountMovementQuery` internal Ledger contract rather than direct `journal_lines` reads from Reporting.
- **Composer:** `composer validate --strict` — valid.
- **Frontend:** `tsc -b` (typecheck), `eslint .` (lint), `vite build` (production build) — all clean.
- **Contract conformance:** all 14 endpoint paths/verbs cross-checked against §13.3's endpoint inventory; query parameter names (`asOf`, `period`, `sbu`, `compare_to`, `customer`, `vendor`, `account`, `range`) cross-checked against §13.5–§13.11 and §7.5; all `details.rule` values except the undefined `report_source_not_ready` (above) cross-checked against §13.15.
- **`git diff --check`:** clean across the full branch diff.
- **Working tree:** clean.
- **Frozen documents:** zero changes under `docs/` on this branch (`git diff --stat main...HEAD -- docs/` empty).

## Recommendation

**MERGE, conditional on Product Owner sign-off for the `report_source_not_ready` gap.** Everything else — all 14 endpoints, the ReportRun lifecycle and maker-checker approval, Close-Gate integration, export, and the frontend — is implemented, tested on both database engines, and contract-conformant. The one open item does not block correctness of anything currently implemented (no code path silently mislabels a real failure); it is a declared-but-undefined rule with no reachable trigger yet. Recommend either (a) a quick Product Owner clarification of the intended trigger condition so it can be added as a small follow-up commit, or (b) an explicit governance note accepting its deferral to a future amendment.
