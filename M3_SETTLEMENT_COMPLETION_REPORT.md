# M3 Settlement Completion Report

Status: Implementation complete; pull request open and unmerged.

- Branch: `codex/m3-settlement`
- Pull request: #13
- Implementation commit: `ca24132` (`feat(m3): implement settlement and credit tranches`)
- Baseline: latest merged M3 contract and foreign-credit-tranche governance on `main`

## Delivered scope

The implementation delivers the seven frozen public endpoints:

1. `POST /v1/receipts`
2. `POST /v1/payments`
3. `POST /v1/credits/{party}/apply`
4. `POST /v1/credits/{party}/refund`
5. `POST /v1/allocations/{id}/reverse`
6. `GET /v1/allocations`
7. `GET /v1/credits/{party}`

Receipts and payments support partial and multi-document application, configured withholding, exact foreign RateRecord references, realised FX, and unapplied party credit. Credit application and refund require explicit immutable source tranches with per-tranche versions. Reversal creates linked records, reverses Ledger journals and document balances, and restores every consumed source tranche using its recorded transaction and functional values.

## Persistence and migration

Migration `backend/database/migrations/2026_07_21_000000_create_m3_settlement_tables.php` adds:

- `settlement_allocations`
- `settlement_allocation_links`
- `settlement_withholding_lines`
- `settlement_credit_tranches`
- `settlement_credit_consumptions`
- `settlement_party_credit_balances`

PostgreSQL constraints enforce non-negative settlement Money, both frozen settlement equations, bounded tranche remainders, the consumption operation set, one reversal per Allocation, and one restoration per original consumption. Partial indexes support available-tranche queries and restoration uniqueness. PostgreSQL triggers prevent mutation or deletion of posted Allocation facts, links, withholding facts, consumption facts, and immutable tranche source fields. SQLite uses the same application invariants and schema-compatible indexes; PostgreSQL-only trigger behavior is tested separately.

No cross-context foreign key was introduced. Party, document, RateRecord, account, and period references cross boundaries only through approved UUID contracts.

## Domain and application components

- Settlement orchestration and transactional abort handling
- Immutable CreditTranche source facts and versioned remainder projections
- Append-only application, refund, and restoration CreditConsumption facts
- Rebuildable PartyCreditBalance projection that is never consumption authority
- Versioned `GetOpenReceivable`, `GetOpenPayable`, and `ApplySettlement` adapters owned by their document contexts
- Ledger-owned SettlementPostingService with exact debit/credit validation and linked reversal posting
- Tax-owned entity-scoped withholding configuration lookup
- FX-owned receipt/payment and credit realised-FX calculations
- Numbering integration with frozen used-and-voided failure behavior
- Durable approval command handlers for receipt, payment, credit application, credit refund, and reversal

## Protocol, security, audit, and events

All commands are authenticated, entity-scoped, capability-gated, idempotent, and unknown-field rejecting. Reversal requires `If-Match`; every document link and selected credit source uses its own expected version. Malformed caller correlation IDs retain the frozen `400 validation` behavior, absent correlation IDs are generated, and successful replay uses exactly `Idempotent-Replay: true`.

Configured maker-checker policy returns the durable Identity-owned pending approval response without creating an Allocation, consuming a number, changing a document, posting a journal, or emitting a Settlement business event. Approved execution replays the encrypted canonical command through the merged approval lifecycle. Existing maker separation, entity isolation, payload integrity, execution recovery, and exactly-once controls remain unchanged.

Audit, idempotency result, Settlement data, document balance mutation, Ledger posting, tranche mutation, projection rebuild, and outbox messages share one database transaction. Event metadata propagates correlation and causation. The implementation emits the frozen Settlement events and v2 tranche schemas, including `CreditHeld`, `CreditApplied`, `CreditRefunded`, and `AllocationReversed`, plus applicable document-status, withholding, realised-FX, and Ledger events. No client-calculated carrying value or realised FX is accepted or exposed as command input.

## Frontend

The existing application shell now routes Settlement to a real API-backed screen. It provides:

- allocation list and authorized reversal
- receipt and payment entry
- party-credit balance and immutable tranche inspection
- explicit credit-source application and refund
- pending-approval messaging that does not report false posting success
- safe API error display
- capability-specific visibility for read, receipt, payment, credit application, credit refund, and reversal actions

No M3 placeholder or mock data remains in the routed Settlement page.

## Accounting examples verified

- Domestic customer receipt: gross BDT 120 = bank BDT 108 + withholding BDT 12 = invoice application BDT 100 + credit held BDT 20. Bank and withholding debits equal receivable and customer-credit credits.
- Domestic vendor payment: payable debit BDT 100 equals bank/withholding credits totaling BDT 100.
- Foreign receipt: USD 10 carried at BDT 100 and settled at BDT 110 posts bank debit BDT 1,100, receivable credit BDT 1,000, and realised gain credit BDT 100.
- Foreign customer-credit application: USD 10 source carrying value BDT 1,000 applied to a document carrying BDT 900 posts customer-credit debit BDT 1,000, receivable credit BDT 900, and realised gain credit BDT 100.
- Foreign customer-credit refund: USD 10 source carrying value BDT 1,000 refunded at BDT 1,200 posts customer-credit debit BDT 1,000, realised loss debit BDT 200, and bank credit BDT 1,200.
- Linked reversal swaps every original journal line, restores document open amounts, and restores the exact source-tranche transaction and functional values; the original plus reversal journals net to zero.

## Validation evidence

- Empty PostgreSQL migration: pass
- PostgreSQL rollback then forward migration: pass
- PostgreSQL full suite: 65 passed, 436 assertions
- SQLite full suite: 63 passed, 429 assertions; 2 PostgreSQL-trigger tests skipped by design
- M3 focused PostgreSQL suite: 11 passed
- M3 focused SQLite suite: 10 passed; 1 PostgreSQL-trigger test skipped by design
- Pint: pass
- PHPStan: pass
- Rector dry run: pass
- Context boundary guard: pass
- Frontend typecheck: pass
- Frontend lint: pass
- Frontend production build: pass on Node 24
- Frozen API JSON blocks: 82 parsed successfully
- Public route inventory: 7/7 exact
- Composer validation: pass
- `git diff --check`: pass
- GitHub CI for implementation commit `ca24132`: Backend pass; Frontend pass

## Configuration dependencies

Deployment must supply approved values; none has a production default:

- entity functional currency and open/postable Periods
- receipt, payment, and refund Numbering prefixes/formats
- active bank/cash accounts
- invoice receivable and bill payable account mappings
- customer-credit and vendor-credit account mappings
- realised FX gain and loss account mappings
- FX rounding scale/mode and immutable applicable RateRecords
- entity-keyed withholding configurations with configuration UUID, version, active/effective dates, account, and posting direction
- entity roles/capabilities and optional durable approval policy

Missing, inactive, ambiguous, or cross-entity configuration fails safely without partial business effects.

## Deferred and excluded scope

Credit Notes, Debit Notes, full Period Close, reconciliation, migration, ageing, later reporting, automatic matching, and all M4/M5 behavior remain excluded. No FIFO, LIFO, weighted-average, pro-rata, automatic tranche selection, approval threshold, withholding rate, legal treatment, FX source, rounding default, or numbering format was invented.

The frontend deliberately uses explicit UUID and JSON-array entry for complex multi-document/tranche lines; richer lookup widgets are a later product-experience enhancement, not an accounting or contract dependency.

## Recommendation

MERGE after Product Owner review and approval. The implementation and automated controls are green; the pull request must remain open and unmerged until that approval is given.
