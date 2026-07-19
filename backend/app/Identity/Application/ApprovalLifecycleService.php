<?php

namespace App\Identity\Application;

use App\Identity\Domain\ApprovalStatus;
use App\Identity\Domain\OriginatingCommand;
use App\Models\IdempotencyRecord;
use App\Models\Identity\ApprovalRequest;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Outbox\Outbox;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Throwable;

final readonly class ApprovalLifecycleService
{
    public function __construct(
        private RoleAuthorizationService $authorization,
        private ApprovalPayloadProtector $payloads,
        private ApprovalCommandRegistry $handlers,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    public function requestApproval(
        User $maker,
        string $entityId,
        OriginatingCommand $command,
        string $originatingOperation,
        string $idempotencyKey,
        string $correlationId,
        ?string $causationId = null,
    ): IdentityActionResult {
        if (! Str::isUuid($entityId)
            || ! Str::isUuid($idempotencyKey)
            || ! Str::isUuid($correlationId)
            || ($command->resourceId !== null && ! Str::isUuid($command->resourceId))
            || $command->schemaVersion < 1
            || trim($command->type) === ''
            || trim($command->requiredApprovalCapability) === '') {
            return IdentityActionResult::error('validation', 'The approval command identifiers and schema must be valid.', 400);
        }
        if (! $this->authorization->canAccessEntity($maker, $entityId)) {
            return IdentityActionResult::error('authorization', 'The requested capability is not permitted for the active entity.', 403);
        }
        $operation = 'approval.request:'.$originatingOperation;
        $requestHash = $this->hash([
            'command_type' => $command->type,
            'command_schema_version' => $command->schemaVersion,
            'entity_id' => $entityId,
            'maker_id' => $maker->id,
            'original_if_match' => $command->originalIfMatch,
            'payload' => $command->payload,
            'required_approval_capability' => $command->requiredApprovalCapability,
            'resource_id' => $command->resourceId,
        ]);
        $replay = $this->idempotencyReplay($maker->id, $entityId, $operation, $idempotencyKey, $requestHash);
        if ($replay !== null) {
            return $replay;
        }

        $protected = $this->payloads->protect($command->payload, $command->schemaVersion);
        $submittedAt = Carbon::now('UTC');
        $originatingIdempotencyRecordId = (string) Str::uuid();
        $causationId ??= $originatingIdempotencyRecordId;
        $retentionDays = config('approval.payload_retention_days');

        try {
            return DB::transaction(function () use ($maker, $entityId, $command, $originatingOperation, $operation, $idempotencyKey, $correlationId, $causationId, $requestHash, $protected, $submittedAt, $retentionDays, $originatingIdempotencyRecordId): IdentityActionResult {
                $approval = ApprovalRequest::query()->create([
                    'entity_id' => $entityId,
                    'maker_id' => $maker->id,
                    'status' => ApprovalStatus::Pending->value,
                    'command_type' => $command->type,
                    'command_schema_version' => $command->schemaVersion,
                    'resource_id' => $command->resourceId,
                    'required_approval_capability' => $command->requiredApprovalCapability,
                    'encrypted_payload' => $protected['ciphertext'],
                    'payload_hash' => $protected['hash'],
                    'originating_idempotency_key' => $idempotencyKey,
                    'originating_operation' => $originatingOperation,
                    'originating_request_hash' => $requestHash,
                    'originating_correlation_id' => $correlationId,
                    'causation_id' => $causationId,
                    'approval_requested_event_id' => null,
                    'original_if_match' => $command->originalIfMatch,
                    'version' => 1,
                    'submitted_at' => $submittedAt,
                    'retained_until' => is_int($retentionDays) ? $submittedAt->copy()->addDays($retentionDays) : null,
                ]);
                $event = $this->outbox->record(
                    'ApprovalRequested',
                    'ApprovalRequest',
                    $approval->id,
                    $this->requestedEventPayload($approval),
                    $entityId,
                    metadata: ['correlation_id' => $correlationId, 'causation_id' => $causationId],
                );
                $approval->approval_requested_event_id = $event->id;
                $approval->save();
                $this->audit->record(
                    'identity', 'approval_requested', 'approval_request', $approval->id,
                    $maker->id, $entityId, after: $this->safeApproval($approval),
                    metadata: ['command_type' => $command->type, 'command_schema_version' => $command->schemaVersion],
                    correlationId: $correlationId,
                );
                $payload = ['approval' => $this->safeApproval($approval)];
                $this->storeIdempotency($maker->id, $entityId, $operation, $idempotencyKey, $requestHash, 202, $payload, $originatingIdempotencyRecordId);

                return IdentityActionResult::ok($payload, 202);
            });
        } catch (UniqueConstraintViolationException) {
            $replay = $this->idempotencyReplay($maker->id, $entityId, $operation, $idempotencyKey, $requestHash);

            return $replay ?? IdentityActionResult::error('idempotency_conflict', 'The idempotency key was already used.', 409);
        }
    }

    public function approve(
        User $approver,
        string $entityId,
        string $approvalId,
        ?string $idempotencyKey,
        ?string $ifMatch,
        string $correlationId,
    ): IdentityActionResult {
        if (! Str::isUuid($entityId) || ! Str::isUuid($approvalId) || ! Str::isUuid($idempotencyKey)) {
            return IdentityActionResult::error('validation', 'X-Entity-Id, approval ID, and Idempotency-Key must be UUIDs.', 400);
        }
        if ($ifMatch === null || preg_match('/^"?\d+"?$/', $ifMatch) !== 1) {
            return IdentityActionResult::error('precondition_required', 'If-Match is required.', 428);
        }
        $expectedVersion = (int) trim($ifMatch, '"');
        if ($expectedVersion < 1) {
            return IdentityActionResult::error('validation', 'If-Match must be a positive integer approval version.', 400);
        }
        $operation = 'approval.approve:'.$approvalId;
        $requestHash = $this->hash(['approval_id' => $approvalId, 'if_match' => $expectedVersion]);
        if (! $this->authorization->canAccessEntity($approver, $entityId)) {
            return IdentityActionResult::error('authorization', 'The requested capability is not permitted for the active entity.', 403);
        }
        $approval = ApprovalRequest::query()->where('entity_id', $entityId)->find($approvalId);
        if (! $approval instanceof ApprovalRequest) {
            return IdentityActionResult::error('not_found', 'The approval request was not found.', 404);
        }
        if ($approval->maker_id === $approver->id) {
            return IdentityActionResult::error('maker_cannot_approve', 'The maker cannot approve their own request.', 403);
        }
        if (! $this->canApprove($approver, $entityId, $approval->required_approval_capability)) {
            return IdentityActionResult::error('authorization', 'The requested capability is not permitted for the active entity.', 403);
        }
        $replay = $this->idempotencyReplay($approver->id, $entityId, $operation, $idempotencyKey, $requestHash);
        if ($replay !== null) {
            return $replay;
        }
        if ($approval->status !== ApprovalStatus::Pending->value) {
            return IdentityActionResult::error('approval_already_decided', 'The approval request is no longer pending.', 409);
        }
        if ($approval->version !== $expectedVersion) {
            return IdentityActionResult::error('concurrency_conflict', 'The approval version is stale.', 409, additional: ['required_version' => $approval->version]);
        }
        $payload = $this->payloads->reveal($approval->encrypted_payload, $approval->payload_hash, $approval->command_schema_version);
        $handler = $this->handlers->resolve($approval->command_type, $approval->command_schema_version);
        if ($payload === null || $handler === null || ($approval->retained_until !== null && $approval->retained_until->isPast())) {
            return $this->failedExecution($approver, $approval, $operation, $idempotencyKey, $requestHash, $correlationId, 'originating_command_invalid');
        }

        try {
            return DB::transaction(function () use ($approver, $entityId, $approval, $payload, $handler, $expectedVersion, $operation, $idempotencyKey, $requestHash, $correlationId): IdentityActionResult {
                $result = $handler->execute($payload, new ApprovalExecutionContext(
                    $approval->id, $entityId, $approval->maker_id, $approver->id,
                    $correlationId, (string) $approval->approval_requested_event_id,
                    $approval->originating_correlation_id, $approval->original_if_match,
                ));
                $approvedAt = Carbon::now('UTC');
                $updated = ApprovalRequest::query()
                    ->whereKey($approval->id)
                    ->where('entity_id', $entityId)
                    ->where('status', ApprovalStatus::Pending->value)
                    ->where('version', $expectedVersion)
                    ->update([
                        'status' => ApprovalStatus::Approved->value,
                        'approver_id' => $approver->id,
                        'approved_at' => $approvedAt,
                        'command_result_status' => $result->status,
                        'command_result_body' => $result->body,
                        'version' => $expectedVersion + 1,
                        'updated_at' => $approvedAt,
                    ]);
                if ($updated !== 1) {
                    throw new ApprovalConcurrencyException;
                }
                $approval->refresh();
                $response = ['approval' => $this->safeApproval($approval), 'command_result' => ['status' => $result->status, 'body' => $result->body]];
                $this->audit->record(
                    'identity', 'approval_granted', 'approval_request', $approval->id,
                    $approver->id, $entityId,
                    before: ['status' => 'pending', 'version' => $expectedVersion],
                    after: ['status' => 'approved', 'version' => $approval->version, 'approver_id' => $approver->id],
                    metadata: ['command_type' => $approval->command_type, 'command_schema_version' => $approval->command_schema_version],
                    correlationId: $correlationId,
                );
                $this->outbox->record(
                    'ApprovalGranted', 'ApprovalRequest', $approval->id,
                    $this->grantedEventPayload($approval), $entityId,
                    metadata: ['correlation_id' => $correlationId, 'causation_id' => $approval->approval_requested_event_id],
                );
                $this->storeIdempotency($approver->id, $entityId, $operation, $idempotencyKey, $requestHash, 200, $response);

                return IdentityActionResult::ok($response);
            });
        } catch (ApprovalConcurrencyException) {
            $replay = $this->idempotencyReplay($approver->id, $entityId, $operation, $idempotencyKey, $requestHash);
            if ($replay !== null) {
                return $replay;
            }
            $current = ApprovalRequest::query()->where('entity_id', $entityId)->find($approval->id);

            return IdentityActionResult::error('concurrency_conflict', 'The approval version is stale.', 409, additional: ['required_version' => $current?->version]);
        } catch (Throwable) {
            return $this->failedExecution($approver, $approval, $operation, $idempotencyKey, $requestHash, $correlationId, 'originating_command_invalid');
        }
    }

    private function failedExecution(User $actor, ApprovalRequest $approval, string $operation, string $idempotencyKey, string $requestHash, string $correlationId, string $failureCode): IdentityActionResult
    {
        $result = IdentityActionResult::error($failureCode, 'The originating command could not be executed.', 422);
        DB::transaction(function () use ($actor, $approval, $operation, $idempotencyKey, $requestHash, $correlationId, $failureCode, $result): void {
            $this->audit->record(
                'identity', 'approval_execution_failed', 'approval_request', $approval->id,
                $actor->id, $approval->entity_id,
                metadata: ['failure_code' => $failureCode, 'command_type' => $approval->command_type],
                correlationId: $correlationId,
            );
            $this->storeIdempotency($actor->id, $approval->entity_id, $operation, $idempotencyKey, $requestHash, $result->status, $result->payload);
        });

        return $result;
    }

    private function canApprove(User $actor, string $entityId, string $commandCapability): bool
    {
        $roles = $this->authorization->roleSlugs($actor, $entityId);
        if ($roles->intersect(['owner', 'admin'])->isNotEmpty()) {
            return true;
        }
        $permissions = $this->authorization->permissions($actor, $entityId);

        return in_array('identity.approvals.approve', $permissions, true)
            && in_array($commandCapability, $permissions, true);
    }

    private function idempotencyReplay(string $actorId, string $entityId, string $operation, string $key, string $requestHash): ?IdentityActionResult
    {
        $record = IdempotencyRecord::query()->where([
            'actor_id' => $actorId, 'entity_id' => $entityId,
            'operation' => $operation, 'idempotency_key' => $key,
        ])->first();
        if (! $record instanceof IdempotencyRecord) {
            return null;
        }
        if (! hash_equals($record->request_hash, $requestHash)) {
            return IdentityActionResult::error('idempotency_conflict', 'The idempotency key was used with a different request.', 409);
        }

        return IdentityActionResult::replay($record->response_status, $record->response_body, ['Idempotent-Replay' => 'true']);
    }

    /** @param array<string, mixed> $response */
    private function storeIdempotency(string $actorId, string $entityId, string $operation, string $key, string $requestHash, int $status, array $response, ?string $recordId = null): void
    {
        $record = new IdempotencyRecord([
            'actor_id' => $actorId, 'entity_id' => $entityId, 'operation' => $operation,
            'idempotency_key' => $key, 'request_hash' => $requestHash,
            'response_status' => $status, 'response_body' => $response,
        ]);
        if ($recordId !== null) {
            $record->id = $recordId;
        }
        $record->save();
    }

    /** @return array<string, mixed> */
    private function safeApproval(ApprovalRequest $approval): array
    {
        return array_filter([
            'id' => $approval->id,
            'status' => $approval->status,
            'command' => $approval->command_type,
            'resource_id' => $approval->resource_id,
            'maker_id' => $approval->maker_id,
            'approver_id' => $approval->approver_id,
            'entity_id' => $approval->entity_id,
            'version' => $approval->version,
            'submitted_at' => $approval->submitted_at->toISOString(),
            'approved_at' => $approval->approved_at?->toISOString(),
        ], fn (mixed $value): bool => $value !== null);
    }

    /** @return array<string, mixed> */
    private function requestedEventPayload(ApprovalRequest $approval): array
    {
        return [
            'approval_id' => $approval->id, 'entity_id' => $approval->entity_id,
            'maker_id' => $approval->maker_id, 'command_type' => $approval->command_type,
            'command_schema_version' => $approval->command_schema_version,
            'resource_id' => $approval->resource_id, 'approval_version' => $approval->version,
            'requested_at' => $approval->submitted_at->toISOString(),
        ];
    }

    /** @return array<string, mixed> */
    private function grantedEventPayload(ApprovalRequest $approval): array
    {
        return [
            'approval_id' => $approval->id, 'entity_id' => $approval->entity_id,
            'maker_id' => $approval->maker_id, 'approver_id' => $approval->approver_id,
            'command_type' => $approval->command_type,
            'command_schema_version' => $approval->command_schema_version,
            'resource_id' => $approval->resource_id, 'approval_version' => $approval->version,
            'approved_at' => $approval->approved_at?->toISOString(),
        ];
    }

    /** @param array<string, mixed> $value */
    private function hash(array $value): string
    {
        $sort = function (mixed $item) use (&$sort): mixed {
            if (! is_array($item)) {
                return $item;
            }
            if (array_is_list($item)) {
                return array_map($sort, $item);
            }
            ksort($item, SORT_STRING);
            foreach ($item as $key => $nested) {
                $item[$key] = $sort($nested);
            }

            return $item;
        };

        return hash('sha256', json_encode($sort($value), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}

final class ApprovalConcurrencyException extends \RuntimeException {}
