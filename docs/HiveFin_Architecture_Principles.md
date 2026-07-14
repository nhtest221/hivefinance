# HiveFin — Architecture Principles Register

Cross-cutting principles that govern the architecture. Distinct from ADRs (point decisions): principles constrain all decisions.

---

## AP-001 — Context Ownership

**Status:** ✅ Accepted (13 July 2026)

### Principle
A bounded context **may own**: its own aggregates, its own invariants, its own master data.
A bounded context **may read** another context's data but **must never modify it directly.**
State changes across bounded contexts occur **only** through published **domain events** or **explicitly defined application services**.
**No** bounded context may become a "God Context" by accumulating business rules that belong elsewhere.

### Evaluation of the Context Interaction Matrix against AP-001

| Clause | Result | Notes |
|---|---|---|
| Own aggregates / invariants / master data | ✔ Pass | Single-owner master data and single-owner invariants already verified; no shared ownership. |
| Read but never modify another's data | ✔ Pass (with 2 clarifications) | Settlement reads document balances (read-only) and effects status changes via events, not writes. Migration and Reconciliation must effect cross-context changes only through target application services — now stated explicitly (clarifications 1 & 2). |
| Cross-context state changes via events or application services | ✔ Pass | Settlement↔document return path is asynchronous events; postings go through PostingService; migration/reconciliation go through application services. |
| No God Context | ✔ Pass (1 refinement) | Settlement has high fan-out but owns only its own invariants — coupling by collaboration, not ownership. The one borderline rule (FX gain/loss *calculation*) is reassigned to FX (refinement 3) so Settlement does not accumulate FX rules. |

**Result: no hard violations.** Three compliance-preserving items adopted before acceptance:

1. **Migration writes via application services only.** Migration must import opening items by invoking each target context's explicit import/application services (e.g., an "ImportOpeningInvoice" command on Receivables, PostJournal on Ledger), never by writing into another context's storage. (Bounded numbering + conversion-period rules from ADR-008/009 already apply.)
2. **Reconciliation writes via application services only.** Bank-only lines that require a ledger/settlement entry are created by *requesting* the owning context through its application service, never by direct write.
3. **FX gain/loss calculation belongs to Currency & FX.** The *calculation* of realised (and unrealised) FX is FX-owned domain logic; Settlement (realised, at settlement) and Period/FX (unrealised, at Soft Close) **invoke** it. Settlement owns the *trigger and per-tranche application*, not the FX formula.

### Consequence
AP-001 is the standing guardrail for the Settlement fan-out watch-item and for all future context additions (e.g., the deferred Group & Consolidation context). Any proposal that would let one context mutate another's data or absorb another's rules is rejected under AP-001.
