# HiveFin — Database Design

**Frozen inputs (immutable):** Domain Model v2 · Context Map · Aggregate Design · Domain Events · Repository Contracts · AP-001.
**Rule:** the schema conforms to the domain — the domain does not bend to the schema. **No domain weakness discovered.** One architectural consequence surfaced and reinforced (§0.2): the accounting core co-resides in one database. This is consistent with the frozen Context Map, not a change to it.
**Technology-agnostic:** relational model described in domain terms (tables/columns/keys/indexes/constraints). No product-specific syntax.

---

## 0. Database Principles

**0.1 Schema-per-context ownership.** Each bounded context owns its own schema (namespace). A context **never** reads or writes another context's tables. Cross-context data is obtained only through application services/queries (AP-001 enforced at the data layer, not just in code).

**0.2 Accounting core co-resides; extractable contexts separable.** The **strong settlement/recognition Unit of Work** spans Ledger + Receivables + Payables + Settlement, so their schemas **share one database instance** (schema-per-context, one DB) to keep that UoW a local transaction. This is exactly the Context Map's *do-not-split* boundary. The **extractable** contexts (Reporting, Identity, Tax, Migration, Reconciliation, Numbering) may live in separate schemas today and separate databases later without breaking a strong transaction.

**0.3 No cross-context foreign keys.** References across contexts are **UUIDs with no database FK** (FKs would couple schemas and block extraction). Referential integrity across contexts is a domain/service concern. **FKs are used only *within* an aggregate** (root→children).

**0.4 UUID identity.** Every aggregate PK is its immutable `DocumentId` (UUID, ADR-009). Business `DocumentNumber` is a separate, indexed, non-PK column.

**0.5 Immutability enforced at the store.** Posted rows (journals, allocations, issued invoices/bills, notes) are **append-only** — updates are blocked except the whitelisted mutable fields (`openBalance`, `status`, `version`). Corrections are **new rows** (reversals/notes). Enforced by DB constraints/triggers/permissions, not merely by application code.

**0.6 Derived balances.** No authoritative "account balance" column. Account balances are computed from posted lines or served by a **balance projection** table maintained from `JournalPosted` events. Document `openBalance` is the single guarded stored balance.

**0.7 Multi-entity isolation.** Every business table carries `entity_id`; all queries are entity-scoped; the boundary is enforced in the repository/query layer.

**0.8 Transactional outbox.** Each context has an `outbox` table; domain events are written in the aggregate's commit transaction and dispatched post-commit (reliable eventual tier).

**0.9 Immutable audit log.** A dedicated append-only `audit_log` (UTC timestamp, user, entity, module, action, record ref, before/after JSON) — never updated or deleted (SRS §10).

---

## 1. Schemas & Core Tables (by context)

> Columns shown are the load-bearing ones; `id (uuid pk)`, `entity_id`, `version`, `created_by`, `created_at` are implicit on business tables.

### ledger
- **account** — `code`, `name`, `type`, `status`, `bank_attributes(json,null)`, `parent_account_id(uuid,null)`. Unique(entity_id, code).
- **journal_entry** — `entry_type`, `entry_date`, `period_ref`, `narration`, `reference`, `source_document_id(uuid)`, `reversal_of_entry_id(uuid,null)`, `state`. Append-only when state=Posted.
- **journal_line** — `entry_id(fk→journal_entry)`, `account_id(uuid)`, `debit`, `credit`, `currency`, `fx_amount(null)`, `rate_record_id(uuid,null)`, `sbu_tag(null)`, `line_no`. FK only to its entry.
- **sbu** — `code`, `name`, `status`. (SBU dimension owned here.)
- **account_balance_projection** — `account_id`, `as_of`, `balance` (maintained from events; rebuildable).
- Indexes: journal_line(account_id, entry_date), journal_entry(source_document_id), journal_entry(period_ref).

### receivables
- **invoice** — `document_number`, `customer_id(uuid)`, `invoice_date`, `due_date`, `currency`, `rate_record_id(uuid)`, `total`, `open_balance`, `status`, `payment_instructions_ref`. Mutable: open_balance, status, version.
- **invoice_line** — `invoice_id(fk)`, `description`, `qty`, `unit_price`, `tax_snapshot(json)`, `amount`.
- **credit_note** — `document_number`, `source_invoice_id(uuid)`, `reason_code`, `disposition`, `tax_snapshot(json)`, `total`, `period_ref`.
- **customer** — `name`, `type`, `tax_id`, `default_currency`, `payment_terms`, `status`.
- Indexes: invoice(document_id) [PK], invoice(customer_id, status), invoice(due_date) partial where status≠Paid, unique(entity_id, document_number).

### payables
- **bill** — `document_number`, `vendor_id(uuid)`, `bill_date`, `due_date`, `currency`, `rate_record_id`, `total`, `open_balance`, `status`, `ait`, `vds`.
- **bill_line** — `bill_id(fk)`, `description`, `qty`, `unit_price`, `tax_snapshot(json)`, `expense_account_id(uuid)`, `amount`.
- **bill_sbu_allocation** — `bill_id(fk)`, `sbu_code`, `weight`. Constraint: Σweight = 1.0000 (checked in aggregate; assertable).
- **debit_note**, **expense** (`settlement_type`), **vendor**.
- Indexes: bill(vendor_id, status), bill(due_date) partial, unique(entity_id, document_number) per series.

### settlement
- **allocation** — `direction`, `settlement_date`, `amount`, `currency`, `rate_record_id(uuid)`, `bank_account_id(uuid)`, `party_id(uuid)`, `state`. Append-only when Posted.
- **allocation_link** — `allocation_id(fk)`, `target_document_id(uuid)`, `applied_amount`, `realised_fx`, `withholding_type(null)`, `withholding_amount(null)`.
- **party_credit_balance** — `party_id`, `balance`. Constraint: balance ≥ 0.
- Indexes: allocation_link(target_document_id), allocation(party_id, settlement_date).

### tax
- **tax_code** — `jurisdiction`, `treatment`, `recoverable`, `gl_mapping`, `return_box_mapping(json)`.
- **tax_code_version** — `tax_code_id(fk)`, `rate`, `calc_method`, `effective_from`, `effective_to`. Non-overlapping per code.
- **tax_pack** — `jurisdiction`, `active_code_ids(json)`.
- Index: tax_code_version(tax_code_id, effective_from).

### fx
- **rate_record** — `currency_pair`, `rate`, `effective_date`, `source`. Append-only (immutable once referenced).
- **revaluation_run** — `period_ref`, `figures(json)`, `reversal_of_run_id(null)`.
- Index: rate_record(currency_pair, effective_date).

### period
- **accounting_period** — `period_ref`, `state`, `vat_lock_status`. Unique(entity_id, period_ref).
- **period_transition** — `period_id(fk)`, `from_state`, `to_state`, `reason_code`, `approver_id(uuid,null)`, `at`.
- **fiscal_calendar** — `entity_id`, `year_start`, `period_defs(json)`.

### identity
- **entity** — `legal_name`, `functional_currency`, `fiscal_config`, `approval_policy(json)`, `settings(json)`.
- **app_user** — `name`, `email`, `status`, `mfa_config`, `entity_grants(json)`, `role_ids(json)`, `delegation(null)`. (Credential hash stored in an **infra-owned** auth store, not the domain schema.) Unique(email).
- **role** — `name`, `is_system`, `base_role_id(null)`.
- **role_permission** — `role_id(fk)`, `capability`.

### reconciliation
- **bank_reconciliation** — `bank_account_id(uuid)`, `opening_balance`, `closing_balance`, `import_source`, `column_mapping(json)`, `status`.
- **statement_line** — `reconciliation_id(fk)`, `date`, `narration`, `amount`, `match_status`, `matched_txn_id(uuid,null)`.
- Index: statement_line(reconciliation_id), duplicate-detection index (bank_account_id, date, amount).

### migration
- **staging_batch** — `source_system`, `migration_identifier`, `status`, `validation_result(json)`, `control_totals(json)`.
- **staged_record** — `batch_id(fk)`, `record_type`, `source_key`, `payload(json)`, `migration_identifier`. **Idempotent upsert** on (migration_identifier, source_key).

### numbering (shared kernel)
- **sequence** — `series_prefix`, `scope(entity_id, fiscal_year)`, `current_value`, `gapless`, `reset_policy`. **Serialized atomic increment** on draw (row lock / atomic update). Unique(series_prefix, scope).
- **voided_number** — `series_prefix`, `scope`, `value` (used-and-voided, never reused).

### reporting (read models)
- Projection tables / materialized views: `trial_balance_mv`, `gl_detail_mv`, `pnl_mv`, `balance_sheet_mv`, `ageing_mv`, `tax_summary_mv`, `cash_view_mv`, maintained from domain events; fully rebuildable from the write side.

### cross-cutting
- **audit_log** (append-only, immutable), **outbox** (per context).

---

## 2. Concurrency & Locking at the DB

- **Optimistic:** `version` column on mutable aggregates; `Save` = conditional update `WHERE id=? AND version=?`; 0 rows ⇒ `ConcurrencyConflict`. Covers Allocation, Invoice, Bill, etc.
- **No-over-allocation:** `ApplySettlement` updates `invoice.open_balance` with `WHERE id=? AND version=?`; the settlement UoW retries on conflict.
- **Sequence:** the **only** serialized point — `DrawNext` performs an atomic increment (row lock or atomic `UPDATE ... RETURNING`), guaranteeing gapless + unique under concurrency.
- **Posted immutability:** enforced by column-level update restrictions/triggers on posted rows (only whitelisted fields mutable).

---

## 3. Multi-Entity & Extraction Readiness

- `entity_id` on every business table; all indexes lead with `entity_id`.
- **Today (MVP):** one database. Accounting-core schemas (ledger/receivables/payables/settlement) co-reside for the strong UoW. Reporting/Identity/Tax/Migration are separate schemas.
- **Later (SaaS):** extractable contexts move to their own databases with **no schema change** (no cross-context FKs to sever); the accounting core stays together (Context Map do-not-split).

---

## 4. Auditability & Reproducibility at the DB

- Posted journals/allocations/documents append-only; corrections are new rows linked by UUID.
- `tax_snapshot(json)` and `rate_record_id` persisted on lines/allocations → reports reproduce historical figures after rules/rates change (ADR-006/007).
- `audit_log` immutable; `outbox` gives a replayable event history; projections rebuildable from the write side.

---

## 5. Performance Notes

- Volumes are small (~36k txns/yr, <10 GB/3yr) — indexing suffices; no partitioning needed for MVP.
- Hot paths indexed: open-balance-by-document (single-row), ageing (partial index on unpaid), account balance (projection), reconciliation duplicate detection.
- Reports read projections, never sum raw lines at request time at scale.

---

## 6. Validation

- **Schema conforms to the domain:** ✔ aggregates → schemas; children FK'd only to their root; UUID identity; no domain change.
- **AP-001 at the data layer:** ✔ schema-per-context, no cross-context FKs, cross-context access via services only.
- **Consistency model:** ✔ accounting core co-resides for the strong UoW; outbox for the eventual tier.
- **Immutability/audit/reproducibility:** ✔ append-only posted rows, snapshots persisted, immutable audit log.
- **Concurrency:** ✔ optimistic everywhere; Sequence the lone serialized point.
- **No domain weakness discovered; no business rule or invariant altered.**

**Database Design complete.**
