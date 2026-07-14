# HiveFin SRS v2.0 — Amendment 005 (incorporating ADR-005)

**Scope:** ONLY changes for ADR-005 (Access Control & SoD). Replaces the flat-access model (§2.1) and resolves Amendment 001 remaining-contradiction #5. Closes the authorisation rider on ADR-002/-003/-004.

---

## A. Sections impacted

| # | Section | Change |
|---|---|---|
| 1 | §2.1 Access philosophy | **Replace** flat access with default-deny RBAC + ABAC scoping |
| 2 | §2.2 User roster | Titles → functional **system roles** (minimum-capability floors) + custom roles |
| 3 | §2.5 User management | Add role assignment, delegation, break-glass, last-Owner protection |
| 4 | §2.6 Out-of-scope | Move Auditor (read-only), feature-level permissions, SBU segmentation **into** scope |
| 5 | §6.3 Security | MFA: optional → **required** for Owner & Finance Manager |
| 6 | New — §2.7 | SoD matrix, maker-checker, compensating controls |

---

## B. Redlined changes (Old → New → Reason)

### §2.1 Access Philosophy *(core replacement)*
**OLD**
> HiveFin MVP operates on a flat-access model. There are no feature-level restrictions… All authenticated and invited users have full read and write access across all modules within their permitted entity.

**NEW**
> HiveFin operates on **default-deny, least-privilege** access. No capability is granted unless a role grants it. Permissions follow a **Hybrid model: RBAC** (roles = permission bundles) **+ ABAC scoping** (entity and, optionally, SBU determine *where* a role applies). The entity boundary is the isolation boundary (and the future tenant boundary per ADR-001).

**REASON:** Flat access contradicts ADR-002/-003/-004 (immutable, period-locked ledger cannot allow everyone to reverse/void/close).

### §2.2 Roles
**OLD**
> These titles are used for audit log attribution only and carry no functional access differences in the MVP.

**NEW — System roles (minimum-capability floors; extensible):**
| Role | Minimum authority |
|---|---|
| Owner/Admin | User & role mgmt; tax/CoA config; approves Hard Close & Reopen |
| Finance Manager | Post/approve transactions; reversals; notes; initiates close; checker |
| Accountant | Maker — create/post transactions; drafts; routine journals |
| Finance Staff | Drafts, invoices, expenses; no journal posting, notes, or close |
| Auditor | Read-all + audit log; no write |
| Service Account | Non-human, scoped, key-based; no approval rights |

**Custom roles** may be created, cloned from a base bundle, and **can never exceed the granting user's own privileges.**

**REASON:** Roles must carry real, differentiated authority to satisfy the prior ADRs and SoD.

### §2.6 → In scope
**OLD:** Read-only/auditor access, feature-level permission toggles, and SBU/department segmentation were out of scope.
**NEW:** All three are **in scope** (Auditor role; role-based feature permissions; optional SBU scoping, default all-SBU for finance).

### §6.3 MFA
**OLD:** MFA optional in MVP.
**NEW:** MFA **required** for Owner and Finance Manager; optional (configurable) for others.

---

## C. New section — §2.7 Segregation of Duties & Approvals

**SoD matrix (enforced conflicts):** creator ≠ approver; journal maker ≠ checker; bill poster ≠ payer; reversal author ≠ original poster (where staffing allows); Hard-Close initiator (Finance Manager) ≠ approver (Owner).

**Maker-Checker (high-risk actions only):** manual journals, reversals, credit/debit notes, Hard Close, Reopen, and tax/CoA/role changes. **Triggering is driven by a configurable Approval Policy — the system defines no monetary thresholds; organisations configure their own** (per COO refinement #1). **Four-eyes** required for Hard Close, Reopen, and tax-config changes.

**Compensating controls (per COO refinement #3):** where staffing forces one person to perform conflicting duties, the system does **not block** — it records an **SoD Exception** with mandatory justification, immutable audit log, and a flag for **post-facto review** by Owner/Auditor.

**Authorisation mapping to prior ADRs:** reversals/voids (ADR-002) → Finance Manager/Owner; credit/debit notes (ADR-003) → Finance Manager/Owner; Soft Close → Finance Manager; Hard Close → FM initiate + Owner approve; Reopen → Owner + management approval + user notification (ADR-004).

**Other controls:**
- **Temporary delegation:** time-boxed, auto-expiring, logged, ≤ delegator's privileges.
- **Break-glass:** documented emergency elevation — time-boxed, reason-coded, auto-notifies Owner, auto-expires, mandatory post-hoc review.
- **Activation/deactivation:** soft-deactivate preserves audit and revokes sessions; the last active Owner cannot be deactivated.
- **Audit-log visibility:** Owner, Finance Manager, Auditor (read-only); immutable for all (§6.4).
- **API & service accounts:** same RBAC, no bypass; least-privilege, rotated keys.

---

## D. New rules & acceptance criteria

**Rules**
- BR-025: Default-deny; least privilege; no implicit grants.
- BR-026: System roles are minimum-capability floors; custom roles ≤ granter's privileges.
- BR-027: Enforced SoD conflicts per the §2.7 matrix.
- BR-028: Maker-checker on high-risk actions, triggered by a **configurable** Approval Policy (no built-in thresholds).
- BR-029: SoD conflicts under staffing constraint → SoD Exception (justification + audit + post-facto review), not a block.
- BR-030: MFA mandatory for Owner & Finance Manager.
- BR-031: Delegation and break-glass are time-boxed, logged, and auto-expiring.

**Acceptance criteria (samples)**
- AC: A Finance Staff user cannot post a journal, issue a note, or close a period.
- AC: A reversal by the original poster is allowed only with a recorded SoD Exception + justification.
- AC: Hard Close requires FM initiation and Owner approval (four-eyes); a single user cannot do both without an SoD Exception.
- AC: A custom role cannot be granted a permission the creator lacks.
- AC: Approval policies are configured by the org; the system ships with none hardcoded.
- AC: Break-glass elevation auto-notifies the Owner, expires automatically, and is queued for review.
- AC: Deactivating the last Owner is rejected.

---

## E. Remaining items (dependencies — not contradictions)

1. **Approval Policy configuration** (which actions need a checker, and any org thresholds) is business configuration, not architecture — a capability to expose, no blocking dependency.

**Riders retired by ADR-005:** ADR-002 rider #2 (who may reverse/void); ADR-003 rider #2 (who may issue notes/voids); ADR-004 rider (close/reopen/approval authorisation).

---

## F. Consistency statement

Consistent with ADR-001 (entity = isolation/tenant boundary), ADR-002 (reversal/void authority defined), ADR-003 (note authority defined), and ADR-004 (close/reopen authority + four-eyes defined). Replaces flat access (§2.1) and resolves Amendment 001 remaining-contradiction #5. Only business-configurable approval policy remains, which is intentionally left to the organisation.
