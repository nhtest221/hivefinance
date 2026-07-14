# HiveFin SRS v2.0 — Amendment 001 (incorporating ADR-001)

**Scope of this amendment:** ONLY the changes required to make the SRS internally consistent with ADR-001
(Accrual ledger of record + derived cash reporting layer). Changes belonging to later decisions
(immutability, credit notes, access control, period close, VAT treatment) are **not** made here and are
listed explicitly in Section E as remaining contradictions.

---

## A. Sections impacted by ADR-001

| # | Section | Nature of change |
|---|---|---|
| 1 | §1.5 Scope | Add Payments & Receipts as a cross-cutting concept; note reporting basis |
| 2 | §3.1.2 / §3.1.4 Dashboard | Basis-label every KPI and chart; separate accrual profit from cash collections |
| 3 | §3.2.5 Journal — Auto-generated entries | **Core rewrite:** two-event posting (issue + settle); add period-end adjustments |
| 4 | §3.3.4 Sales Invoice workflow states | Clarify issuance = accrual recognition point; payment states = settlement events |
| 5 | §3.3.6 Invoice actions | "Record Payment" redefined as a Receipt Allocation |
| 6 | §3.4.6 Bill workflow states | Approval = accrual recognition; payment = settlement |
| 7 | §3.5.3 Expenses | Clarify accrued vs cash-settled expenses |
| 8 | §3.9.2 / §3.9.5 Reports | Add Trial Balance + General Ledger detail (accrual core); add basis toggle |
| 9 | §3.10.6 Tax Summary | **Correctness fix:** output VAT recognised on *issued* invoices, not *paid* |
| 10 | §4.2.1 / §4.2.3 VAT compliance | Recognition point aligned to accrual/supply |
| 11 | §5.2.1 Invoice vs receipt rate | Mandatory invoice-date base-currency valuation of AR/AP |
| 12 | §5.2.2 Forex gain/loss | Add period-end unrealised FX revaluation of open monetary balances |
| 13 | §3.8.3 Chart of Accounts | Add FX Gain/Loss account (consequence of revaluation) |
| — | New | **Payments & Receipts (Allocations)** entity |

---

## B. Redlined changes (Old → New → Reason)

### Change 1 — §3.2.5 Auto-Generated Journal Entries  *(core change)*

**OLD**
> When a sales invoice is marked as paid, a purchase bill is approved, or an expense is recorded, the system shall automatically generate a corresponding journal entry in the background. These system-generated entries shall be visible in the journal log but flagged as [System] and shall not be manually editable.

**NEW**
> The system shall post journal entries on an **accrual basis using a two-event model** — one entry at *recognition* and a separate entry at *settlement*:
>
> **Recognition entries (on issue/approval):**
> - Sales invoice **issued** → Dr Accounts Receivable / Cr Revenue / Cr Output VAT, dated the invoice date.
> - Purchase bill **approved** → Dr Expense (or Asset) / Dr Input VAT / Cr Accounts Payable, dated the bill date.
> - Expense **recorded** → posts per §3.5.3 (immediate cash-settled, or accrued to a payable).
>
> **Settlement entries (on payment, via a Receipt/Payment Allocation):**
> - Customer receipt → Dr Bank/Cash / Dr AIT-Recoverable & Dr VDS-Receivable (if withheld) / Dr-or-Cr FX Gain-Loss (realised) / Cr Accounts Receivable.
> - Vendor payment → Dr Accounts Payable / Cr Bank/Cash / Dr-or-Cr FX Gain-Loss (realised) / Cr AIT-Payable (if withheld).
>
> **Period-end adjustment entries** (accruals, prepayments, depreciation, and foreign-currency revaluation per §5.2.2) are recognised entry types. In the MVP these may be posted as manual journals using system-provided figures.
>
> All system-generated entries are flagged **[System]**, are visible in the journal log, and are not manually editable.

**REASON:** ADR-001 makes accrual the ledger of record. The old single-event ("on paid") model is cash-basis and cannot produce valid AR/AP, ageing, Balance Sheet, or statutory VAT. Recognition must occur at supply, settlement separately.

---

### Change 2 — §3.10.6 Tax Summary Report  *(correctness fix)*

**OLD**
> Total VAT Collected (Output VAT) — Sum of VAT on all **paid** sales invoices
> Total VAT Paid (Input VAT) — Sum of VAT on all approved purchase bills

**NEW**
> Total Output VAT — Sum of VAT on all sales invoices **issued (supplied) within the period**, regardless of payment status.
> Total Input VAT — Sum of VAT on all purchase bills **approved within the period**, regardless of payment status.
> *(Both figures are computed strictly on the accrual basis and are the statutory source for the Mushak-9.1 working. The cash reporting layer must never feed these lines.)*

**REASON:** ADR-001 ring-fences statutory tax to accrual. NBR VAT liability arises on supply, not receipt. The old "paid invoices" basis understated the liability and mixed a cash basis (output) with an accrual basis (input) in the same report.

---

### Change 3 — §3.3.4 Sales Invoice Workflow States

**OLD**
> Sent / Awaiting Payment — Issued to client; need to edit as per the clients requirements. But if we lock in the financial settings then it will not edit.

**NEW**
> Sent / Awaiting Payment — The invoice is **issued**; this is the accrual recognition point (AR and Output VAT are posted, dated the invoice date). Subsequent states — *Partially Paid, Paid* — are **settlement events** recorded through Receipt Allocations (§B, new entity), not through re-recognition. Post-issue corrections are handled by the correction mechanism to be defined in a later ADR (credit/debit notes); direct editing of an issued invoice is out of scope.

**REASON:** ADR-001 separates recognition from settlement, and the original text was ambiguous and self-contradictory. (Editability of issued documents is deferred to the immutability/credit-note decisions — see Section E.)

---

### Change 4 — §3.3.6 Invoice Actions ("Partial Payment" / "Record Payment")

**OLD**
> Partial Payment — Record partial payment against an invoice; system tracks remaining balance.

**NEW**
> Record Receipt — Create a **Receipt Allocation** against one or more invoices. Each receipt captures: receipt date, amount and currency received, manual FX rate (for foreign currency), base-currency equivalent, receiving bank/cash account, and any AIT/VDS withheld by the customer. The system supports partial receipts and a single receipt settling multiple invoices, and tracks remaining balance per invoice.

**REASON:** ADR-001 requires payments to be first-class allocation records so that settlement postings, realised FX, withholding, and the derived cash layer are all computable. A simple "paid amount" field cannot support these.

---

### Change 5 — §3.4.6 Bill Workflow States

**OLD**
> Awaiting Payment — Approved and due; payable confirmed.

**NEW**
> Awaiting Payment — The bill is **approved**; this is the accrual recognition point (Expense/Asset, Input VAT, and AP are posted, dated the bill date). *Partially Paid / Paid* are settlement events recorded through Payment Allocations.

**REASON:** Symmetry with the accrual two-event model for the payables side.

---

### Change 6 — §3.5.3 Expense Entry (new required behaviour)

**OLD** *(no field governing settlement timing existed)*

**NEW** *(add row)*
> Settlement Type — **Cash-settled** (posts Dr Expense / Cr Bank/Cash immediately) or **Accrued** (posts Dr Expense / Cr Accounts Payable, then settled later via a Payment Allocation). Defaults to Cash-settled.

**REASON:** Under accrual, an expense may be incurred before it is paid; the system must be able to represent an unpaid expense as a payable rather than forcing immediate cash reduction.

---

### Change 7 — §3.9.2 Report Inventory (additions)

**OLD** *(inventory listed P&L, Balance Sheet, Expense, Sales, Tax Summary, AR Ageing, AP Ageing)*

**NEW** *(add)*
> - **Trial Balance** — all accounts with debit/credit balances; totals must equal; accrual basis; date-scoped.
> - **General Ledger (Account Detail)** — per-account transaction listing with running balance; accrual basis.
> - **FX Revaluation Report** — period-end unrealised gain/loss on open foreign-currency AR/AP (per §5.2.2).
> - **Cash Collections & Payments (management)** — cash-native dashboards derived from Allocations.

**REASON:** A double-entry ledger is unusable for close and audit without a Trial Balance and GL drill-down (previously present only in the migration module). Revaluation and cash dashboards are direct ADR-001 consequences.

---

### Change 8 — §3.9.5 Report Filters (add)

**OLD** *(filters: date range, fiscal period, SBU, comparison, PDF/CSV export)*

**NEW** *(add row)*
> Reporting Basis — For reports where it is meaningful (management P&L, dashboards): **Accrual (default)** or **Cash (derived)**. Balance Sheet, Trial Balance, General Ledger, VAT Summary, and AIT registers are **accrual-only** and expose no cash toggle.

**REASON:** ADR-001 requires every report/KPI to be basis-labelled and ring-fences statutory reports to accrual.

---

### Change 9 — §3.1.2 & §3.1.4 Dashboard (basis labelling)

**OLD**
> Net Profit / Loss — Year to Date — Revenue minus all expenses from Jul 1 to current date.

**NEW**
> Net Profit / Loss (Accrual) — Year to Date — accrual revenue minus accrual expenses, Jul 1 to date; labelled **Accrual**.
> Cash Collected / Cash Paid (YTD) — new cash-native tiles derived from Allocations; labelled **Cash**.
> All charts (Revenue vs Expenses, Cash Flow Trend, Receivables Ageing) carry an explicit basis label; the Cash Flow Trend is cash-basis by definition.

**REASON:** Prevents a management cash figure from being misread as a statutory/accrual result.

---

### Change 10 — §5.2.1 Invoice vs Receipt Rate (mandatory invoice-date valuation)

**OLD**
> When a USD invoice is raised, the system records the invoice amount in USD. When payment is received, the user records [receipt rate, BDT equivalent, etc.]

**NEW**
> When a foreign-currency invoice is issued, the system records **both** the transaction-currency amount **and** its base-currency (BDT) equivalent using a **mandatory manual invoice-date exchange rate**. This base-currency value is what posts to Accounts Receivable. On receipt, the user records the receipt-date rate; the realised FX difference vs the invoice-date rate is posted to the FX Gain/Loss account.

**REASON:** Accrual requires AR/AP to carry a base-currency value from the moment of recognition, so the Balance Sheet is stated in BDT and realised FX can be computed. The old text captured a rate only at receipt.

---

### Change 11 — §5.2.2 Forex Gain/Loss (add period-end revaluation)

**OLD**
> …In the MVP, this difference is not automatically posted… Auto-posting of realised forex gain/loss is a documented Phase 2 requirement.

**NEW**
> Realised FX gain/loss is computed at settlement (invoice-date rate vs receipt-date rate). In addition, at each period end the system shall produce an **FX Revaluation Report** restating all **open** foreign-currency AR/AP at the period-end rate and computing the **unrealised** gain/loss. In the MVP, the accountant posts the revaluation and realised FX as manual journals using these system-provided figures; auto-posting is Phase 2.

**REASON:** Without period-end revaluation the accrual Balance Sheet misstates foreign monetary balances every close. ADR-001 makes accrual correctness mandatory.

---

### Change 12 — §3.8.3 Chart of Accounts (new account)

**OLD** *(no FX gain/loss account exists)*

**NEW** *(add)*
> - **4050 — Realised/Unrealised FX Gain (Other Income)**
> - **6220 — Realised/Unrealised FX Loss (Operating Expense)**
> *(or a single net FX Gain/Loss account, at the accountant's preference)*

**REASON:** Settlement and revaluation postings require a destination account; none existed.

---

## C. New entity introduced by ADR-001

**Entity: Payment / Receipt Allocation**

| Attribute | Notes |
|---|---|
| Allocation ID | System-generated |
| Direction | Receipt (from customer) / Payment (to vendor) |
| Linked document(s) | One or many invoices/bills (supports batch settlement) |
| Settlement date | Actual bank/cash movement date |
| Amount & currency | In transaction currency |
| FX rate (manual) | Required for foreign currency |
| Base-currency equivalent | Auto-calculated |
| Bank/Cash account | Which account moved |
| AIT withheld | Customer-side (→ 1070 Recoverable) or vendor-side (→ 2030 Payable) |
| VDS withheld | If applicable (→ VDS receivable) |
| Realised FX | Auto-computed vs document's recognition-date rate |
| Created by / at | Audit attribution |

---

## D. New business rules, workflows, reports, and acceptance criteria

**New business rules**
- BR-001: Recognition occurs at issue/approval; settlement is a separate event.
- BR-002: Statutory VAT and AIT are computed on the accrual basis only; the cash layer never feeds them.
- BR-003: The cash view is derived by re-timing recognition to the settlement date, pro-rated by the paid proportion, valued at the receipt-date rate, net of withholding — via a single documented algorithm.
- BR-004: Foreign monetary balances (AR/AP/bank) are revalued at period end (unrealised FX); realised FX is recognised at settlement.
- BR-005: Every report and KPI carries an explicit basis label (Accrual / Cash / Balance).
- BR-006: Receipts capture customer-withheld AIT/VDS so the receivable clears in full even when cash received is less than invoiced.

**New workflows**
- WF-001: Two-event posting (issue → settle).
- WF-002: Period-end adjustment & FX revaluation.
- WF-003: Cash-view derivation from the accrual ledger.

**New reports**
- Trial Balance (accrual, core); General Ledger detail (accrual, core); FX Revaluation Report; Cash Collections/Payments dashboards. *(Full cash-basis P&L restatement — scope TBD per ADR-001 open sub-decision.)*

**New acceptance criteria (samples)**
- AC: Issuing an invoice posts AR + Output VAT dated the invoice date, before any payment.
- AC: A single receipt can settle multiple invoices, each tracking its own remaining balance.
- AC: A foreign receipt records realised FX = (receipt-rate − invoice-rate) × amount, posted to the FX account.
- AC: The Tax Summary output-VAT figure includes issued-but-unpaid invoices and excludes cash receipts as a driver.
- AC: A customer receipt net of AIT clears the receivable in full and posts the AIT to 1070.
- AC: Switching a report's basis to Cash never changes the Balance Sheet, TB, GL, or VAT Summary (they have no cash mode).

---

## E. Remaining contradictions (NOT resolved by ADR-001 — owned by later decisions)

1. **§3.2.3 — posted entries "editable and deletable."** Directly conflicts with the accrual audit trail. → **Decision 2 (immutability).**
2. **§3.3.6 — "void-and-reissue" for sent invoices; no credit-note mechanism.** Non-compliant once VAT-reported. → **Credit/Debit Note decision.**
3. **§4.2.2 — input VAT posted to a *Payable* (liability) account.** Accounting classification error (input VAT is recoverable/an asset). → **VAT treatment decision.**
4. **"Exempt" vs "zero-rated" export treatment.** Affects input-VAT recovery and labelling. → **VAT treatment decision.**
5. **§2.x — flat access model.** No segregation of duties for tax config, posting, voids, user management. → **Access-control decision.**
6. **Period close/lock referenced but undefined.** Accrual needs a defined close to finalise adjustments and revaluation. → **Period-close decision.**
7. **Housekeeping:** currency list differs between §1.2 and §5.1; §6.5 header duplicated. → resolve at final SRS re-issue.

---

## F. Consistency statement

With Amendment 001 applied, the SRS is **internally consistent with ADR-001**: the ledger is unambiguously accrual, recognition and settlement are separated via a first-class Allocation entity, statutory VAT/AIT are ring-fenced to accrual, foreign balances are valued and revalued, and cash reporting is a derived, clearly-labelled layer that creates no second ledger.

**Full internal consistency of the entire SRS is NOT yet achieved** — seven items in Section E remain and are intentionally owned by upcoming decisions. The document is consistent *with respect to the accounting-basis decision*, which is the gate we set for proceeding.
