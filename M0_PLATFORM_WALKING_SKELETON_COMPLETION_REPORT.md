# M0 Platform + Walking Skeleton Completion Report

Branch: `codex/m0-roadmap-alignment`

## Outcome

This branch aligns the existing capabilities on `main` with the frozen roadmap's **M0 Platform + Walking Skeleton** milestone. It completes only the identified M0 gaps and does not implement later business contexts.

## Delivered capabilities

### Approved API governance

- Incorporated the approved M0 manual-journal amendment into `docs/HiveFin_API_Contracts.md`.
- Preserved the proposal, implementation evidence, approval corrections, and rule traceability in `PROPOSED_API_CONTRACT_AMENDMENT_M0.md`.
- Draft dates require valid fiscal-period membership but not current postability.
- `202 PendingApproval` is a command outcome, not an error.
- General Ledger cursor pages preserve deterministic running balances.

### Numbering

- Added the frozen Sequence aggregate, SequenceScope value object, and SequenceRepository contract.
- Added serialized database draws scoped by entity + fiscal year + series.
- Added used-and-voided number recording; issued values are never decremented or reused.
- Added generic configuration only. No document prefix, starting number, or reset decision was invented.

### Period foundation

- Added entity-scoped fiscal-calendar persistence.
- Replaced the Ledger-owned Period service with a Period-owned public query contract.
- Ledger now consumes Period postability through that interface.
- Added an Identity-owned public entity-reference query for functional currency rather than direct Ledger reads of Identity persistence.

### Transactional outbox

- Added an in-process event bus and scheduled dispatcher.
- Dispatch selects committed outbox rows only.
- Added an observable, idempotent `JournalPosted` consumer keyed by event ID + consumer.
- Added retry metadata/backoff and correlation-ID propagation.
- Added rollback, commit, delivery, and duplicate-replay integration coverage.

### Boundary enforcement

- Added a dependency guard for pure Domain layers, Period/Identity persistence bypasses from Ledger, and foreign repository imports.
- Wired the guard into GitHub CI.

### Real walking-skeleton UI

- Replaced the journal mock route with authenticated API integration.
- Loads active entity-scoped accounts.
- Creates an exact-decimal balanced Draft with an idempotency key.
- Posts with `Idempotency-Key` and `If-Match`.
- Displays stable API errors and correlation IDs.
- Reads and renders the resulting accrual General Ledger.

## Frozen rules and contracts

- AP-001; ARCH-01–06.
- ADR-001, ADR-002, ADR-004, ADR-005, and ADR-009.
- DOM-02, DOM-04, DOM-05, DOM-09, DOM-10.
- API-01–06; approved API Contract §7.
- REPO-01–06; SequenceRepository, Period OHS, and GeneralLedgerQuery.
- DB-02–05, DB-08, DB-09.
- LOG-01–04; ERR-01–05; TEST-03–05.

## Validation

Local results:

- `npm run typecheck` — passed.
- `npm run lint` — passed.
- `npm run build` — passed.
- `git diff --check` — passed before commits.
- `backend/composer.json` JSON parse — passed.

Local limitations:

- PHP, Composer, Docker, and `backend/vendor` are unavailable in this shell.
- PHP tooling is therefore validated by the required GitHub PR pipeline.

GitHub PR #3 pipeline, run `29411941069`:

- Backend — passed in 1m 1s.
  - Composer install and application bootstrap.
  - PostgreSQL migration application.
  - Laravel Pint.
  - PHPStan/Larastan.
  - Context-boundary guard.
  - Rector dry run.
  - Pest: 23 tests passed with 86 assertions, including journal API, numbering persistence, outbox rollback/delivery/replay, authz, audit, and ledger integration.
- Frontend — passed in 27s.
  - npm clean install.
  - TypeScript typecheck.
  - ESLint.
  - Production build.

Warnings:

- Vite reports that local Node 20.17.0 is below its preferred 20.19+ or 22.12+ version.
- Vite reports the existing large-bundle warning.

## Unresolved configuration

The frozen SRS leaves fiscal-year reset behavior for invoice numbering as a policy choice. M0 therefore supplies only the generic scoped serialized mechanism. Series prefixes, initial values, and reset policies require approved configuration before a document context consumes Numbering.

## Explicit exclusions confirmed

No Tax, Currency & FX, Receivables, Payables, Settlement, Reconciliation, migration business workflow, credit/debit note, correction, close lifecycle, later reporting, or consolidation behavior was added.

## Completion recommendation

The required pipeline is green. M0 can now be marked complete after human review and approval of PR #3. The next roadmap milestone is **M1 Ledger + Valuation** (Ledger, Tax, FX); this branch does not begin it.
