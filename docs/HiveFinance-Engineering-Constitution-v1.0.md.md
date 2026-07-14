HiveFinance — Engineering Constitution
Version: 1.0 (Draft for ratification)
Status: Binding upon approval by the Chief Software Architect
Applies to: Every human developer and every AI coding agent contributing to the HiveFinance codebase
Precedence: SRS v3.0, ADR-001…ADR-009, and Architecture Principles (AP-001) are the source of truth. Where this Constitution and a frozen artifact appear to conflict, stop and report — do not resolve it in code.

0. Preamble & Enforcement

P-01 — Frozen artifacts are law. Business rules, aggregates, bounded contexts, invariants, ADRs, and Architecture Principles are frozen. They change only through a new, explicitly requested ADR — never through code.
P-02 — No invention. No contributor invents, infers, or "improves" a business rule. If a rule is missing or ambiguous, raise a question; do not guess.
P-03 — Contradiction halts work. On discovering a contradiction between artifacts, or between an artifact and reality, stop, do not commit, and report it.
P-04 — Scope discipline. HiveFinance is a finance-only, modular monolith for the Finance & Accounts team. No feature, integration, or module outside that scope is introduced without an approved change.
P-05 — Rules are referenceable. Every rule below carries an ID. Reviews, PRs, and agent outputs cite the rule ID they satisfy or the one they may violate.
P-06 — Optimisation order. When rules trade off, resolve in this priority: auditability → correctness → maintainability → SaaS evolvability → speed.


1. Architecture Rules (ARCH)

ARCH-01 — Clean Architecture dependency rule is absolute: Domain → Application → Infrastructure/Interface, inward only. The Domain layer depends on nothing external (no framework, ORM, HTTP, or I/O).
ARCH-02 — The system is a modular monolith. Modules map to bounded contexts from the Context Map. No module reaches into another module's internals; interaction happens only through published contracts and domain events per the Context Interaction Matrix.
ARCH-03 — Business logic lives in the Domain/Application layers only. Controllers, repositories, and infrastructure contain no business rules.
ARCH-04 — One aggregate is mutated per transaction. Cross-aggregate and cross-context consistency is eventual, achieved via domain events, never via a shared transaction spanning aggregates.
ARCH-05 — Domain events are published reliably using the outbox pattern (persist event in the same transaction as the state change; dispatch after commit). No fire-and-forget publishing inside domain logic.
ARCH-06 — No shared mutable state across modules; no back-door database reads into another context's tables.
ARCH-07 — New dependencies (libraries, services, infra) require architect approval and a recorded rationale. Default answer is no.

2. Domain Rules (DOM)

DOM-01 — Invariants (BR-001…BR-046) are enforced inside aggregates, at construction and on every mutation. An aggregate must never be constructable in an invalid state.
DOM-02 — Money is never a float. Monetary amounts use the Money value object (fixed-precision decimal or integer minor units per Domain Model v2). Every amount is inseparable from its currency.
DOM-03 — No implicit currency conversion. Conversions use effective-dated Rate Records (ADR-007); the rate reference and both currency amounts are persisted. Rounding occurs only at defined boundaries using the approved rounding policy.
DOM-04 — Posted ledger entries are immutable (ADR-002). Corrections happen only via reversal / credit & debit notes (ADR-003). No code path updates or deletes a posted entry.
DOM-05 — Every journal entry balances to zero (double-entry). This is a domain invariant, not a check bolted on later.
DOM-06 — Period-state guards are enforced in the domain (ADR-004): posting into a Soft-Closed period follows the defined workflow; posting into a Hard-Closed period is rejected.
DOM-07 — Tax is always computed by the Tax Engine against an effective-dated Tax Pack (ADR-006). Rates are never hardcoded. A reproducible tax snapshot is persisted with every taxable transaction.
DOM-08 — Document identity is split (ADR-009): internal Document ID (UUID) is separate from business-facing Document Number; numbers are allocated from atomic scoped sequences.
DOM-09 — State-changing commands are idempotent and carry an idempotency key (aligns with ADR-008/ADR-009). Retries never double-post.
DOM-10 — Domain events are immutable, named in past tense, and versioned. Their payloads match the frozen Domain Events catalogue.
DOM-11 — Ubiquitous language only: type, method, and event names use the exact terms from the SRS/Domain Model. No synonyms, no invented vocabulary.

3. Coding Rules (CODE)

CODE-01 — No business logic outside Domain/Application. No SQL, HTTP, or serialization concerns leak into the Domain.
CODE-02 — Value objects are immutable; entities mutate only through aggregate-root methods that enforce invariants.
CODE-03 — No primitive obsession for domain concepts (money, currency, dates, identifiers, quantities). Use the defined value objects.
CODE-04 — All financial dates/times are stored in UTC with explicit timezone handling at the edge; accounting periods use the domain's period model, not raw dates.
CODE-05 — No magic numbers or literals for rates, thresholds, or codes — these are configuration or Tax Pack data.
CODE-06 — Functions are small and single-purpose; cyclomatic complexity kept low. No dead code, no commented-out code merged.
CODE-07 — No TODO/FIXME merged into main without a tracked issue reference.
CODE-08 — Formatting and linting are automated and non-negotiable; a PR that fails lint/format cannot merge.
CODE-09 — Concurrency on financial state uses optimistic locking / version checks; no last-write-wins on aggregates.

4. Repository Rules (REPO)

REPO-01 — Repositories implement the frozen Repository Contracts exactly. Signatures are not changed to suit an implementation shortcut.
REPO-02 — One repository per aggregate root. Repositories return/accept aggregates, not raw rows or ORM entities.
REPO-03 — No ORM/persistence types cross the Domain boundary. Mapping between persistence and domain happens in Infrastructure.
REPO-04 — Repositories perform no business logic and enforce no invariants — those belong to the aggregate.
REPO-05 — Queries for reporting/read models are separated from write-side repositories (CQRS-lean); read models never mutate state.
REPO-06 — Repository methods are transaction-aware but do not own transaction boundaries across aggregates (see ARCH-04).

5. API Rules (API)

API-01 — Endpoints implement the frozen API Contracts exactly: paths, verbs, request/response schemas, and error codes. Contract changes require an approved update, not ad-hoc edits.
API-02 — API is a thin boundary: validate input, authenticate/authorise, invoke an application service, map the result. No business logic in controllers.
API-03 — All state-changing endpoints accept and honour an idempotency key.
API-04 — Input is validated at the boundary; invalid input is rejected with the contract's error shape before reaching the domain.
API-05 — Errors are mapped to stable, documented error codes. No stack traces, internal identifiers, or raw exception text in responses.
API-06 — Versioned APIs; backward-incompatible changes go to a new version. No silent breaking changes.
API-07 — No sensitive/financial data in URLs or query strings; such data travels in the body over TLS.

6. Database Rules (DB)

DB-01 — Schema matches the frozen Database Design. Structural changes require an approved migration and (if they touch a modelled decision) an ADR.
DB-02 — All schema changes are forward-only, reviewed migrations committed with the code that needs them. No manual, out-of-band schema edits.
DB-03 — Posted-ledger and audit tables are append-only, enforced at the database level (revoked UPDATE/DELETE grants and/or immutability triggers) in addition to the application layer.
DB-04 — Monetary columns use exact numeric types with defined precision/scale (never float/double) and always pair with a currency.
DB-05 — Only parameterised statements. String-built SQL is forbidden.
DB-06 — Every write carries actor and timestamp metadata sufficient for audit reconstruction.
DB-07 — Application DB accounts follow least privilege; no application connects as a superuser.
DB-08 — Numbering sequences are allocated atomically (ADR-009); no application-side gap logic that risks duplicates.
DB-09 — Migrations are tested (including the dry-run / staging path for data migrations per ADR-008) before they run against real data.

7. Testing Requirements (TEST)

TEST-01 — No merge without green tests. CI runs the full required suite on every PR.
TEST-02 — Domain layer has high unit-test coverage of invariants and every business rule (BR-001…BR-046) it owns.
TEST-03 — Accounting-correctness tests are mandatory: journals balance to zero; multi-currency amounts and rates are correct; tax computation matches the Tax Pack; posted-entry immutability is enforced; period-close guards reject illegal posting.
TEST-04 — Contract tests verify API implementations against the frozen API Contracts, and repositories against Repository Contracts.
TEST-05 — Integration tests cover persistence, outbox/event dispatch, and idempotency (a replayed command produces no duplicate effect).
TEST-06 — Property-based tests are used for money arithmetic, rounding, and ledger balancing where practical.
TEST-07 — Every bug fix ships with a regression test that fails before the fix and passes after.
TEST-08 — Tests are deterministic; no reliance on wall-clock, network, or ordering. No flaky tests tolerated in the required suite.

8. Security Requirements (SEC)

SEC-01 — Authorisation follows the hybrid RBAC + ABAC model (ADR-005), enforced at the application boundary on every state-changing operation.
SEC-02 — Segregation of Duties checks are applied; where a compensating-control exception is permitted, it is explicit, approved, and audit-logged — never silently bypassed.
SEC-03 — No secrets in the repository (keys, passwords, tokens, connection strings). Secrets come from a vault/secret manager or injected environment; violations block the merge.
SEC-04 — Least privilege everywhere: DB accounts, service credentials, and roles grant only what is needed.
SEC-05 — Financial and personal data is encrypted in transit (TLS) and at rest.
SEC-06 — All external input is treated as untrusted: validated, and used only via parameterised access.
SEC-07 — Dependencies are scanned for known vulnerabilities in CI; high-severity findings block release.
SEC-08 — Authentication and authorisation are never disabled or stubbed in shared/deployed environments.

9. Logging Requirements (LOG)

LOG-01 — Logs are structured (machine-parseable) and carry a correlation/trace ID that links an API call through application and events.
LOG-02 — Never log secrets, credentials, full financial account numbers, or personal data. Mask/omit at source.
LOG-03 — The audit log is separate from operational logs: append-only, tamper-evident, and never used as a debug channel.
LOG-04 — Every financially significant action produces an audit record: posting, correction/reversal, period open/close, tax snapshot, rate record change, permission change, migration. Each records actor, action, entity, before/after (as applicable), and timestamp.
LOG-05 — Log levels are used correctly; errors are logged with enough context to diagnose without leaking sensitive data.

10. Error-Handling Rules (ERR)

ERR-01 — Distinguish domain errors (invariant/rule violations) from technical errors (I/O, infra). They are typed and handled differently.
ERR-02 — Fail fast on invariant violations; never continue past a broken invariant. No silent catch that swallows failures.
ERR-03 — A failed operation rolls back its transaction; partial financial writes are never left committed.
ERR-04 — Domain errors map to stable API error codes (see API-05). Internal detail stays server-side.
ERR-05 — Retries are safe only because operations are idempotent (DOM-09); no retry logic that risks double-posting.
ERR-06 — No control flow via generic exceptions where a domain result type is appropriate; errors are explicit and intentional.

11. CI/CD Rules (CICD)

CICD-01 — main is protected: no direct pushes; changes land only via reviewed, passing PRs.
CICD-02 — Every PR pipeline runs: format/lint, type check, unit tests, integration/contract tests, migration check, dependency & secret scan. Red pipeline cannot merge.
CICD-03 — Short-lived branches; small, frequent PRs preferred over long-running divergence.
CICD-04 — Build once, promote the same artifact across environments; environments differ only by injected configuration.
CICD-05 — Migrations run automatically as a gated, reviewed step; forward-only (DB-02).
CICD-06 — Every deployment has a documented rollback plan; releases are traceable to the exact commit and artifact.

12. Code Review Rules (REV)

REV-01 — Every PR requires at least one human reviewer; the author cannot approve their own PR.
REV-02 — AI-agent-authored PRs must be reviewed and approved by a human — an agent may not approve or merge (its own or another agent's) work.
REV-03 — Reviewers verify the change against this Constitution and the relevant ADR/contract, and reject anything that invents or bends a business rule.
REV-04 — Changes to architecture, aggregates, contracts, or anything touching a frozen decision require architect approval and, if the decision itself changes, an ADR.
REV-05 — No rubber-stamping. Approval means the reviewer has actually checked correctness, tests, and rule compliance.
REV-06 — Reviewers confirm auditability and accounting-correctness tests exist for any financially significant change.

13. AI Coding Agent Rules (AGENT)

AGENT-01 — Agents operate strictly inside the frozen artifacts. They never invent, infer, or modify a business rule, invariant, aggregate, contract, or ADR.
AGENT-02 — On any ambiguity or contradiction, the agent stops and asks one clarifying question — it does not assume (mirrors P-02/P-03 and project Rule 4).
AGENT-03 — One task = one bounded context (or a single explicitly-scoped concern). Agents do not sprawl across contexts in a single change.
AGENT-04 — Every agent change cites the rule/ADR/contract IDs it implements and the tests that prove it.
AGENT-05 — Agents produce the required tests (TEST-02…TEST-05) alongside the code; untested agent code is not accepted.
AGENT-06 — Agents introduce no new dependencies, no schema changes, and no contract changes without explicit human approval.
AGENT-07 — Agents never write secrets, disable auth, weaken validation, or bypass SoD.
AGENT-08 — Agents leave a clear change summary and rationale for human review. All agent output is subject to REV-02.
AGENT-09 — Agents do not "fix" failing tests by weakening assertions; they fix the code or escalate.
AGENT-10 — If a requested task would require violating any rule here, the agent refuses and reports why.

14. Definition of Done (DoD)
A change is Done only when all are true:

Acceptance criteria and the referenced business rule(s) are fully met.
Complies with this Constitution and the relevant ADR(s)/contract(s); no frozen artifact was altered without an approved ADR.
Required tests written and green, including accounting-correctness and idempotency tests where applicable.
No new lint, format, type, security, or dependency-scan violations.
Auditability covered: audit records and structured logs added for financially significant actions.
Migrations (if any) are forward-only, reviewed, and tested — including the dry-run path for data migrations.
Errors handled per ERR rules; no sensitive data leaked in logs or responses.
Documentation/ADR updated where behaviour or a decision changed.
PR checklist complete; reviewed and approved by a human.

15. Pull Request Checklist (PR)
Copy into every PR description and tick each item:

 Scope is single-purpose and stays within one bounded context.
 References the SRS item / BR / ADR / contract this change implements.
 No business rule, invariant, aggregate, contract, or ADR was invented or modified (or: an approved ADR is linked).
 Clean Architecture dependency direction respected; no framework/ORM leakage into Domain.
 Money handled via the Money value object; no floats; currency always attached.
 Posted entries / audit data remain immutable; corrections use reversal/notes.
 Period-state, tax-snapshot, and numbering rules honoured where relevant.
 State-changing operations are idempotent (idempotency key handled).
 AuthZ (RBAC+ABAC) and SoD enforced; any compensating-control exception is logged.
 Required tests added and passing (unit / accounting-correctness / contract / integration / regression).
 Structured logs + audit records added; no secrets or sensitive data logged.
 Errors mapped to stable API error codes; transactions roll back on failure.
 Migrations forward-only, reviewed, and tested (dry-run for data migrations).
 No new dependencies without approval; secret/dependency scans clean.
 Docs/ADR updated as needed.
 (If agent-authored) Change summary + rule/ADR citations included; human review requested.


Appendix A — Technology Bindings (to be confirmed)
This Constitution is written to be enforceable as principles. The concrete stack is defined by your frozen Database Design, API Contracts, and Implementation Roadmap. Confirm the following so each rule can name specific tooling — I have deliberately not assumed them:
SlotRule(s) it bindsTo confirmLanguage & runtimeCODE, allpendingApplication frameworkARCH-01, APIpendingPersistence / ORMREPO-03, DBpendingMigration toolDB-02, CICD-05pendingTest framework(s)TESTpendingMoney/decimal library or typeDOM-02, DB-04pendingSecret managerSEC-03pendingCI/CD platformCICDpendingStructured logging stackLOG-01pendingDependency/secret scannersSEC-07, CICD-02pending
Once confirmed, these become concrete named requirements without changing any rule above.