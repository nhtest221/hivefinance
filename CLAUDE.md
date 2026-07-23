# CLAUDE.md — HiveFinance Project Rules

These rules bind every Claude Code session working in this repository. They summarize
`docs/README.md` and the frozen governance chain; they do not replace it.

## Authority

- `docs/HiveFin_SRS_v3.0.md`, `docs/HiveFin_Decision_Log.md` (ADR register + Governance
  Approval Records), `docs/HiveFin_Architecture_Principles.md`,
  `docs/HiveFinance-Engineering-Constitution-v1.0.md`, `docs/HiveFin_Domain_Model.md`,
  `docs/HiveFin_Context_Interaction_Matrix.md`, `docs/HiveFin_Context_Map.md`,
  `docs/HiveFin_Aggregate_Design.md`, `docs/HiveFin_Domain_Events.md`,
  `docs/HiveFin_Repository_Contracts.md`, `docs/HiveFin_Database_Design.md`,
  `docs/HiveFin_API_Contracts.md`, `docs/HiveFin_Implementation_Roadmap.md` are
  **authoritative and frozen**. Read the relevant sections before writing code that
  touches them.
- If a frozen document appears to conflict with another, with reality, or with the task
  at hand: **stop, do not commit, and report the contradiction.** Do not resolve it in code.

## Prohibited

- Never guess, infer, or invent a missing accounting, tax, VAT, FX, approval, security,
  period-close, or legal rule. If one is missing or ambiguous, stop and raise it as a
  question rather than assuming an answer.
- Never modify a frozen governance document (anything under "Frozen Documents" in
  `docs/README.md`) without an explicit, approved governance change (a new Governance
  Approval Record / ADR). Approved proposal source files may live outside this repository;
  do not reintroduce or edit them here.
- Never destructively edit a posted/immutable record; corrections happen only through the
  approved reversal/note/void workflows defined in the frozen contracts.
- Never weaken security, immutability, accounting controls, tests, audit, or entity
  isolation to make something pass.
- Never force-push, never bypass CI, and never merge a pull request except under the
  narrow, fully-gated conditions in "Autonomous pull request merging" below.

## Required validation before calling work done

- Accounting-affecting behavior is validated against **PostgreSQL** (not SQLite-only),
  reflecting production constraints/triggers.
- Backend: full test suite, static analysis (PHPStan), formatting (Pint), refactor-safety
  (Rector dry run), and context-boundary guards all pass.
- Frontend: typecheck, lint, and production build all pass.
- Contract conformance: implemented routes/schemas match the frozen API Contracts exactly
  (paths, verbs, request/response shape, error codes).
- CI is green on the pushed branch.
- The working tree is clean (no stray/uncommitted files) and a completion report is
  produced summarizing scope delivered, evidence, and a MERGE / DO NOT MERGE recommendation.

## Autonomous pull request merging

Merging is normally reserved for humans. A pull request may be merged by Claude Code
without a separate human click **only** when every one of the following holds at the
moment of merge — not merely at some earlier check:

1. Backend CI is green on the exact commit being merged.
2. Frontend CI is green on the exact commit being merged.
3. GitHub reports the PR as mergeable (no conflicts, no required-check failures).
4. Migrations apply cleanly from a fresh database on both SQLite and PostgreSQL.
5. SQLite rollback-then-forward and PostgreSQL rollback-then-forward both succeed.
6. The full SQLite test suite and the full PostgreSQL test suite both pass, with any
   pre-existing, out-of-scope failure explicitly identified and unchanged in count.
7. PHPStan, Pint, Rector dry run, and the AP-001 context-boundary guard all pass clean.
8. No file under "Frozen Documents" (`docs/README.md`) changed on this branch, unless the
   PR *is* an approved governance-only change carrying an explicit Governance Approval
   Record / Governance Clarification Record whose content was given verbatim by the
   Product Owner in this conversation — never inferred, never invented.
9. No unresolved P0 or P1 defect remains against the PR's own scope.
10. No accounting imbalance is introduced (posted debits/credits still balance; no
    invariant this repository already enforces is weakened).
11. No maker-checker, audit, outbox, entity-isolation, immutability, idempotency,
    concurrency, or security control is weakened, removed, or bypassed to make something
    pass.
12. No secret, credential, or local-only file (e.g. `.claude/settings.local.json`) is
    included in the diff.
13. The PR's own completion report (or, for a governance-only PR, its description) states
    an unconditional MERGE recommendation — not conditional, not "MERGE once X."

If **any** condition fails, the PR is left open for a human, with the specific failing
condition(s) stated in the PR or in a resume report. When merging is permitted, use squash
merge to preserve this repository's one-PR-one-commit convention, then synchronize `main`
and delete the merged branch. This section itself may only be changed by a human editing
CLAUDE.md directly — Claude Code must never widen its own merge authority.

## Working style

- Reference frozen documents by section/ID; do not copy large verbatim blocks of them into
  code comments, PR descriptions, or other files — cite, don't reproduce.
- Implement only what the frozen artifacts already decided. Do not introduce new product
  decisions, endpoints, fields, or business rules under the guise of implementation detail.
