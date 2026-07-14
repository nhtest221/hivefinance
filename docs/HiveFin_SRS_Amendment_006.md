# HiveFin SRS v2.0 — Amendment 006 (incorporating ADR-006)

**Scope:** ONLY changes for ADR-006 (VAT Treatment & Tax Engine). Fixes input-VAT classification and export treatment. Resolves Amendment 001 remaining-contradictions #3 and #4.

---

## A. Sections impacted

| # | Section | Change |
|---|---|---|
| 1 | §3.3.2 Invoice tax rules | Export = **zero-rated** (not exempt); tax-code driven |
| 2 | §3.8.3 Chart of Accounts | Input VAT → asset; add VDS accounts; relabel Output VAT |
| 3 | §3.10 Tax Engine | Restructure: country-agnostic **Engine** + **Bangladesh Tax Pack**; versioned codes; reproducibility |
| 4 | §4.2 VAT compliance | Input VAT recoverable = asset; net VAT = output − recoverable input |
| 5 | §4.3 ITES | Separate income-tax exemption from VAT zero-rating |
| 6 | §3.10.6 Tax Summary | Correct net VAT computation |

---

## B. Redlined changes (Old → New → Reason)

### §3.3.2 Export treatment
**OLD**
> Foreign / Export — VAT Exempt — ITES export remittance exemption.

**NEW**
> Foreign / Export — **Zero-rated (0%)**, a *taxable* supply with **input VAT recoverable.** Labelled "Zero-rated Export (ITES)". This is distinct from Exempt (which carries no input-VAT recovery).

**REASON:** Exports are zero-rated, not exempt; the old label forfeited input-VAT recovery and misstated the return. Fixes original-review defect.

### §3.8.3 Chart of Accounts
**OLD:** `2020 VAT Payable (15%)`, `2021 VAT Payable (5% IT/ITES)`; input VAT posted to these (per §4.2.2).
**NEW:**
- Relabel `2020 → Output VAT Payable (Standard 15%)`, `2021 → Output VAT Payable (5% IT/ITES)`.
- Add `1071 — Input VAT Recoverable` [Current Asset].
- Add `1072 — VDS Receivable` [Current Asset]; `2025 — VDS Payable` [Current Liability].
- Non-recoverable input VAT is **expensed into the related cost**, not capitalised as an asset.

**REASON:** Recoverable input VAT is an asset — the core §4.2.2 correctness fix; VDS needs its own accounts (both directions).

### §3.10 Tax Engine → Engine + Tax Pack (restructure)
**OLD:** Bangladesh-specific tax logic embedded in the module.
**NEW:**
> The **Tax Engine is country-agnostic**: it resolves a **Tax Code** per line, computes tax, and maps to GL and return boxes. All jurisdiction rules live in **Tax Packs**. **Bangladesh (VAT + AIT + VDS) is the first Tax Pack**; further countries (e.g. Canada GST/HST) are added by configuration, not code.
> **Tax Code** = {jurisdiction, treatment (Standard/Zero-rated/Exempt), rate, recoverable flag, calc method, GL mapping, return-box mapping, **effective-dated versions**}.
> **Versioning:** a transaction uses the tax-code version **effective on its tax-point (transaction) date**; historical transactions never change when rules change.
> **Reproducibility:** every posted line **persists** the applied tax code, rate, calc method, jurisdiction, and rule version, as part of the immutable posted record (ADR-002) — so reports and audits reproduce the original result forever.

**REASON:** COO refinements #1–#3; enables versioned, reproducible, multi-country tax.

### §4.2 / §3.10.6 VAT computation
**NEW:** Net VAT payable = **Output VAT − Recoverable Input VAT** per return period (now correct because input VAT is an asset). VDS withheld is a settlement item, **not** a reduction of the output-VAT liability.

### §4.3 ITES clarification
**NEW:** The **income-tax** ITES exemption (and its expiry-warning banners) is separate from the **VAT** treatment of exports (zero-rating). The two are governed by different laws and dates and are tracked independently.

**REASON:** The SRS conflated two different taxes.

---

## C. Definitions locked

Output VAT (liability, on supply) · Input VAT (recoverable→asset 1071 / non-recoverable→cost) · Standard (15%/5%) · Zero-rated (0%, input recoverable) · Exempt (no VAT, no input recovery) · VAT-inclusive/exclusive pricing (per-document flag) · Credit/Debit-note VAT in note period (ADR-003) · Advance-payment tax point (configurable) · Imports (import-VAT code, recoverable on evidence) · Reverse charge (imported services: simultaneous output+input) · AIT (2030 payable / 1070 recoverable) · VDS (1072 receivable / 2025 payable) · Mushak-9.1 box mapping (configurable per pack).

---

## D. New rules & acceptance criteria

**Rules**
- BR-032: Tax Engine is country-agnostic; jurisdiction rules live in Tax Packs; Bangladesh is Pack #1.
- BR-033: Tax codes are effective-dated; transactions use the version effective on the tax-point date.
- BR-034: Every posted line persists its applied tax snapshot (code, rate, method, jurisdiction, version), immutable per ADR-002.
- BR-035: Recoverable input VAT → asset 1071; non-recoverable → cost.
- BR-036: Export = zero-rated (input recoverable); exempt supplies carry no input recovery.
- BR-037: Reverse charge posts output and input VAT simultaneously.
- BR-038: VDS is recorded both directions; output-VAT liability remains fully recognised.

**Acceptance criteria (samples)**
- AC: A foreign-client invoice is zero-rated, and its related input VAT remains recoverable.
- AC: Input VAT on a purchase posts to 1071 (asset), not to a payable.
- AC: Changing a VAT rate creates a new tax-code version; a prior-dated invoice still computes on the old version.
- AC: Re-running a hard-closed period's VAT report reproduces the original figures despite a later rate change.
- AC: A customer-withheld VDS posts to 1072 while the full output VAT stays on the liability.
- AC: Net VAT = output − recoverable input for the return period.

---

## E. Remaining items (external/legal — parameterise the pack, not the design)

1. Current statutory rates and the advance-payment tax point → VAT consultant.
2. Current legal status of the ITES income-tax exemption → VAT consultant.
3. Precise input-VAT creditability evidence rules (Mushak-6.3) → VAT consultant.

*(These configure the Bangladesh Tax Pack; they do not alter the engine.)*

---

## F. Consistency statement

Consistent with ADR-001 (output on supply, input accrued on approved bills), ADR-002 (tax snapshot part of the immutable record → reproducibility), ADR-003 (note VAT in note period), ADR-004 (versioning by transaction date; reproducible locked-period reports), ADR-005 (tax config is Owner-authorised, four-eyes). **Resolves Amendment 001 remaining-contradictions #3 (input-VAT classification) and #4 (exempt vs zero-rated)** — the last accounting-correctness defects from the original review. Only external legal parameters remain, owned by the VAT consultant.
