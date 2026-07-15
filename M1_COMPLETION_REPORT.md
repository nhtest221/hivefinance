# M1 Completion Report — Identity and Authentication

## Scope Completed

- Added Laravel Sanctum dependency and `/v1` API routing alignment.
- Added Identity-owned persistence for Entity, User, Role, RolePermission, entity grants, role assignments, password reset tokens, sessions, and Sanctum personal access tokens.
- Implemented thin HTTP controllers and request validation for:
  - login
  - MFA challenge completion
  - logout
  - session read
  - password reset request and reset
  - entity list and entity switch
  - active-entity roles and permissions
- Implemented application services for:
  - password hashing and login verification
  - account lockout after failed attempts
  - Owner and Finance Manager MFA foundation
  - default-deny entity access checks
  - RBAC role/permission presentation
  - auth/access audit logging
- Added unit, feature, and security-oriented tests for authentication, logout, lockout, MFA, password reset, entity access, entity switching, and RBAC reads.
- Added frontend identity screens for login, MFA challenge, forgot password, and reset password using the existing design system.
- Preserved the existing NotionHive logo usage and only fixed M1-related auth UI defects.
- Added `frontend/UI_BACKLOG.md` for unrelated UI polish items deferred from M1.

## Architecture Compliance

- Followed AP-001 by keeping Identity as the owner of Entity/User/Role access decisions.
- Kept controllers thin: HTTP classes validate/map requests and delegate to application services.
- Kept business rules out of Eloquent models; models define persistence relationships and casts only.
- Used Sanctum for token/session authentication.
- Enforced privileged-role MFA foundation for Owner and Finance Manager.
- Added audit records for login success/failure, lockout, logout, password reset request/completion, MFA challenge issue, entity switch, and denied entity switch.
- Did not implement accounting modules.
- Did not modify frozen architecture documents under `docs/`.

## Verification

Passed locally:

- `npm run typecheck`
- `npm run lint`
- `npm run build`
- `git diff --check`

Warnings observed:

- `npm run build` completed, but Vite warned that local Node.js `20.17.0` is below its preferred `20.19+` / `22.12+` range.
- `npm run build` completed with an existing bundle-size warning; code splitting is recorded in `frontend/UI_BACKLOG.md`.

Blocked locally:

- `php -v` failed: PHP is not installed in this shell.
- `composer test`, `composer analyse`, `composer format`, and `composer refactor` failed: Composer is not installed in this shell.
- `backend/vendor/bin` is absent, so Pest, Pint, PHPStan, and Rector cannot be run locally.
- `docker --version` failed: Docker is not installed in this shell.

## Commits

- `4482a76 feat(backend): add M1 identity authentication foundation`
- `0e45346 feat(frontend): add M1 identity access screens`

## Review Notes

- Backend tests are authored but require a PHP/Composer environment to execute.
- The MFA implementation is a foundation: local/testing challenge verification uses `MFA_TEST_CODE`; production verification intentionally requires a real MFA provider integration before accepting codes.
- M1 is complete for review and should not proceed to the next milestone until approved.
