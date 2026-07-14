# HiveFin — Domain Model (DDD Phase 1) — v2

**Basis:** SRS v3.0 + ADR-001…009 (frozen). No new business rules; contradictions flagged in §13.
**Scope constraint (this revision):** HiveFin is a **finance-only system**, used exclusively by Finance & Accounts. **No CRM, quotations, opportunities, purchase orders, inventory, procurement/operational workflows, or non-finance approval chains.** (Maker-checker from ADR-005 remains — it is finance-internal SoD, not an ERP workflow.)
**Change in this revision:** "Sales"→**Receivables** and "Purchase"→**Payables** (document/balance management only); **Allocation promoted to its own bounded context — Settlement & Cash Application** (resolves the prior §13.3 open question).

---

## 1. Ubiquitous Language (context-relative)

| Term | Meaning | Owning context |
|---|---|---|
| **Entity (Company)** | A legal company (Bangladesh / Canada); isolation + tenant boundary | Identity & Access |
| **Posting** | A balanced debit/credit set committed to the ledger | Ledger & Posting |
| **Recognition** | Accrual event posting revenue/expense + tax | Ledger & Posting |
| **Document** | Invoice, Bill, Credit/Debit Note, Journal — a business artefact that posts | Receivables / Payables / Ledger |
| **Settlement** | Applying a cash movement to documents and/or advances | Settlement & Cash Application |
| **Allocation** | A settlement record: receipt or payment linked to documents/advances | Settlement & Cash Application |
| **Advance / Unapplied Credit** | Settlement value held for a party, not yet applied (2060 / 1075) | Settlement & Cash Application |
| **Realised FX** | Gain/loss recognised at settlement, per tranche | Settlement & Cash Application |
| **Reversal / Void** | Correction by linked counter-posting; void = safe-window reversal | Receivables/Payables + Ledger |
| **Tax Code / Tax Snapshot** | Versioned tax rule / persisted applied result | Tax |
| **Rate Record** | Versioned exchange-rate master record | Currency & FX |
| **Period** | Accounting period in a lifecycle state | Period & Close |
| **Document ID vs Number** | Immutable UUID identity vs jurisdiction-varying business key | Numbering (shared kernel) |
| **SoD Exception** | Justified logged override of a duty conflict | Identity & Access |
| **Open Item / Conversion** | Migrated open invoice/bill / opening-balance import | Migration |

*(Full glossary in §12.)*

---

## 2. Bounded Context Candidates

1. **Ledger & Posting** — double-entry core: accounts, journals, balances, immutability.
2. **Receivables** — customer invoices, credit notes, customer master, AR balances, statements. *(No receipts — those are Settlement.)*
3. **Payables** — supplier bills, debit notes, expenses, supplier master, AP balances. *(No payments — those are Settlement.)*
4. **Settlement & Cash Application** *(new)* — allocations (receipt/payment), partial & multi-tranche settlement, customer & supplier advances, unapplied credits, realised FX at settlement.
5. **Tax** — tax codes, packs, determination, snapshots, VAT/AIT/VDS.
6. **Currency & FX** — rate records, revaluation (unrealised) figures.
7. **Period & Close** — period lifecycle, close gates, locking.
8. **Identity & Access** — entities, users, roles, SoD, approvals.
9. **Reconciliation** — bank statement import and matching.
10. **Migration** — idempotent, staged open-item conversion.
11. **Reporting & Cash View** — read-side projections (TB, GL, P&L, BS, ageing, cash dashboards).
12. **Group & Consolidation** — *deferred (ADR-010)*; stub only.

> **Naming rationale (finance-only):** Receivables/Payables manage *documents and balances*, never sales/procurement activity. Numbering + the ID/Number split (ADR-009) is a **shared kernel**, not a context.

---

## 3. Context Responsibilities

| Context | Owns (writes) | Must not own |
|---|---|---|
| **Ledger & Posting** | Chart of Accounts, JournalEntry, postings, balances, immutability/reversal integrity | Document lifecycle, tax rules, FX rates, settlement application |
| **Receivables** | Invoice, CreditNote, Customer, AR balance, customer statement, invoice lifecycle & void-window | Cash application (delegates to Settlement), GL truth, tax/FX rates |
| **Payables** | Bill, DebitNote, Expense, Vendor, AP balance, AIT/VDS capture on bills, SBU split | Cash application, GL truth, tax/FX rates |
| **Settlement & Cash Application** | Allocation, advances, unapplied credits, realised FX at settlement, withholding-on-receipt | Document creation, GL immutability rules, tax determination |
| **Tax** | TaxCode(+versions), TaxPack, determination, TaxSnapshot, VAT/AIT/VDS rules, return-box mapping | Posting, document/settlement lifecycle |
| **Currency & FX** | RateRecord (master data), unrealised revaluation figures | Posting, realised-FX application (that's Settlement, using FX calc) |
| **Period & Close** | Period state machine, close gates, lock/reopen | Business document content |
| **Identity & Access** | Entity, User, Role, permissions, SoD, ApprovalPolicy, delegation, break-glass | Financial data |
| **Reconciliation** | BankStatement, StatementLine, match suggestions, status | Authoritative postings (requests them) |
| **Migration** | StagingBatch, dry-run, validation, MigrationIdentifier, Conversion Journal request | Live ledger mutation outside the authorised final post |
| **Reporting & Cash View** | Read projections incl. derived cash view | Any domain write |
| **Group & Consolidation** *(future)* | Consolidated presentation, CTA, intercompany elimination | Entity-level ledgers |

---

## 4. Aggregate Roots

| Aggregate Root | Context | Key invariants |
|---|---|---|
| **Account** | Ledger | Type immutable once postings exist; delete only at zero history |
| **JournalEntry** | Ledger | Debits = credits (functional ccy, post-rounding); immutable once Posted; reversal linked |
| **Invoice** | Receivables | Recognition at issue; immutable once Posted; void only if all 4 safe-window conditions (BR-013); has Document ID + Number |
| **CreditNote** | Receivables | VAT adjustment in note period (BR-014); reason code mandatory; disposition realised via Settlement |
| **Customer** | Receivables | Lean registry; type drives VAT default |
| **Bill** | Payables | SBU weights Σ=1.0000; recognition at approval; immutable once Posted |
| **DebitNote** | Payables | Payables-side correction mirror |
| **Expense** | Payables | Cash-settled or accrued; SBU Σ=1.0000; immutable once Posted |
| **Vendor** | Payables | Lean registry; VAT/TIN for AIT |
| **Allocation** | Settlement | Applied amount ≤ target open balance; per-tranche realised FX vs invoice-date rate (BR-042); withholding captured (BR-006) |
| **PartyCreditBalance** | Settlement | Unapplied advance/credit per party+entity (2060/1075); drawn down by later Allocations; never negative |
| **AccountingPeriod** | Period | One state (BR-019); Soft Close repeatable; Hard Close irreversible via normal ops |
| **TaxCode** | Tax | Effective-dated versions; transaction binds version on tax-point date |
| **RateRecord** | FX | Immutable once referenced |
| **User / Role / Entity** | Identity | Custom role ≤ granter; last Owner protected; entity = isolation |
| **BankReconciliation** | Reconciliation | Complete only when all lines matched & statement ties to zero |
| **StagingBatch** | Migration | Idempotent by MigrationIdentifier; no live post until authorised final migration |

**Not roots (inside a root):** invoice/bill/journal **lines**, `SBUAllocation`, `TaxSnapshot`, `AllocationLink`, `RolePermission`, `TaxCodeVersion`, period transition log entries.

---

## 5. Entities (non-root)

`JournalLine` · `InvoiceLine` · `BillLine` · `AllocationLink` (document ref + applied amount + realised FX) · `StatementLine` · `TaxCodeVersion` · `RolePermission` · `PeriodTransition`.

---

## 6. Value Objects (immutable, equality by value)

`Money` · `Currency` · `ExchangeRateReference` · `TaxSnapshot` · `DocumentNumber` · `DocumentId` · `SBUAllocation` (Σ=1.0000) · `PeriodRef` · `Address` · `ContactInfo` · `BankAccountRef` · `AuditStamp` · `ApprovalPolicy` · `SoDException` · `MigrationIdentifier` · `ReasonCode` · `WithholdingAmount` (AIT/VDS, directional).

---

## 7. Domain Services

| Service | Responsibility | Context |
|---|---|---|
| **PostingService** | Builds balanced two-event postings; enforces debits = credits | Ledger |
| **CorrectionService** | Orchestrates reversal / void (safe-window) / credit-debit-note | Receivables/Payables + Ledger |
| **CashApplicationService** | Applies a receipt/payment across documents & advances; enforces no-over-allocation; triggers realised-FX application per tranche | Settlement |
| **FXGainLossCalculation** | Computes realised & unrealised FX given rates (the formula) | Currency & FX (invoked by Settlement/Period — AP-001) |
| **TaxDeterminationService** | Resolves tax-code version → TaxSnapshot | Tax |
| **RevaluationService** | Soft-Close unrealised revaluation figures + next-period reversal | FX + Period |
| **PeriodCloseService** | Close gates, state transitions, lock/reopen | Period |
| **AuthorizationService / SoDService** | RBAC+ABAC, maker-checker per ApprovalPolicy, SoD exceptions | Identity |
| **NumberingService** | Atomic scoped sequence at post; gapless policy; ID/Number split | Shared kernel |
| **MatchingService** | Statement-line ↔ transaction match suggestions (human-confirmed) | Reconciliation |
| **MigrationService** | Idempotent staging, dry-run, authorised final conversion | Migration |
| **CashViewProjectionService** | Derives cash-basis view from postings + allocations | Reporting |

---

## 8. Domain Events

**Receivables:** `InvoiceIssued`, `InvoiceVoided`, `InvoiceStatusChanged`, `CreditNoteIssued`.
**Payables:** `BillApproved`, `BillVoided`, `BillStatusChanged`, `DebitNoteIssued`, `ExpenseRecorded`.
**Settlement:** `PaymentAllocated`, `ReceiptAllocated`, `AdvanceRecorded`, `CreditHeld`, `CreditApplied`, `CreditRefunded`, `WithholdingCaptured`, `RealisedFXRecognised`.
**Ledger:** `JournalPosted`, `JournalReversed`, `SystemEntryPosted`.
**Tax:** `TaxDetermined`, `TaxCodeVersioned`.
**FX:** `RateRecordAdded`, `UnrealisedFXRevalued`, `RevaluationReversed`.
**Period:** `PeriodSoftClosed`, `PeriodHardClosed`, `PeriodReopened`, `VATPeriodLocked`, `YearEndRolled`.
**Identity:** `UserInvited`, `UserDeactivated`, `RoleAssigned`, `SoDExceptionRaised`, `BreakGlassActivated`.
**Reconciliation:** `StatementImported`, `LineMatched`, `ReconciliationCompleted`.
**Migration:** `DryRunCompleted`, `StagingReset`, `ConversionPosted`.

> **Flow examples:** `InvoiceIssued` → PostingService → `JournalPosted`. `ReceiptAllocated` → `RealisedFXRecognised` + `JournalPosted`, and Receivables consumes it → `InvoiceStatusChanged` (Partially Paid/Paid). `PeriodHardClosed` → `VATPeriodLocked` (feeds the void-window test).

---

## 9. Repository Interfaces (one per aggregate root; entity-scoped)

`AccountRepository` · `JournalEntryRepository` · `InvoiceRepository` · `CreditNoteRepository` · `CustomerRepository` · `BillRepository` · `DebitNoteRepository` · `ExpenseRepository` · `VendorRepository` · `AllocationRepository` · `PartyCreditBalanceRepository` · `AccountingPeriodRepository` · `TaxCodeRepository` · `RateRecordRepository` · `UserRepository` · `RoleRepository` · `EntityRepository` · `BankReconciliationRepository` · `StagingBatchRepository`.

**Read-model queries (Reporting, no writes):** `TrialBalanceQuery` · `GeneralLedgerQuery` · `ProfitAndLossQuery` · `BalanceSheetQuery` · `AgeingQuery` · `TaxSummaryQuery` · `CashViewQuery`.

---

## 10. Cross-Context Dependencies

| Consumer → Provider | What flows | Relationship |
|---|---|---|
| Receivables/Payables → **Ledger** | recognition posting requests | Customer/Supplier (Ledger = upstream truth) |
| Receivables/Payables → **Tax** | determination → TaxSnapshot | Customer/Supplier |
| **Settlement** → Receivables/Payables | reads authoritative open balance (via Document ID) | Customer/Supplier |
| Receivables/Payables → **Settlement** | consumes settlement events → status update | Customer/Supplier (events) |
| **Settlement** → Ledger | settlement posting requests | Customer/Supplier |
| **Settlement** → FX | rate reference + realised-FX calc | Customer/Supplier |
| Receivables/Payables/Settlement/Ledger → **Period** | "is this date postable?" | Conformist |
| All write contexts → **Identity** | authorization + SoD | Conformist |
| Receivables/Payables/Settlement → **Numbering** | next Document Number | Shared Kernel |
| Reconciliation → Settlement/Ledger | match → settlement/posting request | Customer/Supplier |
| Migration → Ledger + Receivables/Payables + Settlement | staged open items → authorised final post | Customer/Supplier |
| Reporting → **all** | read projections (cash view keys off Settlement) | Downstream |
| Group *(future)* → entity Ledgers | translated balances + CTA | Downstream (deferred) |

---

## 11. Context Map

```
                        ┌──────────────────────┐
                        │  IDENTITY & ACCESS    │  (Entity = isolation/tenant)
                        │  authz · SoD · policy │
                        └──────────┬───────────┘
                                   │ conformist (all writes authorised)
     shared kernel                 ▼
  ┌──────────────┐         ┌───────────────┐         ┌───────────────┐
  │  NUMBERING   │◀───────▶│  RECEIVABLES  │         │   PAYABLES    │
  │ (ID/Number)  │         │ invoices/CN/  │         │ bills/DN/exp/ │
  └──────────────┘         │ customers/AR  │         │ vendors/AP    │
                           └───────┬───────┘         └───────┬───────┘
                                   │  open balance (by Document ID) ▲
                                   ▼        settlement events │
                           ┌───────────────────────────────────────┐
                           │      SETTLEMENT & CASH APPLICATION      │
                           │ allocations · advances · realised FX    │
                           └───────┬─────────────┬─────────────┬────┘
                                   │             │             │
                    ┌──────────────▼┐    ┌───────▼────┐   ┌────▼──────┐
                    │  LEDGER &     │    │ CURRENCY   │   │  PERIOD & │
                    │  POSTING      │◀──▶│  & FX      │   │   CLOSE   │
                    └───────┬───────┘    └────────────┘   └───────────┘
        ┌───────────┐       │ upstream truth        ┌───────────┐
        │    TAX    │───────┘                       │RECONCILE  │──▶ Settlement/Ledger
        └───────────┘                               └───────────┘
        ┌───────────┐                               ┌────────────────────┐
        │ MIGRATION │──▶ Ledger/Receivables/         │ REPORTING & CASH   │ (read-only;
        └───────────┘     Payables/Settlement        │ VIEW               │  cash view ← Settlement)
                                                      └────────────────────┘
                        ┌────────────────────────┐
                        │ GROUP & CONSOLIDATION   │  (deferred — ADR-010)
                        └────────────────────────┘
```
**Patterns:** Ledger, Tax, FX, Period are **upstream** of documents & settlement; **Settlement** sits between the document contexts and Ledger/FX/Reporting; Reporting is **downstream** of all (cash view derives from Settlement); Numbering is a **shared kernel**; Identity and Period are **conformist** constraints on every write.

---

## 12. Domain Glossary

*Account, Advance, Aggregate, Allocation, AllocationLink, AccountingPeriod, Approval Policy, Bill, Bounded Context, Break-glass, Cash View, Chart of Accounts, Conversion Journal, Credit Note, CTA, Debit Note, Document ID, Document Number, Entity, Expense, Functional Currency, Gapless, Hard Close, Input VAT (recoverable/non-recoverable), Invoice, Journal Entry, Ledger, Maker-Checker, Migration Identifier, Money, Open Item, Output VAT, PartyCreditBalance, Period State, Posting, Presentation Currency, Rate Record, Realised/Unrealised FX, Recognition, Reconciliation, Reversal, SBU Allocation, Settlement, SoD Exception, Soft Close, Staging Batch, Tax Code, Tax Pack, Tax Snapshot, Transaction Currency, Unapplied Credit, VDS, Void, Withholding, Zero-rated vs Exempt.*
*(Definitions per SRS v3.0 §2 and this document §1 — single source.)*

---

## 13. Self-Challenge (before finalising)

1. **Is Settlement a real context or just a shared table?** It has its own language (settlement/advance/tranche/realised FX), its own reason to change (cash-application rules), and its own invariants (no over-allocation; credit balance never negative; per-tranche FX). It is a context, not a helper.

2. **Balance ownership across Receivables↔Settlement.** The document owns *what is owed* and updates its status by consuming settlement events; Settlement enforces no-over-allocation by reading the document's authoritative open balance (via immutable **Document ID**, ADR-009). Status is eventually consistent; the hard invariant (don't over-allocate) is enforced at application time. Acceptable and standard.

3. **Advances — do they belong to Settlement or to Ledger?** An advance's *balance* lives in the Ledger (2060/1075); its *application lifecycle* (record, hold, draw down, refund) is Settlement. Settlement requests the postings; Ledger holds the truth. Clean split, no double ownership.

4. **Over-fragmentation for a finance-only tool?** The split is by language and change-cadence, not size. Settlement here is genuinely rich (multi-currency, withholding, advances, per-tranche FX). Not over-fragmented.

5. **Receivables vs Payables — still justified as two contexts post-rename?** Yes — distinct languages (output-VAT/customer/statement vs input-VAT/AIT/vendor) and lifecycles; they share only kernel + patterns, now that Settlement is extracted.

6. **Does extracting Settlement leave Receivables/Payables anemic?** No — they still own document creation, VAT-output/input capture, void-window logic, party master, and balance definition. Substantial.

**Contradiction check vs frozen spec:** none. Every invariant traces to a locked BR/ADR; the change is a *reorganisation* (Allocation → own context), not a new rule. The prior §13.3 open question (Allocation home) is now **resolved**.

---

## 14. Consistency & Readiness Statement

- **Internally consistent:** yes — each context has one language, one reason to change; no aggregate owned by two contexts; balance-ownership seam resolved via events + Document ID.
- **Consistent with frozen requirements:** yes — traceable to SRS v3.0 + ADR-001…009; no new business rules; no contradictions.
- **Finance-only:** enforced — no ERP/operational concepts; maker-checker retained as finance-internal SoD.
- **Deferred by design:** Group & Consolidation (ADR-010), a stub with hooks already present (entity boundary, Document ID, presentation currency, CTA reserve).

**Recommendation:** Domain Model v2 is complete and internally consistent. Ready for **Event Storming**, where the §8 events become the backbone and the Settlement↔document balance seam (§13.2) is the first flow to walk end-to-end.
