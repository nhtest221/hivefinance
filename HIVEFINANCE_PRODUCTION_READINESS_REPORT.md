# HiveFinance Production Readiness Report

**Prepared by:** Claude Code, acting as autonomous engineering owner per the standing
CLAUDE.md operating mandate.
**Scope of this report:** the hardening pass across M0–M6, PRs #21–#33, merged to `main`
at commit `9e29cdf`.
**Report date:** 2026-07-25 (session date).

## 1. Session scope

Starting point: `main` at `b6e03e6` ("M6 - Reconciliation (#20)"), with one known
pre-existing test failure (`M1LedgerValuationTest`) and CLAUDE.md prohibiting
autonomous merging.

This session:
1. Amended CLAUDE.md's operational Git/PR policy (PR #21) to permit gated autonomous
   merging, under 13 explicit, human-authored safety conditions, with a self-limiting
   clause preventing the policy from ever being autonomously widened further.
2. Ran a repository audit (Phase 1) covering DDD/context-boundary violations, security
   controls, maker-checker, audit/outbox coverage, concurrency, immutability,
   performance, and the known test failure.
3. Fixed every P0 (production-blocker) and every safely-derivable P1 (finance-team-beta
   blocker) finding, one PR per logical fix, each independently validated on SQLite and
   PostgreSQL, static analysis, and CI, then self-merged under the CLAUDE.md policy.

All 13 PRs (#21–#33) are merged. `main` is clean.

## 2. P0 findings and fixes (production blockers — all fixed)

| # | Finding | PR | Fix |
|---|---|---|---|
| P0-A | `CreditNoteService`/`DebitNoteService::post()` let the note's own maker self-execute posting with no approval policy configured — a real maker-checker bypass. | #22 | Added the same-actor `sod_exception_required` guard already established by `BillService::approve()`. |
| P0-B | `JournalService::post()` checked `version`/`state` before opening its `DB::transaction()`, against an unlocked row — two concurrent posts on the same version could both succeed, producing a duplicate `JournalPosted` event and a lost optimistic-concurrency guarantee. | #23 | Re-fetch with `lockForUpdate()` and re-check inside the transaction, matching the established pattern used elsewhere (`PeriodCloseService`, etc.). |
| P0-C | No catch-all exception handler existed; an unhandled `Throwable` fell through to Laravel's default renderer, which can leak a stack trace in a debug-enabled environment — a direct violation of API Contracts §3 ("No stack traces; no cross-context leakage"). Also found: `backend/.env.uat.example` committed a real, valid Laravel `APP_KEY`. | #24 | Added a final `render()` returning `{error_code: "internal_error", ...}` for JSON requests, explicitly excluding `HttpExceptionInterface` so framework 404/405/429 keep their real codes. Blanked the committed `APP_KEY`. |
| P0-D | `CashViewQuery` and `SourceDataWatermarkCalculator` (Reporting) queried Ledger's `JournalEntry`/`JournalLine` and Settlement's `Allocation` tables directly — an AP-001 context-boundary violation. | #25 | Added narrowly-scoped methods to the existing `AccountMovementQuery` (Ledger) and `AllocationQuery` (Settlement) owning-context contracts; Reporting now depends only on injected interfaces. |
| P0-E | `CreditNoteDispositionService`/`DebitNoteDispositionService::executeReverse()` wrote directly to Settlement's `CreditTranche` table to release an untouched hold on note reversal — another AP-001 violation, plus a duplicated, un-owned write path. | #26 | Added `CreditTrancheLedger::releaseHold()` (locks, re-verifies, zeroes under an optimistic-version guard) and `::findById()`; both disposition services now go only through the ledger. |

## 3. P1 findings and fixes (finance-team-beta blockers — all safely-derivable ones fixed)

| # | Finding | PR | Fix |
|---|---|---|---|
| P1-F/D | Four more Reporting query classes (`ARAgeingQuery`, `APAgeingQuery`, `TaxSummaryQuery`, `FXRevaluationQuery`) had the same AP-001 violation as P0-D — direct reads of Receivables/Payables/CurrencyFx tables. | #27 | New owning-context contracts: `ReceivablesReportQuery`, `PayablesReportQuery`, `RevaluationRunQuery`, plus an extension to `AllocationQuery`. All six Reporting query classes with this defect are now fixed. |
| P1-G | `ReconciliationAccountService::configure()`/`update()` recorded no audit log and no outbox event — the only mutating commands in the codebase without them. `update()` also never computed a request hash or called `DocumentCommandSupport::replay()`, unlike every other PATCH+`If-Match` command. | #28 | Added audit/outbox to both commands; added idempotency replay to `update()`. |
| P1-J | Four of five `money()` presenter helpers (`CreditNoteService`, `CreditNoteDispositionService`, `DebitNoteService`, `DebitNoteDispositionService`) returned raw amount/currency unchanged, unlike `SettlementService`'s correct `ExactDecimal::normalize()` + `strtoupper()` version — an inconsistent Money presentation across the API. | #29 | Centralized the correct behavior into `ExactDecimal::money()`; all five wrappers now delegate to it. |
| P1-K | Root cause of the known `M1LedgerValuationTest` failure: `FxService::revalue()`'s "is this policy setting configured" check treated a declared-but-blank `.env` value (`env()` returns `""`, not `null`) as configured, then failed later with a misleading `invalid_revaluation_account` error instead of the correct `missing_period_end_rate`. | #30 | Also treat empty string as missing. **This was a real bug, not a test-isolation artifact** — fixing it produced the first fully clean full-suite run this session. |
| P1-M | `FxService::reverseRevaluation()` had the same lost-update race as P0-B: fetched/checked outside the transaction, mutated a stale in-memory instance. | #32 | `lockForUpdate()` re-fetch/re-check inside the transaction. **Residual finding carried into this report — see §5.** |
| P1-N | `LedgerReportService::trialBalance()` ran one or two `JournalLine` queries *per account* (N+1) — a large chart of accounts could mean hundreds of queries for one Trial Balance request. `ARAgeingQuery`/`APAgeingQuery` had the identical shape per customer/vendor. | #31 | Added batched `sumAccounts()`/`postedCreditNotesForCustomers()`/`postedDebitNotesForVendors()`/`partyCreditBalanceTotals()`; each report now issues a small, fixed number of queries regardless of dataset size. |
| P1-H | `receivables_invoice_lines`, `payables_bill_lines`, `payables_bill_sbu_allocations`, and `fx_revaluation_runs` had no PostgreSQL immutability trigger — a posted invoice's line amounts/tax/description could be altered at the database level with nothing firing, even though the parent row was protected. | #33 | New parent-state-aware and mutable-field-whitelist triggers, matching the exact patterns already established for M4A note lines and M2 documents respectively. |
| P1-I | `backend/.env.uat.example` committed a real `APP_KEY`. | #24 | Fixed alongside P0-C (same PR, same root cause: a real secret in a template file). |

### P1-L — reclassified to P2, not fixed as originally scoped

Original finding: wrap `ReconciliationService`'s `open()`/`importStatement()`/
`generateMatchSuggestions()` in `DB::transaction()`.

On investigation, all ~9 public methods in `ReconciliationService` — not just these
three — share the same architecture: every individual multi-row sub-operation is already
atomic at the repository layer (`appendImportedLines()`, `replaceSuggestions()`,
`commitMatch()`, `commitConfirm()`, etc. are each independently wrapped in their own
`DB::transaction()`), while the service-level orchestration (domain write + audit +
outbox + idempotency-store, or a loop of per-row repository calls) is not wrapped in one
outer transaction — consistently, across the whole file. Wrapping only three of nine
structurally identical methods would have been an inconsistent, arbitrary change relative
to the file's own established pattern, not a targeted bug fix. Retry-safety in every case
is already protected by idempotency-key, duplicate-file-detection, or optimistic-version
checks further up each method.

This is a legitimate defense-in-depth improvement, but it should be applied consistently
across the whole class (and likely audited across other services using the same
architecture) as a deliberate, reviewed change — not cherry-picked based on an earlier,
shallower audit pass. **Retained as a P2 architectural-consistency item; not implemented
this session.**

## 4. Final validation evidence (this session, on merged `main` @ `9e29cdf`)

- Backend, SQLite: **156 passed, 0 failed, 9 skipped** (skips are the PostgreSQL-only
  immutability-trigger tests, correctly inert on SQLite), 1001 assertions.
- Backend, PostgreSQL (fresh throwaway database, migrated from zero): **165 passed, 0
  failed**, 1042 assertions. This is the first fully clean run on both engines this
  session — the pre-existing `M1LedgerValuationTest` failure is gone (fixed by #30), and
  no fix introduced a regression.
- PHPStan (`--memory-limit=1G`): clean, no errors.
- Pint: passed.
- Rector dry-run: no changes needed.
- Context-boundary guard (`scripts/check-boundaries.php`): passed.
- `composer validate --strict`: valid.
- Secret scan: `backend/.env` is correctly gitignored and untracked; `backend/.env.example`
  and `backend/.env.uat.example` both have a blank `APP_KEY`; no other suspicious
  credential-shaped strings found in tracked env/yml files.
- Frontend: `npm run typecheck` clean, `npm run lint` clean, `npm run build` succeeded
  (advisory-only warnings: local Node 20.17.0 is below Vite's recommended 20.19+/22.12+,
  and the main JS chunk is 902 KB pre-gzip / 268 KB gzipped — a code-splitting opportunity,
  not a defect).
- SQLite and PostgreSQL migrations, rollback+forward, ran clean throughout every PR in
  this session (verified individually per PR; not re-run as one combined pass in this
  final sweep since no schema changed after PR #33's own validation).

## 5. Known residual risks

1. **`FxService::reverseRevaluation()` has no HTTP route or scheduled trigger.** A
   repo-wide search confirms it is only ever invoked directly from
   `M1LedgerValuationTest`. Its signature (`string $actorId`, not `User $actor`) matches
   the codebase's internal/system-triggered method convention, not the user-facing
   command-handler convention. SRS §5.14 requires unrealised FX revaluation to be
   "reversed at next-period start," but no scheduled command or Period state-transition
   hook currently implements that automatic trigger. **This needs an explicit Product
   Owner/architecture decision** (scheduled command? a Period-open hook? what actor
   identity should a system-triggered reversal record?) before it can be wired to any
   real invocation path. Flagged in PR #32; not resolved, by design — inventing the
   trigger mechanism would have been a product decision, not a hardening fix.
2. **P1-L reclassified to P2** (see §3) — `ReconciliationService`'s service-level methods
   rely on repository-level (not service-level) transaction atomicity, consistently
   across all ~9 methods. A full audit of whether this pattern is safe everywhere it's
   used (including possibly other services with the same shape) is recommended before
   deciding whether to change it.
3. **Frontend bundle size**: the production build's main chunk is 902 KB (268 KB
   gzipped) with no code-splitting. Not a defect, but worth addressing before a
   traffic-sensitive launch.
4. **Frontend build environment**: local Node is 20.17.0; Vite recommends 20.19+ or
   22.12+. The build succeeds regardless, but CI's Node version should be confirmed to
   meet Vite's recommendation to avoid divergent local/CI behavior over time.
5. **This session's audit (Phase 1) was not exhaustive.** It was scoped to the findings
   enumerated in the governing instruction (maker-checker, AP-001 boundaries, audit/
   outbox, concurrency/immutability, N+1s, the known test failure, secrets). Phase 3
   (UX polish across all workflows) and Phase 4 (production-readiness documentation:
   deployment runbook, backup/restore, health checks, monitoring, release checklist,
   rollback plan, UAT runbook) as defined in the standing mission have **not** been
   started this session.

## 6. Recommendation

**GO for continued Phase 2 closure / CONDITIONAL GO for finance-team beta**, conditional
on:
- A Product Owner decision on the FX-revaluation-reversal trigger mechanism (§5.1) before
  that feature is exposed to any real workflow — it is currently correctly dormant
  (unreachable), not broken.
- Phase 3 (UX polish) and Phase 4 (production-readiness docs, deployment runbook,
  backup/restore, monitoring) being completed before an actual finance-team beta launch —
  neither has been attempted this session.

All P0 production blockers identified this session are fixed, verified, and merged. All
P1 findings that could be safely resolved without inventing an accounting, tax, VAT, FX,
approval, or security rule have been fixed, verified, and merged. The one P1 finding not
implemented as originally scoped (P1-L) was demoted after investigation showed the
original framing didn't hold up against the full file's architecture, not because it was
skipped — the rationale is recorded above for the next reviewer.

## Appendix: PRs merged this session

| PR | Title |
|---|---|
| #21 | ops(policy): permit gated autonomous PR merging in CLAUDE.md |
| #22 | fix(receivables,payables): block credit/debit note maker from self-posting |
| #23 | fix(ledger): close lost-update race in JournalService::post() |
| #24 | fix(security): add catch-all exception handler; stop committing a real APP_KEY |
| #25 | fix(reporting): close AP-001 violations in CashViewQuery and SourceDataWatermarkCalculator |
| #26 | fix(receivables,payables): close AP-001 violations in note-reversal credit-hold release |
| #27 | fix(reporting): close remaining AP-001 violations (P1-F) — ageing, tax summary, FX revaluation |
| #28 | fix(reconciliation): add audit/outbox and idempotency replay to ReconciliationAccountService |
| #29 | fix(support): deduplicate money() helper, fix inconsistent Money presentation |
| #30 | fix(currencyfx): treat blank-string FX policy config as missing, not configured |
| #31 | fix(reporting,ledger): eliminate N+1 queries in Trial Balance and AR/AP Ageing |
| #32 | fix(currencyfx): close lost-update race in FxService::reverseRevaluation() |
| #33 | fix(database): add missing immutability triggers for M2 document lines/SBU allocations and fx_revaluation_runs |

Final `main` commit: `9e29cdf`.
