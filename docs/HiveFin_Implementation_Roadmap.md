# HiveFin — Implementation Roadmap (Modular Monolith, Multi-Agent)

**Frozen inputs (immutable):** SRS v3.0 · ADR register (`HiveFin_Decision_Log.md`, ADR-001…ADR-009) · Domain Model v2 · Context Interaction Matrix · AP-001 · Context Map · Aggregate Design · Domain Events · Repository Contracts · Database Design.
**Objective:** translate the architecture into incremental, parallelizable phases so multiple coding agents build simultaneously without violating the frozen architecture.
**Organizing rule:** **context = agent = deployable module = schema.** Cross-context work happens only through frozen application-service interfaces and event schemas.

---

## 1. Context Dependency Graph (topological layers)

```
Layer 0 (no deps):  PLATFORM (outbox, event bus, UoW, audit, entity isolation)
                    IDENTITY · PERIOD · NUMBERING · TAX · CURRENCY&FX
Layer 1:            LEDGER & POSTING            (needs Identity, Period)
Layer 2:            RECEIVABLES · PAYABLES      (need Ledger, Tax, FX, Numbering, Identity, Period)
Layer 3:            SETTLEMENT                  (Partnership with Rec/Pay; needs FX, Ledger, Numbering)
Layer 4:            RECONCILIATION · MIGRATION  (need Ledger, Rec, Pay, Settlement)
Cross-cutting:      REPORTING                   (grows continuously as events appear)
```
No cycles (synchronous). Reporting is a continuous parallel track.

---

## 2. Recommended Implementation Order (phases)

| Phase | Contexts | Rationale |
|---|---|---|
| **M0 Platform + Walking Skeleton** | Platform, Identity, Period, Numbering | Cross-cutting spine + thinnest end-to-end path (a manual journal) proves the architecture |
| **M1 Ledger + Valuation** | Ledger, Tax, FX | The posting core and its upstream valuation services |
| **M2 Documents** | Receivables, Payables | Invoice/bill issuance → recognition postings |
| **M3 Settlement** | Settlement | Receipts/payments, `ApplySettlement`, realised FX, withholding |
| **M4 — Corrections, Notes and Period Close Foundations** | Credit/Debit Notes, corrections, Period lifecycle and close-gate foundations | M4A notes/corrections; M4B period lifecycle and evidence-gated close foundations |
| **M5 — Reporting and Cash View** | Reporting (TB, GL, P&L, BS, ageing, tax, cash view) | Read side matured on real events; M5A financial statements, M5B Cash View and Close-Gate evidence |
| **M6 Reconciliation** | Reconciliation | CSV import, matching |
| **M7 Migration + Parallel Run** | Migration | Idempotent conversion, dry-run, parallel run vs Xero |
| **M8 Hardening + Go-Live** | all | Security, DR, UAT, CPA sign-off, cutover |

Reporting projections begin in M1 and grow each phase.

---

## 3. Vertical Slices for MVP

The **walking skeleton** (M0) is the thinnest slice through every layer:
- **Slice 0 — Post a manual journal:** login → authz → period check → balanced journal → immutable post → audit → outbox event → GL query. Proves Identity, Period, Ledger, audit, outbox, UoW, immutability end-to-end.

Subsequent slices (each end-to-end: domain→repo→DB→API→UI):
- **S1 Issue invoice** (Tax determination + FX rate + Numbering + recognition posting).
- **S2 Record receipt** (Settlement Partnership: query balance + `ApplySettlement` + realised FX + withholding).
- **S3 Create & approve bill** (SBU split, AIT/VDS, recognition).
- **S4 Record payment.**
- **S5 Credit note** (VAT in note period).
- **S6 Period soft/hard close** (lock, VAT lock).
- **S7 Reports** (TB, GL, P&L, ageing, tax summary, cash view).
- **S8 Bank reconciliation.**
- **S9 Migration cutover.**

Each slice is independently demoable and testable.

---

## 4. Database Migration Order

1. Platform: `audit_log`, `outbox` (per schema), entity isolation scaffolding.
2. `identity`, `period` (+ fiscal_calendar), `numbering`.
3. `ledger` (account, journal_entry/line, sbu, balance projection).
4. `tax`, `fx`.
5. `receivables` (customer, invoice/line, credit_note).
6. `payables` (vendor, bill/line, sbu_allocation, debit_note, expense).
7. `settlement` (allocation/link, party_credit_balance).
8. `reconciliation`, `migration` (staging).
9. `reporting` projections/MVs (incremental).

Migrations are additive and per-schema; each context migrates independently (no cross-context FKs).

---

## 5. API Implementation Order

Auth/session → Ledger (journal, CoA) → Invoices → Receipts → Bills → Payments → Credit/Debit Notes → Period (close) → Reports (queries) → Reconciliation → Migration → Settings (tax codes, users/roles, SBU, rates). *(Public HTTP API only; cross-context application services stay in-process.)*

---

## 6. Frontend Implementation Order

Login/MFA → App shell + entity switcher + dashboard skeleton → Invoices (+PDF) → Receipts → Bills/Expenses → Journal → Chart of Accounts → Reports → Period close → Reconciliation → Settings (tax, users/roles, SBU, rates) → Migration console. Frontend builds against **API Contracts** with mock data, decoupled from backend timing.

---

## 7. Test Strategy per Phase

| Layer | Focus | Priority phases |
|---|---|---|
| **Domain unit** | invariants (balanced journal, no-over-allocation, SBU Σ=1.0, immutability, void-window) | all |
| **Aggregate/state** | lifecycle transitions | all |
| **Repository/integration** | persistence, **concurrency-conflict**, immutability-at-store | M1+ |
| **Contract tests** | cross-context services (`ApplySettlement`, `PostingService`, import) | M2–M3 |
| **Event/outbox** | reliable dispatch, idempotent consumers | M1+ |
| **Concurrency suites** | over-allocation race, **Sequence gaplessness** | M3, M0 |
| **Reproducibility** | tax snapshot + rate record → report reproduces after rate change | M5 |
| **Accounting correctness (golden master)** | VAT zero-rated≠exempt, input VAT asset, VAT recognition on supply, credit-note period, period lock — reconciled vs Xero | M2, M4, M5, M7 |
| **E2E slice** | the demoable path | each slice |
| **Security/authz** | RBAC+ABAC, SoD, maker-checker, MFA | M0, M8 |

**Gate:** accounting-correctness tests require CPA sign-off before M8.

---

## 8. Parallel Work Opportunities

- **Layer 0 contexts are fully parallel** (Identity, Period, Numbering, Tax, FX, Platform) — no interdependencies.
- **Ledger** starts against **stubbed** Identity/Period contracts.
- **Receivables and Payables** are parallel (independent contexts) once Ledger/Tax/FX/Numbering contracts exist.
- **Reporting** proceeds in parallel from M1 (event schemas are frozen).
- **Frontend** proceeds in parallel against API Contracts.
- Because all crossings are **frozen interfaces + events**, agents integrate via contracts, not shared code.

**Serialization points (cannot parallelize):** Settlement needs Receivables/Payables document contracts (M3 after M2 interfaces exist); Migration needs all target import services (M7).

---

## 9. Coding-Agent Responsibilities

**One agent per bounded context** (plus Platform and Frontend). Each agent:
- Owns **only** its context's domain, repositories, schema, application services, and event producers.
- **Never edits another context's code or schema.** Consumes others via frozen interfaces/events only.
- Treats the frozen architecture docs as the contract; **a discovered weakness is reported, not patched.**

| Agent | Owns |
|---|---|
| Platform | outbox, in-process event bus, UoW, audit log, entity-isolation, CI boundary guards |
| Identity | Entity, User, Role, authz, SoD, approval policy, MFA |
| Period | AccountingPeriod, FiscalCalendar, close lifecycle, IsDatePostable |
| Numbering | Sequence (atomic draw), ID/Number split |
| Tax | TaxCode/versions, TaxPack, determination, snapshot |
| FX | RateRecord, FX gain/loss calc, revaluation |
| Ledger | Account, JournalEntry, PostingService, balance projection |
| Receivables | Invoice, CreditNote, Customer, `ApplySettlement`, `GetOpenReceivable` |
| Payables | Bill, DebitNote, Expense, Vendor, `ApplySettlement`, `GetOpenPayable` |
| Settlement | Allocation, PartyCreditBalance, cash application, realised FX application |
| Reconciliation | BankReconciliation, matching, ACL for CSV |
| Migration | StagingBatch, dry-run, idempotent import, ACL for source |
| Reporting | projections, queries, cash-view derivation |
| Frontend | UI against API Contracts |

**CI boundary guards (Platform agent):** fail the build on (a) cross-context code imports, (b) cross-context DB/table access, (c) cross-context FKs, (d) a context returning another's aggregate. This makes AP-001 mechanically enforced.

---

## 10. Estimated Milestones *(indicative effort bands — planning guidance, not commitments; actuals depend on team velocity)*

| Milestone | Effort band | Depends on |
|---|---|---|
| M0 Platform + Walking Skeleton | M | — |
| M1 Ledger + Tax + FX | M | M0 |
| M2 Receivables + Payables | L | M1 |
| M3 Settlement | M | M2 |
| M4 — Corrections, Notes and Period Close Foundations | M | M2, M3 |
| M5 — Reporting and Cash View | M | M1–M4 (grows) |
| M6 Reconciliation | S–M | M3 |
| M7 Migration + Parallel Run | L | M2–M5 |
| M8 Hardening + Go-Live | M | all |

(S≈small, M≈medium, L≈large relative effort.)

### M4 delivery slices and staged Hard Close

M4 is one roadmap milestone with two conceptual delivery slices; these labels do not create or rename milestones:

- **M4A — Credit/Debit Notes and Corrections:** editable Draft notes; immutable Posted notes; posted invoice/bill correction through linked reversal workflows; partial apply, hold, refund, and linked reversal; explicit M3 CreditTranche selection; immutable TaxSnapshot and RateRecord preservation; approval, audit, outbox, API, frontend, persistence, and tests.
- **M4B — Period Lifecycle and Close-Gate Foundations:** `Open`, `SoftClosed`, `HardClosed`, and `Reopened` state machinery; Soft Close, Hard Close, Reopen, approved-adjustment controls, atomic VAT locking/unlocking policy, versioned close-gate interfaces and immutable evidence; approval, audit, outbox, API, frontend, persistence, and tests.

M4 depends on completed M2 Documents and M3 Settlement. M4 delivers the Hard Close command and close-gate machinery, but Hard Close cannot succeed until every mandatory gate has immutable satisfied evidence. M5 provides Trial Balance, Profit and Loss, Balance Sheet, and VAT-output evidence; M6 provides bank-reconciliation evidence. Until those providers exist, the absent provider is an unmet gate, Hard Close returns `422 close_gate_unmet`, and no Period, VAT, Ledger, business-audit, or business-outbox mutation occurs. M4 implements no M5 or M6 endpoint and provides no bypass.

### M5 delivery slices

M5 is one roadmap milestone with two conceptual delivery slices; these labels do not create or rename milestones:

- **M5A — Reporting read models and financial statements:** Trial Balance, General Ledger, Profit and Loss, Balance Sheet, AR/AP Ageing, Tax/VAT Summary, and FX Revaluation summary reports; the immutable `ReportRun` evidence lifecycle (generation, durable four-eyes approval, automatic supersession); versioned `ReportLayout`, `AccountClassificationMap`, and `AgeingBucketSet` configuration; PDF/CSV export of approved runs; persistence, repository contracts, frontend, and tests.
- **M5B — Cash View and Close-Gate Evidence:** the Cash View report and its versioned `CashViewPolicy`; the `ReportingCloseGateProvider` implementation of M4's `CloseGateProvider` v1 for `trial_balance_reviewed`, `profit_and_loss_approved`, `balance_sheet_approved`, and `vat_outputs_approved`; frontend close-gate status display; close-gate integration tests.

M5 depends on completed M1 Ledger + Valuation, M2 Documents, M3 Settlement, and M4 Corrections/Notes/Period Close. Trial Balance, General Ledger, and account-balance reads remain owned by Ledger and are reached through a Reporting-owned adapter, not migrated or rewritten (`HiveFin_Repository_Contracts.md` §3; `HiveFin_Aggregate_Design.md` §16). M5 supplies four of Hard Close's five baseline gates; `bank_reconciliation_completed` remains M6-owned. Cash-basis Profit and Loss is excluded from M5 MVP — Cash View is the dedicated derived management report instead. M5 implements no M6 Reconciliation endpoint.

---

## 11. Risks & Mitigation (implementation phase)

| Risk | Mitigation |
|---|---|
| Agents violate context boundaries | CI boundary guards (§9); context = code ownership |
| Cross-context contract drift | frozen interfaces + contract tests; version event schemas |
| Concurrency defects (over-allocation, sequence gaps) | dedicated concurrency suites on the two hotspots; optimistic-version + atomic-draw tests |
| Accounting-correctness regressions | golden-master tests reconciled vs Xero; CPA sign-off gate before go-live |
| Migration data corruption | dry-run + idempotency + parallel run + sign-off (ADR-008) |
| Reporting projection drift | projections rebuildable from write side; periodic reconciliation to ledger |
| Scope creep into ERP features | finance-only exclusion (SRS §1) + change control |
| **Production-readiness gaps** (backup/DR, PII, timezone-of-record, parallel-run plan) | schedule explicitly in M0/M8 — still open from the original review |
| External legal tax parameters unconfirmed | VAT consultant sign-off before M2 tax-pack config |

---

## 12. Definition of Done (per phase)

A phase is Done when **all** hold:
- Domain invariant tests green; aggregate state-transition tests green.
- Integration tests incl. **concurrency-conflict** and **immutability-at-store** green.
- Cross-context **contract tests** green; event/outbox dispatch verified; consumers idempotent.
- **Accounting-correctness** tests for the phase pass (reconciled vs Xero where applicable).
- API conforms to the frozen API Contract; authz/SoD/maker-checker enforced; MFA where required.
- Immutability, audit logging, and reproducibility (snapshots/rate refs) verified.
- **CI boundary guards pass** (no cross-context violation).
- The phase's **vertical slice is demoable** end-to-end.
- Docs/traceability updated; no frozen-artifact change (or a reported weakness, if found).

---

**Roadmap complete.**
