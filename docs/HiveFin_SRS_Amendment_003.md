# HiveFin SRS v2.0 — Amendment 003 (incorporating ADR-003)

**Scope:** ONLY changes for ADR-003 (Credit/Debit Notes, Void, Document Correction). Resolves Amendment 001 remaining-contradiction #2. Builds on ADR-001 (accrual + allocations) and ADR-002 (immutability).

---

## A. Sections impacted

| # | Section | Change |
|---|---|---|
| 1 | §1.5 Scope | Add Credit Notes & Debit Notes as first-class documents |
| 2 | §3.3.6 Invoice actions | Redefine Void with 4-condition test; Credit & Reissue → issues a Credit Note |
| 3 | §3.4.7 Bill actions | Debit Note / vendor credit as payables-side correction |
| 4 | New | **Credit Note & Debit Note** documents (numbering, lifecycle, fields) |
| 5 | §3.8.3 Chart of Accounts | Add Customer Credits (liability) + Vendor Credits (asset) |
| 6 | §3.10.6 / §4.2 Tax | Note VAT adjustment posts in the **note's** period (decreasing/increasing) |
| 7 | §3.9.2 Reports | Add Credit/Debit Note register; ageing reflects applied/held credits |

---

## B. Redlined changes (Old → New → Reason)

### Change 1 — §3.3.6 Invoice Actions: Void

**OLD (Amdt 002)**
> Void — Posts a reversal (credit note) that offsets the issued invoice's AR and Output VAT. Remains visible with a *Void* stamp. Nothing is deleted.

**NEW**
> **Void** — An internal reversal (no client-facing document) permitted **only when ALL are true:** invoice is (a) **unpaid**, (b) in the **current open period**, (c) **not in a filed VAT return**, and (d) has **no downstream allocations or settlements.** The invoice stays visible, stamped *Void*; nothing is deleted.
> If **any** condition fails, the invoice **cannot be voided** — correction is via a **Credit Note** (below).

**REASON:** Void is safe only inside the safe-window; outside it, a statutory Credit Note is mandatory (ADR-002: never alter a filed/closed period).

### Change 2 — §3.3.6: Credit & Reissue → Credit Note

**OLD (Amdt 002)**
> Credit & Reissue — creates a credit note against the original and opens a new draft invoice pre-filled from it.

**NEW**
> **Credit & Reissue** *(one-click)* — Issues a **Credit Note** (first-class document, §C) against the original invoice and opens a pre-filled new draft invoice. The credit note carries the statutory VAT adjustment; the reissued invoice is a normal new issuance.

**REASON:** Aligns the workflow with the real document model.

### Change 3 — §3.4.7 Bill Actions (add)

**NEW**
> **Debit Note / Vendor Credit** — The payables-side correction to an approved bill (reduce/increase payable and Input-VAT adjustment). Void of a bill follows the same 4-condition test as invoices; otherwise a Debit Note/Vendor Credit is used.

**REASON:** Symmetric correction path for payables.

### Change 4 — §3.10.6 Tax Summary / §4.2 (add)

**NEW**
> Credit Notes (output-VAT decreasing adjustment) and Debit Notes (input-VAT adjustment) are reported in the Tax Summary of the **period in which the note is issued** — never by restating the original invoice's period. This is the NBR decreasing/increasing-adjustment treatment.

**REASON:** Immutability + filed-return integrity require the adjustment to live in the current open period.

---

## C. New entities — Credit Note & Debit Note

**Type:** First-class accounting documents.
**Numbering:** Own per-entity sequences (e.g., CN-XXXX, DN-XXXX), auto-generated.
**Lifecycle:** Draft (editable) → **Posted** (immutable per ADR-002) → disposition: **Applied / Held / Refunded**.
**Key fields:** reference to original document; reason code *(mandatory)*; full or partial amounts; line-level VAT adjustment; currency + **original invoice-date FX rate** (per ADR-001); disposition.
**Dispositions:**
- **Applied** → allocated (ADR-001 Allocation) to one/more open invoices; reduces receivable.
- **Held** → posts to **Customer Credits (liability)**; available for future application.
- **Refunded** → a Payment Allocation outflow (ADR-001); realised FX on refund per ADR-001.
**Immutability & recursion:** a posted note is corrected only via its **own reversal** (ADR-002).

**CoA additions (§3.8.3):** `2060 — Customer Credits (Unapplied)` [Current Liability]; `1075 — Vendor Credits (Unapplied)` [Current Asset].

---

## D. New business rules & acceptance criteria

**Rules**
- BR-013: Void only if all four conditions hold; else a Credit/Debit Note is mandatory.
- BR-014: A note's VAT adjustment posts in the **note's** issue period; the original period is never restated.
- BR-015: A credit note reverses AR at the **original invoice-date rate**; refund FX per ADR-001.
- BR-016: Credit-note disposition ∈ {Applied, Held→2060, Refunded}. Held balances are visible per customer.
- BR-017: Reason code mandatory on every note.
- BR-018: Notes are immutable once posted; corrected only via their own reversal.

**Acceptance criteria (samples)**
- AC: Voiding is rejected the moment any of the four conditions fails, with the reason shown.
- AC: A credit note against a filed-period invoice posts its VAT adjustment in the current open period.
- AC: A partial credit reduces a single invoice's balance and leaves the remainder open.
- AC: A held credit posts to 2060 and can later be applied to a new invoice via allocation.
- AC: A refunded credit posts an outflow allocation; if foreign, realised FX is computed vs the credit note's rate.
- AC: A posted credit note cannot be edited/deleted; it can only be reversed by its own reversal.

---

## E. Remaining items (dependencies — not contradictions)

1. **NBR adjustment time-limit** for issuing VAT-affecting notes — confirm current statutory window with VAT consultant *(external)*.
2. **Who may issue/authorise notes & voids** → access-control ADR (SoD).
3. **Note dating vs period close** → period-close ADR (reuses ADR-002 BR-012 logic).

*(Amendment 001 remaining-contradiction #2 — no credit-note mechanism / void-and-reissue — is now RESOLVED.)*

---

## F. Consistency statement

Consistent with **ADR-001** (notes post accrual entries; applied/refund use the Allocation entity; FX at original invoice-date rate) and **ADR-002** (notes immutable, corrected via own reversal; void confined to the safe-window that never touches a filed/closed period). Resolves Amendment 001 remaining-contradiction #2. Section E items are forward dependencies owned by later decisions.
