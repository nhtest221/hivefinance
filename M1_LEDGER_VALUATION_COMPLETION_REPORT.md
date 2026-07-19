# M1 Ledger + Valuation Completion Report

## Status

Implementation branch: `codex/m1-ledger-valuation`
Baseline: `f823f190bafe3faaf8ea75b08681ffba1af027ad`
Milestone: frozen roadmap **M1 Ledger + Valuation**

## Reused Components

- Existing Identity/Auth, MFA, entity isolation, roles, capabilities, and session handling.
- Durable `ApprovalLifecycleService`, encrypted originating-command storage, audit, outbox, and idempotency persistence.
- Existing Ledger draft/post flow, Period query contract, exact `DecimalAmount`, General Ledger query, and frontend shell/design system.
- M0 correlation middleware, retaining malformed-header rejection and absent-header generation.

## Corrected Components

- Account create/update/deactivate now use canonical idempotency behavior; update/deactivate enforce `If-Match` optimistic concurrency.
- Account descriptions and contract response fields are persisted and presented.
- Journal search now supports the frozen filters, deterministic cursor ordering, totals, and pagination.
- Reversal uniqueness is database-enforced and safely handles duplicate/concurrent commands; configured approval policies route reversal through the durable maker-checker lifecycle.
- Foreign journal lines persist and verify exact immutable RateRecord references and mark records referenced at posting.
- Account balance responses match the approved Money schema; `JournalPosted` maintains rebuildable balance projections.
- Approval replay responses use the canonical `Idempotent-Replay: true` header.

## New Components

### Tax

- Entity-scoped `TaxCode`, effective-dated `TaxCodeVersion`, and `TaxPack` persistence.
- Non-overlap checks, resource-version concurrency, configured-jurisdiction validation, entity-owned GL mapping validation, and immutable `TaxSnapshot` value output.
- Internal applicable-tax lookup requiring an active TaxPack and exactly applicable version.
- Four-eyes handlers for code definition, version creation, and TaxPack configuration using the merged approval lifecycle.
- Atomic audit records and `TaxCodeVersioned` / `TaxPackConfigured` outbox events.

### Currency & FX

- Append-only `RateRecord` storage with configured-source validation, override-reason enforcement, exact-reference service, and PostgreSQL immutability trigger.
- Internal configured-precedence applicable-rate lookup and exact per-tranche realised-FX calculator using explicitly configured precision and rounding.
- `RevaluationRun` persistence, entity-period uniqueness, Soft Close validation, foreign-bank valuation, balanced system postings, next-period reversal scheduling/linkage, audit, and outbox events.
- Configured rate and revaluation commands route through the durable maker-checker lifecycle.
- Missing source, rounding, gain/loss-account, or period-end-rate configuration fails safely without defaults.

## Migrations

`2026_07_16_100000_create_m1_ledger_valuation_tables.php` adds:

- Ledger account description, foreign reference snapshot columns, single-reversal constraint, and account-balance projection.
- Tax code, version, and TaxPack tables.
- FX RateRecord and RevaluationRun tables plus RateRecord immutability enforcement.
- No cross-context foreign keys were introduced.

## APIs

- Ledger: account commands/balance, complete journal search, reversal, and foreign-currency journal extension.
- Tax: `POST/GET /v1/tax/codes`, `GET /v1/tax/codes/{id}`, `POST /v1/tax/codes/{id}/versions`, and `POST /v1/tax/packs`.
- FX: `POST/GET /v1/fx/rates` and `POST/GET /v1/fx/revaluation`.
- Applicable tax/rate lookup and realised-FX calculation remain internal application contracts.

## Frontend

- Reused the existing authenticated shell, navigation, cards, forms, tables, alerts, and tokens.
- Replaced Tax and FX placeholder routes with API-backed TaxCode and RateRecord list/create flows.
- Tax configuration clearly presents the pending-approval outcome.

## Approval and Security Controls

- Tax configuration is always four-eyes and executes only from encrypted durable approval payloads.
- Configured journal reversal policy uses the same lifecycle with maker/approver separation and entity/capability enforcement.
- Configured FX rate and revaluation policy uses the same lifecycle and safely replays the immutable originating command.
- Every query and mutation is entity-scoped and default-deny.
- Unknown request fields are rejected; sensitive approval payloads remain inaccessible to these contexts.
- Audit and outbox writes share the command transaction.

## Tests and Validation

- Backend: 43 tests, 216 assertions.
- Added coverage for Tax and configured FX four-eyes execution, FX RateRecord/audit/outbox behavior, exact foreign journal references, safe missing revaluation configuration, posted/referenced rates and next-period reversal linkage, reversal approval integration, and realised-FX arithmetic.
- Pint, PHPStan, Rector dry-run, context-boundary guard, SQLite fresh migration, frontend typecheck/lint/build, API JSON/route uniqueness, and `git diff --check` pass.

## Configuration Dependencies

- Approved tax jurisdictions and TaxPack policy/schema values.
- Approved FX sources and source precedence.
- FX precision and rounding mode.
- Unrealised gain/loss Ledger account IDs.
- Entity Approval Policy JSON where configurable reversal approval is required.
- No value above has a hardcoded business default.

## Deferred Scope

- Receivables, Payables, Settlement, Notes, full Period Close, Migration, Reconciliation, and later Reporting remain untouched.
- Revaluation currently consumes M1 foreign-bank Ledger balances. Foreign AR/AP open items join the same internal valuation contract when their owning contexts are implemented in M2.
- Consolidation, inverse/cross-rate generation, feed selection, and revaluation rerun policy remain unimplemented configuration/governance boundaries.

## Pre-merge audit corrections

- Verified the complete migration, indexes, uniqueness constraints, rollback/reapply path, and immutable RateRecord/TaxCodeVersion triggers on PostgreSQL 17.
- Enforced exact foreign-to-functional conversion against the immutable RateRecord and configured rounding policy.
- Added `JournalPosted` for reversal journals so projections reverse with the ledger.
- Removed Tax/FX direct Ledger table access in favor of Ledger application contracts.
- Bound list cursors to entity, filters, ordering, and a stable read boundary.
- Forced PostgreSQL application sessions to UTC and completed request/query validation gaps.
- Replaced remaining mock M1 Chart of Accounts UI with capability-aware API flows.
