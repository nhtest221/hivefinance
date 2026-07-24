# Data Migration Readiness Assessment

## Bottom line

**HiveFinance cannot yet import historical/opening-balance data from any source
system.** The Migration bounded context (M7 in the roadmap) — the only path the
architecture defines for bringing in existing accounting data (e.g. from Xero) — has
not been implemented. A repository-wide search of `backend/app/` found no code for it:
no `StagingBatch` model, no anticorruption-layer import service, no migration
controller or routes.

This assessment exists so that "is HiveFinance ready for a real customer's historical
data" has an honest, checkable answer instead of an assumed one, per `CLAUDE.md`'s
instruction not to invent or assume a missing rule.

## What the architecture already specifies for Migration (not yet built)

From `docs/HiveFin_Context_Map.md` (frozen) and `docs/HiveFin_Implementation_Roadmap.md`
(frozen), Migration is context #10, described as:

- "Idempotent staged open-item conversion" with a `StagingBatch` aggregate and a
  `MigrationIdentifier` map (source-system-ID → HiveFinance-ID) so re-running an import
  is safe.
- An anticorruption layer (ACL) specifically for Xero ("ACL(Xero)" in the context
  diagram) that translates the source system's shape into HiveFinance's canonical
  import model before anything is written — no external schema is meant to leak inward.
- Writes only through each target context's own application services (Ledger,
  Receivables, Payables, Settlement) — Migration does not get special direct-write
  access to any other context's tables, same AP-001 discipline as every other context.
- A required **dry-run** mode and a required **parallel run vs Xero** with an explicit
  go-live acceptance sign-off (ADR-008, referenced in the roadmap's risk table:
  *"Migration data corruption — dry-run + idempotency + parallel run + sign-off
  (ADR-008)"*) before any migrated data is trusted as authoritative.
- Roadmap milestone **M7 "Migration + Parallel Run"** is explicitly sequenced *after*
  M2-M5 (it needs every target context's import services to already exist) and is
  marked effort "L" (large) in the roadmap's own sizing table.

None of this has been implemented. M0 through M6 are built (foundations, Ledger/
valuation, Receivables/Payables documents, Settlement, Period Close, Notes, Reporting,
Reconciliation); M7 Migration is not.

## What this means for a real onboarding

- A new HiveFinance customer with existing accounting history (in Xero or any other
  system) **cannot currently bring that history in through any supported path.** The
  only way data enters HiveFinance today is through the M0-M6 application services
  directly (creating customers/vendors, drafting and issuing invoices/bills, recording
  settlements, etc.) — one document at a time, through the normal user-facing commands,
  not as a bulk historical import.
- There is no opening-balance/opening-trial-balance import mechanism. A finance team
  wanting to start using HiveFinance mid-year with existing open receivables/payables
  would need to either (a) wait for M7, or (b) manually re-enter every still-open
  document through the normal Receivables/Payables UI — both are real operational
  constraints to communicate before any commitment to a go-live date that assumes
  historical data will be present.
- The "parallel run vs Xero" acceptance gate that the roadmap requires before trusting
  migrated data has, by definition, not happened either, since there is nothing to
  parallel-run yet.

## Recommendation

Do not commit to onboarding any customer that requires historical/opening-balance data
import until M7 Migration is scoped, built, and has passed its own dry-run + parallel-
run + sign-off gate per ADR-008. If a near-term onboarding genuinely cannot wait for
M7, the fallback is manual re-entry of only the still-open documents (invoices, bills,
etc.) through the existing M0-M6 UI — that is a real, working path today, just not a
bulk migration.
