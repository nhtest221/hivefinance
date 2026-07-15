<?php

namespace App\Console\Commands;

use App\Support\Outbox\OutboxDispatcher;
use Illuminate\Console\Command;

final class DispatchOutbox extends Command
{
    protected $signature = 'outbox:dispatch {--limit=100}';
    protected $description = 'Dispatch committed transactional outbox events.';

    public function handle(OutboxDispatcher $dispatcher): int
    {
        $this->info((string) $dispatcher->dispatchAvailable((int) $this->option('limit')));

        return self::SUCCESS;
    }
}
