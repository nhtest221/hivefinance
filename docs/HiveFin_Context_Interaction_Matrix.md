# HiveFin — Context Interaction Matrix (pre-Event-Storming)

**Basis:** Domain Model v2 (finance-only; Receivables/Payables; Settlement as its own context) + SRS v3.0 + ADR-001…009.
**Purpose:** verify single-owner master data, single-owner invariants, and an acyclic synchronous-dependency graph before Event Storming.
**Legend:** ▲ upstream (depends on) · ▼ downstream (depended on by) · *sync* = synchronous query/command · *async* = domain-event subscription · *conformist* = obeys another's rules.

---

## Per-Context Matrix

### 1. Ledger & Posting
1. **Responsibilities:** double-entry postings; account balances; immutability & reversal integrity; SBU as a posting dimension.
2. **Aggregate roots:** Account, JournalEntry.
3. **Publishes:** `JournalPosted`, `JournalReversed`, `SystemEntryPosted`.
4. **Consumes:** posting requests from Receivables/Payables/Settlement/Migration (as commands, not events).
5. **Commands:** PostJournal, ReverseJournal, PostSystemEntry, CreateAccount, DeactivateAccount, DefineSBU.
6. **Queries:** GetAccountBalance, GetLedgerEntries, GetSBUList, GetBankAccounts.
7. **Upstream (▲):** Period (postable?), Identity (authz).
8. **Downstream (▼):** Receivables, Payables, Settlement, Migration, Reporting.
9. **External systems:** none.
10. **Master data owned:** Chart of Accounts; **SBU dimension**; **bank-account master** (a bank account = a CoA asset account + bank attributes).
11. **Invariants owned:** debits = credits (functional ccy, post-rounding); posted entries immutable; corrections are linked reversals; account type immutable once posted.

### 2. Receivables
1. **Responsibilities:** customer invoices, credit notes, customer master, AR balances, statements, invoice lifecycle & void-window.
2. **Aggregate roots:** Invoice, CreditNote, Customer.
3. **Publishes:** `InvoiceIssued`, `InvoiceVoided`, `InvoiceStatusChanged`, `CreditNoteIssued`.
4. **Consumes:** `ReceiptAllocated`, `CreditApplied`, `CreditRefunded` (→ update invoice status).
5. **Commands:** CreateInvoiceDraft, IssueInvoice, VoidInvoice, IssueCreditNote, CreateCustomer, UpdateCustomer.
6. **Queries:** GetInvoice, **GetOpenReceivable(byDocumentId)**, GetCustomerBalance, GetCustomerStatement.
7. **Upstream (▲):** Tax (determination), FX (invoice-date rate), Ledger (posting), Period, Identity, Numbering.
8. **Downstream (▼):** Settlement (reads open balance), Reporting.
9. **External systems:** PDF export (invoice).
10. **Master data owned:** Customer; Invoice/CreditNote documents.
11. **Invariants owned:** recognition posts at issue date; void-window 4-condition test; issued documents immutable; AR balance = issued − settled.

### 3. Payables
1. **Responsibilities:** supplier bills, debit notes, expenses, vendor master, AP balances, AIT/VDS capture on bills, SBU split.
2. **Aggregate roots:** Bill, DebitNote, Expense, Vendor.
3. **Publishes:** `BillApproved`, `BillVoided`, `BillStatusChanged`, `DebitNoteIssued`, `ExpenseRecorded`.
4. **Consumes:** `PaymentAllocated`, `CreditApplied` (→ update bill status).
5. **Commands:** CreateBillDraft, ApproveBill, VoidBill, IssueDebitNote, RecordExpense, CreateVendor.
6. **Queries:** GetBill, **GetOpenPayable(byDocumentId)**, GetVendorBalance.
7. **Upstream (▲):** Tax, FX, Ledger, Period, Identity, Numbering.
8. **Downstream (▼):** Settlement, Reporting.
9. **External systems:** none (bill PDF summary internal).
10. **Master data owned:** Vendor; Bill/DebitNote/Expense documents.
11. **Invariants owned:** SBU weights Σ=1.0000; recognition at approval; immutable once posted; AP balance = approved − settled.

### 4. Settlement & Cash Application
1. **Responsibilities:** allocations (receipt/payment), partial & multi-tranche settlement, customer/supplier advances, unapplied credits, realised FX at settlement, withholding-on-receipt capture.
2. **Aggregate roots:** Allocation, PartyCreditBalance.
3. **Publishes:** `ReceiptAllocated`, `PaymentAllocated`, `AdvanceRecorded`, `CreditHeld`, `CreditApplied`, `CreditRefunded`, `WithholdingCaptured`, `RealisedFXRecognised`.
4. **Consumes:** (none required for its own invariants; reads open balances synchronously — see coupling note).
5. **Commands:** RecordReceipt, RecordPayment, RecordAdvance, ApplyCredit, RefundCredit, ApplyWithholding.
6. **Queries:** GetAllocation, GetPartyCreditBalance, GetSettlementsFor(DocumentId).
7. **Upstream (▲):** Receivables/Payables (open balance), FX (rate + realised calc), Ledger (posting), Period, Identity, Numbering.
8. **Downstream (▼):** Reporting (cash view keys off allocations).
9. **External systems:** none.
10. **Master data owned:** Allocation records; PartyCreditBalance (advances/unapplied credits, 2060/1075 detail).
11. **Invariants owned:** applied amount ≤ target open balance; PartyCreditBalance ≥ 0; realised FX **applied** per tranche vs invoice-date rate (the FX *calculation* is invoked from FX, not owned here — AP-001); withholding recorded so receivable/payable clears in full.

### 5. Tax
1. **Responsibilities:** tax codes & packs, determination, snapshots, VAT/AIT/VDS rules, return-box mapping.
2. **Aggregate roots:** TaxCode (+versions), TaxPack.
3. **Publishes:** `TaxCodeVersioned`, `TaxDetermined`.
4. **Consumes:** none (pure upstream authority).
5. **Commands:** DefineTaxCode, VersionTaxCode, ConfigureTaxPack, DetermineTax(line, date).
6. **Queries:** GetApplicableTaxCode(date), GetTaxSnapshot, GetReturnBoxMapping.
7. **Upstream (▲):** Identity (four-eyes on tax config).
8. **Downstream (▼):** Receivables, Payables, Reporting (tax summary).
9. **External systems:** none (Mushak-9.1 export via Reporting).
10. **Master data owned:** Tax codes, versions, packs, return-box maps.
11. **Invariants owned:** transaction binds the version effective on its tax-point date; snapshot persisted for reproducibility; recoverable→asset vs non-recoverable→cost; zero-rated≠exempt.

### 6. Currency & FX
1. **Responsibilities:** exchange-rate master data; unrealised revaluation figures.
2. **Aggregate roots:** RateRecord.
3. **Publishes:** `RateRecordAdded`, `UnrealisedFXRevalued`, `RevaluationReversed`.
4. **Consumes:** none.
5. **Commands:** AddRateRecord, RequestRevaluation(period).
6. **Queries:** GetRate(pair, date), GetRateRecord(id), GetRevaluationFigures(period).
7. **Upstream (▲):** Period (revaluation at Soft Close), Identity.
8. **Downstream (▼):** Receivables, Payables, Settlement, Reporting.
9. **External systems:** none in MVP (feed-ready).
10. **Master data owned:** Rate Records.
11. **Invariants owned:** rate immutable once referenced; effective-dated; revaluation reversed at next-period start; **owns the FX gain/loss calculation** (realised & unrealised), invoked by Settlement (realised) and Period/FX (unrealised) — per AP-001, Settlement does not own the FX formula.

### 7. Period & Close
1. **Responsibilities:** period lifecycle & fiscal calendar; close gates; lock/reopen enforcement.
2. **Aggregate roots:** AccountingPeriod.
3. **Publishes:** `PeriodSoftClosed`, `PeriodHardClosed`, `PeriodReopened`, `VATPeriodLocked`, `YearEndRolled`.
4. **Consumes:** none (may observe `JournalPosted` for activity, non-essential).
5. **Commands:** OpenPeriod, SoftClosePeriod, HardClosePeriod, ReopenPeriod, LockVATPeriod, RollYearEnd.
6. **Queries:** GetPeriodState, **IsDatePostable(date, entity)**.
7. **Upstream (▲):** Identity (four-eyes on Hard Close/Reopen).
8. **Downstream (▼):** Ledger, Receivables, Payables, Settlement, FX (all consult postability).
9. **External systems:** none.
10. **Master data owned:** Periods; **fiscal calendar**.
11. **Invariants owned:** exactly one state per period; Soft Close repeatable; Hard Close irreversible via normal ops; reopen requires role+reason+approval+notification+re-close; corrections to closed periods route to current open period.

### 8. Identity & Access
1. **Responsibilities:** entities (tenant boundary); users, roles, permissions; SoD; approval policy; delegation; break-glass.
2. **Aggregate roots:** Entity, User, Role.
3. **Publishes:** `UserInvited`, `UserDeactivated`, `RoleAssigned`, `SoDExceptionRaised`, `BreakGlassActivated`.
4. **Consumes:** none.
5. **Commands:** InviteUser, DeactivateUser, AssignRole, CreateCustomRole, DefineApprovalPolicy, GrantDelegation, ActivateBreakGlass, RaiseSoDException.
6. **Queries:** CheckAuthorization, GetUserRoles, GetApprovalPolicy, GetEntity.
7. **Upstream (▲):** none.
8. **Downstream (▼):** all write contexts (conformist authz).
9. **External systems:** MFA provider (Owner/Finance Manager).
10. **Master data owned:** Entity; User; Role; ApprovalPolicy.
11. **Invariants owned:** default-deny; custom role ≤ granter; enforced SoD conflicts with compensating exceptions; last Owner protected; MFA required for privileged roles.

### 9. Reconciliation
1. **Responsibilities:** bank statement import; matching; reconciliation status & statement.
2. **Aggregate roots:** BankReconciliation.
3. **Publishes:** `StatementImported`, `LineMatched`, `ReconciliationCompleted`.
4. **Consumes:** none (queries existing transactions to match).
5. **Commands:** ImportStatement, MatchLine, ConfirmMatch, CreateEntryForBankLine (→ requests posting/settlement), CompleteReconciliation.
6. **Queries:** GetReconciliation, GetUnmatchedLines.
7. **Upstream (▲):** Ledger, Settlement, Receivables, Payables (reads transactions); Period; Identity.
8. **Downstream (▼):** Reporting (reconciliation statement).
9. **External systems:** bank CSV files.
10. **Master data owned:** BankStatement imports, reconciliation records.
11. **Invariants owned:** reconciliation complete only when all lines matched & statement ties to zero; duplicate/overlap detection.

### 10. Migration
1. **Responsibilities:** idempotent, staged open-item conversion; dry-run; validation; audit report.
2. **Aggregate roots:** StagingBatch.
3. **Publishes:** `DryRunCompleted`, `StagingReset`, `ConversionPosted`.
4. **Consumes:** none.
5. **Commands:** StageBatch, RunDryRun, ResetStaging, ExecuteFinalMigration.
6. **Queries:** GetStagingValidation, GetMigrationReport.
7. **Upstream (▲):** Ledger, Receivables, Payables, Settlement (final authorised post); Identity (four-eyes).
8. **Downstream (▼):** none (one-shot).
9. **External systems:** source accounting system (Xero = Importer #1); CSV/Excel.
10. **Master data owned:** StagingBatch; MigrationIdentifier mapping.
11. **Invariants owned:** idempotent by MigrationIdentifier; TB balances; no live post before authorised final migration; staging reset has zero ledger impact.

### 11. Reporting & Cash View
1. **Responsibilities:** read-side projections; derived cash view; statutory summaries.
2. **Aggregate roots:** none (read models only).
3. **Publishes:** none.
4. **Consumes:** projections from all write contexts (read).
5. **Commands:** none (queries only).
6. **Queries:** TrialBalance, GeneralLedger, ProfitAndLoss, BalanceSheet, ARAgeing, APAgeing, TaxSummary, CashCollections/Payments, FXRevaluation.
7. **Upstream (▲):** all.
8. **Downstream (▼):** none.
9. **External systems:** PDF/CSV export.
10. **Master data owned:** none.
11. **Invariants owned:** none (read-only); cash view derived by the single documented algorithm; statutory reports accrual-only.

### Shared Kernel — Numbering
- **Owns:** Sequence Registry; ID/Number split. **Commands:** DrawDocumentNumber(series, scope), IssueDocumentId. **Query:** PeekSequenceState. **Invariants:** atomic draw (no duplicates); statutory series gapless; numbers never reused; migration/manual path bounded. **Used by:** Receivables, Payables, Settlement. **Depends on:** nobody.

### Deferred Stub — Group & Consolidation (ADR-010)
- Not built. Hooks present (entity boundary, Document ID, presentation currency, CTA reserve). Would be **downstream** of entity Ledgers.

---

## Cross-Context Dependency Analysis

**Synchronous dependency edges (A depends on B):**
- Receivables → Tax, FX, Ledger, Period, Identity, Numbering
- Payables → Tax, FX, Ledger, Period, Identity, Numbering
- Settlement → Receivables/Payables (open balance), FX, Ledger, Period, Identity, Numbering
- Reconciliation → Ledger, Settlement, Receivables, Payables, Period, Identity
- Migration → Ledger, Receivables, Payables, Settlement, Identity
- FX → Period, Identity ; Tax → Identity ; Period → Identity ; Ledger → Period, Identity
- Reporting → all (read-only)

**Asynchronous (event) edges (A reacts to B's events):**
- Receivables ⇐ Settlement (`ReceiptAllocated`/`CreditApplied`/`CreditRefunded` → status)
- Payables ⇐ Settlement (`PaymentAllocated`/`CreditApplied` → status)
- (Ledger postings are triggered via commands from PostingService, not event subscriptions)

**Conformist constraints (obey, don't couple):** every write context obeys **Period** (postability) and **Identity** (authz). These are one-way.

**Sinks / sources:** Identity and Period are pure sources (no upstream). Reporting is a pure sink (no downstream). Numbering depends on nobody. Migration and Reconciliation are near-sinks (nobody depends on them for steady-state).

### Cycle check
- **Only bidirectional relationship: Settlement ↔ Receivables (and Settlement ↔ Payables).**
  - *Settlement → document* is a **synchronous open-balance query** (to enforce no-over-allocation).
  - *document → Settlement* is **asynchronous event consumption** (status update).
  - Because the return edge is async (event), there is **no synchronous call cycle**. The synchronous dependency graph is a **DAG**.
- No other bidirectional pairs. No A→B→C→A synchronous loops. ✔ **Acyclic (synchronous).**

### Coupling check
- **Settlement has the highest fan-out** (touches Receivables, Payables, FX, Ledger, Period, Identity, Numbering). This is expected for a *cash-application hub* — but it is **coupling-by-collaboration, not by ownership**: Settlement owns only its own invariants and reads others' data through queries/events. Not a god context. **Watch-item:** if Settlement ever starts owning document *status* or ledger balances, that is a violation — it must not.
- **Ledger has the highest fan-in** (all posting contexts). Correct — it is the single source of GL truth; high fan-in to an upstream authority is healthy.
- **Single-owner master data:** ✔ every datum has exactly one owner (CoA/SBU/bank → Ledger; Customer → Receivables; Vendor → Payables; Allocation/advances → Settlement; Tax codes → Tax; Rates → FX; Periods/fiscal calendar → Period; Entity/User/Role/ApprovalPolicy → Identity). No shared ownership.
- **Single-owner invariants:** ✔ no invariant is enforced by two contexts. The subtle case — Settlement's "no-over-allocation" — enforces *its own* rule while *reading* Receivables' balance; Receivables owns the balance's correctness. Clean.

---

## Findings & Recommendations

1. **Adopt the asymmetric Settlement↔document pattern explicitly** (sync query out, async event back). This is what keeps the graph acyclic and must be a stated architecture rule, not left implicit. *(Recommendation, not a new business rule.)*
2. **Master-data placements to ratify** (non-obvious, deliberate): **SBU dimension → Ledger** (it is a posting classification, aggregated in reports); **fiscal calendar → Period** (it defines periods); **bank-account master → Ledger** (a bank account is a CoA asset account with bank attributes). No separate "Organisation Config" context is needed — these placements avoid over-fragmentation.
3. **Settlement fan-out is acceptable but is the primary watch-item** for future coupling drift; guard its ownership boundary (no document status, no ledger balances).
4. **Optional future decoupling (not for MVP):** Settlement could maintain an event-sourced read-model of open balances (consuming `InvoiceIssued`/`InvoiceStatusChanged`) instead of querying Receivables synchronously — removing even the query edge. Deferred: at ~15 users the synchronous query gives stronger no-over-allocation consistency and is simpler.
5. **No context violates DDD principles** after the finance-only rename and Settlement extraction.

---

## AP-001 (Context Ownership) compliance

Evaluated and **compliant**, with three standing rules adopted: (1) Migration writes only via each target context's application services; (2) Reconciliation creates entries only via the owning context's application services; (3) the FX gain/loss *calculation* is owned by Currency & FX and invoked by Settlement/Period (Settlement owns the trigger/application, not the formula). See Architecture Principles register.

## Consistency Statement

- **Single-owner master data:** ✔  **Single-owner invariants:** ✔  **Synchronous graph acyclic:** ✔  **No god context (ownership):** ✔  **AP-001 compliant:** ✔  **Consistent with SRS v3.0 + ADR-001…009:** ✔ (no new business rules).
- The interaction matrix is **internally consistent.** The one bidirectional relationship is resolved by the asymmetric sync/async pattern (Recommendation 1). Ready to proceed to **Event Storming**, beginning with the flow that exercises the most contexts at once: **a foreign-currency customer receipt, net of AIT** (Settlement + Receivables + FX + Tax + Ledger + Period + Identity).
