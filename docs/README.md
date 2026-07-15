# HiveFinance Documentation Index

This directory is the canonical documentation set for HiveFin. It separates current frozen artifacts from archived history so implementation agents can read the right sources in the right order without reinterpreting superseded drafts.

## Source Of Truth

The current implementation sources of truth are:

1. `HiveFin_SRS_v3.0.md` — authoritative requirements baseline.
2. `HiveFin_Decision_Log.md` — authoritative ADR register for ADR-001 through ADR-009.
3. `HiveFin_Architecture_Principles.md` — architecture principles register; AP-001 governs context ownership.
4. `HiveFinance-Engineering-Constitution-v1.0.md` — engineering governance and contribution rules, aligned to the approved architecture artifacts.
5. The approved architecture artifact chain listed below.

If these documents appear to conflict, stop implementation work and report the contradiction. Do not resolve a business rule, ADR, aggregate, API contract, or database design in code.

## Reading Order

Read the documents in this order before implementation:

1. `HiveFin_SRS_v3.0.md` — product scope, accounting rules, business rules, and open decisions.
2. `HiveFin_Decision_Log.md` — ADR-001 through ADR-009 and their rationale.
3. `HiveFin_Architecture_Principles.md` — AP-001 context ownership rule.
4. `HiveFinance-Engineering-Constitution-v1.0.md` — engineering rules, review rules, Definition of Done.
5. `HiveFin_Domain_Model.md` — bounded contexts, ubiquitous language, aggregate list.
6. `HiveFin_Context_Interaction_Matrix.md` — context ownership, dependencies, and AP-001 validation.
7. `HiveFin_Context_Map.md` — definitive context map and deployment boundaries.
8. `HiveFin_Aggregate_Design.md` — aggregate boundaries and approved Unit of Work model.
9. `HiveFin_Domain_Events.md` — event catalog and choreography.
10. `HiveFin_Repository_Contracts.md` — repository and Unit of Work contracts.
11. `HiveFin_Database_Design.md` — schema ownership, constraints, and persistence design.
12. `HiveFin_API_Contracts.md` — public frontend-facing API contract.
13. `HiveFin_Implementation_Roadmap.md` — implementation phases and sequencing.
14. `TASK-M0-Walking-Skeleton.md` — M0 execution task, only after the documents above are understood.

## Implementation Order

Implementation follows the roadmap, not the order files happen to appear in this directory:

1. Platform foundations: module boundaries, Unit of Work, outbox, audit, entity isolation.
2. Foundation contexts: Identity, Period, Numbering, Tax, Currency & FX.
3. Ledger & Posting.
4. Receivables and Payables.
5. Settlement & Cash Application.
6. Reporting & Cash View.
7. Reconciliation.
8. Migration.
9. Frontend flows against the frozen API Contracts.

M0 is a walking skeleton only. It must not invent product behavior or broaden the domain beyond the frozen artifacts.

## Frozen Documents

These documents are current and frozen unless explicitly changed through approved governance:

- `HiveFin_SRS_v3.0.md`
- `HiveFin_Decision_Log.md`
- `HiveFin_Architecture_Principles.md`
- `HiveFinance-Engineering-Constitution-v1.0.md`
- `HiveFin_Domain_Model.md`
- `HiveFin_Context_Interaction_Matrix.md`
- `HiveFin_Context_Map.md`
- `HiveFin_Aggregate_Design.md`
- `HiveFin_Domain_Events.md`
- `HiveFin_Repository_Contracts.md`
- `HiveFin_Database_Design.md`
- `HiveFin_API_Contracts.md`
- `HiveFin_Implementation_Roadmap.md`
- `TASK-M0-Walking-Skeleton.md`

## Archived Documents

These documents are historical context only. They are not implementation sources of truth when they conflict with frozen documents:

- `HiveFin_SRS_Critical_Review_v1.md`
- `HiveFin_SRS_Amendment_001.md`
- `HiveFin_SRS_Amendment_002.md`
- `HiveFin_SRS_Amendment_003.md`
- `HiveFin_SRS_Amendment_004.md`
- `HiveFin_SRS_Amendment_005.md`
- `HiveFin_SRS_Amendment_006.md`
- `HiveFin_SRS_Amendment_007.md`

## Governance Notes

- `HiveFin_Decision_Log.md` is the authoritative ADR register; individual ADR files are not required unless governance later chooses to split them.
- `Governance_Cleanup_Report.md` records documentation-maintenance changes and is not an implementation source of truth.
- ADR-010, Multi-Entity Consolidation & Intercompany, is deferred and not part of core MVP implementation.
- Do not modify business rules, ADRs, architecture decisions, aggregate definitions, API contracts, or database design during ordinary implementation.
- Documentation maintenance may clarify indexing, stale references, duplicate files, or process artifacts, but must not change technical decisions.
