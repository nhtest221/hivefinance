# HiveFin — Aggregate Design (Phase 1: Five Highest-Risk Aggregates)

**Frozen inputs (immutable):** SRS v3.0 · ADR-001…009 · Domain Model v2 · Interaction Matrix · AP-001 · Context Map.
**Contradiction check:** no business-rule contradiction. One architectural refinement to the Context Map's Partnership integration is ratified in §0.3 — an *addition*, not a reversal.
**Scope:** JournalEntry · Allocation (+CreditTranche; PartyCreditBalance projection) · ReceivableDocument · PayableDocument · LedgerAccount. Remaining aggregates follow after review.
**Optimise for:** correctness, auditability, maintainability — not normalisation.

---

## 0. Cross-Cutting Consistency & Transactional Model (read first)

Two — and only two — kinds of cross-aggregate flow exist:

**0.1 Strong (same transaction).** Financial integrity requires that a business document and its ledger posting exist together. So the **application service is the transactional boundary** for:
- **Recognition:** `IssueInvoice` / `ApproveBill` commits the document aggregate **+** its `JournalEntry` **in one transaction.**
- **Settlement:** recording an allocation commits `Allocation` **+** the document's balance update **+** the settlement `JournalEntry` **in one transaction.**
Each aggregate remains its own **consistency boundary** (own invariants); the **service** coordinates them via each context's application services (AP-001 preserved — no context writes another's store).

**0.2 Eventual (async domain events).** Everything not required for financial integrity:
- Read-model/projection updates (Reporting: TB, GL, P&L, ageing, cash view).
- Display-status notifications, audit visibility.
These flow via the in-process event bus and may lag.

**0.3 ✅ RATIFIED REFINEMENT (approved).**
The Context Map characterised the Settlement↔document Partnership as *"sync query + async event (status)."* Aggregate design showed the **no-over-allocation invariant cannot be strongly guaranteed by an async return path** (two concurrent receipts could both read a stale open balance). Resolution (ratified): a synchronous **`ApplySettlement(amount, expectedVersion)`** command on the document, invoked by the settlement service inside the settlement transaction. Still **Settlement→document direction** (acyclic preserved) and **via the document's own command** (AP-001 preserved). The async `InvoiceStatusChanged`/`BillStatusChanged` event remains — for *projections/display*, not for the balance. Context Map updated to reflect this.

**0.4 Balances are derived, not stored authoritatively.** Ledger account balances are the **sum of immutable posted journal lines** (source of truth). Document `openBalance` is the exception — it is maintained on the document (via `ApplySettlement`) because it guards a hard invariant and is read on the Partnership hot path; it is reconcilable to ledger + allocations at any time.

---

## 1. Aggregate: JournalEntry  *(Ledger & Posting)*

1. **Aggregate root:** JournalEntry.
2. **Entities:** JournalLine (child, ordered).
3. **Value objects:** `Money`, `AccountRef`(UUID), `ExchangeRateReference`, `SBUAllocation`, `EntryType`(Manual/System/Adjusting/Reversal/Revaluation/Conversion), `PeriodRef`, `ReasonCode`, `AuditStamp`, `Narration`, `ReferenceText`.
4. **Boundary:** header + all lines (the balance invariant spans all lines → they must be one aggregate). **Excludes** account balances.
5. **Invariants:** Σdebits = Σcredits in functional currency (post-rounding); ≥2 lines; all lines same entity; foreign lines carry a RateRecord + functional equivalent; **Posted ⇒ immutable**; reversal is a linked entry; `[System]` entries reversed only via source document.
6. **Lifecycle:** Draft → Posted → Reversed. (System/adjusting entries created directly Posted.)
7. **Commands:** CreateDraftJournal, AddLine/EditLine/RemoveLine (Draft only), PostJournal, ReverseJournal, DeleteDraft, PostSystemEntry(internal).
8. **Events:** JournalPosted, JournalReversed, SystemEntryPosted.
9. **Repository:** JournalEntryRepository (by DocumentId; query by account/period/status).
10. **Consistency boundary:** strong within entry+lines.
11. **Transactional boundary:** one entry commits atomically; recognition/settlement entries commit within the triggering service transaction (§0.1).
12. **Optimistic concurrency:** version on the aggregate (guards Draft edits). Posted is immutable → no post-commit contention. *Low risk.*
13. **Cross-aggregate refs (UUID):** AccountRef, sourceDocumentId, reversalOfEntryId/reversedByEntryId, rateRecordId, periodRef.
14. **Size challenge:** right-sized. Lines belong in (balance invariant). Balances correctly excluded — including them would make the entry contend with every posting. Not too small (a lone line can't balance).

---

## 2. Aggregate: Allocation (+ CreditTranche; PartyCreditBalance projection) *(Settlement & Cash Application)*

### 2a. Allocation
1. **Root:** Allocation.
2. **Entities:** AllocationLink (child: target DocumentId + applied Money + realised-FX Money); CreditConsumption/Restoration links when named party-credit tranches are consumed or restored.
3. **Value objects:** `Money`, `Direction`(Receipt/Payment), `ExchangeRateReference`, `WithholdingAmount`(AIT/VDS, directional), `BankAccountRef`(UUID), `PartyRef`(UUID), `AuditStamp`.
4. **Boundary:** allocation header + its links (one deposit/payment settling ≥1 document). Excludes target documents and the rebuildable PartyCreditBalance projection; credit application/refund nevertheless names the exact CreditTranches consumed.
5. **Invariants:** Σ(link applied) + unapplied-to-credit + withholding-applied = amount settled; **each link applied ≤ target open balance** (enforced via §0.3 `ApplySettlement`); every credit application/refund explicitly names non-duplicate `credit_sources`, amount, and per-tranche `expected_version`; no FIFO, LIFO, weighted-average, pro-rata, automatic selection, or single-tranche shortcut; realised FX is invoked from FX per link/tranche using immutable references; currency is consistent; **Posted ⇒ immutable** (reversal only).
6. **Lifecycle:** Draft → Posted → Reversed.
7. **Commands:** RecordReceipt, RecordPayment, AllocateToDocuments, HoldAsCredit, ApplyCredit, RefundCredit, ReverseAllocation, ApplyWithholding.
8. **Events:** ReceiptAllocated, PaymentAllocated, RealisedFXRecognised, WithholdingCaptured, CreditHeld, CreditApplied, CreditRefunded, AllocationReversed.
9. **Repository:** AllocationRepository (by DocumentId; query by document/party/date).
10. **Consistency boundary:** strong within allocation+links; the ≤-open-balance check crosses to the document via synchronous `ApplySettlement` in the same transaction (§0.1/0.3).
11. **Transactional boundary:** Allocation + document `ApplySettlement` + settlement JournalEntry + named CreditTranche consumption/restoration and projection updates commit **together**.
12. **Optimistic concurrency:** **highest-risk aggregate.** Allocation version, each document `expectedVersion`, and every selected CreditTranche `expected_version` are checked transactionally. A stale tranche fails the whole command; it is never replaced by another source.
13. **Cross-aggregate refs (UUID):** target documentIds, bankAccountRef, settlement/document/source RateRecordIds, partyId, creditTrancheIds, journalEntryId.
14. **Size challenge:** right-sized. Multi-document links reflect a real single deposit — bounded. CreditTranches outlive individual allocations and PartyCreditBalance remains a separate rebuildable projection.

### 2b. CreditTranche and PartyCreditBalance projection
1. **Root:** CreditTranche, one immutable unapplied-credit source fact for a party, entity, and currency. PartyCreditBalance is a rebuildable read projection and is never consumption authority.
2. **Entities:** append-only CreditConsumption and CreditRestoration facts. 3. **VOs:** transaction and functional `Money`, `PartyRef`, `EntityRef`, immutable source/comparison `ExchangeRateReference`.
4. **Boundary:** immutable source facts plus append-only consumption/restoration history; projected remaining transaction and functional amounts may be persisted for guarded reads.
5. **Invariants:** original amount, original functional amount, source Allocation/reference, party, entity, currency, and source RateRecord never change; projected remainders are non-negative; each application/refund selects explicit sources and consumes no more than each source remainder; reversal restores the exact recorded transaction and functional values to the same source tranches.
6. **Lifecycle:** created by HoldCredit/AdvanceRecorded; partially or fully consumed by application/refund; restored only by a linked reversal. Source facts remain immutable in every state.
7. **Commands:** HoldCredit, ConsumeNamedCreditSources, RefundNamedCreditSources, RestoreRecordedCreditConsumptions.
8. **Events:** CreditHeld, CreditApplied, CreditRefunded, AllocationReversed, using the backward-compatible v2 tranche schemas in Domain Events.
9. **Repository:** CreditTrancheRepository; PartyCreditBalance projection repository is read/rebuild infrastructure only.
10–11. **Consistency/transaction:** source creation, consumption/restoration facts, guarded remainders, projection updates, the Settlement Allocation, document application or bank movement, Ledger posting, realised FX, audit, idempotency, and outbox commit atomically.
12. **Concurrency:** every selected tranche has its own optimistic `expected_version`; no aggregate PartyCredit version substitutes for these guards. *High risk.*
13. **FX:** a foreign application compares the tranche source RateRecord carrying baseline with the target document RateRecord; a foreign refund compares it with the refund RateRecord. FX calculation is internal to FX; clients never provide calculated realised FX or functional carrying values.
14. **Size:** each immutable source tranche is independently guarded. The total PartyCreditBalance remains small and rebuildable but cannot authorize consumption.

---

## 3. Aggregate: ReceivableDocument  *(Receivables — Invoice & CreditNote)*

> Two concrete roots share a Published-Language shape but differ in invariants.

1. **Roots:** Invoice; CreditNote.
2. **Entities:** InvoiceLine / CreditNoteLine.
3. **Value objects:** `DocumentId`, `DocumentNumber`, `Money`, `TaxSnapshot`(per line), `ExchangeRateReference`(invoice-date), `CustomerRef`(UUID), `DueDate`, `PaymentInstructionsRef`, `ReasonCode`(CreditNote), `openBalance`(Money), `AuditStamp`.
4. **Boundary:** document header + lines + its `openBalance`. A CreditNote additionally owns append-only disposition facts, exact current-state disposition amounts, and linked reversal. Excludes customer master, Settlement/CreditTranche storage, and ledger.
5. **Invariants:** VAT defaults from customer type, line-overridable; recognition posts at **issue date**; **issued invoice and Posted CreditNote ⇒ immutable**; **invoice void only if all 4 safe-window conditions** (unpaid, current open period, not in filed VAT return, no downstream allocations); `openBalance` = issued − Σ settled, ≥ 0; **CreditNote VAT adjustment in the note's period**; CreditNote reason code mandatory. For every Posted, non-reversed CreditNote, all values are non-negative Money and `posted_amount = applied_amount + refunded_amount + held_remaining_amount + undisposed_amount`. `posted_amount` is the original immutable posted value; `applied_amount` and `refunded_amount` are cumulative current amounts net of linked reversals; `held_remaining_amount` is the unconsumed note-owned CreditTranche balance; `undisposed_amount` has not been applied, transferred into a tranche, or refunded. A cumulative historical hold value is never used in this current-state invariant.
6. **Lifecycle (Invoice):** Draft → Sent(=recognised) → Partially Paid → Paid; branch → Void; auto Overdue. Draft correction remains edit-or-void. Sent/issued invoices are never edited; correction is a linked safe-window reversal or CreditNote and reissue. **CreditNote:** Draft → Posted → Reversed, with partial disposition balances changing concurrently rather than forming mutually exclusive states.
7. **Commands:** CreateInvoiceDraft, IssueInvoice, VoidInvoice, ApplySettlement(amount, expectedVersion) [§0.3], CreateCreditNoteDraft, EditCreditNoteDraft, PostCreditNote, ApplyCreditNote, HoldCreditNote, RefundCreditNote, ReverseCreditNote.
8. **Events:** InvoiceIssued, InvoiceVoided, InvoiceStatusChanged, CreditNoteCreated, CreditNoteIssued, CreditNoteApplied, CreditNoteHeld, CreditNoteRefunded, CreditNoteReversed. `CreditNoteDispositionSet` remains historical only.
9. **Repository:** InvoiceRepository, CreditNoteRepository, CreditNoteQuery (by DocumentId; query GetOpenReceivable(byDocumentId)).
10. **Consistency boundary:** strong within document+lines+openBalance.
11. **Transactional boundary:** IssueInvoice + recognition JournalEntry commit together; ApplySettlement participates in the settlement transaction.
12. **Optimistic concurrency:** **version on the document** — the guard behind no-over-allocation (ApplySettlement checks expectedVersion). *High risk on the settlement hot path.*
13. **Cross-aggregate refs (UUID):** CustomerRef, taxSnapshot code refs, rateRecordId, sourceInvoiceId (CreditNote→Invoice), journalEntryId(s).
14. **Size challenge:** right-sized. `openBalance` kept on the document (deliberate — guards a hard invariant, read on the hot path; reconcilable to ledger+allocations). Customer/allocations correctly excluded.

---

## 4. Aggregate: PayableDocument  *(Payables — Bill, DebitNote, Expense)*

1. **Roots:** Bill; DebitNote; Expense.
2. **Entities:** BillLine / DebitNoteLine / Expense (single-bodied). DebitNote also owns append-only disposition facts and linked reversal.
3. **Value objects:** `DocumentId`, `DocumentNumber`, `Money`, `TaxSnapshot`, `ExchangeRateReference`, `VendorRef`(UUID), `SBUAllocation`(Σ=1.0000), `WithholdingAmount`(AIT/VDS), `SettlementType`(Expense: Cash/Accrued), `openBalance`, `AuditStamp`.
4. **Boundary:** document header + lines + SBU split + `openBalance`. Excludes vendor master, allocations, ledger.
5. **Invariants:** **SBU weights Σ = 1.0000**; recognition at **approval**; input VAT recoverable→asset / non-recoverable→cost; AIT/VDS captured; **approved Bill and Posted DebitNote ⇒ immutable**; Bill void via the 4-condition safe-window test else DebitNote; `openBalance` = approved − Σ settled, ≥ 0; Expense cash-settled posts to bank, accrued posts to AP. DebitNote mirrors the CreditNote five-field non-negative current-state invariant exactly: `posted_amount = applied_amount + refunded_amount + held_remaining_amount + undisposed_amount`, with no cumulative historical hold value in the invariant.
6. **Lifecycle (Bill):** Draft → Awaiting Payment(=approved) → Partially Paid → Paid; → Void; auto Overdue. Draft correction remains edit-or-void; approved bills are never edited and use linked safe-window reversal or DebitNote correction. Expense: Draft → Recorded (cash or accrued). DebitNote: Draft → Posted → Reversed, with partial disposition balances.
7. **Commands:** CreateBillDraft, ApproveBill, VoidBill, ApplySettlement(amount, expectedVersion), CreateDebitNoteDraft, EditDebitNoteDraft, PostDebitNote, ApplyDebitNote, HoldDebitNote, RefundDebitNote, ReverseDebitNote, RecordExpense.
8. **Events:** BillApproved, BillVoided, BillStatusChanged, DebitNoteCreated, DebitNoteIssued, DebitNoteApplied, DebitNoteHeld, DebitNoteRefunded, DebitNoteReversed, ExpenseRecorded.
9. **Repository:** BillRepository, DebitNoteRepository, DebitNoteQuery, ExpenseRepository (GetOpenPayable(byDocumentId)).
10–11. **Consistency/transaction:** mirror of Receivables (recognition + posting together; ApplySettlement in settlement tx).
12. **Optimistic concurrency:** version on the document (settlement hot path). *High risk.*
13. **Cross-aggregate refs (UUID):** VendorRef, SBU refs, rateRecordId, taxSnapshot refs, sourceBillId (DebitNote→Bill), journalEntryId(s).
14. **Size challenge:** right-sized. SBU split lives with the bill (its Σ=1.0 invariant). Vendor/allocations excluded.

---

## 5. Aggregate: LedgerAccount  *(Ledger & Posting)*

1. **Root:** LedgerAccount (a CoA account).
2. **Entities:** none.
3. **Value objects:** `AccountCode`, `AccountName`, `AccountType`, `EntityRef`(UUID), `BankAttributes`(optional: bank/SWIFT/routing/currency), `AccountStatus`(Active/Deactivated).
4. **Boundary:** the account definition only. **Balance is NOT in the aggregate.**
5. **Invariants:** type immutable once postings exist; delete only at zero history; deactivate = soft (hides from dropdowns, preserves postings); code unique within entity.
6. **Lifecycle:** Active → Deactivated.
7. **Commands:** CreateAccount, EditAccount(name/desc; type only if no postings), DeactivateAccount, DeleteAccount(zero history only).
8. **Events:** AccountCreated, AccountDeactivated.
9. **Repository:** AccountRepository (by DocumentId/AccountId; query GetAccountBalance = **derived** from posted lines).
10. **Consistency boundary:** strong within the account definition; balance is a derived read-model (sum of immutable posted lines).
11. **Transactional boundary:** account config commits standalone; postings never mutate the account aggregate.
12. **Optimistic concurrency:** version on config edits. *Low risk.*
13. **Cross-aggregate refs (UUID):** EntityRef, parentAccountId (future sub-accounts).
14. **Size challenge:** deliberately **small.** Excluding balance is the key call — a stored balance would make the account a hot, contended, drift-prone aggregate touched by every posting. Derived balance = zero drift, full auditability.

---

## 6. Consistency Statement

- **Correctness/auditability first:** balances derived from immutable postings; no mutable running totals except document `openBalance` (guarded, reconcilable).
- **Transactional model explicit:** recognition & settlement commit their document + ledger (+ allocation) **together**; projections/status are **eventual**.
- **Cross-aggregate references are UUID-only** throughout (Document ID from ADR-009 pays off here).
- **AP-001 upheld:** every cross-aggregate write goes through the owning context's application service (incl. `ApplySettlement`, `PostingService`).
- **Concurrency risk located:** Allocation + ReceivableDocument/PayableDocument on the settlement hot path (optimistic version); everything else low.
- **No business rule introduced or modified;** every invariant traces to a frozen BR/ADR/SRS clause.

**Ratified refinement:** §0.3 `ApplySettlement` — approved; Context Map updated.

---

## 7. Aggregate: Customer  *(Receivables)*
1. **Root:** Customer. 2. **Entities:** none. 3. **VOs:** Name, ContactInfo, Address, `CustomerType`(Local/Foreign), TaxId, DefaultCurrency, PaymentTerms, DefaultVATRate(derived from type), Status(Active/Deactivated), EntityRef. 4. **Boundary:** profile only (no invoices/activity — those are queries). 5. **Invariants:** type drives VAT default; soft-deactivate preserves history; unique within entity. **Changing type never alters historical invoices** (each invoice holds its own TaxSnapshot). 6. **Lifecycle:** Active → Deactivated. 7. **Commands:** CreateCustomer, UpdateCustomer, DeactivateCustomer. 8. **Events:** CustomerCreated, CustomerUpdated, CustomerDeactivated. 9. **Repo:** CustomerRepository. 10–11. Standalone consistency/transaction. 12. **Concurrency:** version (low). 13. **Refs (UUID):** EntityRef. 14. **Size:** small, correct; activity view is a projection.

## 8. Aggregate: Vendor  *(Payables)*
Mirror of Customer. **VOs add:** VAT/TIN (AIT tracking), BankDetails, DefaultCurrency/Terms. **Invariants:** soft-deactivate preserves history; unique within entity. **Commands:** CreateVendor, UpdateVendor, DeactivateVendor. **Events:** VendorCreated/Updated/Deactivated. **Refs:** EntityRef. **Size:** small, correct.

## 9. Aggregate: TaxCode (+versions) & TaxPack  *(Tax)*
### TaxCode
1. **Root:** TaxCode. 2. **Entities:** TaxCodeVersion (child, effective-dated). 3. **VOs:** Jurisdiction, `Treatment`(Standard/Zero-rated/Exempt), Rate, RecoverableFlag, CalcMethod, GLMapping, ReturnBoxMapping, EffectiveDates. 4. **Boundary:** code + all versions. 5. **Invariants:** version effective-dates non-overlapping; **transaction binds the version effective on its tax-point date**; versions immutable once used; zero-rated≠exempt (recoverability differs). 6. **Lifecycle:** version Added → Effective → Superseded (never deleted). 7. **Commands:** DefineTaxCode, AddVersion, ConfigureReturnMapping. 8. **Events:** TaxCodeDefined, TaxCodeVersioned. 9. **Repo:** TaxCodeRepository. 10–11. Config standalone; **determination produces a `TaxSnapshot` (VO, Published Language)** embedded in documents — not a mutation. 12. **Concurrency:** low; four-eyes on change (Identity). 13. **Refs (UUID):** GL account refs, jurisdiction. 14. **Size:** code+versions right; **do not** merge multiple codes.
### TaxPack
Small aggregate = a jurisdiction's active code set + return template. **Invariants:** Bangladesh = Pack #1; adding a country = new pack (config, no engine change). **Commands:** ConfigureTaxPack. **Events:** TaxPackConfigured.

## 10. Aggregate: RateRecord (+ RevaluationRun)  *(Currency & FX)*
### RateRecord
1. **Root:** RateRecord. 2. **Entities:** none. 3. **VOs:** CurrencyPair, Rate, EffectiveDate, Source, AuditStamp. 4. **Boundary:** one rate fact. 5. **Invariants:** **immutable once referenced**; effective-dated; override carries a reason. 6. **Lifecycle:** Added → Referenced(frozen). 7. **Commands:** AddRateRecord. 8. **Events:** RateRecordAdded. 9. **Repo:** RateRecordRepository. 12. Low concurrency. 13. **Refs:** currency pair. 14. **Size:** tiny, correct. *FXGainLossCalculation is a domain service (owns the formula), not an aggregate (AP-001).*
### RevaluationRun
Per entity × period: captures unrealised revaluation figures + next-period reversal linkage (audit). **Invariants:** run at Soft Close; reversed at next-period start. **Commands:** RunRevaluation. **Events:** UnrealisedFXRevalued, RevaluationReversed.

## 11. Aggregate: AccountingPeriod (+ FiscalCalendar)  *(Period & Close)*
### AccountingPeriod
1. **Root:** AccountingPeriod (entity × period). 2. **Entities:** PeriodTransition, CloseGateEvidence, LateAdjustmentLink. 3. **VOs:** PeriodRef, `State`(`Open`/`SoftClosed`/`HardClosed`/`Reopened`), VATLockStatus, ReasonCode, AuditStamp, CloseEvidenceSetHash. 4. **Boundary:** period state, VAT state, transition log, copied immutable successful close evidence, and late-adjustment links. Reporting and Reconciliation own evidence production. 5. **Invariants:** exactly one state; Soft Close repeatable; Reopened accepts approved adjustments only and requires role, reason, management approval, user notification, and re-close. Hard Close requires four-eyes and every mandatory immutable gate result. M5 supplies Trial Balance, P&L, Balance Sheet, and VAT-output evidence; M6 supplies bank-reconciliation evidence. Missing provider/evidence returns `422 close_gate_unmet` with no Period, VAT, Ledger, business-audit, or business-outbox mutation. VAT locking is atomic with successful Hard Close. VAT unlock is possible only within approved Reopen jurisdiction policy. Corrections to closed periods route to the permitted approved-adjustment period. 6. **Lifecycle:** Open → SoftClosed(↺) → HardClosed → Reopened → SoftClosed → HardClosed. No bypass transition exists. 7. **Commands:** OpenPeriod, SoftClose, HardClose, Reopen, RollYearEnd. Standalone LockVAT is removed; lock/unlock are transition effects. 8. **Events:** PeriodSoftClosed, PeriodHardClosed, PeriodReopened, VATPeriodLocked, VATPeriodUnlocked, YearEndRolled. `CloseGateFailed` is not a business event. 9. **Repo:** AccountingPeriodRepository; **Queries/contracts:** IsDatePostable (OHS), CloseGateProvider v1. 10–11. The Period application service evaluates providers, revalidates an identical accepted evidence set, and commits transition, copied evidence, VAT state, audit, idempotency, and outbox atomically. 12. **Concurrency:** versioned transitions; Hard Close and Reopen require four-eyes (Identity). 13. **Refs (UUID):** EntityRef, FiscalCalendar, external evidence UUID/hash only. 14. **Size:** right; evidence facts are copied, never produced by Period.
### FiscalCalendar
Small aggregate per entity that defines periods (Jul–Jun). **Commands:** ConfigureFiscalCalendar.

## 12. Aggregates: Entity, User, Role  *(Identity & Access)*
### Entity
**Root.** **VOs:** LegalName, FunctionalCurrency, FiscalYearConfig, `ApprovalPolicy`(config), Settings. **Invariants:** isolation/tenant boundary. **Commands:** CreateEntity, ConfigureApprovalPolicy. **Events:** EntityCreated, ApprovalPolicyConfigured. *(SBU dimension & bank-account master are Ledger-owned per the matrix — not here.)*
### User
**Root.** **VOs:** Name, Email, Status(Invited/Active/Deactivated), MFAConfig, EntityAccessGrants, RoleAssignments, Delegation(time-boxed). **Invariants:** default-deny; **last active Owner protected**; **MFA required for Owner & Finance Manager**; delegation/break-glass time-boxed & auto-expiring. **Lifecycle:** Invited → Active → Deactivated. **Commands:** InviteUser, ActivateUser, DeactivateUser, AssignRole, GrantEntityAccess, GrantDelegation, ActivateBreakGlass, RaiseSoDException. **Events:** UserInvited, UserDeactivated, RoleAssigned, DelegationGranted, BreakGlassActivated, SoDExceptionRaised. *(Credential hashing is infrastructure, referenced by User, not a domain VO.)*
### Role
**Root.** **Entities:** RolePermission (children). **Invariants:** system roles = minimum floors; **custom role ≤ granter's privileges**. **Commands:** CreateCustomRole, UpdateRole. **Events:** RoleCreated, RoleUpdated. **Concurrency:** low. **Refs (UUID):** EntityRef, RoleRefs.

## 13. Aggregate: BankReconciliation  *(Reconciliation)*
1. **Root:** BankReconciliation (bank account × statement/period). 2. **Entities:** StatementLine (children, with match status). 3. **VOs:** BankAccountRef, OpeningBalance, ClosingBalance, ImportSource, ColumnMapping, LineStatus(Unreconciled/Matched/Reconciled/Unexplained), AuditStamp. 4. **Boundary:** reconciliation + its lines (the ties-to-zero invariant spans all lines). 5. **Invariants:** complete only when all lines matched **and** statement ties to zero; duplicate/overlap detection; bank-only lines create entries **via owning-context application services** (AP-001), never direct writes. 6. **Lifecycle:** Imported → Matching → Completed. 7. **Commands:** ImportStatement, MatchLine, ConfirmMatch, CreateEntryForBankLine(→requests posting/settlement), CompleteReconciliation. 8. **Events:** StatementImported, LineMatched, ReconciliationCompleted. 9. **Repo:** BankReconciliationRepository. 10–11. Strong within reconciliation+lines; entry creation crosses via app services. 12. **Concurrency:** version (typically single-user per reconciliation). 13. **Refs (UUID):** BankAccountRef, matched transaction IDs. 14. **Size:** right — lines belong with the ties-to-zero invariant. **ACL** translates bank CSV formats inbound.

## 14. Aggregate: StagingBatch  *(Migration)*
1. **Root:** StagingBatch. 2. **Entities:** StagedRecord (children: staged open items / opening balances). 3. **VOs:** MigrationIdentifier, SourceSystem, ValidationResult, Status(Staged/Validated/Committed/Reset), ControlTotals, AuditStamp. 4. **Boundary:** batch + its staged records. 5. **Invariants:** **idempotent by MigrationIdentifier** (re-run = no duplicates); TB balances; **no live post until authorised final migration**; reset = zero ledger impact; foreign open items retain original currency + rate. 6. **Lifecycle:** Staged → (DryRun ↺) → Validated → Committed | Reset. 7. **Commands:** StageBatch, RunDryRun, ResetStaging, ExecuteFinalMigration(→invokes each target context's import application services). 8. **Events:** DryRunCompleted, StagingReset, ConversionPosted. 9. **Repo:** StagingBatchRepository. 10–11. Staging is isolated; final commit invokes target aggregates' import commands **(AP-001 — never writes their stores)**. 12. **Concurrency:** single migration process; version. 13. **Refs (UUID):** source refs, MigrationIdentifier map. 14. **Size:** right. **ACL** translates source schemas (Xero = Importer #1).

## 15. Aggregate: Sequence  *(Numbering — shared kernel)*
1. **Root:** Sequence (per {document type × scope}). 2. **Entities:** none. 3. **VOs:** SeriesPrefix, Scope(entity+fiscalYear), CurrentValue, GaplessPolicy, ResetPolicy. 4. **Boundary:** one counter. 5. **Invariants:** **atomic draw (no duplicates)**; statutory series gapless; numbers never reused; used-and-voided recorded; DocumentId (UUID) generated separately (identity ≠ number). 6. **Lifecycle:** continuous (optional fiscal-year reset). 7. **Commands:** DrawNumber(atomic), RecordVoidedNumber, ResetForFiscalYear. 8. **Events:** (internal). 9. **Repo:** SequenceRepository. 10–11. **⚠ Concurrency note:** the draw requires a **strongly-serialized atomic increment** (not optimistic-retry) to guarantee gapless + unique under concurrency — the sole aggregate needing strong serialization rather than optimistic concurrency. Well-understood pattern; noted, not a blocking risk. 13. **Refs (UUID):** scope (entity, fiscal year). 14. **Size:** tiny, correct.

## 16. Reporting & Cash View — NO write aggregates
Reporting owns **read models / projections** (TrialBalance, GeneralLedger, ProfitAndLoss, BalanceSheet, AR/AP Ageing, TaxSummary, **CashView**, FXRevaluation), materialised by consuming domain events. **No aggregate roots, no write invariants** (read-only). The **cash-view derivation** (the single documented algorithm from ADR-001) is the only owned logic. This is the CQRS read side.

## Group & Consolidation — deferred (ADR-010); no aggregates yet.

---

## Final Consistency Statement (all aggregates)

- **No business rule introduced or modified;** every invariant traces to a frozen BR/ADR/SRS clause.
- **AP-001 upheld everywhere:** all cross-aggregate writes go through the owning context's application service (`ApplySettlement`, `PostingService`, migration import commands, reconciliation entry requests).
- **Cross-aggregate references are UUID-only** (Document ID / entity / party / account / rate).
- **Consistency model:** recognition & settlement commit document + ledger (+ allocation/openBalance) **together**; projections/status **eventual**; balances **derived** (except guarded document `openBalance`).
- **Concurrency located:** strong — Allocation + document aggregates (optimistic version) and Sequence (atomic serialization); low — everything else.
- **Aggregate sizing:** each challenged for too-large/too-small; balances excluded from LedgerAccount; CreditTranche authority and the PartyCreditBalance projection remain separate from documents, as do Customer/Vendor; document+lines stay together for their balance/tax invariants.
- **No contradiction, no AP-001 violation, no unresolved major risk** discovered.

**Aggregate Design is complete and internally consistent.**
