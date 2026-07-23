# M6 — Reconciliation: Implementation Completion Report

**Branch:** `claude/m6-reconciliation`
**Base:** `main` @ `57e1d6a` (Governance: freeze M6 Reconciliation amendment (M6-GOV-001), #19)
**Governance authority:** `docs/HiveFin_API_Contracts.md` §14; Governance Approval Record `M6-GOV-001` (`docs/HiveFin_Decision_Log.md`)
**Latest commit:** `ed38393`

## Scope delivered

All 16 frozen M6 endpoints, matching the API Contracts §14.3 inventory exactly (verified via `php artisan route:list`):

**`ReconciliationAccount` configuration** (§14.4) — `POST/GET/GET-by-id/PATCH /v1/reconciliation-accounts` — thin, Reconciliation-owned metadata referencing exactly one existing asset-type `LedgerAccount` (validated via a new, additive `AccountReferenceQuery::isActiveAsset()` method); never a second accounting account, never a duplicated Ledger balance; `ledger_account_id`/`entity_id`/`currency` immutable once created.

**`BankReconciliation` lifecycle** (§14.5–§14.9) — 12 endpoints:
- `ImportStatement` — duplicate statement lines rejected (not silently skipped), scoped to the account's full history via a null-safe `(entity, account, date, amount, currency, normalized narration, external reference)` identity check; whole-file re-import rejected before per-line checking.
- `GenerateMatchSuggestions` — matches against M3 Settlement `Allocation.bank_amount` (never raw journal lines) with exact currency/amount, a ±3 calendar-day window, and normalized reference comparison as a ranking signal only. One-to-one, one-to-many, and many-to-one suggestions via a bounded (max 3-item) combination search — a deliberate, stated scope limit. Suggestion-only: never auto-confirms.
- `MatchLine`/`ConfirmMatch` — many-to-one groups are matched and confirmed together atomically (siblings sharing the same `matched_allocation_ids` cascade-confirm); a consumed `Allocation` cannot be reconciled twice.
- `CreateEntryForBankLine` — the offset `LedgerAccount` is always explicitly, manually selected, never inferred or defaulted; posts through Ledger's existing `RecognitionPostingService`, in-process (AP-001).
- `CompleteReconciliation` — every line must be `Reconciled`; the unexplained difference must be exactly `0.0000`; no force-close, no bypass.
- `ReopenReconciliation` — re-admits the batch to further work without reverting any already-`Reconciled` line, mirroring `AccountingPeriod.Reopened`.
- `GET .../unmatched`, `GET .../statement` (PDF/CSV export, XLSX excluded per M5 precedent).

**Mandatory four-eyes** (§14.2, M6-GOV-001 item 9) — `CreateEntryForBankLine`, `CompleteReconciliation`, `ReopenReconciliation` always return `202 pending_approval` (unconditional, matching M4 Hard Close's pattern, not M2–M5's policy-conditional pattern), committed through the existing, unchanged `POST /v1/approvals/{id}/approve` — no new approval endpoint.

**`ReconciliationCloseGateProvider`** (§14.11) — supplies `bank_reconciliation_completed`, replacing `UnavailableCloseGateProvider` in the `CloseGateProviderRegistry`. Mandatory scope is every `ReconciliationAccount` with `reconciliation_enabled=true`; zero configured accounts is vacuously satisfied (the Product Owner's own definition of "mandatory" is "configured"). Composite evidence (multiple accounts, one frozen `CloseGateResult` shape) via a controlling reconciliation plus a tamper-evident SHA-256 digest over the full qualifying set. Staleness re-checked against new/reversed `Allocation` activity, mirroring M5's `report_run_stale` precedent.

**New internal contracts:** `App\Settlement\Application\AllocationQuery` (Settlement-owned, mirrors Ledger's `AccountMovementQuery` pattern exactly, so Reconciliation never reads `settlement_allocations` directly — AP-001) and one additive method on the existing `App\Ledger\Application\AccountReferenceQuery` contract.

**Frontend** — `frontend/src/features/reconciliation/`: bank-account configuration, the full batch workflow (open/import/suggest/match/confirm/bank-entry/complete/reopen), export, and an inline "approve as current user" action against the generic approvals endpoint (necessary here since M6's approval is always mandatory, unlike M5's optional flow). Replaces the pre-M6 `BankAccountsPage` placeholder at `/bank-accounts`, following the exact precedent M5 set replacing `ReportsPage`.

## Defects found and fixed during implementation

1. **Duplicate-line detection compared raw DB strings.** SQLite silently normalizes `"1000.0000"` to `"1000"` and can attach a time component to a `date` column; a naive `->where('amount', $amount)->where('transaction_date', $date)` query would have silently failed to catch real duplicates on SQLite while working correctly on PostgreSQL's strict typing — a genuine cross-engine correctness gap, not just a cosmetic difference. Fixed by narrowing the query to the safe string-exact fields (account, currency, normalized narration, reference) and comparing amount/date in PHP via `ExactDecimal::compare()`/`Carbon`.
2. **`GenerateMatchSuggestions` bumped each line's version but never returned it.** Every suggestion-generation run is a real state change (`Unreconciled`/`Unexplained` → `Suggested`/`Unexplained`) and correctly increments the line's optimistic-concurrency version — but the response never exposed the new version, leaving no way for a real client to build the next `If-Match` header for `MatchLine`/`CreateEntryForBankLine`. Fixed by including `version` per line in the response.

Both were caught while writing the test suite, not by manual review — a concrete case for the "validate against PostgreSQL, not SQLite-only" and "write real tests" requirements doing their job.

## Two implementation-level gaps filled without inventing business rules

The governance amendment specified every business rule precisely but left two purely mechanical details unstated (not business decisions — resource-identification conventions already established everywhere else in this API):
- `OpenReconciliation` with a nonexistent `reconciliation_account_id` returns `404 not_found`, matching the universal cross-context resource-reference convention used throughout M1–M5 (e.g., a nonexistent comparison period in M5's Profit and Loss).
- `CreateEntryForBankLine`'s offset-account validation uses the existing `AccountReferenceQuery::isOwnedByEntity()` check (same entity, any active or inactive Ledger account) rather than inventing a new validation rule — consistent with `PostingService`'s own account validation elsewhere.

## Validation evidence

- **Backend tests, SQLite:** full suite — 146 passed, 1 pre-existing failure (`M1LedgerValuationTest`, confirmed unrelated to M6 in the M5 completion report), 7 skipped (PostgreSQL-only).
- **Backend tests, PostgreSQL:** full suite — 153 passed, same single pre-existing M1 failure. All PostgreSQL-only immutability triggers exercised for real: `protect_bank_reconciliations` (Completed facts frozen, delete blocked), `protect_reconciliation_statement_lines` (Reconciled lines frozen, delete blocked), `protect_reconciliation_import_batches` (append-only). The null-safe `COALESCE`-based duplicate-identity unique index confirmed installed via `\d reconciliation_statement_lines`.
- **28 new M6 tests** across 5 files: persistence (entity isolation, optimistic concurrency, cross-batch duplicate rejection, PostgreSQL triggers), account configuration (asset-type validation, duplicate rejection, cross-entity 404), full lifecycle (happy path with mandatory four-eyes including maker-cannot-approve-own-request, duplicate import, unexplained-difference/not-fully-reconciled blocks, bank-only entry with a real Ledger posting verified, reopen-without-reverting then re-complete), close-gate integration (unmet, vacuous satisfaction, satisfied-with-staleness-detection, and a **full M4+M5+M6 Hard Close end-to-end proof** — all 5 gates satisfied, `HardClosed` achieved), and export (CSV/PDF/xlsx-rejection).
- **Migrations:** clean `migrate:fresh` plus rollback/forward round-trip on both a fresh SQLite file and a fresh PostgreSQL database.
- **Static analysis:** PHPStan — 0 errors.
- **Formatting:** Pint — passed, no diffs.
- **Refactor-safety:** Rector dry run found one mechanical first-class-callable-syntax finding in M6-authored code; applied, re-verified clean.
- **Context boundaries:** `scripts/check-boundaries.php` — passed. AP-001 preserved via the new `AllocationQuery` internal Settlement contract; `CreateEntryForBankLine` posts only through Ledger's existing `RecognitionPostingService`, never a direct write.
- **Composer:** `composer validate --strict` — valid.
- **Frontend:** `tsc -b`, `eslint .`, `vite build` — all clean.
- **Contract conformance:** all 16 endpoint paths/verbs cross-checked against §14.3 via `php artisan route:list` — exact match.
- **Cross-milestone regression check:** two pre-existing M4/M5 tests needed updates because their fixtures configure no `ReconciliationAccount` — `bank_reconciliation_completed` is now correctly vacuously satisfied for them per M6-GOV-001's own definition of "mandatory." `M4PeriodCloseTest`'s two assertions were updated to match; `M5CloseGateIntegrationTest`'s "leaves reconciliation unmet" test was updated by adding a mandatory, incomplete `ReconciliationAccount` fixture to **preserve its original intent** (proving Reporting gates alone are insufficient) rather than just loosening the assertion.
- **`git diff --check`:** clean across the full branch diff.
- **Working tree:** clean.
- **Frozen documents:** zero changes under `docs/` on this branch (the M6-GOV-001 content already exists on `main` from the separately-merged governance PR #19).

## Recommendation

**MERGE.** All 16 frozen M6 endpoints are implemented, tested on both SQLite and PostgreSQL (including a full cross-milestone Hard Close proof), and contract-conformant with API Contracts §14 and Governance Approval Record M6-GOV-001. No known open items remain. The one pre-existing, out-of-scope `M1LedgerValuationTest` failure predates this milestone and should be tracked separately, as noted in the M5 completion report.
