# HiveFin SRS v2.0 — Critical Review & Requirements Validation

**Reviewer role:** Principal PM / Enterprise Solution Architect / Financial Systems Consultant (CPA lens) / QA Lead
**Document under review:** HiveFin Software Requirements Specification v2.0 (20 May 2026)
**Purpose of this review:** Stress-test the *business requirements* before architecture. No design, no code, no stack.
**Verdict up front:** This is a strong *first draft of a bookkeeping tool*, but it is **not yet a production-grade double-entry accounting system**, and it contains at least four accounting-correctness defects that are non-negotiable blockers. Do not proceed to architecture until Sections 3 and the "blind spots" finale are resolved.

---

## 1. Executive Assessment

The document is unusually detailed for an internal MVP — module-by-module field lists, a pre-built chart of accounts, a Bangladesh compliance layer, and an explicit out-of-scope list. That discipline is genuinely above average. **However, detail is not the same as correctness.** The SRS reads as if written by someone who understands *the business and Xero's screens* extremely well, but not the *accounting engine* underneath those screens. The result is a specification that will faithfully reproduce what the finance team *sees* while quietly getting what the ledger *does* wrong.

The single most important finding: **the document cannot decide whether it is a cash-basis or accrual-basis system, and it currently specifies both at once.** It has Accounts Receivable, AR ageing, "Outstanding Receivables" KPIs, and a Balance Sheet (all accrual concepts) — yet the journal-posting rule (§3.2.5) only generates entries "when a sales invoice is marked as paid." Those two things cannot both be true. This contradiction propagates into every report and is the root cause of several downstream defects.

### Scores (1–10)

| Dimension | Score | Rationale |
|---|---|---|
| **Completeness** | 5 | Covers 10 modules in depth but is missing the two most fundamental GL reports — **Trial Balance** and **General Ledger / account detail** (the TB appears *only* inside the data-migration module, §7). Also missing: credit/debit notes, customer advances, bad-debt write-off, AIT withheld *from Notionhive's own income*, and intercompany handling between the two entities. |
| **Clarity** | 6 | Mostly readable, but undermined by internal contradictions: §3.2.3 calls posted entries "mutable… editable and deletable" then immediately restricts deletion; §6.5 appears twice (second copy empty); the currency list differs between §1.2, §5.1; §3.3.4's "Sent" state description is grammatically and logically broken; §2.5 is marked "open to discard" yet the invite flow depends on it. |
| **Functional Coverage** | 5 | The modules chosen are right, but core ledger primitives are missing (see Completeness) and the invoice schema has no SBU dimension despite reports promising SBU-filtered *revenue* (§3.9.4). |
| **Accounting Correctness** | **3** | The weakest area and the reason to halt. Mutable posted entries, cash/accrual confusion, input VAT posted to a *liability* account, output VAT recognised on *cash receipt*, "exempt" vs "zero-rated" export confusion, no period-end FX revaluation, and a Net-VAT-Payable line that nets figures the system explicitly says it won't offset. |
| **Business Logic** | 4 | VAT defaulting, SBU allocation, and ageing logic are well thought out. But there is no period-close mechanism, no approval/authorisation logic (a consequence of flat access), no reversal-of-reversal rule, and no rounding policy. |
| **Scalability** | 7 | 3k txns/month, 15 users, <10 GB/3yr — trivially small. Not a real concern. The only performance risk is *on-the-fly* SBU aggregation and FX revaluation across multiple fiscal years, which is manageable. |
| **Security Readiness** | 5 | TLS, bcrypt (cost ≥12), at-rest encryption, immutable audit log, HTTPS enforcement — all good. Undercut by: **no segregation of duties**, "account lockout can be manually unlocked by *any* active user" (self-defeating), MFA optional, and no defined handling of the sensitive PII/bank data being stored. |
| **Audit Readiness** | 5 | The audit-log design (immutable, before/after JSON, user+timestamp) is genuinely strong. But an immutable audit log wrapped around a **mutable ledger** is theatre — auditors will reject a GL where posted journals can be edited or deleted, no matter how well the change is logged. |
| **Enterprise Readiness** | 4 | Multi-entity is present but there is no consolidation, no intercompany elimination, no segregation of duties, no period close, and no functional-currency framework. This is "small-business bookkeeping," not "enterprise finance." That may be *fine* for your needs — but the document claims enterprise posture it hasn't earned. |

**Overall:** ~**4.6 / 10** as an accounting-system spec. As a *scoping document for a lean internal tool*, closer to 6.5. The gap between those two numbers is the work ahead of us.

---

## 2. Missing Functional Requirements

### CRITICAL (do not build without these)

1. **Trial Balance report.** You cannot close books, produce a Balance Sheet you trust, or hand anything to an auditor without a TB. It currently exists *only* in the migration module. It must be a first-class report.
2. **General Ledger / account activity report (drill-down).** Every account must be openable to see its posting detail with running balance. This is the auditor's and accountant's primary working tool.
3. **Credit Notes and Debit Notes.** Required for post-issue corrections, service credits, discounts after invoicing, and — critically — **VAT adjustments after a return has been filed.** "Void-and-reissue" (§3.3.6) is not compliant once an invoice has been reported in a VAT period.
4. **A coherent posting model (accrual).** Define exactly what journal each document generates *at issuance* and *at settlement* (see Section 6 of this review). Without this, AR/AP, ageing, and the P&L are meaningless.
5. **Period close & locking.** Month-end and year-end close that locks posted transactions and blocks/flags backdated entry into closed periods. Referenced ("if we lock in financial settings") but never specified.
6. **Input VAT (recoverable) as an asset account.** The CoA has no input-VAT asset. §4.2.2 wrongly posts purchase VAT to a *Payable* (liability) account.
7. **AIT withheld *from* Notionhive by customers.** When a local client pays and deducts AIT at source, the receipt is less than the invoice. Today the receivable never clears and account 1070 (AIT Recoverable) is never populated. This is a daily Bangladesh reality.
8. **Customer advances / deposits (unearned revenue).** Agencies collect advances and retainers upfront. There is no liability account and no flow for money received before an invoice exists.
9. **Bad-debt write-off.** A defined path to write off an uncollectible receivable (with the VAT consequence).

### IMPORTANT

10. **Recurring / retainer invoicing.** For a digital agency this is arguably the *most common* billing pattern. "Retainer tracking out of scope" (§3.6.4) may be a mistake — recurring **billing** is different from retainer *tracking*.
11. **Customer statements.** Statement of account to send to a client showing all invoices/payments/balance.
12. **Cash Flow Statement (proper IAS 7 style).** You have a cash-flow *chart* but not a *statement*.
13. **SBU dimension on sales invoices.** Reports promise SBU-filtered revenue; invoices have no SBU field to make that possible.
14. **VAT Deducted at Source (VDS / Mushak-6.6).** Local buyers may withhold VAT at source on your invoices; not handled.
15. **Fixed-asset register & depreciation schedule.** You have the accounts (1090/1091) but depreciation is a manual journal — error-prone and hard to audit.
16. **Batch/split payment matching in reconciliation.** One bank deposit frequently settles multiple invoices (and vice versa). The current 1:1 matching model breaks.
17. **Data retention & archival policy.** NBR record-keeping obligations; "immutable audit log" says nothing about *how long*.
18. **Backup / disaster recovery / RPO-RTO statement.** For a system that *is* your books, this is not optional even in MVP.
19. **Overpayment / credit-balance handling** on both AR and AP.
20. **Refunds** (money out to a customer; money in from a vendor).

### NICE TO HAVE

21. Budget vs actual (already flagged as future — agree).
22. Multi-currency price lists / rate cards.
23. Attachment OCR-assisted data capture for bills.
24. Client portal for invoice viewing/payment status.
25. Saved report templates / scheduled report emails (blocked by the email exclusion).
26. Dimensional tags beyond SBU (e.g., project, client, campaign) — you've excluded project costing, but agencies usually regret this.

---

## 3. Missing / Undefined Business Rules

> These are the rules that make an accounting system *deterministic*. Every one of these is currently silent or contradictory.

- **Cash vs accrual basis** — pick one and state it. (Currently both.)
- **Posting rules** — the exact debit/credit template for each event (invoice issued, invoice paid, partial payment, bill entered, bill paid, expense recorded, AIT withheld, FX on receipt, void, credit note, reversal, conversion).
- **Immutability rule for posted entries** — must be: *posted = immutable; corrections only via reversal or credit note.* Current spec says editable/deletable — **this must change.**
- **Reversal rules** — can a reversal be reversed? Can a system-generated JE be reversed? What date does a reversal take (original date vs today)?
- **Void rules** — can you void an invoice that is partially paid? What happens to the recorded payment and its JE? Can you void across a closed period?
- **Period-close rules** — who can close, who can reopen, what happens to drafts at close, are backdated entries blocked or flagged-and-allowed?
- **Backdating policy** — is a backdated transaction into an *open* prior period allowed? Into a *closed* one?
- **Exchange-rate policy** — source of truth, allowed override, tolerance vs a reference rate, mandatory rate on the *invoice date* (not just receipt), period-end revaluation rate.
- **Rounding policy** — decimal places per currency (BDT vs USD/CAD), round-per-line vs round-on-total, rounding method (half-up vs banker's), and where the rounding difference is posted.
- **Invoice/bill numbering rules** — gapless requirement for VAT? Collision handling on manual override? Concurrency (two users, same next number)? Per-entity sequences?
- **Duplicate-handling rules** — for invoices, bills (same vendor+amount+date+ref?), payments, and re-imported bank CSVs.
- **Allocation rules** — what happens when decimal weights round to 0.9999/1.0001? Is a tolerance band allowed? Can allocations change *after* posting?
- **Permission / authorisation rules** — who may change tax rates, edit the CoA, manage users, post, void, reverse, close periods. "Flat access" is itself a rule, but it's an *inappropriate* one for a money system (see Risks).
- **Audit-trail scope** — is *reading* logged? Are report exports logged? Are login *attempts* logged, not just successes?
- **Version history** — beyond before/after JSON, is there a browsable version chain per record?
- **Data-retention rule** — years retained, purge policy, legal-hold behaviour.
- **Document-ownership rule** — who "owns" a draft; can another user edit/delete someone's draft; what happens to drafts of a deactivated user.
- **FX gain/loss recognition rule** — realised on receipt (defined but not auto-posted) *and* unrealised at period end (entirely missing).
- **VAT recognition point** — on supply/issue (correct) vs on cash receipt (currently specified — likely non-compliant).
- **Tax-rate change effective-dating** — a rate change in Settings must NOT retroactively alter historical invoices.
- **Customer-type change rule** — changing Local↔Foreign must not silently recompute VAT on historical invoices.

---

## 4. Edge Cases (QA lens)

**Numbering & concurrency**
- Two users create invoices simultaneously → same NH-XXXX (race condition). How is this prevented?
- Manual override sets a number that already exists, or a number *below* the current sequence.
- Migration seed set incorrectly → entire sequence off by one.
- Gap created by a voided draft — is the number reused or burned?

**Invoices / payments**
- Zero-amount invoice; negative line item (discount as negative line); credit-balance invoice.
- Overpayment (client pays more than due) — where does the surplus sit?
- Payment in a currency different from the invoice.
- Partial payment of a USD invoice across three receipts at three different rates — how is BDT-equivalent AR and per-tranche FX gain/loss computed?
- One bank deposit clears five invoices; one invoice paid from two deposits.
- Client withholds AIT and/or VDS on payment → receipt < invoice → residual receivable that isn't a real debt.
- Void a partially paid invoice.
- Edit a customer's VAT type after invoices exist.
- Change VAT rate in Settings after invoices issued.
- Invoice dated 30 Jun 2027 vs 1 Jul 2027 — ITES exemption boundary.

**Journals**
- Debit=credit check with mixed base + foreign lines — is equality tested in base currency after rounding?
- 0.333 × 3 allocation = 0.999 ≠ 1.0000 → legitimate rounding rejected by validation.
- Reverse a system-generated JE (spec says system JEs aren't editable — are they reversible?).
- Backdated journal into a closed/reconciled period.
- Attachment deleted after the entry is posted.

**Bank reconciliation**
- Same CSV imported twice; *overlapping* date ranges (partial re-import).
- Bank CSV with reordered columns / new format / non-UTF-8 / commas inside description / amount sign conventions (single signed column vs debit+credit columns).
- Bank-only lines (charges, interest, FX conversion) that must *create* new ledger entries — what JE, and who authorises it mid-reconciliation?
- A reconciled line later needs un-reconciling because the underlying invoice was voided.
- Statement balance won't tie to ledger because of an unposted timing difference.

**Currency / FX**
- FX rate entered as 0, blank, or absurd (10,000 BDT/USD typo).
- Period-end open USD receivables never revalued → Balance Sheet AR misstated.
- CAD entity transaction that touches a BDT account (intercompany).

**Access / data**
- Two users edit the same draft (no record locking specified).
- Two users record payment on the same invoice at the same instant → double payment.
- Deactivate the only Owner/CEO account.
- A locked-out user is unlocked by a colleague who is actually the attacker's accomplice ("any active user can unlock").
- Deactivated SBU still referenced by historical allocations — how does SBU-filtered reporting treat it?
- Delete a CoA account that a *recurring* allocation template points to.

**Time / boundaries**
- Timezone: audit log is UTC, fiscal dates are Bangladesh (UTC+6). An invoice created 11pm BDT is "yesterday" in UTC — dashboard "days overdue" and "today" calculations can be off by a day.
- Leap-year and fiscal-year-boundary transactions.

**Files**
- Attachment >5 MB, wrong type, corrupt PDF, or unreadable scan (you've excluded OCR, so a "receipt" may be an unreadable image with no fallback data-entry requirement).

---

## 5. User Stories (with acceptance criteria)

> A representative but comprehensive set covering every module. Each is written so QA can test it. Where the underlying rule is currently undefined, the acceptance criteria expose the gap deliberately.

### Authentication & Access
**US-A1 — Log in securely.**
*As a* finance user *I want* to log in with email + password *so that* only authorised staff reach financial data.
- AC: Passwords stored bcrypt (cost ≥12); ≥10 chars incl. number + special enforced at set-time.
- AC: 5 consecutive failures locks the account; lock/unlock is itself audit-logged.
- AC: **Unlock requires a privileged role, not "any active user."** *(Change from current spec.)*
- AC: JWT session times out after 60 min inactivity; expiry configurable.

**US-A2 — Switch entities.**
*As a* dual-entity user *I want* to switch between Bangladesh and Canada *so that* I work in the right books.
- AC: Login defaults to Bangladesh.
- AC: Active entity + base currency visibly persistent on every screen.
- AC: No data from one entity is queryable while the other is active.
- AC: Switch reloads CoA/invoices/reports scoped to the new entity without re-auth.

### Sales Invoices
**US-S1 — Create a local IT/ITES invoice.**
*As an* accountant *I want* VAT to default from the customer's type *so that* I don't misapply a rate.
- AC: Customer=Local+IT/ITES → default 5%; Local General → 15%; line-level override allowed.
- AC: Foreign customer → **zero-rated** treatment with an "ITES Export" label (see note: *zero-rated ≠ exempt*).
- AC: Subtotal/VAT/total auto-calc per defined rounding policy.
- AC: On issue, system posts Dr AR / Cr Revenue / Cr Output VAT **at invoice date** *(requires accrual model to be adopted)*.

**US-S2 — Record a partial payment on a USD invoice.**
*As a* finance manager *I want* to record a partial USD receipt at the day's rate *so that* AR reflects reality.
- AC: I enter receipt date, USD amount, manual BDT/USD rate; BDT equivalent auto-calc.
- AC: Remaining balance tracked; invoice moves to Partially Paid.
- AC: Realised FX difference between invoice-date rate and receipt-date rate is displayed.
- AC: If the client withheld AIT/VDS, I can record it so the receivable clears correctly *(requires new fields — currently missing).*

**US-S3 — Correct an already-sent invoice.**
*As an* accountant *I want* to issue a credit note against a sent invoice *so that* VAT and AR are adjusted compliantly.
- AC: A credit note references the original, adjusts AR and output VAT, and appears in the VAT summary for its own period. *(Requires credit-note feature — currently missing; spec's "void-and-reissue" fails this.)*

### Purchase Bills
**US-B1 — Enter a bill with SBU split and AIT.**
- AC: SBU allocation must sum to 1.0000 within the defined tolerance.
- AC: AIT entered → net payable = gross − AIT; AIT posts to 2030.
- AC: Input VAT posts to an **input-VAT asset account** *(currently mis-specified to a payable).*

**US-B2 — Approve and pay a bill.**
- AC: Draft → Awaiting Payment on approval; full/partial payment recorded; Overdue auto-flag past due date.
- AC: Approval identity + timestamp audit-logged. *(Note: "approval" implies a role — undermined by flat access.)*

### Expenses
**US-E1 — Record an operational expense with allocation.**
- AC: Category from CoA-mapped list; SBU split validated to 1.0000; optional AIT; receipt attach ≤5 MB.
- AC: Filterable list (date/category/SBU/method/amount); CSV export.

### Journal
**US-J1 — Post a manual adjustment.**
- AC: Debits must equal credits (in base currency, post-rounding) before posting.
- AC: **Posted entries are immutable; correction is by reversal only** *(change from current spec).*
- AC: Reversal creates a linked counter-entry; original flagged Reversed.

### Chart of Accounts / Tax / SBU (Settings)
**US-C1 — Manage the CoA.**
- AC: Create/edit/deactivate accounts; type editable only if no postings; delete only if zero history.
- AC: **Changing tax config or CoA is restricted to an authorised role** *(change from flat access).*

**US-T1 — Update a VAT rate.**
- AC: Rate change is effective-dated and does **not** alter historical invoices.

### Reports
**US-R1 — Generate a Trial Balance.** *(New — currently missing.)*
- AC: All accounts with debit/credit balances; totals equal; date-scoped; exports PDF/CSV.
**US-R2 — Drill into an account (GL detail).** *(New — currently missing.)*
- AC: Any account opens to a transaction list with running balance.
**US-R3 — Generate a P&L matching the Xero layout** with the computed lines in §3.9.3 and SBU filter.
**US-R4 — Generate a VAT/Tax summary** suitable for the accountant to prepare Mushak-9.1, with output/input VAT on a **consistent, compliant basis.**

### Reconciliation
**US-K1 — Reconcile from a bank CSV.**
- AC: Column mapping saved per bank; auto-match by amount/date/reference; manual assign for the rest.
- AC: Supports one-deposit-to-many-invoices and duplicate-import detection.
- AC: Bank-only lines create authorised ledger entries; reconciliation statement ties to zero.

### Conversion / Migration
**US-M1 — Import opening balances.**
- AC: TB debits = credits; customers/vendors exist or are created; posts a locked "Conversion" journal; produces opening TB/AR/AP/bank reports.

---

## 6. Business Processes (step-by-step)

**Invoice-to-Cash**
1. Accountant creates invoice → VAT defaults from customer type → saves as Draft.
2. On issue: status → Sent; **ledger posts Dr AR / Cr Revenue / Cr Output VAT at invoice date.** *(Accrual — must be adopted.)*
3. Client remits (possibly net of AIT/VDS, possibly FX).
4. Finance records receipt: bank account, amount, date, rate; system posts Dr Bank / Dr AIT-Recoverable(if withheld) / Cr AR; realised FX to a gain/loss account (currently manual).
5. Invoice → Partially Paid / Paid; AR ageing updates.
6. Corrections after issue go through **credit note**, never edit/void of a reported invoice.

**Procure-to-Pay**
1. Bill entered against vendor, coded to CoA, split across SBUs, input VAT and AIT captured.
2. Approval → Awaiting Payment (posts Dr Expense/Asset + Dr Input-VAT / Cr AP + Cr AIT-Payable).
3. Payment recorded → Dr AP / Cr Bank; status Paid.

**Expense capture** — single-step entry, allocation validated, posts Dr Expense (split by SBU) / Cr Bank or Cash, AIT to 2030 if withheld.

**Manual journal** — enter lines → balance check → post (immutable) → reverse if needed.

**Month-end close**
1. Reconcile all bank accounts.
2. Post accruals, prepayments, depreciation.
3. **Revalue open foreign-currency AR/AP at period-end rate** (unrealised FX). *(Missing today.)*
4. Review TB; produce P&L, Balance Sheet, Cash Flow, VAT summary.
5. **Lock the period.**

**Bank reconciliation** — import CSV → auto-match → resolve unmatched (assign or create authorised entry) → statement ties to zero → record reconciled balance/date.

**Audit review** — auditor opens GL detail and audit log; every void/reversal/credit note is traceable to an immutable posted record.

**Exception handling** — defined behaviour for: failed CSV parse, allocation ≠ 1.0000, duplicate detection hit, backdated-into-closed-period attempt, FX outside tolerance.

---

## 7. Assumptions (currently implicit)

**Business** — Notionhive controls its own invoice numbering; recurring/retainer billing is genuinely not needed (doubtful); clients accept invoices delivered *outside* the system (email excluded); two entities rarely transact with each other.

**Technical** — single application instance; LAN-grade performance assumed; manual data entry is acceptable throughput at ~3k txns/month.

**Operational** — finance staff are disciplined enough that flat access won't be abused; someone remembers to close periods; someone manually journals FX gains/losses; someone manually revalues (currently no one does — it's not in scope).

**Accounting** — the P&L "matching Xero exactly" implies Xero is currently correct (verify); the 5% IT/ITES VAT and the ITES income-tax exemption are *currently valid and will remain so through the stated dates* (this is a moving policy target — see Risks); "VAT exempt" is the right characterisation of service exports (**likely wrong — exports are usually zero-rated, which is materially different for input-VAT recovery**).

---

## 8. Risks

| # | Category | Risk | Prob. | Impact | Mitigation |
|---|---|---|---|---|---|
| R1 | Accounting | Mutable posted entries destroy ledger integrity & audit trust | High | Critical | Make posted entries immutable; corrections via reversal/credit note only |
| R2 | Accounting | Cash/accrual confusion misstates P&L, AR/AP, ageing | High | Critical | Adopt accrual; define posting templates before build |
| R3 | Compliance | Output VAT recognised on cash receipt understates NBR liability | Med-High | High | Recognise VAT at supply/issue; align input basis |
| R4 | Compliance | Export treated as "exempt" not "zero-rated" → lost input-VAT recovery & wrong labelling | Med | High | Confirm treatment with tax advisor; model as zero-rated |
| R5 | Compliance | Reliance on ITES exemption whose legal status shifts budget-to-budget | Med | High | Treat exemption dates as configurable *and* re-verify current statute now |
| R6 | Security/Controls | Flat access = zero segregation of duties in a money system | High | High | Introduce minimal roles (who posts/voids/changes tax/manages users) even in MVP |
| R7 | Controls | "Any active user can unlock accounts" defeats lockout | Med | High | Restrict unlock to a privileged role |
| R8 | Data Quality | Manual FX with no period-end revaluation → misstated FX balances | High | Med-High | Add revaluation step / at least a period-end reminder + report |
| R9 | Accounting | AIT/VDS withheld *from* Notionhive not captured → receivables never clear | High | High | Add withholding-on-receipt fields feeding 1070/VDS |
| R10 | Functional | Missing Trial Balance & GL detail → cannot close or audit | High | Critical | Add both as core reports |
| R11 | Functional | Missing credit notes → non-compliant post-issue corrections | High | High | Add credit/debit notes |
| R12 | Operational | No recurring billing for an agency with retainers → heavy manual load | Med-High | Med | Reconsider recurring invoicing for MVP |
| R13 | Data | No backup/DR statement for the system of record | Med | Critical | Define RPO/RTO and backup regime before go-live |
| R14 | Migration | Opening-balance import errors silently corrupt the base | Med | High | Mandatory TB balance check, dry-run, and sign-off gate |
| R15 | UX/Integrity | Invoice-number race condition under concurrency | Med | Med | Server-side atomic sequence per entity |
| R16 | Enterprise | Two related entities with no intercompany/consolidation → messy related-party accounting | Med | Med | Decide now whether intercompany is in scope |
| R17 | AI Reliability | (If any OCR/auto-match "AI" is later added) false matches in reconciliation | Low-Med | Med | Keep matching suggestions human-confirmed; log confidence |
| R18 | Performance | On-the-fly SBU + FX aggregation across years | Low | Low | Not a real risk at this volume |

---

## 9. Open Questions (100+)

**Basis & posting**
1. Cash or accrual? 2. What JE does invoice *issuance* post? 3. What JE does invoice *payment* post? 4. Do expenses post to Bank/Cash immediately or via AP? 5. Are posted entries truly immutable — yes or no? 6. Can a reversal be reversed? 7. Does a reversal take the original date or today's date? 8. Can system-generated JEs be reversed? 9. What posts when a bill is *approved* vs *paid*? 10. How is a void reflected in the ledger?

**VAT / tax**
11. Is service export zero-rated or exempt (they differ)? 12. VAT recognised at issue or receipt? 13. Same basis for input VAT? 14. Is input VAT an asset — which account? 15. Is input-VAT offset in-scope or Phase 2 (the Net-VAT line implies offset)? 16. Do local clients apply VDS (Mushak-6.6)? 17. Where is VDS captured? 18. Is the 5% IT/ITES rate confirmed current with your tax advisor? 19. Is the ITES income-tax exemption currently in force per the latest Finance Act? 20. Are you conflating the VAT export treatment with the income-tax ITES exemption? 21. How is Mushak-6.3 (input evidence) tracked for creditability? 22. Rounding of VAT — per line or total? 23. Does a Settings rate change affect historical invoices (should be *no*)?

**Withholding / AIT**
24. Does the customer withhold AIT on your income? 25. Where does that AIT go (1070)? 26. How does the receivable clear when receipt < invoice due to withholding? 27. Do you issue/collect withholding certificates? 28. Is salary TDS truly out of MVP even though 6020 exists?

**FX / currency**
29. Which currencies are *actually* supported — reconcile §1.2 vs §5.1? 30. Is CAD a base currency for Canada or just a transaction currency? 31. Rate on invoice date — captured? 32. Period-end revaluation of open FX balances — in or out? 33. Where is realised FX gain/loss posted (no account exists)? 34. Rate tolerance / sanity checks? 35. One rate per day or per transaction?

**Numbering / documents**
36. Is gapless numbering required for VAT? 37. How are concurrent-creation collisions prevented? 38. Are sequences per-entity? 39. Can manual override go below the current max? 40. Are voided-draft numbers reused? 41. Do bills need their own sequence or only vendor refs? 42. Do credit notes get their own sequence?

**Period / close**
43. Who can close a period? 44. Who can reopen? 45. Are backdated entries into an *open* prior period allowed? 46. Into a *closed* period — blocked or flagged? 47. What happens to drafts at close? 48. Is year-end roll-forward automatic (retained earnings)? 49. Is there a "lock date" concept?

**Access / SoD**
50. Do you accept flat access for a money system, knowing the control risk? 51. If minimal roles: who may change tax config? 52. Who may edit the CoA? 53. Who may void/reverse? 54. Who may manage users? 55. Who may unlock a locked account? 56. Is a maker-checker step needed anywhere (e.g., journals, bills over a threshold)? 57. Should there be a read-only auditor login even in MVP?

**Reports**
58. Confirm Trial Balance is required as a core report? 59. GL account drill-down required? 60. Cash Flow *Statement* (not just chart)? 61. Customer statements? 62. Is the current Xero P&L verified correct before we mirror it? 63. Do reports need a "posted only" vs "including drafts" toggle? 64. Comparative periods — how many? 65. Are reports as-of-date (point-in-time) or transaction-date based?

**Credit/adjustments**
66. Credit notes in scope? 67. Debit notes? 68. Bad-debt write-off path? 69. Customer overpayment handling? 70. Vendor credit/refund handling? 71. Advances/deposits from customers — which liability account?

**Reconciliation**
72. One-to-many and many-to-one matching supported? 73. What JE do bank-only lines create and who authorises mid-recon? 74. Re-import / overlapping-range handling? 75. Can a reconciled item be un-reconciled (e.g., after a void)? 76. Are there other banks/cards (PayPal, Payoneer) that need "reconciliation" too — they're in the CoA as accounts?

**Multi-entity**
77. Do BD and Canada transact with each other? 78. If so, intercompany balances/eliminations in scope? 79. Consolidated reporting needed? 80. Transfer-pricing documentation implications? 81. Is Canada's fiscal year *actually* Jul–Jun or is that a placeholder?

**Data / migration**
82. What is the exact Xero migration seed (last invoice number, opening TB date)? 83. Are historical transactions needed, or opening balances only (§7.9 says balances only)? 84. Who signs off the opening TB? 85. How are in-flight partially-paid invoices migrated? 86. Retention period for records and attachments?

**Operational / NFR**
87. Where is this hosted and who owns backups? 88. RPO/RTO? 89. Is offsite/geographic backup needed? 90. PII handling for stored contacts/bank details? 91. Timezone of record — UTC vs BDT — and how do fiscal dates resolve? 92. Attachment storage limits and total-capacity plan? 93. Is there a fallback for unreadable receipts (no OCR)?

**Delivery / lifecycle**
94. If email is excluded, how does a client receive an invoice, and what does "Sent" *mean* operationally? 95. Is "Sent" just a status flag with no actual send? 96. Recurring/retainer billing — truly out? 97. Project/campaign cost tracking — truly out, given you're an agency? 98. Depreciation — manual only, or is a fixed-asset register wanted? 99. Is §2.5 user management in or out (it's marked "open to discard" but the invite flow needs it)? 100. Which is authoritative when two sections conflict (e.g., §3.2.3 mutability) — who is the single owner of the SRS? 101. What is the go-live parallel-run plan against Xero, and for how long? 102. What is the acceptance sign-off gate before Xero is switched off?

---

## 10. Future Features (explicitly *not* V1)

- Automated input/output VAT offset and NBR portal filing.
- Auto-calculated AIT by vendor category / payment threshold; salary-slab TDS; challan (Form 58/75) generation.
- Live bank feeds and forex feed; auto-posted realised & unrealised FX.
- Payroll module; fixed-asset register with auto-depreciation.
- Budgeting; project/campaign profitability; client portal.
- Canadian tax engine (GST/HST, T4); intercompany automation & consolidation.
- Feature-level RBAC, SSO/OAuth, and read-only auditor access (if not brought forward).
- Recurring billing automation (candidate to *promote into* V1 — see below).
- Mobile app; multi-language.

---

## 11. MVP Scope Validation

**Consider removing / deferring from MVP:**
- **User-management panel (§2.5)** as specified is half-baked *because* of flat access — either give it real roles or defer it and hard-provision users. Don't ship "any user can deactivate any user."
- **SBU revenue reporting** — either add an SBU field to invoices or scope SBU reporting to *costs only* in V1. Don't promise a report the schema can't produce.
- **Multi-currency journal lines** add real complexity for little MVP value — consider base-currency-only journals in V1.

**Must add before it's a real MVP (do not ship without):**
- **Trial Balance + GL account detail** (core, currently missing).
- **Accrual posting model + immutable posted entries.**
- **Credit notes.**
- **Input-VAT asset account + correct VAT recognition basis.**
- **AIT/VDS withheld-from-us capture.**
- **Period close/lock.**
- **Minimal segregation of duties.**
- **Backup/DR statement.**

**Reconsider bringing forward:**
- **Recurring/retainer invoicing** — for an agency this is likely a bigger daily pain than several things currently in scope.

---

## 12. Production-Readiness Checklist (project gate)

Use this as a go/no-go gate into architecture. Every box must be **owned and answered**, not just built.

**A. Foundational decisions**
- [ ] Cash vs accrual decided and documented.
- [ ] Posting templates written for every event (issue, pay, partial, bill, expense, AIT, VDS, FX, void, credit note, reversal, conversion).
- [ ] Posted-entry immutability rule ratified; correction-by-reversal defined.
- [ ] Period-close & lock rules defined (who closes/reopens, backdating policy).
- [ ] Rounding policy defined per currency.
- [ ] Numbering rules defined (gapless?, per-entity, concurrency, override bounds).

**B. Tax & compliance (sign-off by tax advisor/CPA)**
- [ ] Export = zero-rated vs exempt confirmed; input-VAT recovery consequence understood.
- [ ] VAT recognition point confirmed (supply vs cash).
- [ ] Input-VAT asset account added to CoA.
- [ ] 5% IT/ITES rate and ITES exemption dates verified against current statute.
- [ ] VDS (Mushak-6.6) and AIT-withheld-from-us flows designed.
- [ ] Tax Summary maps cleanly to Mushak-9.1 needs.

**C. Reporting**
- [ ] Trial Balance report specified.
- [ ] GL account drill-down specified.
- [ ] P&L verified against *known-correct* current Xero P&L.
- [ ] Cash Flow Statement decision made.
- [ ] Customer statements decision made.
- [ ] SBU-on-revenue decision made (add field or scope to costs).

**D. Controls & security**
- [ ] Segregation-of-duties model agreed (even if minimal).
- [ ] Unlock/privileged actions restricted to a role.
- [ ] MFA policy decided.
- [ ] PII & bank-data handling policy written.
- [ ] Audit-log scope confirmed (reads? exports? login attempts?).

**E. Multi-entity & FX**
- [ ] Intercompany/consolidation decision made.
- [ ] Canada fiscal year confirmed.
- [ ] Supported-currency list reconciled across the doc.
- [ ] Period-end FX revaluation decision made.
- [ ] Realised FX gain/loss account added.

**F. Data & operations**
- [ ] Migration plan: seed values, opening TB, in-flight invoices, sign-off gate.
- [ ] Backup/DR: RPO/RTO stated.
- [ ] Retention & archival policy set.
- [ ] Timezone-of-record decided.
- [ ] Parallel-run plan vs Xero and acceptance sign-off criteria defined.

**G. Document hygiene**
- [ ] All internal contradictions resolved (§3.2.3 mutability, §3.3.4 Sent-state wording, duplicated §6.5, currency lists, §2.5 in/out).
- [ ] Single named owner of the SRS with change control.

---

## Things I Believe You Haven't Thought About

*(Direct, as requested. Not sugar-coated.)*

**1. You've specified Xero's *screens*, not Xero's *engine*.** The whole document reproduces what your finance team looks at — invoices, dashboards, activity tabs — with excellent fidelity. What it does *not* reproduce is the double-entry machine underneath. That's why posted entries are "editable," why there's no trial balance, and why VAT is recognised on payment. A custom ledger has to be *more* rigorous than Xero here, not less, because Xero silently enforces rules you're now responsible for encoding. This is the deepest risk in the project.

**2. Your ledger is currently corruptible, and no amount of audit logging fixes that.** An immutable audit log around a mutable general ledger is a false sense of security. If a posted journal can be edited or deleted, your "source of truth" is editable, and any auditor — or NBR — will treat the whole system as untrustworthy. The audit log will faithfully record *how* the books were altered; it won't stop the alteration. Immutability of posted entries is the price of entry for calling this an accounting system.

**3. "Flat access" in a system that moves money is not a simplification — it's a liability.** In the MVP a junior finance member can change VAT rates, invent accounts, void invoices, deactivate the CEO, and unlock any locked account. You are the COO; picture the fraud/error surface. You don't need enterprise RBAC on day one, but you need *at least* two tiers: who can *transact* vs who can *change the rules and the users*. This is a governance decision only you can make, and it should be made before, not after, a mistake.

**4. You've built the tax engine for the tax you *charge* and forgotten the tax that's *taken from you*.** Bangladesh clients routinely withhold AIT (and sometimes VAT) at source when they pay you. Today, when a client pays 90 and withholds 10, your invoice for 100 will sit forever 10 short and your AR ageing will slowly fill with phantom debt. Account 1070 exists in your CoA and *nothing in the entire workflow ever puts anything into it.* That's a tell.

**5. "Export is VAT exempt" is probably the wrong words, and the words matter.** Exempt and zero-rated are different animals: zero-rated lets you *recover input VAT*, exempt does not. If your export services are actually zero-rated (they usually are), calling them "exempt" throughout the system could quietly cost you real input-VAT recovery and mislabel your compliance output. Also, you're blending two separate taxes — the *VAT* treatment of exports and the *income-tax* ITES exemption — into one concept. They expire on different logic and are governed by different laws.

**6. You're relying on a tax holiday that keeps moving.** The ITES income-tax exemption has been repeatedly changed, narrowed, and re-dated in successive Bangladeshi budgets. Hard-coding "valid through June 2027" (even as a configurable date) assumes the rule as of today. Verify the *current* statute before you build the warning banners around a date that may already be wrong.

**7. Two related legal entities means intercompany accounting, whether you planned it or not.** The moment Notionhive Bangladesh does work for Notionhive Canada (or shares a cost, or moves cash), you have a related-party transaction, an intercompany balance that must eliminate on consolidation, and transfer-pricing exposure. The doc says "some cross-entity transactions" in one line and then never handles them. Decide now if intercompany is in or out — retrofitting it is painful.

**8. FX only exists in your system on the day cash arrives.** Real forex accounting has three moments: invoice date (record at that rate), *period-end* (revalue what's still open — unrealised gain/loss), and receipt (realise it). You've specified only the third, and even that isn't auto-posted. At every month-end your foreign receivables will be stated at stale rates and your P&L will miss unrealised movement. For a company invoicing in USD/CAD, this isn't an edge case; it's every close.

**9. Reconciliation in the real world is one-to-many.** Clients pay three invoices with one wire; you pay a vendor across two transfers; the bank charges a fee that never existed in your books. Your matching model is 1:1 and your recon can't create-and-authorise the bank-only lines cleanly. This is where reconciliations actually break, and it's under-specified.

**10. "Sent" doesn't send anything.** Email is excluded, so "Sent / Awaiting Payment" is a status a human sets after emailing the PDF from Gmail. That's fine — but it means your invoice-delivery audit trail lives *outside* the system, and disputes about "we never received it" can't be answered by HiveFin. Be honest in the spec that "Sent" is a manual flag, not a delivery event.

**11. You have no trial balance and no way to prove the books balance.** This is the most mundane and most damning omission. Every accountant's first instinct at month-end is to pull a TB. It exists in your document *only* as part of data migration. Without it as a living report, closing the books is guesswork.

**12. For an agency, you've excluded the two things agencies most need: recurring billing and project profitability.** Retainers and monthly recurring invoices are the lifeblood of agency revenue, and "which client/SBU made money" is the question leadership always asks. You've scoped both out. That may be deliberate — but I'd bet the finance team asks for recurring invoices within the first month of go-live.

**13. Nobody owns this document, and it argues with itself.** Section 6.5 is duplicated (one copy empty). Section 3.2.3 says posted entries are both mutable and locked. The currency list changes between sections. Section 2.5 is marked "discard if complex" while the invite flow depends on it. These aren't typos — they're unresolved decisions wearing the costume of a finished spec. Assign one owner, resolve every contradiction, and re-issue as v3 *before* architecture. A spec that contradicts itself will be "interpreted" by whoever builds it, and their interpretation will not be yours.

---

*I've deliberately not proposed any architecture, schema, or technology — per your instruction. The next productive step is not to build; it's to sit down and answer Section 9. I'd suggest we work through the ~15 decisions in the Section 12 gate that unblock everything else (accrual vs cash, immutability, VAT basis, SoD, TB/GL, credit notes, withholding-from-us). Once those are settled, the rest of the spec largely writes itself — and the architecture becomes almost mechanical.*
