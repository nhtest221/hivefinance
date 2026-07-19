<?php

use App\Identity\Application\ApprovalCommandHandler;
use App\Identity\Application\ApprovalCommandRegistry;
use App\Identity\Application\ApprovalCommandResult;
use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Domain\OriginatingCommand;
use App\Models\AuditLog;
use App\Models\Identity\ApprovalRequest;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\OutboxMessage;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config()->set('app.key', str_repeat('a', 32));
    app()->forgetInstance('encrypter');
});

final class ApprovalProbeHandler implements ApprovalCommandHandler
{
    public ?ApprovalExecutionContext $lastContext = null;

    public function __construct(public bool $fails = false, private readonly int $version = 1) {}

    public function commandType(): string
    {
        return 'tax_code_version_create';
    }

    public function schemaVersion(): int
    {
        return $this->version;
    }

    public function execute(array $payload, ApprovalExecutionContext $context): ApprovalCommandResult
    {
        $this->lastContext = $context;
        app(AuditLogger::class)->record(
            'identity', 'approval_probe_executed', 'approval_request', $context->approvalId,
            $context->approverId, $context->entityId,
            metadata: ['probe' => (string) ($payload['probe'] ?? '')],
            correlationId: $context->correlationId,
        );
        if ($this->fails) {
            throw new RuntimeException('safe test failure');
        }

        return new ApprovalCommandResult(201, ['probe_result' => ['id' => (string) Str::uuid()]]);
    }
}

/**
 * @return array{entity:Entity,maker:User,approver:User,unauthorized:User,handler:ApprovalProbeHandler}
 */
function approvalActors(bool $handlerFails = false): array
{
    $entity = Entity::query()->create(['legal_name' => 'Approval Entity '.Str::uuid(), 'functional_currency' => 'BDT']);
    $maker = approvalUser('maker-'.Str::uuid().'@example.com', $entity);
    $approver = approvalUser('approver-'.Str::uuid().'@example.com', $entity);
    $unauthorized = approvalUser('unauthorized-'.Str::uuid().'@example.com', $entity);
    $role = Role::query()->create([
        'entity_id' => $entity->id, 'name' => 'Approval Checker',
        'slug' => 'approval-checker-'.Str::lower(Str::random(6)), 'is_system' => false,
    ]);
    $role->permissions()->createMany([
        ['permission' => 'identity.approvals.approve'],
        ['permission' => 'tax.codes.manage'],
    ]);
    $approver->roles()->attach($role->id, ['entity_id' => $entity->id]);
    $maker->roles()->attach($role->id, ['entity_id' => $entity->id]);
    $handler = new ApprovalProbeHandler($handlerFails);
    app(ApprovalCommandRegistry::class)->replace($handler);

    return compact('entity', 'maker', 'approver', 'unauthorized', 'handler');
}

function approvalUser(string $email, Entity $entity): User
{
    $user = User::query()->create([
        'name' => 'Approval User', 'email' => $email, 'password' => 'correct-horse-battery',
        'status' => 'active', 'active_entity_id' => $entity->id,
    ]);
    $user->entities()->attach($entity->id, ['status' => 'active']);

    return $user;
}

function pendingApproval(User $maker, Entity $entity, int $schemaVersion = 1, array $payload = ['probe' => 'secret-value']): ApprovalRequest
{
    $result = app(ApprovalLifecycleService::class)->requestApproval(
        $maker,
        $entity->id,
        new OriginatingCommand(
            'tax_code_version_create', $schemaVersion, $payload,
            (string) Str::uuid(), 'tax.codes.manage', 1,
        ),
        'tax.codes.versions.create',
        (string) Str::uuid(),
        (string) Str::uuid(),
    );
    expect($result->status)->toBe(202);

    return ApprovalRequest::query()->findOrFail($result->payload['approval']['id']);
}

function approveHeaders(Entity $entity, int $version = 1, ?string $key = null): array
{
    return [
        'X-Entity-Id' => $entity->id,
        'Idempotency-Key' => $key ?? (string) Str::uuid(),
        'If-Match' => (string) $version,
        'X-Correlation-Id' => (string) Str::uuid(),
    ];
}

it('prevents a maker from approving their own request', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity']);
    Sanctum::actingAs($actors['maker']);

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity']))
        ->assertForbidden()->assertJsonPath('error_code', 'maker_cannot_approve');

    expect($approval->refresh()->status)->toBe('pending');
});

it('denies an approver without the required capabilities', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity']);
    Sanctum::actingAs($actors['unauthorized']);

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity']))
        ->assertForbidden()->assertJsonPath('error_code', 'authorization');
});

it('does not disclose an approval through another entity scope', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity']);
    $other = Entity::query()->create(['legal_name' => 'Other '.Str::uuid(), 'functional_currency' => 'CAD']);
    $actors['approver']->entities()->attach($other->id, ['status' => 'active']);
    $role = Role::query()->create(['entity_id' => $other->id, 'name' => 'Other Checker', 'slug' => 'other-checker-'.Str::random(5)]);
    $role->permissions()->createMany([['permission' => 'identity.approvals.approve'], ['permission' => 'tax.codes.manage']]);
    $actors['approver']->roles()->attach($role->id, ['entity_id' => $other->id]);
    Sanctum::actingAs($actors['approver']);

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($other))
        ->assertNotFound()->assertJsonPath('error_code', 'not_found');
});

it('rejects a stale approval version', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity']);
    Sanctum::actingAs($actors['approver']);

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity'], 9))
        ->assertConflict()->assertJsonPath('error_code', 'concurrency_conflict')
        ->assertJsonPath('required_version', 1);
});

it('approves exactly once and safely replays the approval response', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity']);
    $key = (string) Str::uuid();
    $headers = approveHeaders($actors['entity'], 1, $key);
    $correlationId = $headers['X-Correlation-Id'];
    Sanctum::actingAs($actors['approver']);

    $first = $this->postJson("/v1/approvals/{$approval->id}/approve", [], $headers)->assertOk();
    $second = $this->postJson("/v1/approvals/{$approval->id}/approve", [], $headers)->assertOk()
        ->assertHeader('Idempotent-Replay', 'true');

    $requested = OutboxMessage::query()->where('event_type', 'ApprovalRequested')->firstOrFail();
    $granted = OutboxMessage::query()->where('event_type', 'ApprovalGranted')->firstOrFail();
    $grantedKeys = array_keys($granted->payload);
    sort($grantedKeys);

    expect($second->json())->toEqual($first->json())
        ->and(AuditLog::query()->where('action', 'approval_probe_executed')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'ApprovalGranted')->count())->toBe(1)
        ->and($granted->event_version)->toBe(1)
        ->and($granted->metadata['correlation_id'])->toBe($correlationId)
        ->and($granted->metadata['causation_id'])->toBe($requested->id)
        ->and($actors['handler']->lastContext?->correlationId)->toBe($correlationId)
        ->and($actors['handler']->lastContext?->causationId)->toBe($requested->id)
        ->and($actors['handler']->lastContext?->originatingCorrelationId)->toBe($approval->originating_correlation_id)
        ->and($grantedKeys)->toBe([
            'approval_id', 'approval_version', 'approved_at', 'approver_id', 'command_schema_version',
            'command_type', 'entity_id', 'maker_id', 'resource_id',
        ])
        ->and($approval->refresh()->status)->toBe('approved');

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity'], 2))
        ->assertConflict()->assertJsonPath('error_code', 'approval_already_decided');
});

it('fails safely when the encrypted payload is tampered with', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity']);
    DB::table('identity_approval_requests')->where('id', $approval->id)->update(['encrypted_payload' => 'tampered-ciphertext']);
    $approval->refresh();
    Sanctum::actingAs($actors['approver']);

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity']))
        ->assertUnprocessable()->assertJsonPath('error_code', 'originating_command_invalid');

    expect($approval->refresh()->status)->toBe('pending')
        ->and(AuditLog::query()->where('action', 'approval_execution_failed')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'ApprovalGranted')->count())->toBe(0);
});

it('fails safely for an unsupported command schema version', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity'], 2);
    Sanctum::actingAs($actors['approver']);

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity']))
        ->assertUnprocessable()->assertJsonPath('error_code', 'originating_command_invalid');

    expect($approval->refresh()->status)->toBe('pending');
});

it('rolls back a failing originating command and records an immutable failed attempt', function (): void {
    $actors = approvalActors(true);
    $approval = pendingApproval($actors['maker'], $actors['entity']);
    Sanctum::actingAs($actors['approver']);

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity']))
        ->assertUnprocessable()->assertJsonPath('error_code', 'originating_command_invalid');

    expect($approval->refresh()->status)->toBe('pending')
        ->and(AuditLog::query()->where('action', 'approval_probe_executed')->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'approval_execution_failed')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'approval_granted')->count())->toBe(0)
        ->and(OutboxMessage::query()->where('event_type', 'ApprovalGranted')->count())->toBe(0);

    $failedKey = (string) Str::uuid();
    $failedHeaders = approveHeaders($actors['entity'], 1, $failedKey);
    $this->postJson("/v1/approvals/{$approval->id}/approve", [], $failedHeaders)->assertUnprocessable();
    $this->postJson("/v1/approvals/{$approval->id}/approve", [], $failedHeaders)
        ->assertUnprocessable()->assertHeader('Idempotent-Replay', 'true');
    expect(AuditLog::query()->where('action', 'approval_execution_failed')->count())->toBe(2);

    $actors['handler']->fails = false;
    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity']))->assertOk();
    expect(AuditLog::query()->where('action', 'approval_probe_executed')->count())->toBe(1);
});

it('fails safely when the protected payload hash is tampered with', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity']);
    DB::table('identity_approval_requests')->where('id', $approval->id)->update(['payload_hash' => str_repeat('0', 64)]);
    $approval->refresh();
    Sanctum::actingAs($actors['approver']);

    $this->postJson("/v1/approvals/{$approval->id}/approve", [], approveHeaders($actors['entity']))
        ->assertUnprocessable()->assertJsonPath('error_code', 'originating_command_invalid');

    expect($approval->refresh()->status)->toBe('pending')
        ->and(AuditLog::query()->where('action', 'approval_execution_failed')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'ApprovalGranted')->count())->toBe(0);
});

it('prevents application code from mutating captured command data', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity']);

    expect(fn () => $approval->forceFill(['encrypted_payload' => 'replacement'])->save())
        ->toThrow(LogicException::class, 'Originating approval command data is immutable.');

    expect($approval->refresh()->status)->toBe('pending');
});

it('encrypts command payloads and keeps secrets out of API audit and event data', function (): void {
    $actors = approvalActors();
    $approval = pendingApproval($actors['maker'], $actors['entity'], payload: ['probe' => 'top-secret-value']);
    $serializedEvidence = json_encode([
        'approval' => $approval->toArray(),
        'audit' => AuditLog::query()->where('record_id', $approval->id)->get()->toArray(),
        'outbox' => OutboxMessage::query()->where('aggregate_id', $approval->id)->get()->toArray(),
    ], JSON_THROW_ON_ERROR);

    expect($approval->encrypted_payload)->not->toContain('top-secret-value')
        ->and($serializedEvidence)->not->toContain('top-secret-value')
        ->and($serializedEvidence)->not->toContain($approval->payload_hash)
        ->and(OutboxMessage::query()->where('event_type', 'ApprovalRequested')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'approval_requested')->count())->toBe(1);
});
