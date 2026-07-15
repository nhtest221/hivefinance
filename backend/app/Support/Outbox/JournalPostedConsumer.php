<?php

namespace App\Support\Outbox;

use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class JournalPostedConsumer
{
    private const string NAME = 'm0.journal-posted-observer';

    public function consume(OutboxMessage $event): void
    {
        $inserted = DB::table('outbox_consumptions')->insertOrIgnore([
            'event_id' => $event->id,
            'consumer' => self::NAME,
            'consumed_at' => now('UTC'),
            'effect' => json_encode(['observed' => true], JSON_THROW_ON_ERROR),
        ]);
        if ($inserted === 1) {
            $metadata = $event->metadata ?? [];

            Log::info('domain_event_consumed', [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'correlation_id' => $metadata['correlation_id'] ?? null,
                'consumer' => self::NAME,
            ]);
        }
    }
}
