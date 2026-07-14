# HiveFin — Software Requirements Specification v3.0

**Status:** Authoritative requirements baseline. Supersedes SRS v2.0 and Amendments 001–007.
**Incorporates:** ADR-001 (accrual + cash layer), ADR-002 (immutability), ADR-003 (credit/debit notes & void), ADR-004 (period close), ADR-005 (access control & SoD), ADR-006 (VAT/tax engine), ADR-007 (multi-currency/FX), ADR-008 (migration & open-item conversion), ADR-009 (document numbering).
**Rule:** Future ADRs modify this document directly. Layered amendments only when unavoidable.

---

## 1. Purpose & Scope

HiveFin is a lean, custom, double-entry financial management application for internal use by Notionhive, replacing Xero/Wave. It serves a Bangladeshi IT/ITES digital agency (Notionhive Bangladesh Ltd.) and a Canadian entity (Notionhive Canada Inc.), invoicing in BDT and foreign currencies.

**Entities & currencies.** Two independent legal entities in one instance, each with an isolated chart of accounts, ledgers, and reports; no data crosses the entity boundary. Bangladesh functional currency **BDT** (fiscal year 1 Jul–30 Jun); Canada functional currency **CAD**. Supported transaction currencies: **BDT, USD, CAD** (extensible). *(Corrects the v2.0 currency-list mismatch.)* Bangladesh-specific tax modules (VAT, AIT, VDS, ITES) apply to the Bangladesh entity only; Canada is invoicing/expenses/reporting in the MVP.

**Document numbering (ADR-009).** Every document has a permanent immutable internal **Document ID (UUID)** — used for all relationships, APIs, and references, never user-visible, never changing — and a business-facing **Document Number** from the **Numbering Service**. Statutory series (Invoice `NH-`, Credit `CN-`, Debit `DN-`) are **gapless**, scoped per **entity + fiscal year**, drawn atomically at **post/issue** (drafts carry a provisional token; abandoned drafts consume no number). Void documents keep their number; numbers are never reused. Manual numbering is restricted to the bounded migration/legacy path; migrated items keep their source number (ADR-008). Multi-entity/country adds new scoped sequences with no architecture change. *(Fiscal-year reset for `NH-`: policy choice — continuous recommended.)*

**MVP modules.** Dashboard · Journal · Sales Invoices · Purchase Bills · Expenses · **Payments & Receipts (Allocations)** · **Credit/Debit Notes** · Customers · Vendors · Chart of Accounts · Reports · **Tax Engine (+Bangladesh Pack)** · **Period Close** · Multi-Currency/FX · Bank Reconciliation · Conversion Balances.

**Explicit exclusions (MVP):** inventory/POS/e-commerce tax; payroll; live bank feeds; live FX feed; built-in email/SMTP; Canadian tax engine (GST/HST, T4); NBR direct filing; mobile app; multi-language; project costing; budgeting. HiveFin is a **finance-only system** used exclusively by Finance & Accounts — no CRM, quotations, opportunities, purchase orders, procurement/operational workflows, or non-finance approval chains (the ADR-005 maker-checker is finance-internal SoD, not an ERP workflow). Multi-entity **consolidation/CTA** and full cash-basis P&L restatement are deferred (§11).

---

## 2. Ubiquitous Language (Glossary)

| Term | Meaning |
|---|---|
| **Entity** | A legal company (Bangladesh / Canada); isolation and future tenant boundary |
| **Functional currency** | The currency an entity's ledger is kept in (BDT / CAD) |
| **Presentation currency** | The currency a report is rendered in (defaults to functional) |
| **Transaction currency** | The currency a document is denominated in |
| **Draft** | Unposted; freely editable/deletable |
| **Posted** | Committed to the ledger; **immutable** |
| **Recognition** | The accrual event (invoice issued / bill approved) that posts revenue/expense + tax |
| **Settlement** | The cash event (receipt / payment) recorded as an Allocation |
| **Allocation** | A first-class receipt/payment record linking cash to one or more documents |
| **Reversal** | A linked counter-entry that corrects a posted entry |
| **Void** | An internal reversal, allowed only inside the safe-window (§6.3) |
| **Credit / Debit Note** | First-class statutory correction document for issued invoices/bills |
| **Soft Close / Hard Close** | Period states: adjustments-only / fully locked |
| **SoD Exception** | Justified, logged override when staffing forces a duty conflict |
| **Tax Code / Tax Pack** | A versioned tax rule / a jurisdiction's rule set (Bangladesh = Pack #1) |
| **Rate Record** | A versioned exchange-rate master-data record referenced by transactions |
| **Tax Snapshot** | The applied tax code/rate/method/jurisdiction/version persisted on a posted line |

---

## 3. Access Control & Segregation of Duties *(ADR-005)*

**Model.** Default-deny, least-privilege, **Hybrid RBAC + ABAC**: roles are permission bundles; entity and optional SBU scope where a role applies. The entity boundary is the isolation/tenant boundary.

**System roles (minimum-capability floors; extensible).** Owner/Admin (user/role mgmt, tax/CoA config, approves Hard Close & Reopen) · Finance Manager (post/approve, reversals, notes, initiate close, checker) · Accountant (maker; create/post transactions, routine journals) · Finance Staff (drafts, invoices, expenses; no journal/notes/close) · Auditor (read-all + audit log) · Service Account (non-human, scoped, key-based). **Custom roles** allowed, never exceeding the granter's privileges.

**Segregation of Duties.** Enforced conflicts: creator≠approver; journal maker≠checker; bill poster≠payer; reversal author≠original poster; Hard-Close initiator≠approver. **Maker-checker** on high-risk actions (journals, reversals, notes, close, reopen, tax/CoA/role changes), triggered by a **configurable Approval Policy — no monetary thresholds in the architecture**. **Four-eyes** for Hard Close, Reopen, tax-config changes. **Compensating control:** staffing-forced conflicts record an **SoD Exception** (justification + audit + post-facto review) rather than blocking.

**Other.** Temporary delegation (time-boxed, auto-expiring, ≤ delegator). Break-glass (time-boxed, reason-coded, auto-notifies Owner, auto-expires, post-hoc review). Soft-deactivate preserves audit and revokes sessions; the last Owner cannot be deactivated. Audit log visible to Owner/Finance Manager/Auditor, immutable for all. API uses the same RBAC. **MFA required** for Owner & Finance Manager; JWT sessions, 60-min idle timeout.

---

## 4. Accounting Foundations (cross-cutting)

**4.1 Basis (ADR-001).** The ledger of record is **accrual**. Recognition and settlement are separate postings. Statutory reports (VAT/AIT) are accrual-only. **Cash reporting is a derived layer** over the same ledger — never a second ledger — computed by re-timing recognition to settlement date, pro-rated by paid proportion, valued at the settlement rate, net of withholding. Every report/KPI is basis-labelled (Accrual / Cash / Balance).

**4.2 Two-event posting.**
- Invoice **issued** → Dr Accounts Receivable / Cr Revenue / Cr Output VAT (invoice date).
- Bill **approved** → Dr Expense/Asset / Dr Input VAT / Cr Accounts Payable (bill date).
- Expense **recorded** → cash-settled (Cr Bank/Cash) or accrued (Cr AP).
- **Settlement** (Allocation) → Dr Bank / Dr AIT-Recoverable & VDS-Receivable (if withheld) / Dr-or-Cr FX Gain-Loss (realised) / Cr AR (and the payables mirror).
- `[System]` entries are immutable and reversed only via their source document.

**4.3 Immutability & correction (ADR-002).** Posted entries cannot be edited or deleted by anyone, including admins. Corrections are new **linked** posted entries: reversal, adjusting journal, credit/debit note, reissue. **Void = reversal, never delete.** One-click workflows (Reverse Journal, Reverse & Correct, Credit & Reissue, Debit/Vendor Credit) generate standard linked entries — no privileged mutation path exists.

**4.4 Reproducibility.** Every posted line permanently references its **Tax Snapshot** (ADR-006) and **Rate Record** (ADR-007), so any period reproduces its original figures even after rules/rates change.

---

## 5. Functional Modules

**5.1 Dashboard.** Entity-scoped, fiscal-year KPIs, each basis-labelled: Cash in Bank (ledger cash balance) · Outstanding Receivables · Bills to Pay · **Net Profit/Loss (Accrual) YTD** · **Cash Collected/Paid (Cash) YTD** · Top-5 Delayed Invoices. Charts: Revenue vs Expenses (accrual), Cash Flow Trend (cash), Receivables Ageing donut. Recent-transactions feed (last 10).

**5.2 Journal.** Manual double-entry (debits = credits in functional currency, post-rounding). States: Draft (editable) → **Posted (immutable)** → Reversed (linked). Foreign lines carry a Rate Record. Adjusting entries (accruals, prepayments, depreciation, FX revaluation) are recognised entry types, posted in Soft Close. Searchable history with created/modified attribution; `[System]` entries flagged and source-reversed only.

**5.3 Sales Invoices.** Local BDT (5% IT/ITES or 15% general) and foreign (**zero-rated** export) via tax codes; VAT defaults from customer type, overridable at line level. Required fields per v2.0 (number, dates, customer, currency, reference, line items, payment-instructions block, attachments). Issuance posts the accrual recognition entry at the invoice-date Rate Record. States: Draft → Sent (recognised) → Partially Paid / Paid (settlement via Allocations) → Void / Overdue. Actions: PDF export (branded, BIN, dispute footer), duplicate, **Record Receipt** (Allocation), **Credit & Reissue**, **Void** (safe-window only), read-only view. Edit is Draft-only.

**5.4 Purchase Bills.** Vendor payables with line items, expense category (CoA-mapped), **SBU allocation** (decimal weights summing to 1.0000), **AIT** and **input VAT** capture. Approval posts the accrual recognition entry. States: Draft → Awaiting Payment (approved) → Partially Paid / Paid → Void / Overdue. Correction via **Debit Note / Vendor Credit** or reversal. SBU weights are configurable per bill.

**5.5 Expenses.** Day-to-day expenditure mapped to Operating Expenses; SBU allocation, optional AIT, receipt attachment. **Settlement type:** cash-settled (default) or accrued (→ AP). Posted expenses corrected via Reverse & Correct. Category list carried from v2.0 (editable, extensible). Filterable list + CSV export.

**5.6 Payments & Receipts (Allocations)** *(new).* First-class settlement records linking cash to one or more documents. Fields: direction, linked document(s), settlement date, amount + transaction currency, **Rate Record**, base-currency equivalent, bank/cash account, AIT withheld, VDS withheld, realised FX. Support partial settlement and one-payment-to-many-documents. Realised FX per tranche vs the document's invoice-date rate.

**5.7 Credit / Debit Notes** *(new, ADR-003).* First-class documents with own per-entity numbering (e.g., `CN-XXXX`, `DN-XXXX`), reason code (mandatory), full/partial amounts, line-level VAT adjustment, original invoice-date Rate Record. Lifecycle: Draft → **Posted (immutable)** → Applied / Held / Refunded. **Applied** → Allocation to open invoices; **Held** → Customer Credits (2060); **Refunded** → outflow Allocation. VAT adjustment posts in the **note's** period.

**5.8 Customers.** Lean registry; type (Local/Foreign) drives VAT default; payment terms pre-fill due date; default currency; tabbed activity view (Activity, Invoices, Received Money, Outstanding, **Held Credits**). No CRM fields.

**5.9 Vendors.** Lean registry; VAT/TIN for AIT tracking; bank details; default currency/terms; tabbed activity view (Activity, Bills, Payments Made, Outstanding). No PO/procurement.

**5.10 Chart of Accounts.** Flat per entity; SBU handled at the transaction-allocation layer, not via sub-accounts. Pre-built from the Notionhive P&L, amended per ADR-006:

| Class | Accounts (amended entries in **bold**) |
|---|---|
| 1 Assets | 1010 Cash · 1020 NRB Current · 1021 NRB SOD · 1030 SCB · 1031 EBL · 1040 PayPal (USD) · 1050 Payoneer (USD) · 1060 AR · 1070 AIT Recoverable · **1071 Input VAT Recoverable** · **1072 VDS Receivable** · **1075 Vendor Credits (Unapplied)** · 1080 Security Deposits · 1090 Fixed Assets · 1091 Accum. Depreciation (contra) |
| 2 Liabilities | 2010 AP · **2020 Output VAT Payable (15%)** · **2021 Output VAT Payable (5% IT/ITES)** · **2025 VDS Payable** · 2030 AIT Payable · 2040 Salaries Payable · 2050 Short-Term Loans · **2060 Customer Credits (Unapplied)** |
| 3 Equity | 3010 Share Capital · 3020 Retained Earnings · 3030 Current-Year P&L *(CTA reserve reserved for future consolidation)* |
| 4 Revenue | 4010 Client Service Revenue · 4020 Sales Discount (contra) · 4030 Interest Income · 4040 Other Revenue · **4050 FX Gain (Other Income)** |
| 5 Cost of Sales | 5010 Media Buying · 5020 Outsourced Services · 5030 Third-Party Subscriptions |
| 6 Operating Exp. | 6010–6210 per v2.0 · **6220 FX Loss** |

Non-recoverable input VAT is expensed into cost, not asseted. Account create/edit/deactivate per v2.0 controls; type editable only if no postings; delete only if zero history.

**5.11 Reports.** Entity-scoped, filterable by date/fiscal period/SBU, with **reporting-basis** toggle where meaningful. **Accrual-only** (no cash toggle): Balance Sheet, **Trial Balance**, **General Ledger (account detail)**, VAT Summary, AIT registers. Inventory: P&L (exact Notionhive layout with computed lines and % columns) · Balance Sheet · **Trial Balance** · **General Ledger** · Expense (by category/SBU) · Sales (by client/currency/SBU) · Tax Summary · AR/AP Ageing · **FX Revaluation** · **Cash Collections/Payments dashboards**. SBU filter recalculates from decimal-weighted allocations. PDF + CSV export.

**5.12 Tax Engine (ADR-006).** Country-agnostic engine + **Tax Packs**; **Bangladesh (VAT/AIT/VDS) = Pack #1**; future countries by configuration. A **Tax Code** = {jurisdiction, treatment (Standard/Zero-rated/Exempt), rate, recoverable flag, calc method, GL mapping, return-box mapping, **effective-dated versions**}. Transactions use the version effective on the tax-point date; the applied **Tax Snapshot** is persisted (reproducibility). Output VAT on supply (liability); recoverable input VAT → asset 1071, non-recoverable → cost; reverse charge posts output+input simultaneously; VAT-inclusive/exclusive pricing supported; advance-payment tax point configurable; Mushak-9.1 box mapping configurable.

**5.13 Period Close (ADR-004).** Lifecycle **Open → Soft Close (repeatable) → Hard Close → Reopened**. Open: all posting. Soft Close: adjusting entries only (accruals, prepayments, depreciation, FX revaluation, reclass); repeatable. Hard Close: fully locked, VAT locked, irreversible via normal ops. Reopen: authorised role + reason + **management approval** + audit + **user notification** + mandatory re-close. Corrections to hard-closed periods post to the current open period. **Month-end gate:** reconcile banks → accruals/prepayments → depreciation → FX revaluation → review Trial Balance → generate P&L/BS/VAT → Hard Close → file VAT. **Year-end:** roll net profit to 3020, clear 3030, Hard Close fiscal year.

---

## 6. Bangladesh Compliance Layer

**6.1 VAT.** Output VAT at supply per §5.12 tax codes: Local IT/ITES 5%, Local general 15%, **Export zero-rated (0%, input recoverable)** — distinct from Exempt (no input recovery). Input VAT → 1071 (recoverable) or cost (non-recoverable). **Net VAT = Output − Recoverable Input** per return period. Tax Summary supports the accountant's Mushak-9.1 (no direct NBR filing in MVP).

**6.2 ITES.** The **income-tax** ITES exemption (with Dashboard expiry-warning banners at Jan/Apr/Jun 2027 escalation and 1 Jul 2027 manual-review flag) is tracked **separately** from the **VAT** zero-rating of exports — different laws, different dates.

**6.3 AIT.** Both directions: withheld **by** Notionhive → 2030 (liability); withheld **from** Notionhive on receipts → 1070 (asset), captured on the Allocation. Manual in MVP; register exportable for NBR challan.

**6.4 VDS.** Both directions: customer-withheld on our sales → 1072 (asset); our deduction on purchases → 2025 (liability). Output-VAT liability remains fully recognised; VDS is a withholding, not a supply reduction.

---

## 7. Multi-Currency & FX (ADR-007, IAS 21)

Functional currency per entity (BDT/CAD); presentation currency per report; transaction currency per document. **Rate Table = effective-dated master data**, immutable once referenced; manual in MVP, feed-ready; overrides need a reason. Invoice-date rate mandatory (sets AR/AP base value); **realised FX** at settlement per tranche vs invoice-date rate → 4050/6220; **unrealised revaluation** of open monetary items (AR, AP, foreign bank balances) at Soft Close, **reversed at next-period start**. Consolidation/CTA deferred (§11).

---

## 8. Bank Reconciliation

CSV-based manual reconciliation (no live feed). Import → parse → auto-match by amount/date/reference (supporting one-to-many and many-to-one) → confirm/assign → create authorised entries for bank-only lines → statement ties to zero. Saved column mapping per bank; duplicate/overlap detection. Statuses: Unreconciled / Matched / Reconciled / Unexplained. Reconciliation Statement exportable (PDF).

---

## 9. Migration & Open-Item Conversion *(ADR-008)*

**Strategy.** Source-agnostic cutover at a **Conversion Date** (fiscal-year boundary preferred). Legacy history stays archived in the source system (Xero = Importer #1); **no historical-transaction replay** in MVP. A mapping layer (source schema → HiveFin canonical import model) makes future sources (QuickBooks, etc.) new mappings, not new code.

**Opening balances.** A locked, immutable **Conversion Journal** (ADR-002), tagged to a pre-open conversion period (ADR-004), establishes the opening Trial Balance (debits = credits).

**Open-item AR/AP.** Receivables and payables migrate as **individual open invoices/bills**, each with document number, dates, due date, customer/vendor, **original transaction currency + amount, and invoice-date Rate Record** — so they age, settle via Allocations (§5.6), and revalue (§7). *(Resolves the open-item requirement from ADR-001/007.)*

**Master & balances.** CoA imported/mapped to the §5.10 structure (unmapped accounts resolved before posting). Customers/Vendors imported first (must exist before open items; dedup on name/Tax-ID). Bank opening balance = reconciled statement balance at the Conversion Date (becomes the reconciliation opening, §8). Fixed assets as cost + accumulated depreciation (1090/1091). Tax balances — AIT (1070/2030), VDS (1072/2025), VAT (1071/Output) — must reconcile to the last filed Mushak. Every foreign open item and bank balance carries **original currency + Rate Record** (never BDT-only).

**Idempotency (ADR-008 refinement).** Migration is re-runnable without creating duplicates; **every imported record carries a persistent migration identifier** linking it to its source record.

**Dry-run & staging (ADR-008 refinement).** Finance may run **unlimited dry-runs** against a **staging area** — validating, generating reconciliation reports, and resetting — with **zero production-ledger impact**. Only an explicit, authorised **final migration** posts immutable entries (four-eyes on final post, ADR-005).

**Validation gates (pre-post).** TB balances; every open item maps to an existing party; currencies valid per entity; no duplicate document numbers; foreign items carry a rate; totals reconcile to source control totals.

**Parallel run & go-live.** Run alongside the source for **1–2 closes**, reconciling TB, AR/AP ageing, bank, and VAT summary each period; sign-off gate before source switch-off. Checklist: freeze source → export → import CoA → parties → open AR/AP → bank/tax/asset balances → validate → post Conversion Journal → reconcile to source → parallel run → sign-off → cutover.

**Rollback.** Fully reversible while staged; a failed validation discards the batch with zero ledger impact. Post-acceptance corrections follow the normal reversal/adjustment model (ADR-002/004).

**Migration audit report.** Per import: source, imported-by, timestamp, record counts, control-total reconciliation, exceptions, and resulting opening TB/AR/AP/bank reports.

**Out of scope (MVP):** full historical-transaction migration; attachment migration; live API import — all documented future options.

---

## 10. Non-Functional Requirements

**Performance.** ~3,000 txns/month (~36k/yr); page loads ≤2s; reports ≤5s over 3 fiscal years; CSV import (≤500 rows) ≤10s; ≤15 concurrent users; <10 GB/3yr.

**Security.** TLS 1.2+; at-rest encryption; bcrypt (cost ≥12); JWT + 60-min timeout; 5-attempt lockout (**unlock by privileged role only**); HTTPS enforced; attachments scanned, stored outside web root; **MFA required for Owner & Finance Manager**.

**Immutability & audit.** Posted financial records **and** the audit log are immutable for all users. Every create/edit(draft)/post/void/reverse/login/close logged with UTC timestamp, user, entity, module, action, record reference, and before/after JSON. All corrections carry a reference to the entry they correct.

---

## 11. Open Decisions (future ADRs)

| ID | Decision | Why it matters | Carries |
|---|---|---|---|
| **ADR-010** | **Multi-Entity Consolidation & Intercompany** | Group reporting, CTA translation, intercompany elimination/transfer pricing | ADR-007 deferral |

**Deferred (non-blocking, may become future ADRs):** fixed-asset register + auto-depreciation; attachment migration; full historical-transaction migration.

**External/legal parameters (not architecture — configure the Bangladesh Tax Pack):** current statutory VAT rates; ITES income-tax exemption legal status; advance-payment tax point; input-VAT creditability evidence (Mushak-6.3); adopted period-end rate source. → confirm with VAT/tax consultant.

---

## 12. Traceability Matrix (ADR → sections modified)

| ADR | Sections in v3.0 |
|---|---|
| 001 Accrual + cash layer | §4.1, §4.2, §5.1, §5.2, §5.3, §5.4, §5.5, §5.6, §5.11 |
| 002 Immutability + correction | §4.3, §5.2, §5.3, §5.4, §5.5, §5.7, §10 |
| 003 Credit/debit notes + void | §5.3, §5.4, §5.7, §5.10, §6.1, §5.11 |
| 004 Period close | §5.13, §4.2, §5.3/§5.4 (void-window), §7, §6.1 |
| 005 Access control + SoD | §3, §10 (MFA) |
| 006 VAT/tax engine | §5.10, §5.12, §6.1–6.4, §4.4 |
| 007 Multi-currency/FX | §2, §5.6, §5.11, §7, §5.10 (4050/6220) |
| 008 Migration & open-item conversion | §9, §7, §5.6 |
| 009 Document numbering | §1, §5.3, §5.7, §14 |

---

## 13. ADR Dependency Map

```
ADR-001 (Accrual + Cash Layer)  ── foundation
   ├── ADR-002 (Immutability)         needs a ledger to make immutable; enables reproducibility
   │      ├── ADR-003 (Credit/Debit Notes)  correction of issued docs; void = reversal
   │      └── ADR-006 (Tax Engine)          tax snapshot persisted in immutable record
   ├── ADR-004 (Period Close)         houses accruals + FX revaluation; enforces void-window
   │      └── (finalises dating for ADR-002 & ADR-003)
   ├── ADR-007 (Multi-Currency/FX)    invoice-date rate, revaluation at Soft Close
   │      └── (rate record mirrors ADR-006 reproducibility)
   └── ADR-005 (Access Control)       authorises reverse/void/note/close across 002/003/004
                                       (entity = tenant boundary from 001)

Deferred: ADR-010 (Consolidation) ← 007
Resolved rework risk: ADR-008 (Migration) ← 001/007 ; ADR-009 (Numbering) — atomic sequences + UUID identity
```
No cyclic dependencies. Each locked ADR is consistent with all prior. ADR-004 retired dating riders on 001/002/003; ADR-005 retired authorisation riders on 002/003/004; ADR-006 fixed the last correctness defects; ADR-007 retired ADR-001's FX rider; ADR-008 retired ADR-001's open-item rider; ADR-009 closed the numbering/concurrency gap.

---

## 14. Domain Model Summary (DDD)

**Aggregates (roots):** JournalEntry (+lines) · Invoice (+lines) · Bill (+lines) · Expense · CreditNote / DebitNote · Allocation (Receipt/Payment) · AccountingPeriod · Account (CoA) · Customer · Vendor · TaxCode (+versions) · RateRecord · User · Role · Entity. *(All document aggregates use an immutable internal **Document ID (UUID)** as identity, distinct from the business-facing Document Number — ADR-009.)*

**Value objects:** Money (amount + currency) · ExchangeRateReference · TaxSnapshot (code/rate/method/jurisdiction/version) · **DocumentNumber (business key, jurisdiction-varying)** · SBUAllocation (weights, Σ=1.0000) · Period (date range + state) · Address · ContactInfo · AuditStamp (user/timestamp) · ApprovalPolicy · SoDException · MigrationIdentifier.

**Domain services:** PostingService (two-event recognition/settlement) · CorrectionService (reversal/void/credit/debit) · TaxDeterminationService (code resolution + snapshot) · FXRevaluationService (realised + unrealised) · PeriodCloseService (state transitions, gates) · AllocationMatchingService (reconciliation) · AuthorizationService / SoDService (RBAC+ABAC, maker-checker, exceptions) · **NumberingService (atomic scoped sequences, gapless policy, ID/number split)** · MigrationService (idempotent, staged, dry-run).

**Bounded-context candidates:** Ledger & Posting · Receivables/Payables & Documents · Tax · FX/Currency · Period & Close · Identity & Access · Reconciliation · Migration.

**Business-rule catalog (retained IDs for traceability):**
- *Basis/posting:* BR-001..006 (recognition/settlement, statutory-accrual, cash derivation, revaluation, basis labels, withholding-on-receipt).
- *Immutability/correction:* BR-007..012 (posted-immutable, linked corrections, void=reversal, one-click wrappers, system-entry source-reversal, reversal dating).
- *Notes/void:* BR-013..018 (void 4-condition test, note VAT in note period, credit at original rate, dispositions, reason code, note immutability).
- *Period close:* BR-019..024 (single state, repeatable Soft Close, Hard Close lock, reopen controls, closed-period dating, year-end roll).
- *Access/SoD:* BR-025..031 (default-deny, role floors, SoD conflicts, configurable maker-checker, compensating exception, MFA, delegation/break-glass).
- *Tax:* BR-032..038 (engine/pack split, versioned codes, tax snapshot, recoverable=asset, zero-rated export, reverse charge, VDS both directions).
- *FX:* BR-039..046 (functional-currency ledger, rate master data, rate reference on line, per-tranche realised FX, Soft-Close revaluation + reversal, foreign bank revaluation, override reason, consolidation deferral).

---

## 15. Ready-for-Architecture Checklist

**Locked & consistent (9 ADRs):** ✅ accounting basis · ✅ immutability/correction · ✅ credit/debit notes & void · ✅ period close · ✅ access control/SoD · ✅ VAT/tax engine · ✅ multi-currency/FX · ✅ migration & open-item conversion · ✅ document numbering. **All original-review accounting-correctness defects resolved.** Document internally consistent; only housekeeping swept (currency list, duplicate header).

**Future architecture decision:**
- ☐ **ADR-010 Consolidation** — likely a separate bounded context; affects Reports.

**Non-blocking for architecture (parameters/config):** external legal tax parameters; approval-policy configuration; period-end rate source.

**Production-readiness items still to schedule (from original review §12, unaffected by ADRs):** backup/DR (RPO/RTO); data-retention policy; PII handling for stored contacts/bank data; timezone-of-record (UTC vs BDT) resolution; parallel-run plan vs Xero and go-live acceptance sign-off.

**Verdict:** The **entity-level accounting domain is architecture-ready** and all aggregate-shaping decisions except consolidation are locked. Foundational bounded contexts (Ledger, Documents, Tax, FX, Period, Access, Migration) can be modelled now. Only **ADR-010 (Consolidation)** remains — it likely introduces a *new* bounded context rather than altering existing aggregates, so architecture on the core need not wait for it.
