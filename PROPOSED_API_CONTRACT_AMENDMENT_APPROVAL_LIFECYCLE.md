# Proposed API Contract Amendment — Durable Approval Lifecycle

**Status:** Proposed; not frozen  
**Scope:** Missing ADR-005 maker-checker protocol required before M1  
**Implementation authority:** None until approved

## 1. Scope

This amendment defines the durable approval request created when an originating command requires maker-checker review and the public command that approves it.

Only `pending` and `approved` are proposed because those are the only lifecycle outcomes currently expressed by the frozen artifacts. Rejection and cancellation are not defined and are excluded pending separate approval.

## 2. Common requirements

- TLS, authentication, and UUID `X-Entity-Id` are required.
- UUID `X-Correlation-Id` is optional. The server generates one when absent or invalid, echoes it, and propagates it to audit, outbox, logs, and replayed command causation metadata.
- UUID `Idempotency-Key` is required for approval.
- Integer `If-Match` is required for approval.
- Entity access and approval capability are default-deny.
- The approver must differ from the originating maker.
- Approval is scoped to the same entity as the originating command.
- Approval never accepts a replacement command payload. The durable canonical payload captured by the originating command is replayed.
- Error envelope:

```json
{
  "error_code": "concurrency_conflict",
  "message": "The approval version is stale.",
  "details": {},
  "required_version": 2
}
```

Stable errors are `400 validation`, `401 unauthenticated`, `403 authorization`, `403 maker_cannot_approve`, `404 not_found`, `409 concurrency_conflict`, `409 idempotency_conflict`, `409 approval_already_decided`, `422 originating_command_invalid`, and `428 precondition_required`.

## 3. Pending approval outcome from an originating command

When configured policy requires approval, the originating command returns `202` and makes no originating business-state change.

```json
{
  "approval": {
    "id": "3530ca0e-4201-4ab1-8521-20f851defd44",
    "status": "pending",
    "command": "tax_code_version_create",
    "resource_id": "fb861bea-a516-4546-b92e-2a96a19a3379",
    "maker_id": "b7447cf1-adf8-439b-bf4c-34c5752cfdd7",
    "entity_id": "2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d",
    "version": 1,
    "submitted_at": "2026-07-16T10:30:00Z"
  }
}
```

The approval request durably stores:

- Approval ID, status, and approval version.
- Command type and command schema version.
- Entity ID and maker ID.
- Canonical immutable command payload and original concurrency precondition.
- Originating idempotency key and request scope.
- Originating correlation ID and causation metadata.
- SHA-256 payload hash computed from the canonical payload bytes.
- UTC created timestamp.

### 3.1 Secure originating-command storage

- The stored payload is the canonical immutable payload accepted by the originating application service. It cannot be replaced or edited after approval submission.
- Sensitive payload fields are encrypted at rest using the platform's approved encryption facility and managed encryption keys. This proposal does not define a key provider or rotation policy.
- Before replay, the application service decrypts the payload, canonicalizes it using the stored schema version, recomputes its SHA-256 hash, and performs constant-time comparison with the stored payload hash.
- An unsupported command type or schema version, decryption failure, or hash mismatch fails safely with `422 originating_command_invalid`. No originating business mutation or approval transition occurs.
- Only registered application services may read or decrypt the payload. Controllers, repositories exposed outside Identity, frontend clients, and event consumers have no payload access.
- The payload, sensitive fields, encryption material, and payload hash are never returned by an API or written to logs, audit details, or domain-event payloads/metadata.
- Audit and events identify the command by approval ID, command type, entity ID, resource ID when applicable, and schema version only.
- Retention is configurable by policy. Expiry or secure erasure must preserve non-sensitive approval/audit evidence while making an unreplayable pending request fail safely.
- Replay always uses the stored payload. Mutable client input is never accepted as a replacement.

Identical replay of the originating command returns the original `202` response without creating another approval request, audit record, or outbox event. Reuse of its idempotency key with different input returns `409 idempotency_conflict`.

Creation commits the pending approval, audit record, idempotency result, and `ApprovalRequested` outbox event atomically.

## 4. Approve command

### 4.1 Method and path

`POST /v1/approvals/{id}/approve`

### 4.2 Authorization and headers

- Capability: `identity.approvals.approve` plus any command-specific approval capability required by configured policy.
- Required headers: `X-Entity-Id`, `Idempotency-Key`, and `If-Match`.
- Optional header: `X-Correlation-Id`.
- The authenticated actor cannot be the maker.

### 4.3 Request

The body is empty.

```json
{}
```

### 4.4 Validation

- `{id}` is a UUID for a pending approval in the active entity.
- `If-Match` equals the current approval version.
- The approver has current entity access and every required capability.
- The approver differs from the maker.
- The approval is still `pending`.
- The captured originating command, payload, and handler version are supported.
- The originating command is replayed using the captured payload and original precondition; no client payload substitution is allowed.
- All originating command invariants are re-evaluated at approval time.
- If the originating resource changed after submission, approval fails without changing approval status.

### 4.5 Success response

`200`:

```json
{
  "approval": {
    "id": "3530ca0e-4201-4ab1-8521-20f851defd44",
    "status": "approved",
    "command": "tax_code_version_create",
    "resource_id": "fb861bea-a516-4546-b92e-2a96a19a3379",
    "maker_id": "b7447cf1-adf8-439b-bf4c-34c5752cfdd7",
    "approver_id": "1b8f3c2f-4e62-4fa9-a924-77848017a9a6",
    "entity_id": "2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d",
    "version": 2,
    "submitted_at": "2026-07-16T10:30:00Z",
    "approved_at": "2026-07-16T11:00:00Z"
  },
  "command_result": {
    "status": 201,
    "body": {
      "tax_code_version": {
        "id": "5b597b92-5ba8-45a5-acce-166172b32a51",
        "version_number": 2
      }
    }
  }
}
```

`command_result.status` and `command_result.body` are the originating command's approved success response. They are stored durably for safe idempotent replay.

### 4.6 Errors

- Missing `If-Match`: `428 precondition_required`.
- Stale approval version: `409 concurrency_conflict` with `required_version`.
- Maker attempts approval: `403 maker_cannot_approve`.
- Missing entity/capability: `403 authorization`.
- Unknown or cross-entity approval: `404 not_found`.
- Approval is no longer pending: `409 approval_already_decided`.
- Approval idempotency key reused with different input: `409 idempotency_conflict`.
- Captured originating command is no longer valid: `422 originating_command_invalid`; approval remains pending and no originating business change occurs.

For any failure while verifying or executing the originating command:

- Approval remains `pending` at its current version.
- No originating business mutation commits.
- No `ApprovalGranted` event is written.
- An immutable failed-attempt audit record is written with approval ID, actor ID, entity ID, safe failure code, correlation ID, and UTC timestamp. It contains no stored payload or sensitive values.
- A retry is allowed using a new approval idempotency key and the current `If-Match`, or by replaying the exact failed request under the existing idempotency rules. A failed idempotency result never permits two successful executions.

### 4.7 Idempotency and concurrency

- Approval idempotency scope is actor, entity, endpoint, and approval ID.
- Identical replay returns the original `200` response and `Idempotency-Replayed: true`.
- Approval uses an optimistic conditional transition from `pending` at the supplied version to `approved` at the next version.
- Concurrent or duplicate approval produces at most one originating command execution.

### 4.8 Transaction, audit, and outbox

The following commit atomically:

- Originating command business changes.
- Approval transition and approver attribution.
- Approval and originating-command audit records.
- Approval idempotency result and originating command result.
- Originating command outbox events.
- `ApprovalGranted` outbox event.

`ApprovalGranted` carries approval ID, entity ID, maker ID, approver ID, command name, resource ID, approval version, and UTC approval time. Metadata carries correlation ID and the `ApprovalRequested` event ID as causation ID.

## 5. Proposed event schemas

These events are proposals and do not become frozen until this amendment is approved through governance.

### 5.1 ApprovalRequested

- **Version:** `1`.
- **Owning context:** Identity & Access.
- **Trigger:** A configured maker-checker policy accepts an originating command for durable approval and commits its pending request.
- **Idempotency:** One event per approval ID. Replay of the originating idempotency key returns the existing request and emits no additional event.
- **Causation:** The originating command request ID when available; otherwise the originating idempotency key's durable record ID.
- **Correlation:** The effective originating `X-Correlation-Id`.

Payload:

```json
{
  "approval_id": "3530ca0e-4201-4ab1-8521-20f851defd44",
  "entity_id": "2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d",
  "maker_id": "b7447cf1-adf8-439b-bf4c-34c5752cfdd7",
  "command_type": "tax_code_version_create",
  "command_schema_version": 1,
  "resource_id": "fb861bea-a516-4546-b92e-2a96a19a3379",
  "approval_version": 1,
  "requested_at": "2026-07-16T10:30:00Z"
}
```

Metadata:

```json
{
  "event_id": "64033b88-c073-48bb-8c85-da4a8ec76871",
  "event_name": "ApprovalRequested",
  "event_version": 1,
  "occurred_at": "2026-07-16T10:30:00Z",
  "correlation_id": "00f777b2-a18e-46dd-b6c7-9fac9063f213",
  "causation_id": "41d7a445-6734-44d3-a2b5-d61a60ec44dc"
}
```

The event contains no command payload, payload hash, idempotency key, sensitive field, or encryption detail.

### 5.2 ApprovalGranted

- **Version:** `1`.
- **Owning context:** Identity & Access.
- **Trigger:** The approval transition and originating command execute successfully in the approved atomic transaction.
- **Idempotency:** One event per approval ID. Duplicate or concurrent approval cannot emit another event or repeat the originating command.
- **Causation:** The `ApprovalRequested` event ID.
- **Correlation:** The effective approval-command `X-Correlation-Id`; the originating correlation ID remains linked in durable approval metadata.

Payload:

```json
{
  "approval_id": "3530ca0e-4201-4ab1-8521-20f851defd44",
  "entity_id": "2b222b7c-9b5d-428d-9f1e-71bf7c7a5f2d",
  "maker_id": "b7447cf1-adf8-439b-bf4c-34c5752cfdd7",
  "approver_id": "1b8f3c2f-4e62-4fa9-a924-77848017a9a6",
  "command_type": "tax_code_version_create",
  "command_schema_version": 1,
  "resource_id": "fb861bea-a516-4546-b92e-2a96a19a3379",
  "approval_version": 2,
  "approved_at": "2026-07-16T11:00:00Z"
}
```

Metadata:

```json
{
  "event_id": "7fea2473-2cf0-469f-936b-0f798ef8f803",
  "event_name": "ApprovalGranted",
  "event_version": 1,
  "occurred_at": "2026-07-16T11:00:00Z",
  "correlation_id": "e0aee0cf-bcd3-45de-a409-c5bb42ad9857",
  "causation_id": "64033b88-c073-48bb-8c85-da4a8ec76871"
}
```

The event contains no command payload, command result body, payload hash, idempotency key, sensitive field, or encryption detail.

## 6. Excluded lifecycle operations

No public reject or cancel endpoint is proposed. No `rejected` or `cancelled` state may be implemented until its transition authority, request schema, reason requirements, idempotency, audit, and events are separately approved.
