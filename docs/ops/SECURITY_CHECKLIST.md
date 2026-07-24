# HiveFinance Security Checklist

This documents what the codebase actually does today around authentication, session
handling, and access control, plus the concrete gaps that exist as of this writing. It
does not invent a security control that isn't implemented — per `CLAUDE.md`, a missing
security rule is reported here as an open item, not assumed or filled in.

## 1. Authentication and session tokens

- Sessions are Laravel Sanctum personal-access tokens (`$user->createToken('api-session')`
  in `App\Identity\Application\LoginAction`).
- **`SANCTUM_EXPIRATION` has no default (null) and is not set in either
  `.env.example` or `.env.uat.example`.** As shipped, session tokens **do not expire**.
  Before production, an explicit decision is needed on token lifetime (this is a
  security policy decision, not something to default silently) and `SANCTUM_EXPIRATION`
  must be set accordingly.
- Failed-login lockout is implemented (`FailedLoginLockoutPolicy`): after
  `AUTH_LOCKOUT_MAX_ATTEMPTS` (default 5) failures, the account locks for
  `AUTH_LOCKOUT_DECAY_MINUTES` (default 15); a locked account gets HTTP 423 with
  `locked_until`.
- Password hashing: `config/hashing.php` defaults to `bcrypt` with `BCRYPT_ROUNDS`
  (default 12).

## 2. Multi-factor authentication — real gap, not a checklist item

`App\Identity\Application\MfaService::verifyChallenge()`:

```php
if (! app()->environment(['local', 'testing'])) {
    return null;
}
if (! hash_equals((string) config('identity.mfa.test_code', '000000'), $code)) {
    return null;
}
```

**In any environment other than `local`/`testing`, MFA verification always fails.**
There is no real TOTP/SMS/email second-factor provider wired up anywhere in this
codebase — the only implemented "verification" is a hardcoded test code
(`MFA_TEST_CODE`, default `000000`), and it is explicitly gated off outside
`local`/`testing`.

`App\Enums\SystemRole::requiresMfa()` returns `true` for the `Owner` and
`FinanceManager` roles (and individually for any user with `mfa_required` set). This
means: **any user holding the Owner or FinanceManager role cannot complete login in a
production (`APP_ENV=production`) deployment as the code stands today** — `LoginAction`
issues an MFA challenge for them, and `MfaService::verifyChallenge()` will unconditionally
reject any code presented in a non-local/testing environment.

This is not a configuration gap that can be closed with an env var. It requires
implementing a real MFA provider before any Owner/FinanceManager account can be used in
production. Flag this to the Product Owner explicitly — it blocks production login for
those two roles, not just "best practice" hardening.

## 3. CORS

**No `backend/config/cors.php` exists in this repository.** The app's `config/`
directory has no CORS customization at all. No explicit allow-list of origins has been
authored for this application. Before exposing the API to a browser frontend on a
different origin than the backend, an explicit CORS policy needs to be published and
reviewed — do not assume Laravel's framework default is already the intended
production policy; it has not been reviewed or approved for this application.

## 4. Rate limiting

**No `throttle` middleware is applied to any route, and no custom `RateLimiter::for(...)`
limiter is defined anywhere in the app.** The only brute-force protection that exists is
the login-attempt lockout policy in §1, which is specific to repeated failed passwords
on one account — it does not protect against high-volume request abuse, credential
stuffing across many accounts, or general API abuse. Adding rate limiting is
infrastructure/application work not yet done.

## 5. Access control (implemented, working)

- Every command handler authorizes against an explicit permission string
  (`$this->commands->authorize($actor, $entityId, '<permission>')`) — this is the
  pattern this session's UI work relied on throughout (`hasPermission()` on the
  frontend mirrors these exact strings).
- Maker-checker / four-eyes approval is enforced server-side wherever the entity's
  `approval_policy` is non-empty (`ApprovalPolicyQuery::isConfigured()`), and Hard
  Close / Reopen enforce it unconditionally regardless of entity configuration.
  `ApprovalLifecycleService::canApprove()` requires the approver to hold both
  `identity.approvals.approve` and the exact capability being approved, and separately
  rejects the maker approving their own request.
- Immutability of posted financial facts is enforced by PostgreSQL triggers (not just
  application-layer checks) — see `docs/ops/DEPLOYMENT_AND_OPERATIONS_RUNBOOK.md` §1 on
  why PostgreSQL is mandatory.
- Entity isolation: every query scopes to `entity_id`; this was exercised extensively by
  this session's test suite runs (every `*PersistenceTest.php` file asserts entity
  isolation per aggregate).

## 6. Secrets handling

- `.env` and `.env.*` (except `.env.example` and `backend/.env.uat.example`) are
  gitignored — confirmed via `git check-ignore`.
- No secret, API key, or credential is committed anywhere in the tracked repository as
  of this writing (checked as part of every PR merge this session per `CLAUDE.md`
  condition 12).
- `backend/.env.uat.example`'s password (`uat-only-change-me`) and the UAT seeder's
  shared password (`UatOnly!ChangeMe2026`) are illustrative/local-only by explicit
  design — both files carry "LOCAL/UAT ONLY, never deploy" headers. Never reuse them
  anywhere real.

## 7. Before production — summary of open items

- [ ] Set `SANCTUM_EXPIRATION` to an approved token lifetime (§1).
- [ ] Implement a real MFA provider before any Owner/FinanceManager account can log in
      to a production deployment (§2) — **this blocks production use for those roles as
      the code stands today**.
- [ ] Author and review an explicit CORS policy (§3).
- [ ] Add rate limiting to the public API surface (§4).
- [ ] Confirm mail transport if any flow needs real email delivery — see the
      Deployment and Operations Runbook §5 (no SMTP/SES/Mailgun configured anywhere
      in this repo today).
