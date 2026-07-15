<?php

namespace App\Support\Outbox;

use App\Models\OutboxMessage;
use Illuminate\Support\Carbon;

final class Outbox
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $eventType,
        string $aggregateType,
        string $aggregateId,
        array $payload,
        ?string $entityId = null,
        int $eventVersion = 1,
        array $metadata = [],
    ): OutboxMessage {
        $now = Carbon::now('UTC');

        return OutboxMessage::query()->create([
            'event_type' => $eventType,
            'event_version' => $eventVersion,
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'entity_id' => $entityId,
            'payload' => $payload,
            'metadata' => $metadata,
            'occurred_at' => $now,
            'available_at' => $now,
            'attempts' => 0,
        ]);
    }
}
