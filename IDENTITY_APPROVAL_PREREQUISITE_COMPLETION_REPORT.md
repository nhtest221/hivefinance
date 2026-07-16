# Durable Approval Lifecycle Prerequisite Completion Report

## Status

- Scope: frozen durable approval lifecycle prerequisite only.
- Branch: `codex/identity-approval-prerequisite`.
- States implemented: `pending`, `approved`.
- Explicitly not implemented: rejection, cancellation, M1 Ledger + Valuation, Tax, FX, Receivables, Payables, Settlement, Notes, Period Close, Migration, and later Reporting.

## Reused platform capabilities

- Existing Sanctum authentication, MFA issuance flow, entity grants, roles, permissions, and `approval_policy` storage.
- Existing audit logger, transactional outbox, correlation middleware, and idempotency records.
- Existing Identity models and authorization queries; no Identity/Auth rebuild was performed.

## Migration

- Added `identity_approval_requests` with entity/maker/approver linkage, two-state lifecycle, version, immutable command identity, encrypted payload, SHA-256 hash, schema version, original concurrency precondition, originating idempotency/correlation/causation linkage, durable command result, event linkage, timestamps, and configurable retention expiry.
- Added entity queue, resource/command, and originating-command idempotency indexes and Identity-local foreign keys.

## Domain and application components

- Added `ApprovalStatus` with only `pending` and `approved`.
- Added the immutable `OriginatingCommand` value and versioned `ApprovalCommandHandler` contract.
- Added a handler registry so an originating bounded context can register only supported command type/schema pairs.
- Added `ApprovalLifecycleService` for atomic pending-request creation, maker-checker authorization, optimistic approval, safe command execution, retry, and idempotent replay.
- Added execution context propagation for approval ID, entity, maker, approver, effective correlation, `ApprovalRequested` causation, originating correlation, and original `If-Match`.

## Infrastructure and security controls

- Canonical payload JSON is encrypted at rest with Laravel's platform encryption facility; the canonical plaintext is SHA-256 hashed and compared in constant time before replay.
- Decryption, integrity validation, schema validation, and handler resolution occur behind application interfaces. The API never accepts a replacement payload.
- Encrypted payloads, hashes, idempotency keys, command results stored for replay, and correlation internals are hidden from model serialization and excluded from audit/outbox payloads.
- Unsupported schema/handler, expired retention, decryption failure, and hash mismatch fail safely while the approval remains pending.
- Failed execution rolls back originating changes and writes a separate immutable safe audit attempt plus an idempotent failure result.
- `APPROVAL_PAYLOAD_RETENTION_DAYS` is an explicit configuration dependency. No retention duration, key provider, key rotation rule, tax value, approval threshold, or legal policy was invented.

## API

- Added authenticated `POST /v1/approvals/{id}/approve` with an empty body.
- Enforces UUID entity/approval/idempotency identifiers, `If-Match`, current entity access, maker/approver separation, approval plus command-specific capability, pending status, and optimistic version transition.
- Identical replay returns the stored response with `Idempotency-Replayed: true`; conflicting key reuse and stale versions return their frozen errors.
- Responses expose only the safe approval projection and the originating command's approved result.

## Audit and domain events

- Pending creation atomically writes the approval request, idempotency result, `approval_requested` audit, and `ApprovalRequested` outbox event.
- Successful approval atomically writes originating business/audit/outbox work, approval transition/result, approval idempotency result, `approval_granted` audit, and `ApprovalGranted` outbox event.
- `ApprovalGranted` is caused by the stored `ApprovalRequested` event ID. The effective approval correlation and originating correlation are available to the registered originating handler.
- No `ApprovalGranted` event or approval transition commits on failed originating-command execution.

## Tests and validation

- Approval feature tests cover maker self-approval, missing capabilities, cross-entity access, stale versions, duplicate approval, idempotent success and failure replay, encrypted payload tampering, hash tampering, unsupported schema, execution rollback/retry, exactly-once business execution, correlation/causation, safe serialization, audit, and outbox behavior.
- Focused approval suite: 11 tests, 73 assertions passed.
- Full backend suite: 34 tests, 166 assertions passed.
- Laravel Pint: passed.
- PHPStan level 6: passed.
- Context boundary guard: passed.
- Rector dry run: passed.
- Frontend typecheck, lint, and production build: passed. The existing Vite bundle-size warning remains non-blocking.
- `git diff --check`: passed.

## M1 Tax consumption

M1 Tax will continue to own tax command validation and policy evaluation. When the frozen `approval_policy` requires maker-checker review, its application service will submit the already-authorized, already-validated canonical command through `ApprovalLifecycleService::requestApproval()` and return the frozen `202` approval projection. Tax will register a versioned `ApprovalCommandHandler` that revalidates Tax invariants and applies Tax mutations, audit, and outbox work inside the approval transaction. It will not expose the stored payload or accept mutable replacement input at approval time.

## Deferred configuration and scope

- Deployment must provide `APP_KEY` through the existing platform secret process and select `APPROVAL_PAYLOAD_RETENTION_DAYS` through approved policy before retention expiry is enabled.
- Concrete originating handlers, including Tax, are intentionally deferred to their approved milestones.
- Rejection and cancellation remain absent pending a separately frozen contract.
