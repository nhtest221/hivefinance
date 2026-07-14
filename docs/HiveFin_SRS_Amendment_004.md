# HiveFin SRS v2.0 — Amendment 004 (incorporating ADR-004)

**Scope:** ONLY changes for ADR-004 (Period Close, Locking, Reopening). Finalises open dating riders on ADR-001/-002/-003.

---

## A. Sections impacted

| # | Section | Change |
|---|---|---|
| 1 | New — §3.11 Accounting Periods | Add period lifecycle + allowed operations |
| 2 | §3.2 Journal | Finalise reversal dating (closes ADR-002 BR-012); adjusting entries in Soft Close |
| 3 | §3.3.6 / §3.4.7 | Anchor void-window to Hard Close + VAT lock (closes ADR-003 rider #3) |
| 4 | §5.2.2 FX | FX revaluation runs at Soft Close, posted before Hard Close |
| 5 | §3.10.6 / §4.2 VAT | VAT figures freeze and lock at Hard Close (feeds ADR-003 void test) |
| 6 | §3.9 Reports | Trial Balance review as Hard-Close gate; add month-end/year-end procedures |
| 7 | §6.4 Audit | Log all state transitions, reopens, post-close adjustments |

---

## B. New section — §3.11 Accounting Period Lifecycle

**States & allowed operations**
- **Open** — all normal posting (invoices, bills, expenses, journals, allocations).
- **Soft Close** *(repeatable)* — normal day-to-day posting blocked; **only** adjusting entries: accruals, prepayments, depreciation, FX revaluation, reclassifications. Finance may run **multiple** review/adjust cycles before Hard Close.
- **Hard Close** — fully locked; no entry may carry a date within the period; **VAT return locked.** This is the state ADR-002/ADR-003 test against.
- **Reopened** — Hard/Soft Close temporarily lifted; every reopen is reason-coded, logged, and requires a re-close.

**Reversal & correction dating (finalises ADR-002 BR-012 / ADR-003 rider #3)**
- Original in an **Open/Soft-Closed** period → correction may date to that period.
- Original in a **Hard-Closed** period → correction posts to the **current open period**, never reaching back. If it affects a filed VAT period, it flows as a credit/debit-note adjustment (ADR-003), not a restatement.

**Timing**
- Accruals / prepayments / depreciation / FX revaluation → Soft Close, posted before Hard Close.
- VAT figures freeze at Hard Close; filing marks the period **VAT-locked.**

**Month-end checklist (gate to Hard Close):** reconcile all banks → post accruals & prepayments → post depreciation → run + post FX revaluation → review Trial Balance → generate P&L / Balance Sheet / VAT Summary → **Hard Close** → file VAT.

**Year-end:** all 12 months hard-closed → final Soft Close for year-end adjustments → roll net profit to `3020 Retained Earnings` and clear `3030 Current-Year P&L` → **Hard Close** the fiscal year (Jul–Jun per ADR-001).

**Reopening rules (per COO clarification)** — Hard Close is **irreversible through normal operations.** Reopening requires **all**: authorised role; mandatory reason; **management approval**; audit-log entry; **automatic notification to affected users**; and a mandatory re-close afterward.

---

## C. Redlined changes (Old → New → Reason)

### §3.2 Journal — reversal dating
**OLD (Amdt 002 BR-012, provisional):** reversal posts to an open period; else current open period.
**NEW:** Superseded by §3.11 dating rules (now final and period-state-driven).
**REASON:** Period lifecycle now defines "open/closed" precisely.

### §5.2.2 FX Revaluation — timing
**OLD (Amdt 001):** period-end FX revaluation produced; accountant posts manual journal.
**NEW (append):** Revaluation is executed during **Soft Close** and its journal posted **before Hard Close**, so the locked period reflects revalued foreign balances.
**REASON:** Gives revaluation a defined deadline within the lifecycle.

### §3.10.6 / §4.2 — VAT lock
**OLD (Amdt 001/003):** Tax Summary computed on accrual; note adjustments in note period.
**NEW (append):** At **Hard Close**, the period's VAT figures are frozen and the return locked. Post-lock adjustments occur only via credit/debit notes in the current open period (ADR-003).
**REASON:** Makes ADR-003's "not in a filed VAT return" void-condition enforceable.

---

## D. New rules & acceptance criteria

**Rules**
- BR-019: A period is always in exactly one state: Open / Soft Close / Hard Close / Reopened.
- BR-020: Soft Close permits adjusting entries only and is **repeatable** until Hard Close.
- BR-021: Hard Close blocks all in-period dated postings and locks VAT; it is irreversible via normal operations.
- BR-022: Reopening requires authorised role + reason + management approval + audit log + user notification + mandatory re-close.
- BR-023: Corrections to hard-closed periods post to the current open period (finalises ADR-002/003 dating).
- BR-024: Year-end rolls net profit to 3020 and clears 3030 before fiscal-year Hard Close.

**Acceptance criteria (samples)**
- AC: Posting a normal invoice/bill/expense dated in a Soft-Closed period is rejected; an adjusting journal is allowed.
- AC: Any posting dated in a Hard-Closed period is rejected regardless of user role.
- AC: Voiding is impossible once the invoice's period is Hard-Closed or VAT-locked (defers to credit note).
- AC: Reopening a Hard Close without management approval is rejected; a successful reopen notifies affected users and logs the reason.
- AC: FX revaluation posted in Soft Close appears in the Hard-Closed Balance Sheet.
- AC: Year-end close moves net profit to Retained Earnings and zeroes Current-Year P&L.

---

## E. Remaining item (dependency — not a contradiction)

1. **Authorisation of Soft/Hard Close, Reopen, and "management approval"** → **access-control ADR** (SoD). The *requirements* are fixed here; the *role definitions* are owned there.

**Riders retired by ADR-004:** ADR-002 rider #1 (reversal dating); ADR-003 rider #3 (note dating vs period); ADR-001 riders on period-end adjustment/revaluation timing.

---

## F. Consistency statement

Consistent with ADR-001 (Soft Close is where its period-end adjustments live — completing the accrual model), ADR-002 (finalises reversal dating; hard-closed corrections go to the current open period), and ADR-003 (Hard Close + VAT-lock make the void-window conditions enforceable). One dependency remains: close/reopen **authorisation**, owned by the access-control ADR.
