# TASK.md — Milestone M0: Walking Skeleton

**Project:** HiveFinance
**Milestone:** M0 — Walking Skeleton
**Governance:** Engineering Constitution v1.0 (binding). All rule IDs (e.g. `ARCH-04`, `DOM-04`) reference that document.
**Business source of truth (frozen):** SRS v3.0, ADR register (`HiveFin_Decision_Log.md`, ADR-001…ADR-009), Domain Model v2, Context Map, Aggregate Design, Context Interaction Matrix, Domain Events, Repository Contracts, Database Design, API Contracts, Implementation Roadmap, AP-001.
**Nature of this document:** An executable work package. Agents implement against the frozen artifacts verbatim and **never invent** rules, fields, or invariants (`P-02`, `AGENT-01`). On ambiguity or contradiction: **stop and ask** (`P-03`, `AGENT-02`).

---

## ⚠️ Execution Preconditions (Gate)

M0 execution **must not begin** until both inputs are supplied. These are the only two items that require human input before agents can run without architectural interpretation:

1. **Appendix A technology binding — confirmed.** See the *Proposed M0 Technology Binding* section below. Confirm as-is or override. If the frozen **Database Design** fixes a different DB engine, that engine governs (`P-03`) — tell me and I adjust the migration tasks.
2. **Walking-skeleton aggregate — named.** M0 wires exactly **one minimal master-data aggregate** as defined in **Aggregate Design**. Confirm which one (see *Scope → Chosen Slice*). Agents implement its fields and invariants **exactly as the frozen artifact defines them**.

Everything else in this document is fully specified.

---

## Proposed M0 Technology Binding (Appendix A — confirm or override)

> Presented as a recommendation, not an assumption. Nothing here is a business rule; it lives in Appendix A and may change without touching any constitutional rule. **Confirm before Phase 1.**

| Slot | Recommendation | Rationale (Constitution goal) | Leading alternative |
|---|---|---|---|
| Language / runtime | TypeScript on Node.js LTS | Stack uniformity with a React frontend; large agent training corpus; strong typing aids financial-domain correctness | C# / .NET or Java |
| App framework | NestJS | First-class DI, module boundaries, and Clean-Architecture layering for a **modular monolith** (`ARCH-01/02`) | .NET (Clean Architecture template) / Spring Modulith |
| Persistence | Prisma (typed client + migrations) with **hand-written repository implementations** mapping to domain aggregates | Keeps ORM types out of the domain (`REPO-03`); native `Decimal` → Postgres `NUMERIC` supports exact money (`DOM-02`, `DB-04`) | EF Core / TypeORM / JPA |
| Database | PostgreSQL | Exact `NUMERIC`, DB-level append-only enforcement via revoked grants/triggers (`DB-03`) | Per frozen Database Design if different |
| Migrations | Prisma Migrate (forward-only) | `DB-02` | Flyway / Liquibase / EF Migrations |
| Money type | `decimal.js` via Prisma `Decimal` — **never** JS `number** | `DOM-02` | Native `decimal` (.NET) / `BigDecimal` (Java) |
| Testing | Vitest/Jest (unit) · Supertest (API) · Testcontainers (integration DB) · schema/contract test vs API Contracts | `TEST-01…08` | xUnit / JUnit + Testcontainers |
| Frontend | React + Vite SPA (auth-gated internal tool) | Thin client; matches in-house React skill; no SSR needed for an internal finance tool | Next.js if SSR desired |
| CI/CD | GitHub Actions | `CICD-01…06` | GitLab CI / Azure DevOps |
| Logging | `pino` (structured JSON) + correlation-ID middleware | `LOG-01/05` | Serilog / Logback |
| Secrets | Environment injection + a secret manager (choose: cloud secret manager / Doppler) | `SEC-03` | — |

**Honest tradeoff to weigh:** TypeScript gives full-stack uniformity, agent-friendliness, and fit with your in-house React skills. Its one watch-item is money handling — JS has no native exact decimal, so `DOM-02` must be enforced by mandating the decimal library (already covered). If correctness-criticality makes you prefer a **native-decimal enterprise stack**, .NET (EF Core) or Java (Spring Modulith — which enforces module boundaries at test time, directly serving `ARCH-02`) are the strong alternatives. **Your call.**

---

## 1. Objective

Prove the entire architecture and delivery pipeline end-to-end with the **thinnest possible real vertical slice**, and establish the skeleton every future feature slots into. M0 exercises the architecturally significant *seams* — Clean-Architecture layering, module boundaries, repository↔DB, outbox + domain event, idempotency, authorization enforcement, audit logging, structured logging, and the full CI gate — using one trivial master-data aggregate.

M0 is explicitly **not** about accounting features. No posting, ledger, tax, or multi-currency logic is built.

## 2. Scope

**Chosen Slice (confirm exact aggregate — see Gate #2):** the single simplest master-data aggregate defined in **Aggregate Design** with a real invariant. Candidates typically are the Chart-of-Accounts **Account**, **Currency**, or **Legal Entity** — whichever Aggregate Design defines as smallest with a genuine invariant (e.g. code uniqueness within a scope, valid type). Agents implement it **verbatim from the frozen artifact**.

In scope:
- Repository + modular-monolith skeleton with module folders per **Context Map** and Clean-Architecture layers (`ARCH-01/02`).
- One bounded-context module fully wired (the module that **owns** the chosen aggregate).
- One aggregate, one write use case (create) and one read use case (list + get-by-id).
- Repository implementing the frozen **Repository Contract** for that aggregate (`REPO-01`).
- Forward-only migrations creating the aggregate table, outbox table, audit table, idempotency-key store (`DB-02`).
- API endpoint pair (POST create, GET list/by-id) per **API Contracts** (`API-01`).
- Outbox mechanism + **one** concrete domain event (from **Domain Events**), published after commit, with a handler that runs observably (`ARCH-05`, `DOM-10`).
- Idempotency on the write endpoint (`DOM-09`, `API-03`).
- Authorization **enforcement seam** at the application boundary with one seeded finance role (`SEC-01`, ADR-005).
- Structured logging + correlation IDs + one append-only audit record on create (`LOG-01/03/04`).
- Minimal, auth-gated frontend that lists and creates the record via the real API.
- Full CI pipeline: format/lint, type check, unit, integration, contract, migration check, dependency + secret scan (`CICD-02`).
- Test harness with **all required test categories scaffolded**, including the accounting-correctness category (`TEST-03`) even though its M0 case is only invariant/immutability enforcement.
- Health/readiness endpoints; containerized local DB; config/secrets via env.

## 3. Out of Scope

- Journal entries, postings, double-entry balancing, derived cash layer (ADR-001) — **no ledger in M0**.
- Posting immutability *workflow* beyond the append-only DB seam; corrections/reversals/credit-debit notes/voids (ADR-002/003).
- Period close lifecycle (ADR-004).
- Full RBAC + ABAC policy set and SoD rules (ADR-005) — only the enforcement seam + one role.
- Tax Engine, Tax Packs, tax snapshots, multi-currency conversion, Rate Records (ADR-006/007).
- Legacy/open-item data migration mechanism (ADR-008) — the migration *tooling* is set up; no real data is migrated.
- Scoped business numbering sequences beyond what the chosen aggregate strictly needs (ADR-009) — full sequence engine deferred.
- Multi-entity consolidation / intercompany (ADR-010 — not yet locked).
- Reporting, dashboards, exports.
- Production infra hardening (autoscaling, DR, real IdP integration).

## 4. Dependencies

- **Gate #1** — confirmed Appendix A binding.
- **Gate #2** — named aggregate + access to its definition in Aggregate Design.
- Frozen artifacts present in the repo for agents to read: Context Map, Aggregate Design, Domain Model v2, Domain Events, Repository Contracts, Database Design, API Contracts, Context Interaction Matrix, Implementation Roadmap.
- Provisioned database (per confirmed engine) for local + CI (containerized).
- CI/CD platform + secret strategy available (Appendix A).
- If the **Implementation Roadmap** defines M0's slice differently from the Chosen Slice above, the Roadmap governs — report and reconcile before starting (`P-03`).

## 5. Technical Tasks (architecture & cross-cutting)

- **T-01** Scaffold the repo and modular-monolith structure: one module per bounded context (Context Map), each with `domain / application / infrastructure / interface` folders (`ARCH-01/02`).
- **T-02** Enforce dependency direction (domain depends on nothing outward) via lint/boundary rules; add a module-boundary verification test if the stack supports it (`ARCH-01`, ADR/`AP-001`).
- **T-03** Composition root / DI wiring; no service locators inside the domain (`ARCH-03`).
- **T-04** Outbox mechanism: outbox write inside the same transaction as the aggregate write; separate dispatcher publishes after commit (`ARCH-05`).
- **T-05** Domain-event base + the one concrete event from Domain Events (immutable, past-tense, versioned) and a handler that runs observably (`DOM-10`).
- **T-06** Idempotency mechanism: idempotency-key ingestion + store; replay yields a single effect (`DOM-09`).
- **T-07** Structured logging (`pino` or chosen lib) + correlation-ID middleware propagated through application and event handling; no sensitive data logged (`LOG-01/02/05`).
- **T-08** Append-only audit writer invoked by the create use case, recording actor/action/entity/timestamp (`LOG-03/04`).
- **T-09** Authorization enforcement seam at the application boundary (RBAC check) with one seeded finance role; unauthenticated/forbidden requests rejected before the domain (`SEC-01/08`, ADR-005).
- **T-10** Config & secrets via environment; secret manager wired; **no secrets in repo** (`SEC-03`); containerized local DB (compose).
- **T-11** Health + readiness endpoints.
- **T-12** Error-handling scaffold: typed **domain errors vs technical errors**; mapping to stable API error codes per API Contracts; transactions roll back on failure (`ERR-01/03/04`).

## 6. Database Tasks

- **DB-T-01** Set up migration tooling; establish the forward-only convention and naming (`DB-02`).
- **DB-T-02** Migration: the chosen aggregate's table **exactly per Database Design** (columns/types verbatim; any monetary column `NUMERIC`, never float — `DB-04`).
- **DB-T-03** Migration: outbox table.
- **DB-T-04** Migration: audit table, **append-only enforced at DB level** (revoked UPDATE/DELETE grants and/or immutability trigger) (`DB-03`).
- **DB-T-05** Migration: idempotency-key store table.
- **DB-T-06** Provision a **least-privilege** application DB role; app never connects as superuser (`DB-07`).
- **DB-T-07** Seed the one finance role/user for the authz seam (seed script, separated from schema migrations).
- **DB-T-08** Establish the migration test + dry-run convention (mechanism only; ADR-008 data migration deferred) (`DB-09`).

## 7. API Tasks

- **API-T-01** POST create endpoint per API Contracts: boundary validation, authz enforced, idempotency key honored (`API-02/03/04`).
- **API-T-02** GET list and GET by-id per API Contracts.
- **API-T-03** Error responses mapped to stable, documented codes; **no stack traces / internal detail** leaked (`API-05`, `ERR-04`).
- **API-T-04** Generate/verify the API surface against the frozen API Contracts; wire the contract test (`API-01`, `TEST-04`).
- **API-T-05** Accept/generate and echo a correlation ID; structured request logging with no sensitive data (`API-07`, `LOG-02`).

## 8. Frontend Tasks

- **FE-T-01** Minimal auth-gated finance-team UI shell (finance-only scope, `P-04`).
- **FE-T-02** List view calling GET list/by-id.
- **FE-T-03** Create form calling POST with an idempotency key; boundary validation; render errors from the API's stable error codes.
- **FE-T-04** Surface the correlation ID in error states (for support/audit traceability).
- **FE-T-05** **No business logic in the frontend** — it is a thin client only (`API-02` spirit; business logic lives in Domain/Application).

## 9. Testing Tasks

- **TST-01** Unit tests for the chosen aggregate's invariants, taken **from Aggregate Design** (`TEST-02`).
- **TST-02** Accounting-correctness test **category scaffolded** (`TEST-03`); M0 case = invariant enforcement + audit/immutable-table append-only enforcement.
- **TST-03** Integration tests: repository↔DB round-trip; outbox write + dispatch; **idempotency replay → single effect**; audit record written (`TEST-05`).
- **TST-04** Contract test: API implementation vs frozen API Contracts (`TEST-04`).
- **TST-05** AuthZ tests: unauthenticated/forbidden requests rejected at the boundary (`SEC-01`).
- **TST-06** Migration test: forward migration applies cleanly; append-only enforcement on audit (and any immutable) table verified at DB level (`TEST-01`, `DB-03`).
- **TST-07** E2E smoke: frontend create → API → DB → list reflects the record.
- **TST-08** CI wires all of the above; **red pipeline blocks merge** (`CICD-02`).

## 10. Acceptance Criteria

M0 is accepted only when all are demonstrably true:

1. A finance-role user creates and lists the master-data record end-to-end through the real UI.
2. The create endpoint is **idempotent** — the same idempotency key twice yields exactly one record.
3. A domain event is emitted via the **outbox** and its handler runs observably.
4. An **append-only audit record** exists for the create, capturing actor, action, entity, and timestamp.
5. An attempted UPDATE/DELETE on the audit (and any immutable) table is **rejected at the database level**.
6. An unauthenticated/unauthorized request is **rejected at the boundary** before reaching the domain.
7. Structured logs carry a **correlation ID** traceable across the request lifecycle.
8. The CI pipeline runs format/lint, type check, unit, integration, contract, migration, and dependency + secret scans; a red pipeline **cannot merge**.
9. The **dependency-direction / module-boundary** check passes (domain depends on nothing outward).
10. The implemented API **matches the frozen API Contracts** (contract test green).
11. **No secrets in the repo**; secret scan clean.
12. **Appendix A is updated** with the bindings actually used.

## 11. Definition of Done

Constitution **§14 (DoD)** applies in full. M0-specific additions:

- All Acceptance Criteria (§10) met and demonstrated.
- The chosen aggregate, event, repository, schema, and API were implemented **from the frozen artifacts without invention**; any gap was raised as a question, not assumed (`AGENT-01/02`).
- Every immutable/audit table has DB-level append-only enforcement, verified by test.
- Human-reviewed and approved; agent-authored PRs reviewed per `REV-02`.
- Appendix A finalized and committed.

## 12. References to Frozen Artifacts

| Work area | Governing frozen artifact(s) | Constitution rule(s) |
|---|---|---|
| Module layout / boundaries | Context Map, Context Interaction Matrix, AP-001 | ARCH-01/02 |
| Chosen aggregate + invariants | Aggregate Design, Domain Model v2 | DOM-01/11 |
| Domain event | Domain Events | ARCH-05, DOM-10 |
| Repository | Repository Contracts | REPO-01…06 |
| Schema / migrations | Database Design | DB-01/02/04 |
| Immutability / audit | ADR-002, Database Design | DB-03, DOM-04, LOG-03/04 |
| API endpoints / errors | API Contracts | API-01/05 |
| AuthZ seam | ADR-005 | SEC-01/02 |
| Idempotency / IDs | ADR-009 | DOM-09, API-03 |
| Migration safety convention | ADR-008 | DB-09 |
| Slice definition / ordering | Implementation Roadmap | P-03 (report conflicts) |

## 13. Suggested Coding-Agent Assignment

Each agent receives the relevant frozen artifacts + the Constitution, stays within one context/concern (`AGENT-03`), cites the rule/artifact IDs it satisfies (`AGENT-04`), and produces its own tests (`AGENT-05`). All agent PRs are human-reviewed (`REV-02`); agents stop-and-ask on ambiguity (`AGENT-02`).

| Agent | Lane | Tasks |
|---|---|---|
| **A — Platform/Infra** | Repo scaffold, layering, DI, config/secrets, logging, health, CI pipeline, containerized DB | T-01, T-02, T-03, T-07, T-10, T-11, CI (TST-08) |
| **B — Persistence** | Migration tooling, all migrations, least-privilege role, seeds, migration tests | DB-T-01…08, TST-06 |
| **C — Domain/Application** | Aggregate (from Aggregate Design), use cases, outbox, event, idempotency, audit writer, authz seam, error types | T-04, T-05, T-06, T-08, T-09, T-12, TST-01, TST-02, TST-03, TST-05 |
| **D — API** | Endpoints, validation, error mapping, correlation ID, contract test | API-T-01…05, TST-04 |
| **E — Frontend** | UI shell, list, create, error display | FE-T-01…05 |
| **F — E2E/QA** | End-to-end smoke, acceptance-criteria verification | TST-07 |

## 14. Estimated Implementation Sequence

Dependency-ordered phases (relative size S/M/L; no calendar estimate given, as team velocity is not an input I'll assume). Parallelism is noted where safe.

- **Phase 0 — Gate (human):** confirm Appendix A binding + named aggregate. *(blocking)*
- **Phase 1 — Platform (Agent A):** repo scaffold, layering + boundary checks, DI, config/secrets, logging, health, CI skeleton, containerized DB. **L.** *Everything depends on this.*
- **Phase 2 — Persistence (Agent B):** migrations for aggregate/outbox/audit/idempotency tables, least-privilege role, seeds. **M.** *Depends on P1 + Database Design.* Can start once P1 scaffolding + DB are up.
- **Phase 3 — Domain/Application (Agent C):** aggregate, use cases, outbox, event, idempotency, audit writer, authz seam, error types + their tests. **L.** *Depends on P1/P2 + Aggregate Design + Repository Contracts.*
- **Phase 4 — API (Agent D):** endpoints, validation, error mapping, correlation ID, contract test. **M.** *Depends on P3 + API Contracts.*
- **Phase 5 — Frontend (Agent E):** UI shell, list, create, error handling. **S–M.** *Depends on P4.* Can scaffold against the API Contracts in parallel with P4, integrate after.
- **Phase 6 — E2E + full green (Agent F):** e2e smoke, wire all Acceptance Criteria into CI. **S.** *Depends on P5.*
- **Phase 7 — Sign-off (human):** DoD review (Constitution §14 + §11 here), finalize Appendix A. *(blocking close)*

**Critical path:** P0 → P1 → P2 → P3 → P4 → P5 → P6 → P7. Agents D and E may pre-build against the frozen API Contracts while P3 completes, then integrate.

---

*This work package is executable once the two Gate inputs are supplied. It intentionally references — and never reproduces or reinterprets — the frozen business architecture.*
