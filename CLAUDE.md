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
- Never merge a pull request. Open PRs and leave them for human approval.
- Never destructively edit a posted/immutable record; corrections happen only through the
  approved reversal/note/void workflows defined in the frozen contracts.
- Never weaken security, immutability, accounting controls, tests, audit, or entity
  isolation to make something pass.

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

## Working style

- Reference frozen documents by section/ID; do not copy large verbatim blocks of them into
  code comments, PR descriptions, or other files — cite, don't reproduce.
- Implement only what the frozen artifacts already decided. Do not introduce new product
  decisions, endpoints, fields, or business rules under the guise of implementation detail.
