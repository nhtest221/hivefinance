# HiveFin — Decision Log (Architecture & Accounting Decision Records)

This log is the authoritative ADR register for HiveFin. It captures the binding decisions made during the pre-architecture Decision Workshop.
Each entry supersedes any conflicting statement in SRS v2.0; the current requirements baseline is SRS v3.0.

---

## ADR-001 — Accounting Basis: Accrual Ledger with a Derived Cash Reporting Layer

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 90%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
The **system of record is a single, fully accrual-based general ledger.** All accounting entries, AR, AP, GL, Trial Balance, Balance Sheet, P&L, and statutory reports (VAT / AIT) are produced on the accrual basis.

The system additionally provides **cash-basis management reports and dashboards as a derived reporting layer only.** The cash view is computed from the same accrual ledger and its linked settlement records. It **must never** create a second ledger, duplicate journal entries, or alter statutory figures.

### Rationale
- **Matches current operating reality (Xero + Hubdoc):** Xero runs accrual by default; Hubdoc pushes bills that exist before payment. Accrual is a continuation, not a regression, of how the team already works.
- **Bangladesh compliance:** NBR VAT liability arises on *supply/invoice* (not receipt); AIT/VDS are withheld against an already-recognised receivable/payable. Statutory reporting is inherently accrual.
- **Multi-currency correctness:** Accrual anchors each document at its invoice-date rate, which is the only reference point against which realised (receipt) and unrealised (period-end) FX gain/loss can be computed.
- **ITES + Non-ITES service model:** Both bill on terms; the gap between invoicing and collection *is* accounts receivable, representable only under accrual.
- **Future SaaS:** No serious accounting SaaS ships cash-only. Accrual-of-record + optional cash reporting is the industry-standard, market-preserving posture.

### Consequences
1. **Posting model must be two-event, not one-event.** Documents post at *issuance/approval* (accrual recognition) and again at *settlement* (cash movement). This supersedes SRS §3.2.5, which posted only "when marked paid."
2. **Payments/receipts become first-class allocation records** (payment → document link, with settlement date, currency, manual FX rate, and any AIT/VDS withheld). A simple "paid amount" field is insufficient — the cash layer and FX logic both depend on this structure.
3. **Period-end adjustments become mandatory** for the accrual accounts to be correct: accruals, prepayments, depreciation, and **foreign-currency revaluation** of open AR/AP at the period-end rate (currently absent from the SRS).
4. **Statutory tax figures are ring-fenced to accrual.** The cash reporting layer must never feed VAT returns (Mushak-9.1) or AIT registers.
5. **Every report and dashboard KPI must be basis-labelled** (Accrual / Cash / Balance) so no one misreads a management cash figure as a statutory result.
6. **Derivation algorithm must be single-sourced and documented** so "cash revenue" is computed identically everywhere (re-time to payment date, pro-rate by paid proportion, value at receipt-date rate, net of withholding).

### Open sub-decision (to resolve during the Reports decision, not now)
- **MVP scope boundary of the cash layer.** Recommended split: MVP ships (a) full accrual books + accrual reports, and (b) *cash-native* dashboards that are trivially derived (cash in bank, actual collections this period, actual payments this period, cash-flow trend). A full **cash-basis P&L restatement** (re-timing all revenue/expense recognition line-by-line) is heavier and is a candidate to defer to Phase 1.5/2. To be confirmed.

### Impacted modules
- **Journal (§3.2):** rewrite auto-JE rules to two-event posting; add period-end adjustment entries.
- **Sales Invoices (§3.3):** issuance posts Dr AR / Cr Revenue / Cr Output VAT at invoice date; settlement posts separately; payment allocation model added.
- **Purchase Bills (§3.4):** approval posts Dr Expense/Input-VAT / Cr AP; payment posts separately.
- **Expenses (§3.5):** clarify whether expenses may be *unpaid* (accrued → payable) or are always cash-settled on entry.
- **Dashboard (§3.1):** basis-label every KPI/chart; separate accrual P&L from cash collections.
- **Reports (§3.9):** add Trial Balance + GL detail as accrual core; add basis toggle where applicable; define which reports are accrual-only (BS, TB, VAT/AIT).
- **Tax Engine / Compliance (§3.10, §4):** confirm statutory VAT/AIT are accrual and isolated from the cash layer.
- **Multi-Currency (§5.2):** define accrual FX (invoice-date rate + period-end revaluation) vs cash-view FX (receipt-date/realised).
- **New cross-cutting concept:** Payments & Receipts (allocations) as a first-class entity feeding both settlement postings and the cash layer.

### Gate Review Outcome (13 July 2026)

**Result:** PASSED → LOCKED. Confidence 90% that the accounting-basis decision will not require redesign.

**Lock riders — must be resolved within their owning decisions before build (do NOT reopen ADR-001):**
1. **Open-item migration granularity.** Opening AR/AP must migrate at invoice/bill (open-item) level, not account-balance level, or ageing/allocation/FX on pre-migration items breaks. → *Migration decision.*
2. **Cash-layer scope + written derivation algorithm.** Document the single authoritative algorithm (re-time to settlement, pro-rate, strip VAT portion, choose FX rate) and the MVP scope boundary (cash-native dashboards in MVP; full cash-basis P&L restatement TBD). → *Reports decision.*
3. **Functional currency per entity + period-end rate source + multi-tranche realised-FX rule.** Confirm BD ledger in BDT, Canada in CAD; define one period-end rate per currency from a named source; realised FX per tranche measured vs original invoice-date rate. → *FX policy decision.*
4. **Foreign opening balances retain original-currency amount** (not just converted BDT), to enable revaluation. → *Migration decision.*

**Undocumented assumptions carried by ADR-001 (to confirm as their decisions arrive):** functional currency per entity; recognition date = document date; multi-tranche realised FX vs invoice-date rate; one period-end rate per currency; authoritative cash-derivation algorithm; "Cash in Bank" = ledger cash-account balance; VAT excluded from cash-basis revenue; expense-accrued vs purchase-bill boundary rule.

**What would raise confidence from 90% → ~98%:** closing riders 1–4 above (all specification gaps, not design flaws).

---

## ADR-002 — Posted-Entry Immutability & Correction Model

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 94%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
**Posted transactions are immutable** — once posted, no entry (journal, invoice, bill, expense, allocation, or `[System]` entry) may be edited or deleted by any user, including administrators. **Drafts** remain fully editable and deletable until posting.

All corrections are performed through new, linked posted entries: **reversal entries, adjusting journals, credit notes, debit notes, and reissued documents.** **Void = reversal**, never delete. `[System]` entries are reversed only by reversing their **source document.**

To keep immutability frictionless, the system provides **one-click correction workflows** — *Reverse Journal, Reverse & Correct, Credit & Reissue, Debit/Vendor Credit* — which generate the same standard linked entries a manual correction would. There is no privileged mutation path.

### Rationale
- **Auditability & IAS 8:** errors are corrected by new entries, never silent edits; the trail is tamper-evident.
- **Bangladesh compliance:** a filed Mushak-9.1 or AIT register always ties to the ledger as filed — posted history cannot shift beneath it.
- **Internal controls & SaaS:** strongest defensible posture for multi-tenant scale.
- **UX preserved:** the Draft state plus one-click reversals deliver ~90% of "just edit it" convenience without the audit liability, so immutability adds minimal friction.

### Consequences
1. Correction taxonomy replaces edit/delete of posted records.
2. Linked reversal relationships (original ↔ correction) become first-class.
3. Reversal **dating rule** required — provisional (open period; else current open period) pending the period-close ADR.
4. Correction **authorisation** (who may reverse/void) is deferred to the access-control ADR.
5. Credit/debit-note **mechanics** are the next ADR (principle only established here).

### Impacted modules
Journal (§3.2), Sales Invoices (§3.3), Purchase Bills (§3.4), Expenses (§3.5), Reports (as-filed integrity), Security/Audit (§6.4). Conversion (§7.5) already consistent.

### Gate Review Outcome (13 July 2026)
**Result:** PASSED → LOCKED. Confidence **94%**. Consistency with ADR-001 confirmed — immutability reinforces the two-event recognition↔settlement linkage; resolves Amendment 001 remaining-contradiction #1.

**Lock riders (owned by later decisions; do NOT reopen ADR-002):**
1. Final reversal-dating rule → *period-close ADR.*
2. Who may post reversals/voids (SoD) → *access-control ADR.*
3. Credit/debit-note full mechanics → *next ADR.*
4. Draft document-number handling on delete → *numbering rule.*

**What would raise confidence 94% → ~99%:** closing riders 1–3.

---

## ADR-003 — Credit Notes, Debit Notes, Voiding & Document Correction

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 92%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
Adopt **Option C (Hybrid).** Credit Notes and Debit Notes are **first-class documents** (own numbering, audit trail, lifecycle Draft → Posted → Applied/Held/Refunded), immutable once posted per ADR-002.

**Void** is an internal reversal permitted **only when ALL** are true: unpaid; current open period; not in a filed VAT return; no downstream allocations/settlements. If any fails, correction is by **Credit/Debit Note**.

Credit Notes may be **full or partial**, and **Applied** (to open invoices), **Held** (as customer credit → account 2060), or **Refunded** (outflow allocation).

### Rationale
NBR-compliant (decreasing/increasing VAT adjustment lives in the note's period, never restating a filed period); IFRS-clean; matches Xero's real behaviour (void of unpaid/unreported, notes otherwise); full auditability and SaaS-standard document model; void-window removes friction for trivial same-period corrections.

### Consequences
1. New Credit Note / Debit Note documents + per-entity numbering.
2. CoA gains 2060 Customer Credits (liability) and 1075 Vendor Credits (asset) — also closes the customer-advance/credit gap.
3. Note VAT adjustments report in the note's issue period.
4. Applied/Refund reuse the ADR-001 Allocation entity; FX at original invoice-date rate.

### Impacted modules
Sales Invoices (§3.3), Purchase Bills (§3.4), Chart of Accounts (§3.8), Tax Engine/Compliance (§3.10, §4.2), Reports (§3.9), Scope (§1.5).

### Gate Review Outcome (13 July 2026)
**Result:** PASSED → LOCKED. Confidence **92%**. Consistent with ADR-001 and ADR-002. Resolves Amendment 001 remaining-contradiction #2.

**Lock riders (owned by later decisions; do NOT reopen ADR-003):**
1. NBR statutory adjustment time-limit → confirm with VAT consultant (external).
2. Authorisation to issue notes/voids (SoD) → access-control ADR.
3. Note dating vs period boundaries → period-close ADR (reuses ADR-002 BR-012).

**What would raise confidence 92% → ~98%:** closing riders 1–2.

---

## ADR-004 — Accounting Period Close, Locking & Reopening

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 93%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
Adopt **Option B — two-stage lifecycle**: **Open → Soft Close (repeatable) → Hard Close → Reopened.**
- **Open:** all normal posting. **Soft Close:** adjusting entries only (accruals, prepayments, depreciation, FX revaluation, reclass); repeatable until Hard Close. **Hard Close:** fully locked, VAT return locked, irreversible via normal operations. **Reopened:** requires authorised role + mandatory reason + **management approval** + audit log + **automatic user notification** + mandatory re-close.
- **Dating:** corrections to hard-closed periods post to the **current open period** (never reach back).
- **Timing:** FX revaluation / depreciation / accruals in Soft Close; VAT freezes at Hard Close.
- **Year-end:** roll net profit to 3020 Retained Earnings, clear 3030, Hard Close the fiscal year (Jul–Jun).

### Rationale
Clean cutoff + IAS 8 treatment for post-close errors; enforces NBR VAT-period integrity; superset of Xero's lock-date; strongest audit/control posture; the Soft-Close review window is where month-end quality control and the accrual adjustments from ADR-001 actually happen.

### Consequences
1. New §3.11 period lifecycle governs all posting.
2. Finalises reversal/note dating across ADR-002 and ADR-003.
3. FX revaluation and VAT lock gain firm deadlines.
4. Month-end checklist becomes the gate to Hard Close.

### Impacted modules
Journal (§3.2), Sales/Purchase (§3.3/§3.4 void-window), FX (§5.2.2), Tax/Compliance (§3.10/§4.2), Reports (§3.9), Audit (§6.4), new §3.11.

### Gate Review Outcome (13 July 2026)
**Result:** PASSED → LOCKED. Confidence **93%**. Consistent with ADR-001/-002/-003.
**Riders retired by this ADR:** ADR-002 reversal-dating; ADR-003 note-dating-vs-period; ADR-001 period-end adjustment/revaluation timing.
**Remaining rider (owned elsewhere):** close/reopen + management-approval **authorisation** → access-control ADR (requirements fixed here; roles defined there).
**What would raise confidence 93% → ~98%:** closing the access-control rider.

---

## ADR-005 — Access Control & Segregation of Duties

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 93%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
Adopt **Hybrid RBAC + ABAC**, default-deny, least-privilege. Roles = permission bundles; entity + optional SBU = scope; entity = isolation/tenant boundary.
- **System roles** (minimum-capability floors, extensible): Owner/Admin, Finance Manager, Accountant, Finance Staff, Auditor (read-only), Service Account. **Custom roles** allowed, never exceeding the granter's privileges.
- **SoD matrix** enforces core conflicts (creator≠approver, maker≠checker, poster≠payer, close initiator≠approver).
- **Maker-checker** on high-risk actions (journals, reversals, notes, close, reopen, tax/CoA/role changes), **triggered by a configurable Approval Policy — no monetary thresholds in the architecture** (COO refinement #1). Four-eyes for Hard Close, Reopen, tax config.
- **Compensating controls** (COO refinement #3): staffing-forced conflicts record an SoD Exception (justification + audit + post-facto review) rather than blocking.
- Delegation and break-glass are time-boxed/logged/auto-expiring. MFA required for Owner & Finance Manager.

### Rationale
Least-privilege + legible roles + SoD satisfy internal control and audit; hybrid scoping gives multi-entity/SaaS isolation; configurable policy and compensating controls make enterprise-grade SoD practical for a 4-person finance team without login-sharing.

### Consequences
1. Flat access (§2.1) replaced; §2.6 items (Auditor, feature perms, SBU scope) brought in scope.
2. All prior-ADR authority questions now answered.
3. Approval thresholds/policies are org configuration, not domain rules.

### Impacted modules
§2.1/§2.2/§2.5/§2.6 (access), new §2.7 (SoD/approvals), §6.3 (MFA), and authorisation hooks in Journal/Sales/Purchase/Close.

### Gate Review Outcome (13 July 2026)
**Result:** PASSED → LOCKED. Confidence **93%**. Consistent with ADR-001–004.
**Riders retired:** ADR-002 (reverse/void authority); ADR-003 (note/void authority); ADR-004 (close/reopen/approval authority).
**Remaining item (business config, non-blocking):** organisation-specific Approval Policy configuration.
**Resolved:** flat-access contradiction (original review + Amendment 001 #5).

---

## ADR-006 — VAT Treatment & Tax Engine

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 90%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
Adopt **Option B — configurable Tax Code Registry**, split into a **country-agnostic Tax Engine + Tax Packs** (Bangladesh VAT/AIT/VDS = Pack #1; future countries by configuration). Definitions locked: Output VAT (liability, on supply); **Input VAT recoverable → asset `1071`**, non-recoverable → cost; **Standard / Zero-rated / Exempt** as three distinct treatments (**export ITES = zero-rated, input recoverable**, not exempt); VAT-inclusive/exclusive pricing; credit/debit-note VAT in the note period; configurable advance-payment tax point; imports; reverse charge (simultaneous output+input); AIT both directions (2030/1070); **VDS both directions (1072/2025)**; configurable Mushak-9.1 box mapping.

**COO refinements:** (1) Tax Engine country-agnostic, Bangladesh as first Tax Pack; (2) tax codes **effective-dated/versioned**, transactions use the version effective on the transaction date; (3) **reproducibility** — every posted line persists its applied tax snapshot (code, rate, method, jurisdiction, version), immutable per ADR-002.

### Rationale
NBR-correct; IFRS-correct (recoverable input VAT is an asset); matches Xero tax codes; auditable and reproducible; multi-country by design. Fixes the export mislabel (recovers input VAT) and the input-VAT classification error.

### Consequences
1. CoA: `1071 Input VAT Recoverable`, `1072 VDS Receivable`, `2025 VDS Payable`; `2020/2021` relabelled Output VAT.
2. Net VAT = Output − Recoverable Input (now correct).
3. Versioned, reproducible tax snapshot on every posted line.
4. ITES income-tax exemption separated from VAT zero-rating.

### Impacted modules
Sales Invoices (§3.3.2), Chart of Accounts (§3.8.3), Tax Engine (§3.10), VAT Compliance (§4.2/§4.3), Tax Summary (§3.10.6).

### Gate Review Outcome (13 July 2026)
**Result:** PASSED → LOCKED. Confidence **90%**. Consistent with ADR-001–005. **Resolves Amendment 001 remaining-contradictions #3 and #4 — the last accounting-correctness defects from the original review.**
**Riders (external/legal; parameterise the Bangladesh pack, not the engine):** current statutory rates & advance tax point; ITES exemption legal status; input-VAT creditability evidence rules → VAT consultant.
**Open document item remaining overall:** only #7 housekeeping (currency-list mismatch §1.2/§5.1; duplicated §6.5 header).

---

## ADR-007 — Multi-Currency & Foreign Exchange (FX) Policy

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 91%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
Adopt **Option B — IAS 21 entity-level FX** (manual rates in MVP, feed-ready). Functional currency per entity (**BDT** / **CAD**); presentation currency (report render, defaults to functional); transaction currency per document. **Rate Table = effective-dated master data**, immutable once referenced. Invoice-date rate mandatory (sets AR/AP base value); realised FX **per tranche vs the invoice-date rate**; **unrealised revaluation at Soft Close, reversed at next-period start**; foreign bank balances revalued; manual overrides need a reason.

**COO refinements:** (1) entity-level FX is core; **consolidation/CTA/group reporting deferred to the Multi-Entity ADR** (architecture supports); (2) every posted line **references the exact Rate Record ID/version**, not just the numeric rate.

### Rationale
IAS 21-compliant; matches Xero's revaluation + gain/loss tracking; BDT-functional with USD/CAD transactions fits Bangladesh export reality; rate master data + persisted rate reference give full auditability and reproducibility (same mechanism as ADR-006).

### Consequences
1. Three currency roles formalised; ledger kept in functional currency.
2. Realised + unrealised FX both handled; revaluation housed in Soft Close (ADR-004).
3. Rate records are governed, versioned, immutable-once-used master data.
4. Consolidation/CTA reserved for the Multi-Entity ADR.

### Impacted modules
Multi-Currency (§5.1/§5.2), FX Gain/Loss accounts (4050/6220, from ADR-006), Journal (revaluation entries), Reports (revaluation report).

### Gate Review Outcome (13 July 2026)
**Result:** PASSED → LOCKED. Confidence **91%**. Consistent with ADR-001–006.
**Rider retired:** ADR-001 rider #3 (functional currency + multi-tranche realised-FX rule).
**Remaining items:** period-end rate-source policy (external, non-blocking); consolidation/CTA → Multi-Entity ADR (deferred, architecture-supported).

---

## ADR-008 — Migration & Open-Item Conversion

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 91%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
Adopt **Option B — open-item conversion**, source-agnostic. Cutover at a **Conversion Date**; legacy history stays archived in Xero (no historical-transaction replay in MVP). A locked **Conversion Journal** establishes the opening Trial Balance. **AR/AP migrate as individual open invoices/bills** with document number, dates, party, **original transaction currency + amount, and invoice-date Rate Record** (enabling ageing, allocation, and revaluation). CoA mapped to the ADR-006 structure; customers/vendors imported first; bank/tax/fixed-asset opening balances; foreign items always carry original currency + rate.

**COO refinements:** (1) **Idempotent** — re-runnable without duplicates; every imported record carries a **persistent migration identifier** linking to the source. (2) **Full dry-run mode** — unlimited validation runs with reconciliation reports and resettable staging; only an explicit **final migration** posts immutable accounting entries.

### Rationale
Xero-standard cutover; accrual/FX-correct open items (resolves ADR-001 rider #1); low operational risk; strong auditability via the migration audit report; source-agnostic importer mirrors the Tax-Pack pattern for SaaS.

### Consequences
1. §9 rewritten to open-item conversion + staging/dry-run + idempotency.
2. Migrated open items behave as normal documents (settle via Allocations, revalue via FX).
3. Parallel run (1–2 closes) + sign-off gate before Xero switch-off.
4. Fixed-asset register, attachment migration, full historical migration → deferred (non-blocking).

### Impacted modules (v3.0)
§9 (Migration), §5.6 (Allocations settle migrated items), §7 (foreign open items carry Rate Record), §11/§12/§13/§15.

### Gate Review Outcome (13 July 2026)
**Result:** PASSED → LOCKED. Confidence **91%**. Consistent with ADR-001–007. **Resolves ADR-001 rider #1** (open-item granularity) — the last significant rework risk.
Staging = pre-post (draft-equivalent); final migration = immutable post (ADR-002); conversion period locked after acceptance (ADR-004); tax balances reconcile to the last filed Mushak (ADR-006); migration is an authorised high-risk action with four-eyes on final post (ADR-005).
**Riders (deferred, non-blocking):** fixed-asset register; attachment migration; exact Conversion Date (operational — recommend fiscal-year boundary).

---

## ADR-009 — Document Numbering & Sequencing

**Status:** 🔒 LOCKED (gate review passed 13 July 2026; confidence 93%)
**Date:** 13 July 2026
**Decision owner:** Director & COO, Notionhive

### Decision
Adopt **Option B — provisional draft token; statutory number assigned atomically at post.** A **Sequence Registry** holds one server-side atomic counter per {document type × scope}. Scope = **entity + fiscal year** (branch supported, off in MVP; multi-entity/country = new scoped sequences, no architecture change). Statutory series (Invoice `NH-`, Credit `CN-`, Debit `DN-`) are **gapless**; internal artefacts (journals, allocations) may be non-gapless. Drafts carry a non-statutory provisional token; deleting a draft consumes no number. Void documents **keep** their number (never reused); a post that fails after drawing records the number as used-and-voided (explainable gapless trail). Manual numbering restricted to the bounded migration/legacy path (flagged, logged). Migrated items keep their source number + migration ID (ADR-008), not drawing from the live sequence. External references (PO/WBS) are free-text, never the statutory number. Fiscal-year reset configurable per sequence.

**COO refinement — identity vs business key:** every document has (1) a permanent immutable internal **Document ID (UUID)** for all relationships/APIs/references, never user-visible, never changing; and (2) a business-facing **Document Number** from the Numbering Service, which may vary by jurisdiction/entity/regulation. The Document ID is stable for the document's whole lifecycle.

### Rationale
Gapless where statutory, collision-free under concurrency, matches Xero's assign-on-issue; scoped sequences and UUID identity give clean multi-entity/country scalability and robust DDD identity.

### Consequences
1. Concurrency collision (v2.0 defect) eliminated via atomic draw.
2. Draft abandonment no longer creates statutory gaps.
3. UUID identity decouples relationships from jurisdiction-varying numbers.
4. Manual override on live documents removed (migration path only).

### Impacted modules (v3.0)
§1 (numbering), §5.3/§5.7 (assign at post), §14 (DocumentNumber value object, DocumentId identity, NumberingService).

### Gate Review Outcome (13 July 2026)
**Result:** PASSED → LOCKED. Confidence **93%**. Consistent with ADR-001–008. Statutory number immutable at post (ADR-002); void keeps number (ADR-003); migrated numbers preserved (ADR-008); manual path authorised (ADR-005). Closes the v2.0 numbering gap.
**Rider (policy, not architecture):** fiscal-year reset choice for `NH-` (continuous vs annual) — recommend continuous to preserve the Xero-inherited run.

---

## Governance Approval Record — API-M1-001

**Status:** APPROVED
**Date:** 16 July 2026
**Approved artifact:** `PROPOSED_API_CONTRACT_AMENDMENT_M1.md`
**Approved SHA-256:** `5952d79cca49dcbdef0ee684bf579ce28856730bc474ebe899cbba9ec43260bf`

The approved M1 Ledger + Valuation API contract is incorporated into `HiveFin_API_Contracts.md` §8. It freezes the implementation-facing public contracts for M1 Ledger, Tax, and Currency & FX while preserving the approved M0 contract in §7. Applicable tax lookup, applicable FX rate lookup, and realised FX calculation remain internal contracts and are not public HTTP endpoints.

**Traceability:** SRS v3.0 §§4.3–4.4, 5.2, 5.10–5.13, and 7; ADR-002, ADR-004, ADR-005, ADR-006, and ADR-007; Aggregate Design §§1, 5, 9, and 10; Repository Contracts; Database Design; Domain Events; Engineering Constitution API-01 through API-07.

---

## Governance Approval Record — API-APPROVAL-001

**Status:** APPROVED
**Date:** 16 July 2026
**Approved artifact:** `PROPOSED_API_CONTRACT_AMENDMENT_APPROVAL_LIFECYCLE.md`
**Approved SHA-256:** `9edd79b9b181eaab8f99836ae5faf02bf09f307803d587645b837e94182de06f`

The durable maker-checker contract is incorporated into `HiveFin_API_Contracts.md` §9. `ApprovalRequested` and `ApprovalGranted` v1 are incorporated into the Identity & Access section of `HiveFin_Domain_Events.md`. The approved lifecycle contains only pending and approved transitions; it authorizes no rejection or cancellation transition.

**Traceability:** ADR-005; SRS v3.0 §3 and BR-025 through BR-031; Engineering Constitution ARCH-02/05, DOM-09/10, API-01 through API-05, SEC-01/02, AUD-01 through AUD-04, and LOG-01/02; API Contracts §§3, 7.4, 8.1.3, and 9; Domain Events Identity & Access catalogue.

---

## Governance Clarification Record — API-M1-002

**Status:** APPROVED
**Date:** 16 July 2026
**Scope:** M1 shared-protocol compatibility with the approved M0 contract

M1 retains the approved M0 shared-protocol behavior. A malformed caller-supplied `X-Correlation-Id` returns `400 validation`; the server generates a correlation UUID only when the header is absent. The canonical idempotent-replay response header is `Idempotent-Replay: true`.

This clarification corrects the M1 and durable approval lifecycle common-protocol text in `HiveFin_API_Contracts.md` without changing the approved M0 text or behavior. It introduces no new endpoint, state, event, business rule, or application implementation.

**Traceability:** API Contracts §§7.1, 7.3, 7.4, 8.1.1, 8.3.7, 9.2, and 9.4.7; Governance Approval Records API-M1-001 and API-APPROVAL-001; Engineering Constitution P-01 through P-03 and API-01 through API-05.

---

## Governance Clarification Record — API-M1-003

**Status:** APPROVED
**Date:** 19 July 2026
**Scope:** RevaluationRun query-status ownership

The optional `status` filter on `GET /v1/fx/revaluation` accepts only `posted` or `reversed`. A pending approval request is owned by Identity and is not a RevaluationRun. A RevaluationRun is created only when the approved originating command executes successfully; before then, only the Identity ApprovalRequest exists. The originating `POST /v1/fx/revaluation` continues to return that approval resource through the standard `202 pending_approval` command outcome.

This clarification removes `pending_approval` only from the RevaluationRun query status enumeration. It does not change the durable approval lifecycle, fabricate a pending RevaluationRun representation, add an endpoint or event, or alter any application implementation.

**Traceability:** API Contracts §§8.5.3, 8.5.4, 9.3, and 9.4; ADR-005; ADR-007; Aggregate Design §10; Governance Approval Records API-M1-001 and API-APPROVAL-001.

---

## Governance Approval Record — API-M2-001

**Status:** APPROVED
**Date:** 20 July 2026
**Approved artifact:** `PROPOSED_API_CONTRACT_AMENDMENT_M2_DOCUMENTS.md`
**Approved SHA-256:** `801e5043a22f8d3556a9297b0cafbf86ea7ab92cbfe6eabb84bd595389bbde3c`

The approved M2 Documents API contract is incorporated into `HiveFin_API_Contracts.md` §10. It freezes the 24 public Customer, Invoice, Vendor, Bill, and Expense endpoints while preserving the approved M0/M1 protocol. It authorizes draft editing and public soft deactivation, defers attachment transport, and freezes entity/jurisdiction/master-type tax-identifier uniqueness using Unicode NFKC, outer-whitespace trimming, and uppercase-letter normalization without transliteration, punctuation stripping, or fuzzy matching.

The approval introduces no Settlement, Credit/Debit Note, Period Close, ageing, reconciliation, migration, or later-reporting behavior. Ledger posting, Tax/FX determination, and Numbering remain internal contracts owned by their frozen contexts.

**Traceability:** SRS v3.0 §§4.1–4.4 and 5.3–5.5, 5.8–5.9; ADR-001, ADR-002, ADR-005, ADR-006, ADR-007, and ADR-009; Aggregate Design §§0, 3, 4, 7, and 8; Repository Contracts; Database Design; Domain Events; Engineering Constitution API-01 through API-07.

---

## Governance Approval Record — API-M3-001

**Status:** APPROVED
**Date:** 20 July 2026
**Approved artifact:** `PROPOSED_API_CONTRACT_AMENDMENT_M3_SETTLEMENT.md`
**Approved SHA-256:** `84f29614cf3b830c2c24867e83c3731667c37e622ef30b4aa244b407b23cba6f`

The approved M3 Settlement API contract is incorporated into `HiveFin_API_Contracts.md` §11. It freezes seven public Receipt, Payment, PartyCredit, Allocation query, and linked-reversal endpoints while preserving all approved M0/M1/M2 shared-protocol behavior.

All four settlement amounts are non-negative Money values. The approved positive-value invariants are `gross_amount = bank_amount + withholding_amount` and `gross_amount = sum(document_allocations) + unapplied_amount`. Withholding and unapplied party credit are never subtracted. Settlement remains atomic across Allocation, versioned document application, PartyCredit, Numbering, Ledger posting, audit, idempotency, and outbox effects.

The amendment introduces no new event schema. `ReceiptAllocated`, `PaymentAllocated`, `RealisedFXRecognised`, `WithholdingCaptured`, `CreditHeld`, `CreditApplied`, `CreditRefunded`, and `AllocationReversed` remain governed by the existing frozen Domain Events catalogue.

**Traceability:** SRS v3.0 §§4.1–4.4, 5.3–5.6, 6.3, and 6.4; ADR-001, ADR-002, ADR-004, ADR-005, ADR-006, ADR-007, and ADR-009; Context Interaction Matrix §4; Context Map Settlement Partnership; Aggregate Design §§0.3, 2a, and 2b; Repository Contracts Allocation and document partnership contracts; Database Design settlement schema; Domain Events Settlement & Cash Application catalogue; API Contracts §§1–3 and 11; Implementation Roadmap M3; Engineering Constitution ARCH-02 through ARCH-05, DOM-02 through DOM-10, API-01 through API-05, DB-02 through DB-06, and ERR-01 through ERR-05.

---

## Governance Approval Record — API-M3-002

**Status:** APPROVED
**Date:** 21 July 2026
**Approved artifact:** `PROPOSED_GOVERNANCE_AMENDMENT_M3_FOREIGN_CREDIT_TRANCHES.md`
**Approved SHA-256:** `087de4a1c5613541111c8cb79f1b89d93ca199ad137cd516975484f1b103a74f`

This approval refines only foreign party-credit ownership and consumption under API-M3-001. Party credit is persisted as immutable source CreditTranches. Every application and refund explicitly selects all `credit_sources`; every selected tranche carries its own `expected_version`. FIFO, LIFO, weighted-average, pro-rata, automatic selection, and source-selection shortcuts are prohibited. PartyCreditBalance is a rebuildable projection and cannot authorize consumption.

For foreign application, each tranche's immutable source RateRecord is the carrying-rate baseline and the target document's immutable RateRecord is the comparison rate. For foreign refund, the source RateRecord remains the carrying baseline and the exact refund RateRecord is the comparison rate. Realised-FX calculation remains internal to FX. Clients never provide calculated realised FX or functional carrying values.

Consumption and restoration facts are append-only. A reversal restores the exact transaction and functional values to the same source tranches and retains source/comparison RateRecord and original-consumption linkage. CreditHeld, CreditApplied, CreditRefunded, and AllocationReversed retain their existing names and v1 meanings; the approved backward-compatible v2 schemas identify every created, consumed, and restored tranche. All tranche, projection, document, posting, FX, audit, idempotency, and outbox effects remain atomic.

This record supersedes only the aggregate PartyCredit concurrency/source-selection assumptions in API-M3-001. It adds no new public endpoint, event name, withholding rule, rate source, rounding policy, approval threshold, allocation rule, or M4/M5 scope.

**Traceability:** approved proposal §§1–7; API Contracts §11.2.6 and §§11.4–11.6; Aggregate Design §§2a–2b; Database Design settlement schema; Repository Contracts AllocationRepository, CreditTrancheRepository, and Settlement UoW; Domain Events Settlement party-credit v2 schemas; ADR-002, ADR-007, and ADR-009; Engineering Constitution ARCH-02 through ARCH-05, DOM-02 through DOM-10, DB-02 through DB-06, and ERR-01 through ERR-05.
