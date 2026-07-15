<?php

namespace App\Support\Outbox;

use App\Models\OutboxMessage;
use Illuminate\Support\Facades\DB;
use Throwable;

final readonly class OutboxDispatcher
{
    public function __construct(private InProcessEventBus $bus) {}

    public function dispatchAvailable(int $limit = 100): int
    {
        $ids = OutboxMessage::query()->whereNull('processed_at')->where('available_at', '<=', now('UTC'))
            ->orderBy('occurred_at')->limit($limit)->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, &$count): void {
                $event = OutboxMessage::query()->whereKey($id)->lockForUpdate()->first();
                if ($event === null || $event->processed_at !== null) {
                    return;
                }
                try {
                    $this->bus->dispatch($event);
                    $event->processed_at = now('UTC');
                    $event->attempts++;
                    $event->last_error = null;
                    $event->save();
                    $count++;
                } catch (Throwable $error) {
                    $event->attempts++;
                    $event->last_error = mb_substr($error->getMessage(), 0, 2000);
                    $event->available_at = now('UTC')->addSeconds(min(300, 2 ** min($event->attempts, 8)));
                    $event->save();
                    throw $error;
                }
            });
        }

        return $count;
    }
}
