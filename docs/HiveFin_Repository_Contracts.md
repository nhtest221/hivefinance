# HiveFin — Repository Contracts

**Frozen inputs (immutable):** Domain Model v2 · Context Map · Aggregate Design · Domain Events · AP-001.
**Rule:** domain-oriented, persistence-agnostic interfaces. **No ORM concepts** in the domain layer. If persistence exposed a domain weakness it would be reported, not patched — **none found.**

---

## 0. Repository Principles (apply to all)

- **Aggregate-root granularity.** A repository loads/saves **one aggregate root and its children as a whole** — never partial internals, never child entities standalone.
- **Persistence-agnostic contracts.** Methods speak the domain (`GetById`, `Save`, `Add`); no SQL, ORM, session, or table concepts leak into the interface.
- **Context-private.** A repository belongs to exactly one bounded context. **No context ever references another context's repository** — cross-context data flows through the owning context's query/command application services (AP-001).
- **Optimistic concurrency by default.** Every mutable aggregate carries a `version`; `Save` requires the expected version and raises `ConcurrencyConflict` on mismatch. **Exception:** `SequenceRepository` (serialized atomic draw).
- **Unit of Work at the application layer.** Repositories enlist in a UoW that the *application service* owns; the UoW = the transactional boundary from Aggregate Design §0. Repositories never open their own transactions.
- **Reliable events.** On commit, a repository persists the aggregate's domain events to the **outbox** in the same UoW; dispatch is post-commit (eventual tier).
- **Whole-aggregate loading.** Root + children load together (a bounded set). **No lazy cross-aggregate loading** — cross-aggregate references are UUIDs resolved via services.
- **Reads vs writes.** Write repositories return aggregates; **Reporting uses separate persistence-agnostic Query interfaces** returning read-model DTOs.

---

## 1. High-risk repositories (full treatment)

### JournalEntryRepository *(Ledger)*
```
GetById(entryId): JournalEntry | null
Save(entry)                       // version-checked; append-only for Posted
FindByAccount(accountId, dateRange, status): JournalEntry[]
FindBySourceDocument(documentId): JournalEntry[]
```
1. **Interface:** above. 2. **Persistence:** entry + all lines as one unit; **Posted rows are never updated** (immutability enforced at the store); corrections are new entries. 3. **Queries:** by account, period, status, source document. 4. **Transaction boundary:** enlists in the recognition/settlement UoW (commits with the triggering document). 5. **Optimistic concurrency:** version (guards Draft edits only; Posted is immutable → no contention). 6. **Locking:** none. 7. **Loading:** whole entry + lines. 8. **Cross-context:** internal to Ledger; other contexts post via `PostingService`, never this repo. 9. **UoW:** owned by the coordinating application service. 10. **Performance:** account-balance queries do **not** load entries into aggregates — served by a balance projection (§ Reporting); line queries indexed by (accountId, date), (sourceDocumentId).

### AllocationRepository *(Settlement)*
```
GetById(allocationId): Allocation | null
Save(allocation)                  // version-checked; Posted immutable
FindByDocument(documentId): Allocation[]
FindByParty(partyId, dateRange): Allocation[]
```
1. **Interface:** above (+ `CreditTrancheRepository`). 2. **Persistence:** allocation + links as one unit; Posted immutable; credit consumption/restoration facts append-only. 3. **Queries:** by document, party, date, bank account. 4. **Transaction boundary:** the **settlement UoW** — Allocation + `Invoice/Bill.ApplySettlement` + settlement `JournalEntry` + exact CreditTranche consumptions/restorations and projection updates commit together. 5. **Optimistic concurrency:** **highest-risk** — allocation version, document `expectedVersion`, and every selected CreditTranche `expectedVersion` are checked; a mismatch fails the whole UoW and never triggers source substitution. 6. **Locking:** none (optimistic + version guard). 7. **Loading:** allocation + links; named tranche sources and immutable consumption records only when the command requires them. 8. **Cross-context:** reads document open balance via `GetOpenReceivable/Payable`; writes document via `ApplySettlement`; invokes FX for calculations — never accesses another context's repository. 9. **UoW:** settlement application service. 10. **Performance:** indexed by (documentId), (partyId, date), and tranche party/currency/remainder; links bounded by a real deposit.

### CreditTrancheRepository *(Settlement)*
```
GetById(creditTrancheId): CreditTranche | null
FindByParty(entityId, partyType, partyId, currency, cursor): CreditTranchePage
Consume(creditTrancheId, amount, expectedVersion, allocationId): CreditConsumption
RecordConsumption(consumption): void
Restore(originalConsumptionId, expectedVersion, reversalAllocationId): CreditConsumption
```
1. **Authority:** immutable CreditTranche source facts and append-only CreditConsumption/restoration facts are consumption authority; PartyCreditBalance is a rebuildable query projection only. 2. **Selection:** application and refund commands supply every `credit_tranche_id`, amount, and `expected_version`; the repository never chooses a source and implements no FIFO, LIFO, weighted-average, pro-rata, automatic, or shortcut policy. 3. **Concurrency:** `Consume` and `Restore` conditionally advance only the named tranche version and projected remainders; all named guards succeed or the Settlement UoW rolls back. 4. **FX values:** the source RateRecord and recorded transaction/functional carrying values are immutable; application comparison uses the target document RateRecord and refund comparison uses the refund RateRecord through the FX-owned internal contract. 5. **Reversal:** `Restore` appends one restoration linked uniquely to the exact original consumption and restores its recorded values to the same source tranche without recalculation. 6. **Security/boundaries:** all operations require entity, party type, party, and currency agreement; RateRecord, party, and document references are UUID contracts with no cross-context repository or foreign-key access.

### InvoiceRepository / CreditNoteRepository *(Receivables)*
```
GetById(invoiceId): Invoice | null
Save(invoice)                     // version-checked; Issued immutable except openBalance/status via ApplySettlement
GetOpenReceivable(documentId): { openBalance, version }   // Partnership query
FindByCustomer(customerId, status): Invoice[]
FindOverdue(asOf): Invoice[]

CreditNoteRepository
  GetById(noteId): CreditNote | null
  AddDraft(note): void
  SaveDraft(note, expectedVersion): void
  CommitPost(note, expectedVersion): void
  AppendDisposition(note, disposition, expectedVersion): void
  CommitReversal(note, reversal, expectedVersion): void
  FindReversal(noteId): NoteReversal | null

CreditNoteQuery
  GetDetail(entityId, noteId): CreditNoteDetail | null
  Search(entityId, filters, signedCursor, limit): CreditNotePage
```
1. **Interface:** above. 2. **Persistence:** Invoice document + lines; `openBalance`/status mutate **only** through `ApplySettlement`/void; the rest immutable once Issued. CreditNote Drafts are editable; Posted note facts, TaxSnapshots, RateRecord references, dispositions, applications, and reversals are append-only except guarded current-state projections. 3. **Queries:** open receivable (hot path), by customer, overdue, by period, and signed-cursor note detail/search. 4. **Transaction boundary:** recognition, note posting/disposition/reversal, and settlement UoWs. 5. **Optimistic concurrency:** document/note version plus each relevant target-document and CreditTranche `expected_version`; any mismatch fails the UoW. 6. **Locking:** none. 7. **Loading:** whole aggregate; named tranche refs only for a held-source operation. 8. **Cross-context:** exposes `GetOpenReceivable`/`ApplySettlement` to Settlement; invokes Ledger, Tax, FX, Period, Numbering, and Settlement through owning services only. The repository never selects a document or tranche and implements no FIFO/LIFO/weighted-average/pro-rata/automatic policy. 9. **UoW:** application service. 10. **Amounts:** aggregate/read DTOs expose exactly `posted_amount`, `applied_amount`, `refunded_amount`, `held_remaining_amount`, and `undisposed_amount`; no current-state alias `held_amount` or `remaining_amount`. Historical holds may use operation-specific `transferred_amount`.

### BillRepository / DebitNoteRepository / ExpenseRepository *(Payables)*
Mirror of Receivables, including explicit `DebitNoteRepository` and `DebitNoteQuery` methods equivalent to the CreditNote contracts above and the exact same five current-state amount names. **Adds:** SBU split (Σ=1.0000) and AIT/VDS persisted with the bill; `GetOpenPayable(documentId)` for the Partnership; expense `settlementType` drives cash vs accrued. Same concurrency, no-selection, UoW, loading, cross-context, immutability, and performance rules as Receivables.

### AccountingPeriodRepository and CloseGateProvider *(Period)*
```
AccountingPeriodRepository
  GetById(periodId): AccountingPeriod | null
  Search(entityId, filters, signedCursor, limit): AccountingPeriodPage
  SaveTransition(period, transition, expectedVersion): void
  AppendCloseEvidence(periodId, closeAttemptId, evidenceSet): void
  AppendLateAdjustmentLink(link): void

CloseGateProvider v1
  Evaluate(contractVersion, entityId, periodId, periodRef, gateType, correlationId, evaluatedAt): CloseGateResult
```
The repository loads only Period-owned state, transition history, copied accepted evidence, and late-adjustment links. `CloseGateProvider` is an internal versioned consumer contract implemented by Reporting/Reconciliation adapters; it returns immutable evidence metadata but never an external aggregate or ORM model. Missing, failed, stale, malformed, or changed evidence is `unmet`. Successful Hard Close conditionally saves the transition and exact accepted evidence set in one UoW. An unmet gate saves no business state and produces no `CloseGateFailed` outbox event.

---

## 2. Remaining repositories (consolidated)

| Repository (context) | Key methods | Concurrency | Locking | Loading | Cross-context | Performance |
|---|---|---|---|---|---|---|
| **CreditTrancheRepository** (Settlement) | GetById; FindByParty; Consume; RecordConsumption; Restore | per selected tranche version | none | named sources + immutable facts | FX/document access only via contracts | index (entityId, partyId, currency, remainder) |
| **PartyCreditBalanceProjection** (Settlement read model) | GetByParty; Rebuild | projection version only; never authorizes consumption | none | per-currency totals | Settlement query only | index (entityId, partyType, partyId, currency) |
| **LedgerAccountRepository** (Ledger) | GetById; FindByEntity; Save | version (config) | none | whole (no balance) | account list via query; balance via projection | index (entityId, code) |
| **CustomerRepository** (Receivables) | GetById; Search; Save | version | none | whole | via Receivables | index (entityId, name/taxId) |
| **VendorRepository** (Payables) | GetById; Search; Save | version | none | whole | via Payables | index (entityId, name/TIN) |
| **TaxCodeRepository** (Tax) | GetById; GetApplicable(jurisdiction, taxPointDate); Save | version (config) | none | code + versions | Tax exposes DetermineTax → snapshot | index (jurisdiction, effectiveDate) |
| **TaxPackRepository** (Tax) | GetByJurisdiction; Save | version | none | whole | Tax only | small |
| **RateRecordRepository** (FX) | GetRate(pair, date); Add | append-only (immutable once referenced) | none | single | referenced via ExchangeRateReference | index (pair, effectiveDate) |
| **RevaluationRunRepository** (FX) | GetByPeriod; Save | version | none | whole | FX only | small |
| **AccountingPeriodRepository** (Period) | GetById; GetByRef; Search; IsDatePostable; SaveTransition; AppendCloseEvidence; AppendLateAdjustmentLink | version | none | period + transitions + accepted evidence + late links | OHS/query and CloseGateProvider adapters only | index (entityId, periodRef) |
| **FiscalCalendarRepository** (Period) | GetByEntity; Save | version | none | whole | Period only | small |
| **EntityRepository** (Identity) | GetById; Save | version | none | whole | isolation root | small |
| **UserRepository** (Identity) | GetById; FindByEmail; Save | version | none | user + grants/roles refs | authz via service | index (email), (entityId) |
| **RoleRepository** (Identity) | GetById; FindByEntity; Save | version | none | role + permissions | via Identity | small |
| **ReconciliationAccountRepository** (Reconciliation, `M6-GOV-001`) | GetById; FindByEntity; Save | version (config) | none | whole (no balance) | referenced by BankReconciliation and ReconciliationCloseGateProvider; never a second Ledger account | index (entityId, ledgerAccountId) |
| **StagingBatchRepository** (Migration) | GetById; FindByMigrationId; Save | version | none | batch + staged records | final post via target app services | idempotent upsert by MigrationIdentifier |
| **SequenceRepository** (Numbering) | **DrawNext(series, scope)**; RecordVoided; Reset | **serialized atomic** (not optimistic) | **atomic increment / short lock** | single counter | shared kernel; called by Rec/Pay/Settlement | ⚠ the one serialized hotspot; tiny row, fast |

---

## 3. Reporting — Query Interfaces and ReportRun (`M5-GOV-001`)

Persistence-agnostic query contracts returning read-model DTOs (built from events/projections, or from Ledger's own read contracts where noted):
```
TrialBalanceQuery(entityId, asOf, periodRef?, sbu?)              // adapter over Ledger's existing LedgerReportService — not migrated (§3.1)
GeneralLedgerQuery(entityId, accountId, dateRange, sbu?, cursor, limit)  // adapter over Ledger's existing LedgerReportService — not migrated (§3.1)
ProfitAndLossQuery(entityId, periodRef, sbu?, basis, compareTo?)  // Reporting-owned
BalanceSheetQuery(entityId, asOf, sbu?, compareTo?)               // Reporting-owned
ARAgeingQuery(entityId, asOf, customerId?)                        // Reporting-owned
APAgeingQuery(entityId, asOf, vendorId?)                          // Reporting-owned
TaxSummaryQuery(entityId, periodRef)                              // Reporting-owned; accrual only
FXRevaluationQuery(entityId, periodRef)                           // Reporting-owned
CashViewQuery(entityId, periodRef, sbu?)                          // Reporting-owned
AccountBalanceQuery(accountId, asOf)               // serves the derived balance; Ledger-owned, unchanged
```
No writes; no domain invariants beyond `ReportRun` below; cash view via the single documented algorithm (ADR-001, resolved by `M5-GOV-001`).

### 3.1 Reporting/Ledger context boundary

`TrialBalanceQuery` and `GeneralLedgerQuery` are **adapters**, not new computations: they call Ledger's already-implemented, tested `LedgerReportService::trialBalance()`/`generalLedger()` in-process and return its result unchanged. **This proposal amendment does not migrate or rewrite that implementation.** Ledger continues to own Ledger facts and these two read contracts; Reporting must not read `journal_lines`/`ledger_accounts` directly where this adapter already exists. Every other query above, plus `ReportRun` and its configuration providers below, is Reporting-owned outright. A future context extraction (moving Trial Balance/General Ledger fully into Reporting) requires a separate governance decision.

### 3.2 ReportRun (Reporting's one write aggregate)

```text
ReportRunRepository
  GetById(reportRunId): ReportRun|null
  AddGenerated(run): void
  CommitApproval(run, expectedVersion): void            // sets Approved + supersession of the prior Approved run for the same key, one UoW
  CommitRejection(run, expectedVersion): void
  Search(entityId, filters, cursor, limit): ReportRunPage
  FindCurrentApproved(entityId, reportType, basis, periodKey): ReportRun|null   // Close-Gate lookup (API Contracts §13.12)

ReportLayoutProvider           GetEffective(entityId|global, reportType, atDate): ReportLayout|null
AccountClassificationProvider  GetEffective(entityId|global, atDate): AccountClassificationMap|null
AgeingBucketProvider           GetEffective(entityId|global, atDate): AgeingBucketSet|null
CashViewPolicyProvider         GetEffective(entityId|global, atDate): CashViewPolicy|null

ReportingCloseGateProvider implements CloseGateProvider   // Period-owned interface, unchanged (API Contracts §12.7, §13.12)
```

`ReportRunRepository` implements no business rule beyond the version-guarded conditional save and the atomic supersession commit; it returns no ORM model to another context. `CommitApproval` enforces that the generator (maker) cannot also be the approver (checker) — the same maker/checker separation every M2–M4 approval command already uses.

---

## 4. Reconciliation — BankReconciliation (`M6-GOV-001`)

```text
BankReconciliationRepository
  GetById(reconciliationId): BankReconciliation|null
  FindCurrentCompleted(entityId, reconciliationAccountId, periodRef): BankReconciliation|null   // Close-Gate lookup (API Contracts §14.11)
  Search(entityId, filters, cursor, limit): BankReconciliationPage
  AddDraft(reconciliation): void
  AppendImportedLines(reconciliationId, batch, lines, expectedVersion): ImportResult            // rejects duplicate-identity lines, never silently skips (§14.5)
  ReplaceSuggestions(reconciliationId, lineId, suggestions, expectedLineVersion): void
  CommitMatch(reconciliationId, lineId, allocationIds, expectedLineVersion): void
  CommitConfirm(reconciliationId, lineId, expectedLineVersion): void                            // marks the consumed Allocation(s); a consumed Allocation cannot be reconciled twice
  CommitBankOnlyResolution(reconciliationId, lineId, journalEntryId, expectedLineVersion): void  // called post-approval; entry itself is posted via Ledger PostingService, not here
  CommitCompletion(reconciliationId, expectedVersion): void                                      // sets Completed, freezes source_data_watermark + content_hash, one UoW
  CommitReopen(reconciliationId, expectedVersion): void                                          // sets Reopened; does not revert any line's own status

ReconciliationCloseGateProvider implements CloseGateProvider   // Period-owned interface, unchanged (API Contracts §12.7, §14.11)
```

1. **Interface:** above. 2. **Persistence:** batch + its `StatementLine`s + their attached `MatchSuggestion`s as one bounded set; a `Reconciled` line and a `Completed` batch's frozen fields are protected at the store (Database Design `reconciliation`, PostgreSQL immutability triggers). 3. **Queries:** by account, period, state; `FindCurrentCompleted` serves `ReconciliationCloseGateProvider` exactly as `ReportRunRepository::FindCurrentApproved` serves `ReportingCloseGateProvider` (§3.2). 4. **Transaction boundary:** each command (`ImportStatement`, `MatchLine`, `ConfirmMatch`, `CompleteReconciliation`, `ReopenReconciliation`) commits in its own UoW; `CreateEntryForBankLine`'s approved commit additionally enlists Ledger's `PostingService` UoW for the new `JournalEntry`, coordinated by the Reconciliation application service (AP-001 — Reconciliation never opens Ledger's own transaction directly, it invokes the service). 5. **Optimistic concurrency:** the batch carries its own `version` (guards `CompleteReconciliation`/`ReopenReconciliation`); each `StatementLine` carries an independent `version` (guards `MatchLine`/`ConfirmMatch`/`CreateEntryForBankLine` on that line only, so concurrent work on different lines of the same batch never spuriously conflicts). 6. **Locking:** none. 7. **Loading:** whole reconciliation + lines + suggestions; match-candidate `Allocation`s are read through Settlement's own query contract, never loaded as part of this aggregate. 8. **Cross-context:** reads `Allocation` facts via Settlement's query contract and Ledger facts (staleness recheck, §14.11) via Ledger's query contracts; writes reach Ledger only through `PostingService` (AP-001) — never a direct `settlement_allocations`/`journal_lines`/`ledger_accounts` write. 9. **UoW:** owned by the Reconciliation application service (and jointly with Ledger's `PostingService` UoW for bank-only entries, per point 4). 10. **Performance:** duplicate-identity lookups indexed at the DB (Database Design `reconciliation`); match-suggestion generation batches its `Allocation` candidate query by `(reconciliationAccountId, currency, dateWindow)`, not per-line.

---

## 5. Cross-Context Access Rules (AP-001)

- A context uses **only its own repositories.**
- Cross-context **reads** → the owning context's **Query** (e.g., `GetOpenReceivable`).
- Cross-context **writes** → the owning context's **application service/command** (e.g., `ApplySettlement`, `PostingService`, migration import services, reconciliation entry requests).
- **No repository returns another context's aggregate.** No shared repositories except the Numbering shared kernel (draw only).

---

## 6. Unit of Work Boundaries (per flow)

| Flow | Aggregates in one UoW | Owner |
|---|---|---|
| Invoice issuance (recognition) | Invoice + JournalEntry | Receivables app service |
| Bill approval (recognition) | Bill + JournalEntry | Payables app service |
| Customer receipt / vendor payment (settlement) | Allocation + document ApplySettlement + JournalEntry + created CreditTranche(s), when unapplied | Settlement app service |
| Party-credit application/refund/reversal | Allocation + exact CreditTranche consumption/restoration facts + projection + document ApplySettlement or bank movement + JournalEntry + FX result, when foreign | Settlement app service |
| Credit/debit note post/disposition/reversal | Note + exact document/CreditTranche effects + JournalEntry/FX result when applicable | Receivables/Payables app service through owning contracts |
| Period close | AccountingPeriod + immutable accepted close evidence + atomic VAT state + audit/outbox | Period app service |
| ReportRun generation | ReportRun (Generated) + audit/outbox | Reporting app service |
| ReportRun approval | ReportRun (Approved, `reviewed_by`/`approved_by` set atomically) + supersession of the prior Approved run for the same key + audit/outbox | Reporting app service |
| FX revaluation | RevaluationRun + JournalEntry(s) | FX/Period app service |
| Bank-only entry (approved) | BankReconciliation (line resolved) + JournalEntry, posted via Ledger `PostingService` + audit/outbox | Reconciliation app service, jointly with Ledger |
| Reconciliation completion | BankReconciliation (Completed, frozen `source_data_watermark`/`content_hash`) + audit/outbox | Reconciliation app service |
| Migration final post | StagingBatch + target import services (idempotent, chunkable) | Migration app service |

Projection/notification updates are **outside** these UoWs (eventual, via outbox events).

---

## 7. Validation
- **Persistence-agnostic & no ORM in domain:** ✔ interfaces are domain verbs only.
- **AP-001:** ✔ context-private repositories; cross-context via services; no foreign-aggregate returns.
- **Consistency model honoured:** ✔ UoW = the frozen transactional boundaries; strong where required, eventual otherwise.
- **Concurrency:** ✔ optimistic everywhere except the Sequence serialized draw.
- **No domain change; no weakness discovered.**

**Repository Contracts complete.**
