<?php

namespace App\Support\Outbox;

use App\Models\OutboxMessage;

final readonly class InProcessEventBus
{
    public function __construct(private JournalPostedConsumer $journalPostedConsumer) {}

    public function dispatch(OutboxMessage $event): void
    {
        if ($event->event_type === 'JournalPosted') {
            $this->journalPostedConsumer->consume($event);
        }
    }
}
