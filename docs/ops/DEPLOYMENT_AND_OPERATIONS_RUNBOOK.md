# HiveFinance Deployment and Operations Runbook

This is an operational reference for running HiveFinance outside local development. It
is **not a frozen governance document** — it does not redefine anything in
`docs/README.md`'s Frozen Documents list, and where the codebase has not yet made a
production decision, this document says so explicitly rather than inventing one.

No deployment has been performed from this document. It describes the mechanics that
already exist in the repository (Docker image, config surface, scheduler, health
endpoints) and lists what is still missing before a real production deploy.

## 1. What already exists

- **Backend container**: `backend/Dockerfile` builds `php:8.4-cli` with `pdo_pgsql` and
  `redis` extensions, runs `composer install --no-scripts`, exposes port 8080, and its
  `CMD` is `php artisan serve --host=0.0.0.0 --port=8080`. This is Laravel's built-in
  development server, not a production-grade process manager (no PHP-FPM+nginx, no
  Octane, no worker pool). **Do not run this image's default command as the sole
  production entrypoint without first replacing it with a production-appropriate server
  process** — that replacement is an infrastructure decision, not made in this repo.
- **`docker-compose.yml`** (repo root) is a local-development/CI-adjacent composition:
  `backend`, `scheduler`, `queue`, `frontend`, `postgres` (17-alpine), `redis` (7-alpine).
  All backend-family services load `env_file: ./backend/.env.example` and pin
  `APP_ENV: local`, `APP_DEBUG: "true"` — **this file is not a production topology** and
  must not be deployed as-is (debug mode must be off in production; see §5).
- **Database**: only `sqlite` and `pgsql` connections are defined in
  `backend/config/database.php`; there is no `mysql` connection. Several migrations
  (9 of 22) install raw PostgreSQL trigger functions (`CREATE TRIGGER` / PL/pgSQL) that
  enforce immutability on posted financial facts — these have no MySQL equivalent.
  **PostgreSQL is mandatory for any real deployment**, matching `CLAUDE.md`'s existing
  instruction that accounting-affecting behavior must be validated against PostgreSQL,
  not SQLite.
- **CI**: `.github/workflows/ci.yml` is the only workflow in the repository. It runs on
  every `pull_request` and every push to `main`: backend job (PHP 8.4, Postgres 17,
  Redis 7 — `composer install`, `migrate --force`, Pint, PHPStan, the AP-001 context-
  boundary guard, Rector dry run, Pest) and frontend job (Node 20, `npm ci`, typecheck,
  lint, build). **There is no CD/deploy workflow.** Nothing in this repository builds or
  pushes a release artifact, deploys to any environment, or runs a smoke test post-
  deploy. That pipeline does not exist yet and must be built before automated deploys are
  possible.

## 2. Health and readiness endpoints

- `GET /up` — Laravel's stock framework liveness check (wired via
  `bootstrap/app.php`'s `health: '/up'`). Unauthenticated, returns Laravel's default
  health view.
- `GET /v1/health` — `App\Http\Controllers\HealthCheckController`. Unauthenticated,
  returns `{"status":"ok","service":"hivefinance-backend"}` unconditionally.

**Both endpoints are liveness checks only — neither verifies database connectivity,
Redis connectivity, or queue/outbox health.** A process that is running but cannot reach
Postgres or Redis will still report healthy on both endpoints. Do not configure a load
balancer or orchestrator to treat either endpoint as a readiness probe for downstream
dependencies without adding real dependency checks first (not present in this codebase
today — would need to be built, e.g. a DB `SELECT 1` and a Redis `PING`, before being
trusted as a readiness signal).

## 3. Queue and outbox operation

HiveFinance uses a transactional outbox pattern (`app/Support/Outbox/Outbox.php`
writes `outbox_messages` rows inside the same DB transaction as the domain write that
produced them) plus a dispatcher that publishes them in-process
(`app/Support/Outbox/OutboxDispatcher.php` → `InProcessEventBus`).

- **Scheduled work** (`backend/routes/console.php` — this is the entirety of the
  application's scheduled work, no other cron entries exist anywhere in the repo):
  ```php
  Schedule::command('queue:work --stop-when-empty')->everyMinute()->withoutOverlapping();
  Schedule::command('outbox:dispatch')->everyMinute()->withoutOverlapping();
  ```
- **`outbox:dispatch`** (`app/Console/Commands/DispatchOutbox.php`, `outbox:dispatch
  {--limit=100}`) is the only thing that publishes outbox events. **It is driven
  entirely by the Laravel scheduler.** If `php artisan schedule:work` (or an external
  cron calling `php artisan schedule:run` every minute) is not running continuously in
  an environment, outbox events (ledger projections, `CreditNoteIssued`,
  `ReceiptAllocated`, etc.) will never be dispatched, silently. This is an operational
  hard requirement, not optional infrastructure.
- `docker-compose.yml`'s `scheduler` service runs `php artisan schedule:work`
  continuously — this is what fires both scheduled commands above.
- **Known redundancy to be aware of, not necessarily a bug**: the compose file also runs
  a separate `queue` service with `php artisan queue:work --sleep=3 --tries=3` running
  continuously, *in addition to* the scheduler firing `queue:work --stop-when-empty`
  every minute. Both process the same Redis queue. This means queued jobs are picked up
  by whichever worker is free — harmless for correctness but means the continuous
  `queue` service is doing the bulk of the real work and the scheduled
  `--stop-when-empty` invocation is largely redundant with it. Before production,
  confirm the intended topology (probably: keep only the continuous `queue` worker, and
  keep `outbox:dispatch` on the scheduler as designed) rather than assuming
  `docker-compose.yml`'s local dev topology is the intended production shape.
- **Failed jobs**: `QUEUE_FAILED_DRIVER` defaults to `database-uuids` against the
  `failed_jobs` table. Nothing in this repo currently alerts on failed-job accumulation —
  operational monitoring for `failed_jobs` growth is an open item (see §4).
- **Retry/backoff**: `OutboxDispatcher` applies exponential backoff on failure
  (`min(300, 2**min(attempts,8))` seconds) and records `last_error` (truncated to 2000
  chars) on the `outbox_messages` row itself — that table is a useful place to check for
  stuck/failing outbox delivery.

## 4. Monitoring and health-check guidance

Nothing in this repository currently ships a monitoring/alerting integration (no APM
agent, no error-tracking SDK, no metrics exporter). What can be monitored today using
only what exists:

- **Process liveness**: `GET /up` or `GET /v1/health` on a fixed interval — liveness
  only, per §2.
- **Scheduler/queue health**: alert if `outbox_messages` rows exist with
  `processed_at IS NULL` and `available_at` more than a few minutes in the past — this
  directly signals the scheduler/`outbox:dispatch` loop has stopped running (see §3).
- **Failed jobs**: alert on `failed_jobs` row count growth.
- **Database**: standard Postgres connection-count / replication-lag / disk-usage
  monitoring at the infrastructure layer — no application-level instrumentation for this
  exists in the repo.
- **Logs**: `LOG_CHANNEL=stack`, `LOG_STACK=stderr` in both example env files — the
  application logs to stderr by default, suitable for container log collection, but no
  structured-logging/log-shipping configuration exists beyond that.

Building real monitoring (APM, error tracking, metrics, alerting) is infrastructure work
outside this repository's current scope and should be scoped as its own project before
a production launch that depends on it.

## 5. Environment configuration checklist

Source of truth for every variable: `backend/.env.example` (production-shaped, all
policy values deliberately blank) and `backend/.env.uat.example` (illustrative UAT
values only — **never use `.env.uat.example` values in any shared or production
environment**, its own header says so).

Every deployment must set, at minimum:

| Variable | Notes |
|---|---|
| `APP_KEY` | Blank in both example files. Generate with `php artisan key:generate` per environment — never reuse a key across environments. |
| `APP_ENV` | Must be `production` in production. `MfaService::verifyChallenge()` only accepts the hardcoded MFA test code in `local`/`testing` environments — see §"MFA" in `SECURITY_CHECKLIST.md`; this is also why `APP_ENV` must never be `local`/`testing` in a real deployment. |
| `APP_DEBUG` | Must be `false` in production. Both example files set `true`; `docker-compose.yml` also pins `true`. Leaving debug mode on in production would leak stack traces/environment detail on error pages. |
| `APP_URL` | Real production URL. |
| `DB_CONNECTION` / `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Must be `pgsql` (see §1). Never the example password. |
| `CACHE_STORE`, `QUEUE_CONNECTION` | Production-shaped example uses `redis` for both; UAT example uses `database`/`sync`. Confirm `REDIS_HOST`/`REDIS_PORT` and, if the Redis deployment requires auth, `REDIS_PASSWORD`/`REDIS_USERNAME` — **neither appears in either example file even though `config/database.php` reads them**, so a password-protected Redis instance needs those two added explicitly; they are not optional extras, they are a gap in the example files. |
| `SESSION_DRIVER` | `database` in both examples. |
| `SANCTUM_STATEFUL_DOMAINS` | Both examples list `localhost,127.0.0.1`. Must be the real production frontend domain(s) — leaving the example value in production would misconfigure Sanctum's stateful-domain CSRF protection for the actual frontend origin. |
| `AUTH_LOCKOUT_MAX_ATTEMPTS`, `AUTH_LOCKOUT_DECAY_MINUTES`, `MFA_CHALLENGE_TTL_MINUTES` | Have sane defaults (5, 15, 5) if unset; confirm they match whatever policy is actually approved rather than assuming the default is the approved value. |
| `HIVEFIN_TAX_*`, `HIVEFIN_FX_*`, `SETTLEMENT_*`, `DOCUMENT_*`, `INVOICE_*`, `BILL_*`, `EXPENSE_*` | All blank in `.env.example` by design — the comments in that file state these "are deployment policy and intentionally have no defaults" and "deployments must supply approved values." **Do not fill these from `.env.uat.example`'s illustrative values.** Each one is a real accounting/tax/FX/numbering policy decision requiring its own Governance Approval Record before a production entity can safely post financial transactions — this runbook does not and must not supply those values. |
| `DOCUMENT_REASON_CODES`, `CREDIT_NOTE_NUMBER_PREFIX`/`_FORMAT`, `DEBIT_NOTE_NUMBER_PREFIX`/`_FORMAT`, `PERIOD_SOFT_CLOSE_ADJUSTMENT_ENTRY_TYPES`, `PERIOD_VAT_UNLOCK_PERMITTED` | Consumed by `config/documents.php`/`config/period.php` but **absent from both example env files** — a gap. These must be set explicitly (with approved values, same governance requirement as above) or the M4A Notes and Period Close features will fail with `missing_*_configuration`-style errors in production. |
| Mail | `config/mail.php` only defines a `log` mailer — **no SMTP/SES/Mailgun transport is configured anywhere in this repo, and no mail credentials exist in either example file.** As shipped, the application cannot send real email. If any product flow depends on email delivery (password reset, notifications), a mail transport must be added and configured — that is new work, not a missing env var. |

## 6. Release checklist

Before treating a build as release-candidate:

1. CI is green on the exact commit (`.github/workflows/ci.yml` — backend and frontend
   jobs both pass; this already covers Pint, PHPStan, the AP-001 boundary guard, Rector,
   the full Pest suite against PostgreSQL, and the frontend typecheck/lint/build).
2. Migrations apply cleanly from a fresh database, and rollback-then-forward succeeds,
   on PostgreSQL (CI only runs a forward `migrate --force`; rollback/forward is not
   currently exercised in CI and must be checked manually per `CLAUDE.md`'s autonomous-
   merge conditions whenever a migration file changed in the release).
3. No file under `docs/README.md`'s Frozen Documents list changed without an explicit
   Governance Approval Record.
4. Every environment variable in §5's table is set to a real, approved value for the
   target environment — not copied from `.env.uat.example`.
5. `APP_ENV=production`, `APP_DEBUG=false` confirmed in the target environment's actual
   runtime config, not just the example files.
6. The scheduler process (`php artisan schedule:work`, or external cron running
   `php artisan schedule:run` every minute) and at least one continuous
   `php artisan queue:work` process are both confirmed running in the target
   environment — see §3; without both, outbox events silently stop dispatching.
7. `GET /up` and `GET /v1/health` both return healthy against the deployed build.

## 7. Rollback plan

No automated rollback tooling exists in this repository (no deploy workflow, per §1).
Until one is built, rollback is a manual procedure:

1. Re-deploy the previous known-good commit/image using whatever manual deployment
   process was used to deploy the failing release (the specific mechanism is
   infrastructure-dependent and not defined in this repo).
2. If the failing release included a migration: run
   `php artisan migrate:rollback --step=<N>` for the exact number of migration batches
   introduced by the failing release **before** re-deploying the previous code — rolling
   back code while a newer migration's schema is still applied risks the older code
   querying columns/tables it doesn't expect. Confirm rollback safety per-migration; the
   project's own merge-gate discipline (`CLAUDE.md`) already requires every migration
   that ships to have been verified for rollback-then-forward on both SQLite and
   PostgreSQL before merge, so a clean rollback should be achievable if that discipline
   was followed for the release in question.
3. Because financial facts in this system are protected by immutability triggers and an
   append-only audit/outbox design, a rollback must never attempt to directly edit or
   delete posted rows to "undo" a bad release's data effects — any data correction must
   go through the application's own reversal/void/note workflows, run *after* rollback,
   not through direct database surgery.
4. Confirm `GET /up` / `GET /v1/health` healthy and the scheduler/queue workers (§3)
   running again post-rollback.

## 8. Production configuration checklist (summary)

This is a pointer, not a duplicate — see the referenced sections for detail:

- [ ] PostgreSQL 17 (or the CI-tested version) as the database — never MySQL/SQLite (§1).
- [ ] `APP_ENV=production`, `APP_DEBUG=false` (§5).
- [ ] Every variable in §5's table set to a real, approved value — none copied from
      `.env.uat.example`.
- [ ] A production-appropriate PHP process manager replacing the Dockerfile's default
      `artisan serve` command (§1).
- [ ] Scheduler + at least one continuous queue worker both running (§3).
- [ ] Redis auth configured if the Redis deployment requires it (`REDIS_PASSWORD`/
      `REDIS_USERNAME` — not present in either example file, §5).
- [ ] A real mail transport configured if any flow depends on email delivery (§5).
- [ ] `SANCTUM_STATEFUL_DOMAINS` set to the real frontend origin(s), not localhost (§5).
- [ ] See `SECURITY_CHECKLIST.md` for the security-specific items (MFA, token
      expiration, CORS, rate limiting) — deliberately not duplicated here.
- [ ] See `RELEASE_ROLLBACK_AND_BACKUP.md` for backup/DR — deliberately not duplicated
      here, and **currently an open governance gap, not a solved problem**.
