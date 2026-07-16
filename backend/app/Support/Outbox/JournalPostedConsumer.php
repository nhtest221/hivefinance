<?php

namespace App\Support\Outbox;

use App\Ledger\Domain\DecimalAmount;
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
            $this->projectBalances($event);
            $metadata = $event->metadata ?? [];

            Log::info('domain_event_consumed', [
                'event_id' => $event->id,
                'event_type' => $event->event_type,
                'correlation_id' => $metadata['correlation_id'] ?? null,
                'consumer' => self::NAME,
            ]);
        }
    }

    private function projectBalances(OutboxMessage $event): void
    {
        $payload = $event->payload;
        $entityId = $payload['entityId'] ?? null;
        $asOf = $payload['entryDate'] ?? null;
        $lines = $payload['lines'] ?? null;
        if (! is_string($entityId) || ! is_string($asOf) || ! is_array($lines)) {
            return;
        }
        foreach ($lines as $line) {
            if (! is_array($line) || ! is_string($line['accountId'] ?? null)) {
                continue;
            }
            $debit = DecimalAmount::fromString($line['debit']['amount'] ?? '0');
            $credit = DecimalAmount::fromString($line['credit']['amount'] ?? '0');
            $delta = $debit->subtract($credit);
            $existing = DB::table('ledger_account_balance_projections')->where('entity_id', $entityId)->where('account_id', $line['accountId'])->where('as_of', $asOf)->first();
            $base = $existing !== null
                ? $existing->balance
                : (DB::table('ledger_account_balance_projections')->where('entity_id', $entityId)->where('account_id', $line['accountId'])->where('as_of', '<', $asOf)->orderByDesc('as_of')->value('balance') ?? '0');
            $balance = DecimalAmount::fromString((string) $base)->add($delta)->toString();
            DB::table('ledger_account_balance_projections')->updateOrInsert(['entity_id' => $entityId, 'account_id' => $line['accountId'], 'as_of' => $asOf], ['balance' => $balance, 'currency' => $line['debit']['currency'] ?? $line['credit']['currency'] ?? '', 'created_at' => now('UTC'), 'updated_at' => now('UTC')]);
            $future = DB::table('ledger_account_balance_projections')->where('entity_id', $entityId)->where('account_id', $line['accountId'])->where('as_of', '>', $asOf)->get();
            foreach ($future as $row) {
                DB::table('ledger_account_balance_projections')->where('id', $row->id)->update(['balance' => DecimalAmount::fromString((string) $row->balance)->add($delta)->toString(), 'updated_at' => now('UTC')]);
            }
        }
    }
}
