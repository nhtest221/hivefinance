<?php

namespace App\Reporting\Application;

use App\Models\Ledger\JournalEntry;
use App\Models\Settlement\Allocation;
use Illuminate\Support\Carbon;

/**
 * API Contracts §13.4/§13.12: the maximum posted_at of any qualifying posted fact
 * included in a report's computed content, captured at generation time and recomputed
 * at Close-Gate evaluation time to detect stale evidence. Single source of truth shared
 * by ReportRunService (freeze) and ReportingCloseGateProvider (staleness check).
 */
final readonly class SourceDataWatermarkCalculator
{
    public function forBasis(string $entityId, string $basis, ?string $to): Carbon
    {
        if ($basis === 'cash') {
            $max = Allocation::query()->where('entity_id', $entityId)->where('state', 'posted')
                ->when($to !== null, fn ($q) => $q->whereDate('settlement_date', '<=', $to))
                ->max('posted_at');
        } else {
            $max = JournalEntry::query()->where('entity_id', $entityId)->where('state', 'posted')
                ->when($to !== null, fn ($q) => $q->whereDate('entry_date', '<=', $to))
                ->max('posted_at');
        }

        return is_string($max) ? Carbon::parse($max, 'UTC') : Carbon::now('UTC');
    }
}
