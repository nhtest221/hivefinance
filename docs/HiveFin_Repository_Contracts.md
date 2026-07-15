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
1. **Interface:** above (+ `PartyCreditBalanceRepository`). 2. **Persistence:** allocation + links as one unit; Posted immutable. 3. **Queries:** by document, party, date, bank account. 4. **Transaction boundary:** the **settlement UoW** — Allocation + `Invoice/Bill.ApplySettlement` + settlement `JournalEntry` (+ PartyCreditBalance) commit together. 5. **Optimistic concurrency:** **highest-risk** — allocation version **plus** the document's `expectedVersion` passed to `ApplySettlement`; on document-version mismatch the whole UoW retries against the fresh open balance (strong no-over-allocation). 6. **Locking:** none (optimistic + version guard). 7. **Loading:** allocation + links. 8. **Cross-context:** reads document open balance via `GetOpenReceivable/Payable`; writes document via `ApplySettlement` — never via a Receivables/Payables repository. 9. **UoW:** settlement application service. 10. **Performance:** indexed by (documentId), (partyId, date); links bounded by a real deposit.

### InvoiceRepository / CreditNoteRepository *(Receivables)*
```
GetById(invoiceId): Invoice | null
Save(invoice)                     // version-checked; Issued immutable except openBalance/status via ApplySettlement
GetOpenReceivable(documentId): { openBalance, version }   // Partnership query
FindByCustomer(customerId, status): Invoice[]
FindOverdue(asOf): Invoice[]
```
1. **Interface:** above. 2. **Persistence:** document + lines; `openBalance`/status mutate **only** through `ApplySettlement`/void; the rest immutable once Issued. 3. **Queries:** open receivable (hot path), by customer, overdue, by period. 4. **Transaction boundary:** recognition UoW (issue) and settlement UoW (ApplySettlement). 5. **Optimistic concurrency:** **document version** — the guard `ApplySettlement(amount, expectedVersion)` checks (no-over-allocation). 6. **Locking:** none. 7. **Loading:** document + lines. 8. **Cross-context:** exposes `GetOpenReceivable`/`ApplySettlement` to Settlement; consumes Tax/FX via services. 9. **UoW:** app service. 10. **Performance:** `GetOpenReceivable` indexed by documentId (single-row read); ageing served by projection.

### BillRepository / DebitNoteRepository / ExpenseRepository *(Payables)*
Mirror of Receivables. **Adds:** SBU split (Σ=1.0000) and AIT/VDS persisted with the bill; `GetOpenPayable(documentId)` for the Partnership; expense `settlementType` drives cash vs accrued. Same concurrency/UoW/loading/cross-context/performance profile as Receivables.

---

## 2. Remaining repositories (consolidated)

| Repository (context) | Key methods | Concurrency | Locking | Loading | Cross-context | Performance |
|---|---|---|---|---|---|---|
| **PartyCreditBalanceRepository** (Settlement) | GetByParty; Save | version (concurrent draws) | none | whole | via Settlement only | index (partyId, entityId) |
| **LedgerAccountRepository** (Ledger) | GetById; FindByEntity; Save | version (config) | none | whole (no balance) | account list via query; balance via projection | index (entityId, code) |
| **CustomerRepository** (Receivables) | GetById; Search; Save | version | none | whole | via Receivables | index (entityId, name/taxId) |
| **VendorRepository** (Payables) | GetById; Search; Save | version | none | whole | via Payables | index (entityId, name/TIN) |
| **TaxCodeRepository** (Tax) | GetById; GetApplicable(jurisdiction, taxPointDate); Save | version (config) | none | code + versions | Tax exposes DetermineTax → snapshot | index (jurisdiction, effectiveDate) |
| **TaxPackRepository** (Tax) | GetByJurisdiction; Save | version | none | whole | Tax only | small |
| **RateRecordRepository** (FX) | GetRate(pair, date); Add | append-only (immutable once referenced) | none | single | referenced via ExchangeRateReference | index (pair, effectiveDate) |
| **RevaluationRunRepository** (FX) | GetByPeriod; Save | version | none | whole | FX only | small |
| **AccountingPeriodRepository** (Period) | GetByRef; IsDatePostable; Save | version | none | period + transition log | OHS query to all | index (entityId, periodRef) |
| **FiscalCalendarRepository** (Period) | GetByEntity; Save | version | none | whole | Period only | small |
| **EntityRepository** (Identity) | GetById; Save | version | none | whole | isolation root | small |
| **UserRepository** (Identity) | GetById; FindByEmail; Save | version | none | user + grants/roles refs | authz via service | index (email), (entityId) |
| **RoleRepository** (Identity) | GetById; FindByEntity; Save | version | none | role + permissions | via Identity | small |
| **BankReconciliationRepository** (Reconciliation) | GetById; FindByAccount; Save | version | none | reconciliation + lines | reads txns via queries; posts via app services | index (bankAccountId, period) |
| **StagingBatchRepository** (Migration) | GetById; FindByMigrationId; Save | version | none | batch + staged records | final post via target app services | idempotent upsert by MigrationIdentifier |
| **SequenceRepository** (Numbering) | **DrawNext(series, scope)**; RecordVoided; Reset | **serialized atomic** (not optimistic) | **atomic increment / short lock** | single counter | shared kernel; called by Rec/Pay/Settlement | ⚠ the one serialized hotspot; tiny row, fast |

---

## 3. Reporting — Query Interfaces (read side, no aggregates)

Persistence-agnostic query contracts returning read-model DTOs (built from events/projections):
```
TrialBalanceQuery(entityId, asOf, basis)          GeneralLedgerQuery(accountId, dateRange)
ProfitAndLossQuery(entityId, period, sbu, basis)  BalanceSheetQuery(entityId, asOf)
ARAgeingQuery / APAgeingQuery(entityId, asOf)      TaxSummaryQuery(entityId, period)   // accrual only
CashViewQuery(entityId, period)                    FXRevaluationQuery(entityId, period)
AccountBalanceQuery(accountId, asOf)               // serves the derived balance
```
No writes; no domain invariants; cash view via the single documented algorithm (ADR-001).

---

## 4. Cross-Context Access Rules (AP-001)

- A context uses **only its own repositories.**
- Cross-context **reads** → the owning context's **Query** (e.g., `GetOpenReceivable`).
- Cross-context **writes** → the owning context's **application service/command** (e.g., `ApplySettlement`, `PostingService`, migration import services, reconciliation entry requests).
- **No repository returns another context's aggregate.** No shared repositories except the Numbering shared kernel (draw only).

---

## 5. Unit of Work Boundaries (per flow)

| Flow | Aggregates in one UoW | Owner |
|---|---|---|
| Invoice issuance (recognition) | Invoice + JournalEntry | Receivables app service |
| Bill approval (recognition) | Bill + JournalEntry | Payables app service |
| Customer receipt (settlement) | Allocation + Invoice(ApplySettlement) + JournalEntry (+ PartyCreditBalance) | Settlement app service |
| Vendor payment (settlement) | Allocation + Bill(ApplySettlement) + JournalEntry | Settlement app service |
| Credit note | CreditNote + JournalEntry | Receivables app service |
| Period close | AccountingPeriod (+ emitted locks) | Period app service |
| FX revaluation | RevaluationRun + JournalEntry(s) | FX/Period app service |
| Reconciliation entry | BankReconciliation + (requested) JournalEntry | Reconciliation app service |
| Migration final post | StagingBatch + target import services (idempotent, chunkable) | Migration app service |

Projection/notification updates are **outside** these UoWs (eventual, via outbox events).

---

## 6. Validation
- **Persistence-agnostic & no ORM in domain:** ✔ interfaces are domain verbs only.
- **AP-001:** ✔ context-private repositories; cross-context via services; no foreign-aggregate returns.
- **Consistency model honoured:** ✔ UoW = the frozen transactional boundaries; strong where required, eventual otherwise.
- **Concurrency:** ✔ optimistic everywhere except the Sequence serialized draw.
- **No domain change; no weakness discovered.**

**Repository Contracts complete.**
