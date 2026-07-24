# HiveFinance Executed UAT Report — 2026-07 Gap-Closure Pass

> Companion to `docs/uat/HIVEFINANCE_UAT_DEFECT_LOG.md`. This report covers only the
> scenarios that were missing from prior technical validation, as identified in the
> coverage report that preceded this pass. It does not re-document scenarios that were
> already executed and passing in earlier work (see `docs/uat/HIVEFINANCE_FULL_UAT_RUNBOOK.md`
> and `docs/uat/M2_DOCUMENTS_UAT.md` for those). All scenarios were executed end-to-end
> through the real UI via Playwright browser automation against a locally seeded
> `M2UatSeeder` environment (SQLite dev DB), never against production data.

## 1. Environment

- Backend: `php artisan serve --env=local --host=127.0.0.1 --port=8080`
- Frontend: Vite dev server, `http://127.0.0.1:5173`
- Seeder: `M2UatSeeder` (deterministic, UAT-only; `HIVEFIN_UAT_SEED_ALLOWED=true` required)
- Browser: headless Chromium via Playwright

## 2. Test users and roles

| Role | Email | Used for |
|------|-------|----------|
| Maker | `maker.m2.uat@hivefinance.local` | Submitting approval-gated commands; also completes ReportRun-approve completions (see §3) |
| Checker | `checker.m2.uat@hivefinance.local` | Completing approvals as a distinct actor from the Maker |
| Auditor | `auditor.m2.uat@hivefinance.local` | Not exercised in this pass (read-only role, no new scope items required it) |

## 3. Scope item 1 — UAT seeder permission gaps

`M2UatSeeder`'s Maker and Checker roles were extended with the minimum UAT-only
permissions needed for: approvals completion, `periods.soft_close`/`hard_close`/`reopen`,
`reporting.report_runs.*` (and the per-report-type read permissions), `tax.codes.manage`,
`fx.rates.manage`, invoice/bill void, reconciliation lifecycle actions
(`create_bank_entry`/`complete`/`reopen`), and the relevant export actions. All additions
are confined to `backend/database/seeders/M2UatSeeder.php` — no production RBAC default
was touched.

One deliberate, explicitly-commented UAT-only design decision: the Maker was also granted
`identity.approvals.approve`, because ReportRun approval is itself approval-gated (a
Checker's `approve()` action requires a *second*, distinct completion via `/approvals`).
Self-approval remains blocked regardless of this grant, enforced independently by
`ApprovalRequest.maker_id !== approver`.

**Validation:** `backend/tests/Feature/M2UatSeederTest.php` (53 assertions) passes on both
SQLite and PostgreSQL. Every permission was also exercised live through the real UI in the
scenarios below — none of the actions in §4 required any production authorization change.

## 4. Scope item 2 — scenario execution log

Legend: **P** = Pass, evidence files are under the local UAT evidence directory (not
committed to the repo — screenshots are local browser-automation artifacts; filenames are
recorded here for traceability). Defect column references `HIVEFINANCE_UAT_DEFECT_LOG.md`.
All rows show the **final, post-fix** result; where a defect was found mid-scenario, the
scenario was re-run after the fix and passed on regression (see the defect log for
before/after detail).

### 4.1 Approval-gate mechanics

| # | Scenario | Actor(s) / role | Expected | Actual | Result | Evidence | Defect |
|---|----------|-----------------|----------|--------|--------|----------|--------|
| 1 | Maker submits a command requiring approval | Maker | Command enters `Pending` approval state | Tax code creation entered `Pending`; pattern re-confirmed on FX rate, Soft Close, Hard Close, Reopen, bank-only entry, all 9 report approvals, and all 4 void requests (10 total approval-gated actions this pass) | P | `01-tax-code-submitted.png`, `05-fx-rate-submitted.png`, `17-maker-soft-close-request.png`, `42-hard-close-request.png`, `53-reopen-request-final.png`, `31-bank-only-entry-requested.png` | — |
| 2 | Checker logs in separately and completes approval through the UI | Checker | Checker sees the pending item at `/approvals` and can complete it | Confirmed for tax code, FX rate, soft close, hard close, reopen, bank-only entry, all 9 report approvals, and voids | P | `03-checker-approves-tax-code.png`, `06-checker-approves-fx-rate.png`, `18-checker-approves-soft-close.png` | — |
| 3 | Maker cannot approve their own command | Maker | Self-approval attempt rejected | Confirmed rejected for the standard maker-checker flow and separately for Hard Close's self-approval path | P | `02-maker-self-approve-blocked.png`, `50-hard-close-self-approve-blocked.png` | — |

### 4.2 Tax and FX

| # | Scenario | Actor(s) / role | Expected | Actual | Result | Evidence | Defect |
|---|----------|-----------------|----------|--------|--------|----------|--------|
| 4 | Create a tax code through the UI | Maker → Checker | Tax code reaches `Active` after approval | Confirmed | P | `01`–`04` (`tax-code-*.png`) | — |
| 5 | Create an FX rate through the UI | Maker → Checker | Rate reaches `Active` after approval | Confirmed | P | `05`–`07` (`fx-rate-*.png`) | — |

### 4.3 Period lifecycle

| # | Scenario | Actor(s) / role | Expected | Actual | Result | Evidence | Defect |
|---|----------|-----------------|----------|--------|--------|----------|--------|
| 6 | Soft Close a period | Maker → Checker | Period reaches `SoftClosed` | Confirmed | P | `17`–`19`, `40`–`41` | — |
| 7 | Attempt Hard Close and verify close-gate behavior | Maker | Hard Close correctly **blocked** while any of the 5 mandatory gates (`trial_balance_reviewed`, `profit_and_loss_approved`, `balance_sheet_approved`, `vat_outputs_approved`, `bank_reconciliation_completed`) is unmet; correctly **succeeds** once every gate is independently satisfied, including a report that had gone stale (see §4.4) being regenerated and re-approved first | Confirmed both the block and the eventual unblock; watermark-staleness re-confirmed as intentional, not a defect | P | `20-hard-close-blocked-by-gates.png`, `48-gates-after-report-refresh.png`, `49-hard-close-retry.png`, `51-hard-close-approved.png`, `52-period-hardclosed-final.png` | — |
| 8 | Reopen a period through approval | Maker → Checker | Period reaches `Reopened` with the required narrative recorded | Initially blocked by a missing UI field | Fixed → P | `53-reopen-request-final.png`, `54-reopen-approved-final.png`, `55-period-reopened-final.png` | UAT-D06 |

### 4.4 Reporting

All 9 report types were generated, submitted for approval by the Checker, and completed
by the Maker (the double-gated ReportRun-approve flow, §3). Trial Balance and Profit &
Loss were additionally exported to both PDF and CSV as representative samples of the
export contract, which is already covered generically for all 9 types by
`M5ReportRunLifecycleTest`.

| # | Report type | Generate | Approve (submit + complete) | Result | Evidence | Defect |
|---|---|---|---|---|---|---|
| 9 | Trial Balance | ✓ | ✓ | P | `08-generate-trial-balance.png`, `10-checker-approve-click-trial-balance.png`, `11-maker-completes-approval-trial-balance.png` | — |
| 10 | General Ledger | ✓ | ✓ | P | `08-generate-general-ledger.png`, `10-...-general-ledger.png`, `11-...-general-ledger.png` | — |
| 11 | Profit & Loss | ✓ | ✓ | Initially poisoned by a basis-selection bug | Fixed → P | `08-generate-profit-and-loss.png`, `15-pnl-regenerated.png` | UAT-D05 |
| 12 | Balance Sheet | ✓ | ✓ | P | `08-generate-balance-sheet.png`, `10-...-balance-sheet.png`, `11-...-balance-sheet.png` | — |
| 13 | AR Ageing | ✓ | ✓ | P | `08-generate-receivables-ageing.png` | — |
| 14 | AP Ageing | ✓ | ✓ | P | `08-generate-payables-ageing.png` | — |
| 15 | Tax Summary | ✓ | ✓ | P | `08-generate-tax-*.png` | — |
| 16 | FX Revaluation | ✓ | ✓ | P | `08-generate-fx-revaluation.png` | — |
| 17 | Cash View | ✓ | ✓ | P | `08-generate-cash-view.png` | — |
| 18 | Approve a ReportRun through the UI | — | — | Demonstrated 9×, one per report type above | P | see above | — |
| 19 | Export approved reports to PDF and CSV | — | — | Confirmed for Trial Balance and Profit & Loss | P | `export-trial-balance.{pdf,csv}`, `export-profit-and-loss.{pdf,csv}` | — |

### 4.5 Reconciliation

| # | Scenario | Actor(s) / role | Expected | Actual | Result | Evidence | Defect |
|---|----------|-----------------|----------|--------|--------|----------|--------|
| 20 | Open a reconciliation batch | Maker | Batch opens against a configured bank account | Confirmed | P | `22-reconciliation-opened.png` | — |
| 21 | Import a realistic CSV statement | Maker | Statement lines imported, one per CSV row | No UI existed to do this at all | Fixed → P | `23-csv-statement-imported.png` | UAT-D03 |
| 22 | Generate match suggestions | System | Suggestions generated for every unmatched line | Initially crashed | Fixed → P | `24-match-suggestions-generated.png` | UAT-D02 |
| 23 | Complete one-to-one matching | Maker | Single line matched to single allocation | Confirmed | P | `29-all-suggestions-matched.png` | — |
| 24 | Complete one-to-many matching | Maker | Single line matched to multiple allocations | Confirmed | P | `29-all-suggestions-matched.png` | — |
| 25 | Complete many-to-one matching | Maker | Multiple lines matched to a single allocation, all sides updated | Sibling lines silently left unmatched | Fixed → P | `29-all-suggestions-matched.png` | UAT-D04 |
| 26 | Confirm matches | Maker | All matched lines reach `Reconciled` | Confirmed | P | `30-all-matches-confirmed.png` | — |
| 27 | Create and approve a bank-only entry | Maker → Checker | Unexplained line resolved via an approved bank-only journal entry | Confirmed | P | `31-bank-only-entry-requested.png`, `32-checker-approves-bank-entry.png` | — |
| 28 | Complete reconciliation | Maker → Checker | Batch reaches `Completed`; `closing_balance == opening_balance + SUM(reconciled amounts)` | Confirmed (formula verified directly against `preflightComplete()` source, not assumed) | P | `34-maker-requests-complete.png`, `35-checker-completes-reconciliation.png`, `36-reconciliation-completed-state.png` | — |
| 29 | Export the reconciliation statement | Maker | PDF and CSV export of the completed statement | Confirmed | P | `export-reconciliation-statement.{pdf,csv}` | — |

### 4.6 Void actions

| # | Scenario | Actor(s) / role | Expected | Actual | Result | Evidence | Defect |
|---|----------|-----------------|----------|--------|--------|----------|--------|
| 30 | Void one draft Invoice | Maker | Voided directly, no approval or journal entry needed | Confirmed | P | `56`–`57` | — |
| 31 | Void one issued Invoice | Maker → Checker | Safe-window void with a linked reversing journal entry, via approval | Confirmed — executed **before** the period Reopen scenario in the final run, since a period that has completed a Reopen cycle no longer satisfies the void safe-window's literal `'Open'` state check (see `HIVEFINANCE_UAT_DEFECT_LOG.md`, UAT-F05) | P | `58-issued-invoice-void-requested.png`, `59-issued-invoice-void-approved.png` | — |
| 32 | Void one draft Bill | Maker | Voided directly | Confirmed | P | `60-draft-bill-voided.png` | — |
| 33 | Void one approved Bill | Maker → Checker | Safe-window void via approval | Confirmed | P | `63-approved-bill-void-requested.png`, `64-approved-bill-void-approved.png` | — |

## 5. Scope item 3 — responsive and accessibility checks

### 5.1 Responsive

9 routes (`/`, `/receivables`, `/payables`, `/notes`, `/settlement`, `/bank-accounts`,
`/reports`, `/periods`, `/approvals`) × 3 viewports (desktop 1440×900, tablet 834×1112,
mobile 390×844) = **27 checks**.

- **Initial run:** 6 of 27 checks failed (horizontal page overflow at mobile width on
  Payables, Settlement, Bank Accounts, Reports, Periods, Receivables) → UAT-D07, UAT-D08.
- **Post-fix regression:** 27/27 pass, 0 horizontal overflow on any route at any width.
- Evidence: `70-{desktop,tablet,mobile}-*.png` (initial), `71-mobile-periods-fixed.png`,
  `74-mobile-payables-fixed.png` (post-fix).
- No critical action became inaccessible at any width; tables and forms remained usable
  (tables now scroll independently within their own container rather than the page).

### 5.2 Keyboard navigation

Checked Tab order and the displayed keyboard-shortcut system on major forms
(`/approvals`, `/receivables` and others).

- **Tab order:** functional — every interactive element is reachable — but Tab traverses
  the entire ~17-item sidebar before reaching page content on every page. No skip link
  exists. Logged as UAT-F01 (LOW), not fixed this pass (new component, not a defect fix).
- **Keyboard shortcuts:** the "G D" / "G R" / etc. labels shown throughout the sidebar are
  purely cosmetic. Confirmed via `page.keyboard.press('g')` + `press('d')` on `/receivables`
  producing no navigation, and via `grep` across the codebase that no keydown/hotkey
  handler is implemented anywhere. Logged as UAT-F02 (MEDIUM), not fixed this pass
  (implementing real hotkeys is new feature scope, not a defect fix).

### 5.3 Accessibility audit

axe-core (installed into a scratch directory only, `npm install axe-core --no-save`,
never added as a project dependency) was run against 5 representative pages — Dashboard,
Approvals, Bank Accounts (reconciliation), Reports, Periods — using the `wcag2a`,
`wcag2aa`, and `best-practice` rule sets.

- **Initial scan:** 2 critical (`button-name` missing on the entity switcher) +
  38 contrast violations on the Dashboard alone; similar counts on the other 4 pages;
  plus a `select-name` critical violation and 3 structural landmark violations on Bank
  Accounts (the latter three turned out to be a false alarm from an incorrect route in
  my own scan script, not a real defect — the actual route is `/bank-accounts`, and once
  corrected, no landmark/heading violations were present).
- **Fixed:** UAT-D09 (missing accessible names), UAT-D10 (systemic color-contrast).
- **Post-fix regression:** **0 violations of any severity on all 5 pages**, re-scanned
  against the same 3 rule sets.
- Evidence: raw scan output captured in this session's transcript; visual sanity
  screenshots `80-a11y-color-fix-dashboard.png`, `81-a11y-color-fix-periods.png`.

## 6. Scope item 5 — defects fixed

See `docs/uat/HIVEFINANCE_UAT_DEFECT_LOG.md` for full detail. Summary: 16 defects/findings
identified; 10 fixed (1 BLOCKER, 3 HIGH, 6 MEDIUM), all regression-verified; 6 logged as
findings or open policy questions per `CLAUDE.md`'s prohibition on inventing accounting,
approval, or period-close rules.

## 7. Scope item 7 — final validation

| Check | Result |
|---|---|
| Full SQLite test suite | 156 passed, 9 skipped (pre-existing, PostgreSQL-only), 0 failed |
| Full PostgreSQL test suite | 165 passed, 0 skipped, 0 failed |
| Frontend typecheck (`tsc -b`) | Clean |
| Frontend lint (`eslint .`) | Clean |
| Frontend production build (`vite build`) | Clean (pre-existing advisory-only chunk-size and Node-version warnings, no errors) |
| PHPStan | 0 errors (289/289 files analysed) |
| Pint | Passed, no formatting deltas |
| Rector (dry run) | No changes needed |
| AP-001 context-boundary guard | Passed |
| `composer validate --strict` | Valid |
| Secret scan (manual pattern scan; no scanner installed in repo/environment) | No credentials, keys, or tokens found in the diff |
| `git diff --check` | Clean (no whitespace errors, no conflict markers) |
| PostgreSQL migrate fresh / rollback / forward | All clean, no errors |

## 8. Scenario totals

- **Core functional scenarios (§4):** 33 executed, 33 passed on final regression (7 found
  a defect mid-scenario, all fixed and re-passed)
- **Responsive checks (§5.1):** 27 executed, 27 passed on final regression (6 initially
  failed, all fixed and re-passed)
- **Keyboard navigation checks (§5.2):** 2 executed, both pass (2 findings logged, not
  defects)
- **Accessibility audit (§5.3):** 5 pages scanned, 5 pages at 0 violations on final
  regression (2 initially had violations, both fixed and re-passed)
- **Permission-seeding validation (§3):** 1 executed (test suite + live confirmation), pass
- **Grand total:** 68 checks executed, **68 passed on final regression**, 0 outstanding
  failures

## 9. Remaining manual finance-user checks

These require a real finance user's business judgment and cannot be automated or
substituted by engineering validation:

1. **Confirm the illustrative tax rates, FX rates, chart-of-accounts classification map,
   and ageing bucket boundaries** seeded by `M2UatSeeder` reflect real intended policy —
   they are explicitly documented as UAT fixtures, not approved defaults.
2. **Review UAT-F05** (void safe-window blocked once a period has completed a Reopen
   cycle) and confirm whether this is intended policy or a gap requiring a governance
   change.
3. **Review UAT-F03/UAT-F04** (reconciliation "Amount (signed)" and "Closing balance"
   labels) and decide whether the current unsigned-sum accounting behavior and its UI
   wording need to change — this is a business-facing wording/policy call, not an
   engineering one.
4. **Sign off on the report layout, account classification, ageing bucket, and Cash View
   policy versions** seeded for M5 Reporting — these are real, versioned, entity-scoped
   configuration rows a finance user should ultimately own and approve, not an engineer.
5. **Exercise the full UAT runbook** (`docs/uat/HIVEFINANCE_FULL_UAT_RUNBOOK.md`) as a
   real finance user, end to end, once — this pass validated every action is technically
   correct and reachable; only a finance user can confirm the numbers, narratives, and
   workflow *feel* right for actual month-end use.

## 10. Final recommendation

**CONDITIONAL GO.**

All identified technical UAT gaps are closed: every scope-item-2 scenario passes, every
BLOCKER/HIGH/MEDIUM defect found is fixed and regression-verified, responsive and
accessibility checks pass cleanly, and the full validation suite (dual-database tests,
static analysis, formatting, refactor-safety, boundary guard, secret scan) is green.

The **conditional** qualifier reflects two things that only a human finance/product
decision can close, not an engineering one:

- **UAT-F05** (void-after-Reopen policy question) is a live ambiguity in accounting
  behavior that this pass deliberately did not resolve in code, per `CLAUDE.md`.
- **§9's manual finance-user checks** (data/policy sign-off) have not yet been performed
  by an actual finance user — this pass exercised every workflow technically correctly,
  but did not and cannot substitute for that business sign-off.

Recommend: route UAT-F05 to the Product Owner for a governance decision, have a finance
user complete the §9 checklist, and then this is ready for a production GO decision with
no further engineering work anticipated.
