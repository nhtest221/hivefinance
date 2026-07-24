# HiveFinance UAT Defect Log — 2026-07 Gap-Closure Pass

> Companion to `docs/uat/HIVEFINANCE_EXECUTED_UAT_REPORT.md`. Every defect below was
> found during live, browser-driven UAT execution against `M2UatSeeder` fixtures (never
> against production data), fixed in this pass, and re-verified by a full regression
> (targeted feature re-run + the complete SQLite and PostgreSQL test suites). Fix
> locations are file paths on the branch carrying this pass; see the accompanying PR for
> the exact commit SHA.

## Fixed defects (BLOCKER / HIGH / MEDIUM)

| ID | Severity | Area | Summary |
|----|----------|------|---------|
| UAT-D01 | BLOCKER | Settlement | Receipt/Payment/Refund creation fails for every real HTTP request outside the seeder process |
| UAT-D02 | HIGH | Reconciliation | Match-suggestion engine crashes once any candidate has been filtered out of its pool |
| UAT-D03 | HIGH | Reconciliation | No UI affordance to import a bank statement CSV |
| UAT-D04 | HIGH | Reconciliation | Confirming a many-to-one match suggestion leaves sibling lines unmatched |
| UAT-D05 | MEDIUM | Reporting | Cash-basis P&L generation silently forced accrual basis |
| UAT-D06 | MEDIUM | Periods | Reopen form has no field for the backend-required narrative |
| UAT-D07 | MEDIUM | Frontend (systemic) | Every table forces page-wide horizontal scroll on narrow viewports |
| UAT-D08 | MEDIUM | Documents | Invoice/Bill line-item grids don't collapse on mobile |
| UAT-D09 | MEDIUM (axe: critical impact) | Accessibility | Two controls have no accessible name (entity switcher, reconciliation bank-account picker) |
| UAT-D10 | MEDIUM (axe: serious impact) | Accessibility | Five color tokens fail WCAG AA contrast, systemically, across nearly every page |

---

### UAT-D01 — Settlement receipt/payment/refund fails outside the seeder process (BLOCKER)

- **Found during:** Live settlement testing (seeding a second advance receipt fixture for the reconciliation scenario; later reproduced against a real HTTP request from the running dev server).
- **Symptom:** Any receipt, payment, or refund submitted through a real HTTP request against `php artisan serve` failed with `missing_numbering_configuration`, even though the identical operation succeeded when run from inside `M2UatSeeder`.
- **Root cause:** `M2UatSeeder` configures document numbering and account mapping via runtime `config()->set()` calls. Those calls only affect the seeder's own PHP process — they are invisible to any other already-running process (e.g. `php artisan serve`), which reads `env()` only at its own boot time. `.env.uat.example` never defined the M3 Settlement numbering/account variables, so a live server process had no way to pick them up at all.
- **Impact if unfixed:** Every finance-user-facing Settlement action (the entire Receipt/Payment/Refund UAT surface) would have been completely non-functional for any real UAT session driven through the browser rather than the seeder — this was undetected until this pass because prior validation had only exercised Settlement through direct seeder/test-suite calls, never through a live running server.
- **Fix:** Added `SETTLEMENT_RECEIPT_NUMBER_PREFIX/FORMAT`, `SETTLEMENT_PAYMENT_NUMBER_PREFIX/FORMAT`, `SETTLEMENT_REFUND_NUMBER_PREFIX/FORMAT`, `SETTLEMENT_CUSTOMER_CREDIT_ACCOUNT_ID`, `SETTLEMENT_VENDOR_CREDIT_ACCOUNT_ID`, `SETTLEMENT_REALISED_FX_GAIN_ACCOUNT_ID`, `SETTLEMENT_REALISED_FX_LOSS_ACCOUNT_ID` to `backend/.env.uat.example`, with account IDs matching the ledger accounts `M2UatSeeder` already creates.
- **File:** `backend/.env.uat.example`
- **Regression:** Live receipt creation via HTTP against the running dev server succeeded after adding the same variables to the local `.env.local`; full SQLite and PostgreSQL suites remain green (this is a config-only fix, no application code changed).

### UAT-D02 — Reconciliation matching engine crashes on filtered candidate pools (HIGH)

- **Found during:** The one-to-many / many-to-one statement-line matching scenario.
- **Symptom:** `ReconciliationService::matchLinesToGroups()` threw an undefined-array-key error partway through generating suggestions, once at least one earlier candidate allocation or line had already been excluded from the working set.
- **Root cause:** `combinationsOfSize()` indexes its input with a plain `range(0, $size-1)` against a plain PHP array. `Collection::reject()`/`filter()` preserve the *original* integer keys rather than renumbering them, so as soon as any earlier item had been filtered out, the very next lookup landed on a now-missing index.
- **Fix:** Added `->values()` before `->all()` at both call sites inside `matchLinesToGroups()` — the one-to-many candidate-allocation pool and the many-to-one candidate-line pool — reindexing each pool to a sequential 0-based array before it reaches `combinationsOfSize()`.
- **File:** `backend/app/Reconciliation/Application/ReconciliationService.php`
- **Regression:** `php artisan test --filter=M6` (24 passed, 1 pre-existing SQLite-only skip); full SQLite suite (156 passed) and full PostgreSQL suite (165 passed) both green; live browser run completed all three matching patterns (one-to-one, one-to-many, many-to-one) successfully.

### UAT-D03 — No CSV import affordance in the Reconciliation UI (HIGH)

- **Found during:** Attempting to execute the "import a realistic CSV statement" scenario.
- **Symptom:** The reconciliation detail page only exposed a manual, one-line-at-a-time "Import line" button. There was no way to import a realistic multi-line bank statement CSV through the UI at all, making that scope item impossible to execute as a genuine end-user action (only reachable via direct API calls, which is not a UAT-representative path).
- **Fix:** Added a file input plus `splitCsvLine()`, `parseStatementCsv()`, `sha256Hex()` helpers and an `importCsvFile()` handler to the reconciliation detail page, wired to the existing statement-line import API contract.
- **File:** `frontend/src/features/reconciliation/reconciliation-page.tsx`
- **Regression:** Full `statement.csv` (4 lines, exercising 1:1/1-to-many/many-to-1 patterns) imported successfully end-to-end via browser automation; frontend typecheck/lint/build all clean.

### UAT-D04 — Many-to-one match confirmation drops sibling lines (HIGH)

- **Found during:** The many-to-one matching scenario (two statement lines summing to one bank allocation).
- **Symptom:** Clicking "confirm" on a many-to-one match suggestion only matched the single line that was clicked; its sibling line(s) in the same combination were silently left `Unmatched`, even though the suggestion engine had correctly identified them as a group.
- **Root cause:** `matchFirstSuggestion()` called the match API with only the current line's ID and never inspected or forwarded the other lines that shared the same suggested allocation set.
- **Fix:** `matchFirstSuggestion()` now detects sibling lines whose own first suggestion targets the identical allocation-ID set (via a `sameAllocationSet()` helper) and passes them as an explicit group to `matchLine()`.
- **File:** `frontend/src/features/reconciliation/reconciliation-page.tsx`
- **Regression:** Live browser run confirmed all statement lines reached `Reconciled` state, including the many-to-one pair, in a single confirmation action; full suites green.

### UAT-D05 — Cash-basis P&L generation silently forces accrual basis (MEDIUM)

- **Found during:** Generating Profit & Loss reports ahead of the close-gate verification scenario.
- **Symptom:** `reporting-page.tsx`'s `generate()` unconditionally sent `filters.basis = 'accrual'` regardless of the reporting-basis control the user had actually selected, so choosing "cash" basis for a P&L silently produced an accrual-basis report instead, and separately poisoned the close-gate's exact-empty-filters reproducibility-key lookup.
- **Fix:** `generate()` now only sets `filters.basis` when `reportType === 'profit_and_loss' && basis === 'cash'`; otherwise it omits the field entirely, matching the close gate's expected empty-filters accrual default.
- **File:** `frontend/src/features/reporting/reporting-page.tsx`
- **Regression:** Live regeneration confirmed all 4 gate-relevant report types (`trial_balance`, `profit_and_loss`, `balance_sheet`, `tax_summary`) satisfied their Hard Close gates once regenerated; full suites green.

### UAT-D06 — Period Reopen form missing the required narrative field (MEDIUM)

- **Found during:** The period Reopen scenario.
- **Symptom:** The Reopen form had no input for the mandatory business-justification narrative the backend requires; submissions were rejected by API validation with no way to supply the missing field through the UI.
- **Fix:** Added a `narrative` `Textarea` to `ReopenAction`, and added `narrative: string` to the `reopen()` request type.
- **Files:** `frontend/src/features/periods/periods-page.tsx`, `frontend/src/features/periods/periods-api.ts`
- **Regression:** Full Reopen-through-approval flow completed live via browser automation (Maker requests → Checker approves → period reaches `Reopened`); typecheck/lint/build clean.

### UAT-D07 — Every table forces page-wide horizontal scroll on narrow viewports (MEDIUM, systemic)

- **Found during:** The responsive/mobile-width pass (390px viewport) across Payables, Settlement, Bank Accounts, Reports, Periods, and Receivables.
- **Symptom:** Any page with a table wider than the viewport scrolled the *entire page* horizontally — including the sidebar and top navigation — rather than scrolling only the table.
- **Root cause:** The shared design-system `Table` component rendered a bare `<table>` with no independent horizontal-scroll container.
- **Fix:** `Table` now wraps its `<table>` in `<div className="overflow-x-auto">`, fixing every page that composes `Table` simultaneously (this component is used by essentially every list view in the app).
- **File:** `frontend/src/design-system/components/table.tsx`
- **Regression:** Re-verified 0 horizontal overflow at 390px on all 6 previously-affected routes; typecheck/lint/build clean.

### UAT-D08 — Invoice/Bill line-item grids don't collapse on mobile (MEDIUM)

- **Found during:** The same responsive pass, specifically the Receivables and Payables document-entry forms.
- **Symptom:** Fixed multi-column CSS grids (`grid-cols-[1fr_...]`) on the Invoice and Bill line-item editor rows caused the same page-wide overflow, independent of UAT-D07, because the fixed-width columns never collapsed below their combined natural width.
- **Fix:** Changed both grids to `grid-cols-1` by default with the original fixed-column layout restored only at the `sm:` breakpoint and above.
- **Files:** `frontend/src/features/documents/payables-page.tsx`, `frontend/src/features/documents/receivables-page.tsx`
- **Regression:** 0 horizontal overflow confirmed at 390px on both forms; typecheck/lint/build clean.

### UAT-D09 — Two controls have no accessible name (MEDIUM; axe-core rates this "critical" impact)

- **Found during:** The axe-core accessibility scan (Dashboard, Approvals, Bank Accounts, Reports, Periods).
- **Symptom:** axe-core's `button-name` rule failed on the top-navigation entity-switcher `Select`, and its `select-name` rule failed on the native bank-account `<select>` in the reconciliation "Open a reconciliation batch" panel — both WCAG 2.1 A, 4.1.2 (Name, Role, Value) failures. A screen-reader user has no way to know what either control represents.
- **Fix:** Added `aria-label="Active entity"` to the entity-switcher `SelectTrigger`; added `aria-label="Bank account"` to the native `<select>`.
- **Files:** `frontend/src/layouts/app-layout.tsx`, `frontend/src/features/reconciliation/reconciliation-page.tsx`
- **Regression:** Re-scanned both routes with axe-core (`wcag2a`/`wcag2aa`/`best-practice` rule sets) — `button-name` and `select-name` violations both cleared to zero.

### UAT-D10 — Five color tokens fail WCAG AA contrast, systemically (MEDIUM; axe-core rates this "serious" impact)

- **Found during:** The same axe-core accessibility scan — 38 distinct contrast-violation nodes were reported on the Dashboard alone.
- **Symptom:** `--color-text-subtle` (#8a94a6), `--color-text-muted` (#667085), `--color-info` (#2563eb), `--color-warning` (#b7791f), and `--color-success` (#0f8b5f) measured between 2.74:1 and 4.47:1 against their typical backgrounds — all below WCAG AA's 4.5:1 threshold for normal text. Because these are shared design-system tokens, the failure was systemic: nav labels, section headings, badges, and status pills on nearly every page in the app.
- **Fix:** Darkened all five tokens in `frontend/src/app/styles.css` to standard, hue-matched shade steps, each individually re-verified at ≥4.5:1 against every background it is actually used against in this app:
  - `--color-text-subtle`: `#8a94a6` → `#475569`
  - `--color-text-muted`: `#667085` → `#334155` (also re-darkened, in the same pass, specifically to preserve the `text > muted > subtle` emphasis ordering — an initial fix of `subtle` alone would have made it *darker* than `muted`, inverting the intended hierarchy)
  - `--color-info`: `#2563eb` → `#1d4ed8` (reuses the existing `--color-primary-hover` value; no new brand color introduced)
  - `--color-warning`: `#b7791f` → `#b45309`
  - `--color-success`: `#0f8b5f` → `#047857`
- **File:** `frontend/src/app/styles.css`
- **Regression:** Full axe-core re-scan of all 5 sampled pages (Dashboard, Approvals, Bank Accounts, Reports, Periods) against `wcag2a`/`wcag2aa`/`best-practice` — **0 violations of any kind** on every page. Visual sanity screenshots (`80-a11y-color-fix-dashboard.png`, `81-a11y-color-fix-periods.png`) confirm no readability regression.

---

## Findings logged, not fixed (informational / out of scope / policy questions)

Per `CLAUDE.md`'s prohibition on inventing accounting, approval, or period-close rules, and
on introducing new product decisions under the guise of implementation detail, the items
below were deliberately **not** changed in code. They are recorded here for Product Owner
review.

| ID | Severity | Summary | Why not fixed |
|----|----------|---------|----------------|
| UAT-F01 | LOW | No "skip to main content" link; keyboard Tab order traverses the full ~17-item sidebar before reaching page content on every page | Real accessibility gap, but a genuine addition (new skip-link component + focus target), not a defect fix within this pass's scope |
| UAT-F02 | MEDIUM | The "G D" / "G R" / etc. keyboard-shortcut labels shown throughout the sidebar are purely cosmetic — confirmed via `grep` that **no keydown/hotkey handler exists anywhere in the codebase** | Implementing real hotkeys is new feature work, not a defect fix |
| UAT-F03 | LOW | Reconciliation statement-line "Amount (signed)" label is misleading — no signed debit/credit convention is actually modeled at the matching layer; all amounts are stored and compared unsigned | Changing the matching engine's sign semantics is an accounting-behavior decision requiring Product Owner sign-off, not a UI wording fix |
| UAT-F04 | LOW | "Closing balance (per statement)" label doesn't communicate that the system computes it as `opening_balance + SUM(unsigned reconciled amounts)`, not a conventional signed net-cash-movement balance | Confirmed correct-per-source-code (`ReconciliationService::preflightComplete()`); a label-clarity improvement, not a defect, deferred to avoid scope creep on a business-facing label |
| UAT-F05 | **Open question, not a defect** | `InvoiceVoidService`/`BillVoidService::safeWindowBlocker()` requires the document's own period to be in state exactly `'Open'` — a period that has gone through a completed Reopen cycle (state `'Reopened'`) does **not** satisfy this, so void becomes permanently unavailable once a period has ever been reopened | May be intentional policy (Reopened periods may have a separate correction workflow) or may be a gap — this is exactly the class of ambiguous accounting/approval rule `CLAUDE.md` requires be raised, not guessed at |
| UAT-F06 | LOW, cosmetic | Entity-switcher `Select` trigger is a fixed 176px (`w-44`) width; a longer entity display name wraps to two lines inside the pill (visible in `80-a11y-color-fix-dashboard.png`) | No functional impact; a width/truncation decision outside this pass's explicit scope |

## Totals

- **Defects found:** 16 (10 fixed; 6 logged as findings/open questions)
- **Fixed, by severity:** 1 BLOCKER, 3 HIGH, 6 MEDIUM
- **Regression status:** All 10 fixed defects re-verified individually (targeted scenario re-run) and collectively (full SQLite suite: 156 passed / 9 pre-existing PostgreSQL-only skips; full PostgreSQL suite: 165 passed, 0 skips; frontend typecheck/lint/build clean)
