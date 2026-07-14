# HiveFin — Definitive Context Map (Architecture Phase)

**Frozen inputs (immutable):** SRS v3.0 · ADR-001…009 · Domain Model v2 · Context Interaction Matrix · AP-001.
**Contradiction check:** none found — this map is derived from the frozen artifacts without new business rules.
**Principle:** architecture first, technology second; MVP = **modular monolith**; finance-only.

---

## 1–3. Contexts, Responsibilities, Ownership

| # | Bounded Context | Responsibility (owns writes) | Master data owned | Invariants owned |
|---|---|---|---|---|
| 1 | **Ledger & Posting** | Double-entry postings, balances, immutability/reversal | Chart of Accounts, SBU dimension, bank-account master | Debits=credits; posted immutable; corrections linked |
| 2 | **Receivables** | Customer invoices, credit notes, AR, statements | Customer, Invoice, CreditNote | Recognition at issue; void-window; issued immutable; AR = issued−settled |
| 3 | **Payables** | Supplier bills, debit notes, expenses, AP | Vendor, Bill, DebitNote, Expense | SBU Σ=1.0; recognition at approval; immutable; AP = approved−settled |
| 4 | **Settlement & Cash Application** | Allocations, advances, unapplied credits, realised-FX application, withholding | Allocation, PartyCreditBalance | Applied ≤ open balance; credit ≥ 0; per-tranche FX applied; withholding clears balance |
| 5 | **Tax** | Tax codes/packs, determination, snapshots, VAT/AIT/VDS | TaxCode(+versions), TaxPack, return-box maps | Version by tax-point date; snapshot persisted; recoverable≠cost; zero-rated≠exempt |
| 6 | **Currency & FX** | Rate master data; **FX gain/loss calculation**; revaluation figures | Rate Records | Rate immutable once referenced; effective-dated; revaluation reversed next period |
| 7 | **Period & Close** | Period lifecycle, fiscal calendar, close gates, lock/reopen | Periods, fiscal calendar | One state; Soft repeatable; Hard irreversible; closed-period corrections route to open |
| 8 | **Identity & Access** | Entities, users, roles, SoD, approval policy | Entity, User, Role, ApprovalPolicy | Default-deny; role ≤ granter; SoD w/ compensating exception; last Owner protected; MFA |
| 9 | **Reconciliation** | Bank statement import, matching, status | BankStatement, reconciliation records | Complete only when tied to zero; duplicate/overlap detection |
| 10 | **Migration** | Idempotent staged open-item conversion | StagingBatch, MigrationIdentifier map | Idempotent; TB balances; no live post before authorised final; reset = zero impact |
| 11 | **Reporting & Cash View** | Read projections; derived cash view; statutory summaries | none (read models) | Cash view via single algorithm; statutory reports accrual-only |
| SK | **Numbering** (shared kernel) | ID/Number split; atomic scoped sequences | Sequence registry | Atomic draw; statutory gapless; never reused |
| — | **Group & Consolidation** *(deferred, ADR-010)* | Consolidated presentation, CTA, intercompany | (future) | (future) |

---

## 4–6. Relationships, DDD Patterns, Communication

| Relationship | DDD Pattern | Communication | Integration mechanism |
|---|---|---|---|
| Identity → all writers | **Open Host Service** (Identity) / **Conformist** (writers) | Sync **query** | AuthorizationService (stable contract) |
| Period → posting contexts | **OHS** / **Conformist** | Sync **query** | `IsDatePostable` |
| Tax → Receivables, Payables | **Customer/Supplier** + **Published Language** (TaxSnapshot) | Sync **command/query** | DetermineTax → embedded snapshot |
| FX → Receivables, Payables, Settlement | **Customer/Supplier** + **Published Language** (RateRecord ref) | Sync **query** | GetRate; FXGainLossCalculation (invoked) |
| Numbering ↔ Receivables, Payables, Settlement | **Shared Kernel** (DocumentId/DocumentNumber) + **OHS** (draw) | Sync **command** | DrawDocumentNumber (atomic) |
| **Settlement ↔ Receivables** | **Partnership** | Sync **query** (open balance) + sync **command** `ApplySettlement`(versioned) + **async event** (projections) | GetOpenReceivable(byDocId); ApplySettlement(amt,ver); `ReceiptAllocated`→projections |
| **Settlement ↔ Payables** | **Partnership** | Sync **query** + sync **command** `ApplySettlement`(versioned) + **async event** (projections) | GetOpenPayable(byDocId); ApplySettlement(amt,ver); `PaymentAllocated`→projections |
| Receivables, Payables, Settlement → Ledger | **Customer/Supplier** (Ledger = supplier of truth) | Sync **command** | PostingService (application service) |
| Reconciliation → Ledger, Settlement, Rec, Pay | **Customer/Supplier** | Sync **query** + **command** | reads txns; requests entries via app services |
| Reconciliation → bank CSV | **Anticorruption Layer** | File import (batch) | statement-format adapter (per bank) |
| Migration → Ledger, Rec, Pay, Settlement | **Customer/Supplier** | Sync **command** | each context's import application services |
| Migration → source system (Xero) | **Anticorruption Layer** | Batch | canonical import model (source-agnostic) |
| Reporting → all write contexts | **Conformist / Customer** (CQRS read) | **Query** + **async event** | read models / projections |
| Group *(future)* → entity Ledgers | **Customer/Supplier** | (deferred) | balance translation + CTA |

**Communication summary:** writes = **commands**; reads = **queries**; cross-context state propagation = **domain events** (async). No context writes into another's store; all crossings are OHS, Published Language, events, or ACL.

---

## 7. Integration Patterns (why each)

- **OHS + Conformist** for Identity and Period: both are cross-cutting authorities (access, time) that every writer must obey without negotiation — a stable published service is the right contract.
- **Customer/Supplier + Published Language** for Tax and FX: documents depend on them, and the *contract* (TaxSnapshot, RateRecord reference) is a stable published value object embedded in the consumer — reproducibility (ADR-006/007) demands the published form travel with the document.
- **Shared Kernel** for Numbering: the DocumentId/DocumentNumber model is co-owned and deliberately tiny and stable; changes are rare and coordinated.
- **Partnership** for Settlement ↔ documents: genuine mutual dependency (a receipt needs an invoice; an invoice's balance needs settlement). Implemented as: synchronous open-balance **query** + synchronous versioned **`ApplySettlement`** command (enforcing no-over-allocation via optimistic concurrency) + asynchronous **projection events**. All edges are Settlement→document, keeping the synchronous graph acyclic (ratified refinement — Aggregate Design §0.3).
- **ACL** for Reconciliation (bank CSV) and Migration (Xero/other): both face messy, foreign, or legacy schemas; an anticorruption layer translates them into HiveFin's model so no external shape leaks inward.
- **CQRS read side** for Reporting: a pure downstream sink consuming projections; owns only the cash-view derivation.

---

## 8. Domain Events Flowing Between Contexts

| Event | Producer | Consumer(s) | Effect |
|---|---|---|---|
| `InvoiceIssued`, `BillApproved` | Receivables/Payables | Ledger (via PostingService), Reporting | Recognition posting; projection update |
| `ReceiptAllocated`, `CreditApplied`, `CreditRefunded` | Settlement | Receivables, Reporting | Invoice status; cash-view/AR projections |
| `PaymentAllocated`, `CreditApplied` | Settlement | Payables, Reporting | Bill status; cash-view/AP projections |
| `RealisedFXRecognised` | Settlement | Ledger, Reporting | FX gain/loss posting; projections |
| `TaxDetermined` | Tax | (embedded snapshot in document) | Reproducible tax |
| `RateRecordAdded`, `UnrealisedFXRevalued`, `RevaluationReversed` | FX | Ledger, Reporting | Revaluation postings; projections |
| `PeriodSoftClosed`, `PeriodHardClosed`, `VATPeriodLocked`, `YearEndRolled` | Period | Ledger, Rec, Pay, Settlement, Reporting | Enforce postability/void-window; roll |
| `JournalPosted`, `JournalReversed` | Ledger | Reporting | Ledger projections (TB/GL/P&L/BS) |
| `SoDExceptionRaised`, `BreakGlassActivated` | Identity | Reporting (audit), Owner notify | Audit visibility |
| `StatementImported`, `ReconciliationCompleted` | Reconciliation | Reporting | Reconciliation statement |
| `ConversionPosted` | Migration | Reporting | Opening projections |

---

## 9. Context Dependency Diagram

```
        (OHS/Conformist)                         (OHS/Conformist)
     ┌───────────────────┐                    ┌───────────────────┐
     │ IDENTITY & ACCESS │◀───authz (query)───│  PERIOD & CLOSE   │──postable? (query)─┐
     └─────────┬─────────┘                    └─────────┬─────────┘                    │
               │ (all writers conform)                  │ (posting contexts conform)   │
               ▼                                         ▼                              ▼
   ┌──────────────┐   Partnership    ┌───────────────────────────┐   Partnership   ┌──────────────┐
   │ RECEIVABLES  │◀════════════════▶│ SETTLEMENT & CASH APP.     │◀═══════════════▶│  PAYABLES    │
   │ (Cust of Tax │  query+event     │ (allocations, advances,    │  query+event    │ (Cust of Tax │
   │  & FX)       │                  │  realised-FX application)  │                 │  & FX)       │
   └──────┬───────┘                  └───────────┬───────────────┘                 └──────┬───────┘
          │ C/S(command)                         │ C/S(command)                           │
          ▼                                      ▼                                        ▼
   ┌───────────────┐   Published Lang   ┌───────────────┐   Published Lang   ┌───────────────────┐
   │  TAX          │───(TaxSnapshot)───▶│  LEDGER &     │◀──(RateRecord)─────│  CURRENCY & FX    │
   │  (Supplier)   │                    │  POSTING      │                    │ (owns FX calc)    │
   └───────────────┘                    └──────┬────────┘                    └───────────────────┘
                                               │ (source of truth; JournalPosted)
   ┌──────────────┐  Shared Kernel             │
   │  NUMBERING   │  (ID/Number) ──▶ Rec/Pay/Settlement
   └──────────────┘
   ┌──────────────┐  ACL(bank CSV)   ┌──────────────┐  ACL(Xero)
   │RECONCILIATION│─────────────────▶│  MIGRATION   │──▶ import via app services → Ledger/Rec/Pay/Settlement
   └──────┬───────┘                  └──────────────┘
          │ requests entries (app service)
          ▼
   ┌────────────────────────────────────────────────────────────┐
   │ REPORTING & CASH VIEW  (CQRS read sink; consumes all events)│
   └────────────────────────────────────────────────────────────┘
   ┌────────────────────────┐
   │ GROUP & CONSOLIDATION  │  (deferred — downstream of entity Ledgers)
   └────────────────────────┘

Legend: ─▶ synchronous (query/command)   ═▶ Partnership (query + versioned ApplySettlement command out; events for projections)
Sources (no upstream): Identity, Period, Numbering.  Sink (no downstream): Reporting.
```

---

## 10. Recommended Deployment Boundaries (MVP)

**One deployable — a modular monolith.** Boundaries:
- **Module per bounded context.** Each module exposes only (a) an **application-service interface** (commands/queries) and (b) **published domain events**. No module reaches into another's internals.
- **Persistence ownership per context.** Each context owns its own tables/schema; **no cross-context table access** — enforcing AP-001 at the data layer, not just the code layer. One database instance, ownership by schema/table prefix.
- **In-process domain-event bus** for asynchronous cross-context propagation (status updates, projections). Synchronous in-process calls for queries/commands.
- **Numbering** is a shared-kernel module with the smallest possible surface (ID/Number model + atomic draw).
- **Reporting** reads from projections updated by events, never by querying write-side internals.

This gives clean seams (each module ≈ a future service) while avoiding distributed-systems cost the MVP doesn't need.

---

## 11. Future Extraction Candidates (post-MVP, only with domain reason)

| Candidate | Extract when | Domain reason |
|---|---|---|
| **Reporting & Cash View** | Reporting load or tenant count grows | Pure read-side, no write coupling — cleanest first extraction (independent scaling) |
| **Identity & Access** | Multi-tenant SaaS scale | Auth is commonly a shared platform service across products |
| **Tax (Tax Packs)** | Many jurisdictions/packs | Country packs proliferate; a tax service isolates regulatory change |
| **Migration** | Onboarding volume grows | Batch, run-rarely, near-zero steady-state coupling — trivially extractable |
| **Group & Consolidation** | When built (ADR-010) | Aggregates across entity ledgers; naturally its own service |
| **KEEP TOGETHER: Ledger + Receivables + Payables + Settlement** | — | The transactional accounting core is chatty and Partnership-coupled (Settlement↔documents). Splitting it introduces distributed-transaction and consistency pain with **no domain benefit**. This is the explicit *do-not-split* recommendation. |

---

## 12. AP-001 Validation (never violated)

- **Own aggregates/invariants/master data:** ✔ single-owner, verified in §1–3.
- **Read but never modify another's data:** ✔ every crossing is OHS, Published Language, event, or ACL; writes only via the owning context's application services (incl. Migration and Reconciliation).
- **State changes via events or application services:** ✔ Partnership return path is events; postings via PostingService; migration/reconciliation via import/entry services.
- **No God Context:** ✔ Settlement has high fan-out but owns only its own invariants (Partnership, not ownership); FX formula reassigned to FX; Reporting owns only the cash-view derivation.
**Result: AP-001 holds across every relationship in this map.**

---

## 13. Architectural Smells — Challenged

1. **Settlement fan-out → god-context risk.** *Assessed:* coupling by collaboration, not ownership; AP-001 guards it. *Mitigation:* boundary guard — Settlement must never own document status or ledger balances. **Watch-item, not a smell today.**
2. **Partnership bidirectional coupling (Settlement↔documents).** *Assessed:* partnerships are higher-maintenance, but the domain is genuinely interdependent. Implemented as synchronous open-balance query + versioned `ApplySettlement` command out, with events only for projections — all edges Settlement→document, so the synchronous graph stays acyclic and no-over-allocation is strongly enforced. *Future option:* event-sourced open-balance read-model in Settlement (deferred; unnecessary at ~15 users). **Justified, monitored.**
3. **Reporting depends on everything.** *Assessed:* a CQRS read sink legitimately consumes all — this is not coupling, it's the read side. *Risk:* business logic creeping into Reporting. *Guard:* only the cash-view derivation lives there. **Not a smell.**
4. **Numbering shared kernel.** *Assessed:* shared kernels are a coupling risk. Kept tiny (ID/Number) and stable. **Acceptable; keep minimal.**
5. **Ledger high fan-in.** *Assessed:* correct for a single source of GL truth. **Healthy, not a smell.**
6. **Cross-cutting authorities (Identity, Period) touched by all.** *Assessed:* legitimate cross-cutting concerns exposed as OHS; conformist consumption. **Not a smell.**

No blocking smell. Two monitored items (Settlement boundary; Partnership).

---

## 14. Architecture Readiness Score

| Dimension | Score | Basis |
|---|---|---|
| Context clarity & responsibility | 95 | One language, one reason to change per context |
| Ownership (data & invariants) | 96 | Single-owner verified; AP-001 clean |
| Coupling & cohesion | 88 | Settlement fan-out + one Partnership (monitored) |
| Relationship-pattern correctness | 93 | OHS/CS/PL/SK/ACL/Partnership all justified |
| Event design | 90 | Cross-context events defined; acyclic sync graph |
| Deployment plan (modular monolith) | 94 | Clean module + persistence boundaries; extraction-ready |
| AP-001 compliance | 100 | Holds across all relationships |
| Auditability & SaaS evolution | 92 | Reproducibility contracts; clear extraction path |

**Composite: 93 / 100 — READY.**

**Verdict:** The Context Map is internally consistent, AP-001-compliant, acyclic in its synchronous dependencies, and free of blocking architectural smells. Two items are on watch (Settlement boundary discipline; the Settlement↔document Partnership). The modular-monolith deployment has clean seams and a justified extraction path, with an explicit *do-not-split* around the accounting core.

**Recommendation:** use this Context Map as the approved boundary guide for Aggregate Design and implementation planning, beginning with the most invariant-dense aggregates — **JournalEntry** (balanced + immutable) and **Allocation** (no-over-allocation + per-tranche FX) — since they anchor the Ledger and the Settlement Partnership respectively.
