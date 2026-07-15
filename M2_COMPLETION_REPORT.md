# M2 Completion Report — Ledger Core

Branch: `codex/m2-ledger-core`

## Status

Implementation work for Milestone M2 is complete for review. The branch has not been pushed.

Backend local validation is blocked in this environment because `php`, `composer`, `docker`, and `backend/vendor` are unavailable locally. Frontend validation completed successfully.

## Scope Delivered

- Chart of Accounts persistence and API.
- Accounting Period persistence and postability query.
- Manual Journal Entry draft creation.
- Double-entry validation using exact fixed-precision decimal handling.
- Journal posting with period-status enforcement.
- Posted-entry immutability foundation with PostgreSQL database triggers.
- Reversal workflow through linked posted reversal entries.
- General Ledger query.
- Trial Balance query.
- Audit logging for account and journal actions.
- Transactional outbox records for account lifecycle, journal posting, and reversal events.
- M1 role/entity authorization enforcement on M2 operations.
- M2 frontend layouts for Chart of Accounts, Journal Entries, Reports, and period/accounting controls using the existing Design System only.

## Architecture Compliance

- ADR-001: Ledger remains accrual system of record; reports are derived from posted journal lines.
- ADR-002: Posted journal entries are not edited or deleted; corrections use linked reversal entries.
- ADR-004: Posting is gated by accounting period status.
- ADR-005: M2 commands enforce entity-scoped RBAC/ABAC through the M1 authorization service.
- AP-001: Ledger owns CoA, journals, posting, and derived balances; Period and Identity are consumed as upstream authorities.
- Aggregate Design: `LedgerAccount`, `JournalEntry`, and `AccountingPeriod` boundaries are preserved.
- Repository/Database Contracts: balances are derived; posted rows have database immutability enforcement; audit and outbox are separate.
- API Contracts: M2 endpoints follow `/v1/accounts`, `/v1/journals`, `/v1/periods`, and `/v1/reports` shapes.

## Tests Added

- Unit:
  - Exact decimal arithmetic.
  - Float money rejection.
- Feature:
  - CoA create/deactivate with audit and outbox.
  - RBAC denial.
  - Balanced journal posting.
  - Period lock rejection.
  - General Ledger and Trial Balance reads.
  - Journal reversal without mutating the original posted entry.
  - Journal audit and outbox events.

## Validation

Passed:

- `npm run typecheck`
- `npm run lint`
- `npm run build`
- `git diff --check`

Warnings:

- Vite build completed, but local Node is `20.17.0`; Vite recommends `20.19+` or `22.12+`.

Blocked locally:

- `php -v` failed: `php` not found.
- `composer test` failed: `composer` not found.
- `docker --version` failed: `docker` not found.
- `backend/vendor/bin` is absent, so Pint, PHPStan, Rector, and Pest cannot run locally in this environment.

## Commits

- `8cba62b` — `feat(backend): implement ledger core foundation`
- `b29dc18` — `feat(frontend): add ledger core screens`

## Not Included

- No Receivables, Payables, Settlement, Tax, FX, Reconciliation, Migration, or M3 business logic.
- No changes to frozen architecture documents.
- No backend CI confirmation, because the branch was not pushed per instruction.
