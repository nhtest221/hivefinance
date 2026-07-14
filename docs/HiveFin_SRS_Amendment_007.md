# HiveFin SRS v2.0 — Amendment 007 (incorporating ADR-007)

**Scope:** ONLY changes for ADR-007 (Multi-Currency & FX Policy, entity level). Consolidation/CTA deferred to the Multi-Entity ADR. Closes ADR-001's FX rider.

---

## A. Sections impacted

| # | Section | Change |
|---|---|---|
| 1 | §5.1 Supported currencies | Add functional / presentation / transaction currency definitions |
| 2 | §5.2.1 Invoice vs receipt rate | Reference exact rate record; invoice-date rate mandatory |
| 3 | §5.2.2 Forex gain/loss | Realised per tranche; unrealised revaluation at Soft Close + next-period reversal |
| 4 | §5.2.3 Rate source | Rate Table as effective-dated **master data**; manual, feed-ready |

---

## B. Redlined changes (Old → New → Reason)

### §5.1 Currency roles
**NEW (add):**
> - **Functional currency** (per entity, IAS 21): **BDT** for Bangladesh, **CAD** for Canada. Each entity's ledger is maintained in its functional currency (ADR-001).
> - **Presentation currency:** the currency a report is rendered in; defaults to functional, may differ (for future consolidation).
> - **Transaction currency:** the currency a document is denominated in (BDT/USD/CAD/…).

**REASON:** IAS 21 requires these three roles to be distinct; the SRS treated currency as a single flat list.

### §5.2.3 Exchange rates = master data
**OLD**
> Exchange rates are entered manually by the user at the time of payment recording… The system shall store a historical rate log.

**NEW**
> Exchange rates are **master data**: a **Rate Table** of effective-dated records per currency pair, each carrying rate, date, source, and the user who entered it. Rates are **manual in MVP** and the design is **feed-ready**. A rate record is **immutable once referenced** by a posted transaction (ADR-002); superseded rates are never overwritten. Manual overrides at entry require a **mandatory reason** and are logged; a reference rate may be shown for sanity but is advisory, not blocking.

**REASON:** Rates must be governed, versioned, and auditable — not free-typed numbers.

### §5.2.1 / §5.2.2 Rates on transactions & FX gain/loss
**OLD (Amdt 001):** invoice-date rate sets AR base value; realised FX displayed; revaluation report at period-end (manual journal).
**NEW:**
> - **Every posted line permanently references the exact Rate Record (ID/version)** used — not just the numeric rate (COO refinement #2; reproducibility, ADR-006).
> - **Realised FX** at settlement = (settlement-rate − invoice-date rate) × amount → FX Gain/Loss (`4050`/`6220`). Computed **per tranche**, each measured against the **original invoice-date rate** (finalises ADR-001 assumption A5).
> - **Unrealised FX revaluation** runs at **Soft Close** (ADR-004): all open monetary items (AR, AP, foreign bank balances) are restated at the period-end rate, the unrealised gain/loss is posted, and the entry is **reversed at the start of the next period** so settlement isn't double-counted.

**REASON:** Completes IAS 21 (dated rates, realised + unrealised, reversal); ties rates to the reproducible immutable record.

---

## C. Definitions locked

Functional currency (per entity) · Presentation currency (report render) · Transaction currency (document) · Rate Table (effective-dated master data) · Invoice-date rate (mandatory, sets AR/AP base value) · Settlement-date rate (per Allocation) · Realised FX (per tranche vs invoice-date rate) · Unrealised revaluation (Soft Close, reversed next period) · Foreign bank balances (monetary, revalued) · Manual override (reason + log) · Historical rate preservation (immutable once used). **Consolidation, CTA, group translation → deferred to Multi-Entity ADR (architecture supports).**

---

## D. New rules & acceptance criteria

**Rules**
- BR-039: Each entity's ledger is kept in its functional currency (BDT / CAD).
- BR-040: Exchange rates are effective-dated master-data records; immutable once referenced.
- BR-041: Every posted line references the exact Rate Record ID/version used.
- BR-042: Realised FX is computed per tranche against the original invoice-date rate.
- BR-043: Unrealised revaluation posts at Soft Close and reverses at the next period's start.
- BR-044: Foreign-currency bank balances are monetary items and are revalued.
- BR-045: Manual rate overrides require a reason and are logged; tolerance is advisory, not blocking.
- BR-046: Consolidation/CTA are deferred; the architecture must accommodate a future CTA equity reserve and presentation-currency translation.

**Acceptance criteria (samples)**
- AC: A USD invoice posts AR in BDT at the referenced invoice-date rate record.
- AC: Two receipts on one USD invoice each realise FX vs the invoice-date rate, not vs each other.
- AC: At Soft Close, an open USD receivable is revalued to the period-end rate; the entry reverses on the first day of the next period.
- AC: A USD PayPal/Payoneer balance is revalued at period-end.
- AC: Re-opening a posted transaction's detail shows the exact rate record (ID/version) applied.
- AC: A manual override without a reason is rejected.

---

## E. Remaining items

1. **Period-end rate source** (which published BB/bank rate is adopted) — policy input, not architecture → finance policy.
2. **Consolidation / CTA / group translation** → deferred to the **Multi-Entity ADR** (architecture must not preclude it).

**Rider retired:** ADR-001 rider #3 (functional currency + multi-tranche realised-FX rule) — resolved here; only the external rate-source policy remains.

---

## F. Consistency statement

Consistent with ADR-001 (functional currency per entity; invoice-date rate; allocation-based settlement; finalises assumption A5), ADR-002 (rate records immutable once used; rate reference part of the immutable posted line), ADR-004 (revaluation at Soft Close with next-period reversal), and ADR-006 (rate reference mirrors the reproducible tax snapshot). Consolidation/CTA are explicitly deferred to the Multi-Entity ADR with architectural support required.
