<?php

namespace App\Support\Documents;

use App\Identity\Application\RoleAuthorizationService;
use App\Models\IdempotencyRecord;
use App\Models\User;
use Illuminate\Support\Str;

final readonly class DocumentCommandSupport
{
    public function __construct(private RoleAuthorizationService $roles) {}

    public function authorize(User $actor, string $entityId, string $capability): ?DocumentActionResult
    {
        if (! $this->roles->canAccessEntity($actor, $entityId)) {
            return $this->error('authorization', 'The requested capability is not permitted for the active entity.', 403, ['permission' => $capability]);
        }
        $slugs = $this->roles->roleSlugs($actor, $entityId);
        if ($slugs->intersect(['owner', 'admin'])->isEmpty() && ! in_array($capability, $this->roles->permissions($actor, $entityId), true)) {
            return $this->error('authorization', 'The requested capability is not permitted for the active entity.', 403, ['permission' => $capability]);
        }

        return null;
    }

    public function requireIdempotency(?string $key): ?DocumentActionResult
    {
        return is_string($key) && Str::isUuid($key) ? null : $this->error('validation', 'Idempotency-Key must be a UUID.', 400);
    }

    public function expectedVersion(?string $ifMatch): int|DocumentActionResult
    {
        if ($ifMatch === null) {
            return $this->error('precondition_required', 'If-Match is required.', 428);
        }
        if (preg_match('/^"?([1-9][0-9]*)"?$/', $ifMatch, $matches) !== 1) {
            return $this->error('validation', 'If-Match must be a positive integer version.', 400);
        }

        return (int) $matches[1];
    }

    /** @param array<mixed> $data */
    public function hash(array $data): string
    {
        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
    }

    public function replay(string $actorId, string $entityId, string $operation, string $key, string $hash): ?DocumentActionResult
    {
        $record = IdempotencyRecord::query()->where('actor_id', $actorId)->where('entity_id', $entityId)
            ->where('operation', $operation)->where('idempotency_key', $key)->first();
        if ($record === null) {
            return null;
        }
        if (! hash_equals($record->request_hash, $hash)) {
            return $this->error('idempotency_conflict', 'The idempotency key was used for another request.', 409);
        }

        return new DocumentActionResult($record->response_body, $record->response_status, ['Idempotent-Replay' => 'true']);
    }

    /** @param array<string, mixed> $body */
    public function store(string $actorId, string $entityId, string $operation, string $key, string $hash, int $status, array $body): void
    {
        IdempotencyRecord::query()->create(['actor_id' => $actorId, 'entity_id' => $entityId, 'operation' => $operation, 'idempotency_key' => $key, 'request_hash' => $hash, 'response_status' => $status, 'response_body' => $body]);
    }

    /** @param array<string, mixed> $details */
    public function error(string $code, string $message, int $status, array $details = []): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => $code, 'message' => $message, 'details' => $details], $status);
    }
}
