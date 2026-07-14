<?php

namespace App\Support\Audit;

use App\Models\AuditLog;
use Illuminate\Support\Carbon;

final class AuditLogger
{
    /**
     * @param array<string, mixed>|null $before
     * @param array<string, mixed>|null $after
     * @param array<string, mixed> $metadata
     */
    public function record(
        string $module,
        string $action,
        string $recordType,
        string $recordId,
        ?string $actorId = null,
        ?string $entityId = null,
        ?array $before = null,
        ?array $after = null,
        array $metadata = [],
        ?string $correlationId = null,
    ): AuditLog {
        return AuditLog::query()->create([
            'occurred_at' => Carbon::now('UTC'),
            'actor_id' => $actorId,
            'entity_id' => $entityId,
            'module' => $module,
            'action' => $action,
            'record_type' => $recordType,
            'record_id' => $recordId,
            'before' => $before,
            'after' => $after,
            'metadata' => $metadata,
            'correlation_id' => $correlationId,
        ]);
    }
}
