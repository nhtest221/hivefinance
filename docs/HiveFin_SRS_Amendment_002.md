# HiveFin SRS v2.0 — Amendment 002 (incorporating ADR-002)

**Scope:** ONLY changes required to make the SRS consistent with ADR-002 (Posted-Entry Immutability & Correction Model). Resolves remaining-contradiction #1 from Amendment 001. Credit/debit-note *mechanics* are established in principle only; their full specification is the next ADR.

---

## A. Sections impacted

| # | Section | Change |
|---|---|---|
| 1 | §3.2.3 Journal workflow states | **Core:** Posted = immutable; remove "editable and deletable" |
| 2 | §3.2.5 (as amended) [System] entries | Add: immutable; reversed only via source document |
| 3 | §3.3.6 Invoice actions | Redefine Void as reversal; Edit = draft only; add Credit & Reissue |
| 4 | §3.4.7 Bill actions | Redefine Void as reversal; add Reverse & Correct |
| 5 | §3.5.3 Expenses | Posted expense corrected by reversal; add Reverse & Correct |
| 6 | §6.4 Audit & compliance logging | Reinforce: posted financial records immutable, not only the audit log |
| 7 | New cross-cutting | Correction Workflows capability |
| — | §7.5 Conversion | Already consistent ("once posted, entries are locked") — no change |

---

## B. Redlined changes (Old → New → Reason)

### Change 1 — §3.2.3 Journal Workflow States *(core)*

**OLD**
> Posted — Committed to the ledger; creates mutable accounting records, editable and deletable
> *(note)* Posted journal entries shall be deleted only exception if we lock in the financial settings., preserving the full audit trail.

**NEW**
> **Posted** — Committed to the ledger. **Immutable: cannot be edited or deleted by any user, including administrators.** Corrections are made only by posting a new **linked** entry (reversal or adjusting journal). The original and its correction reference each other bidirectionally.
> **Reversed** — A reversal counter-entry has been posted; the original is flagged *Reversed* and remains fully visible. Both entries persist permanently.
> *(note replaced)* Posted entries are **never** edited or deleted. Every correction creates a new posted entry linked to the original, preserving a complete and tamper-evident audit trail.

**REASON:** The "mutable / editable and deletable" specification destroys the audit trail and would allow retroactive alteration of filed VAT periods. ADR-002 makes posted entries strictly immutable.

---

### Change 2 — §3.2.5 (as amended) System-Generated Entries

**OLD** *(Amendment 001 text: "…are flagged [System]… and are not manually editable.")*

**NEW** *(append)*
> `[System]` entries are **immutable** and are **never reversed directly.** They are reversed only by reversing or voiding their **source document** (invoice, bill, expense, or allocation), which automatically posts the linked reversing `[System]` entry. This guarantees a system entry can never desynchronise from the document that generated it.

**REASON:** Preserves the recognition↔settlement linkage locked in ADR-001; prevents orphaned or contradictory system postings.

---

### Change 3 — §3.3.6 Invoice Actions

**OLD**
> Void — Soft-delete with audit record
> Edit — Available in Draft state only; Sent invoices require void-and-reissue

**NEW**
> **Void** — Posts a **reversal** (credit note) that offsets the issued invoice's AR and Output VAT. The invoice remains visible with a *Void* stamp. **Nothing is deleted.**
> **Edit** — Draft state only. An **issued** invoice is immutable; correction is via **Credit Note**, then reissue *(full credit-note behaviour specified in the forthcoming ADR).*
> **Credit & Reissue** *(new, one-click)* — Creates a credit note against the original invoice and opens a **new draft invoice pre-filled** from it for the user to correct and re-issue.

**REASON:** "Soft-delete" and "void-and-reissue" are incompatible with immutability and (once VAT-reported) with compliance. Void becomes a reversal; the one-click workflow removes the friction.

---

### Change 4 — §3.4.7 Bill Actions

**OLD**
> Void — Soft-cancel with audit record
> Edit — Draft state only

**NEW**
> **Void** — Posts a **reversal** offsetting the approved bill's Expense/Input-VAT/AP. The bill remains visible with a *Void* stamp. **Nothing is deleted.**
> **Edit** — Draft state only.
> **Reverse & Correct** *(new, one-click)* — Posts a reversal of the approved bill and opens a **new draft bill pre-filled** from it.

**REASON:** Symmetry with the payables side; preserves immutability with minimal user effort.

---

### Change 5 — §3.5.3 Expenses (add behaviour)

**NEW** *(add)*
> A **posted** expense is immutable. Correction is via **Reverse & Correct** (one-click): the system posts a reversal and opens a new draft expense pre-filled from the original. Draft expenses remain freely editable/deletable.

**REASON:** Applies the correction model uniformly across all posting modules.

---

### Change 6 — §6.4 Audit & Compliance Logging (reinforce)

**NEW** *(add)*
> Immutability applies to **both** the audit log **and** the posted financial records themselves. No posted journal, invoice, bill, expense, allocation, or system entry may be edited or deleted by any user. All corrections are new posted entries carrying a reference to the entry they correct, making every change traceable to its origin.

**REASON:** Amendment 001 left an immutable audit log wrapped around a mutable ledger — theatre. This closes the gap.

---

## C. New capability, rules, and acceptance criteria

**New cross-cutting capability — Correction Workflows** (convenience wrappers that generate the *same* immutable linked entries a manual correction would; there is **no** privileged mutation path):
- **Reverse Journal** — post a linked counter-entry to a posted journal.
- **Reverse & Correct** — reverse + open a pre-filled draft (journals, bills, expenses).
- **Credit & Reissue** — credit note + pre-filled new draft invoice.
- **Debit Note / Vendor Credit** — the payables-side correction (principle established; mechanics with the note ADR).

**New business rules**
- BR-007: Posted entries are immutable for **all** users, including administrators.
- BR-008: Every correction is a new posted entry, bidirectionally linked to the original.
- BR-009: **Void = reversal**, never delete; voided documents stay visible with a stamp.
- BR-010: One-click workflows produce standard linked entries only — no special edit/delete path exists anywhere.
- BR-011: `[System]` entries are reversed **only** via their source document.
- BR-012 *(dependency)*: A reversal posts within an **open** period; if the original period is closed, the reversal posts to the **current open period.** *(Final rule depends on the period-close ADR.)*

**New acceptance criteria (samples)**
- AC: Any attempt to edit or delete a posted entry is rejected — no exception for admins.
- AC: Reverse Journal creates a linked counter-entry dated per BR-012; original flagged *Reversed*; both visible.
- AC: Voiding an issued invoice posts an offsetting reversal; the invoice remains visible, stamped *Void*; no record is removed.
- AC: Voiding an invoice auto-reverses its linked `[System]` recognition and any settlement entries.
- AC: Deleting a **draft** removes it with zero ledger impact.
- AC: Credit & Reissue produces a credit note referencing the original plus a pre-filled new draft invoice.

---

## D. Remaining items (dependencies / forward references — not contradictions)

1. **Reversal dating** — final rule depends on the **period-close ADR** (BR-012 is provisional).
2. **Correction authorisation** — *who* may post reversals/voids is a **segregation-of-duties** question owned by the **access-control ADR**. Immutability says no one may edit; it does not yet say who may reverse.
3. **Credit/Debit note mechanics** — ADR-002 establishes the *principle* (issued documents corrected by note); full behaviour (VAT-period placement, numbering, partial credits) is the **next ADR**.
4. **Draft document-number handling on delete** (reuse vs burn) — minor; owned by the numbering rule.

*(Previously-listed remaining contradictions from Amendment 001 — input-VAT classification, exempt vs zero-rated, flat access, period close, housekeeping — are unaffected and still open under their own decisions.)*

---

## E. Consistency statement

Amendment 002 is **internally consistent with ADR-001 and ADR-002.** Immutability strengthens ADR-001's two-event model: recognition and settlement entries, and their `[System]` postings, are all immutable and are corrected only through linked reversals — so the recognition↔settlement link can never be silently broken. Remaining-contradiction #1 (mutable posted entries) from Amendment 001 is **resolved.** The four items in Section D are forward dependencies, not contradictions, and are owned by decisions still ahead.
