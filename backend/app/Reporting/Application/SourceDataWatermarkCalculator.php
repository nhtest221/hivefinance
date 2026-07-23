<?php

namespace App\Reporting\Application;

use App\Ledger\Application\AccountMovementQuery;
use App\Settlement\Application\AllocationQuery;
use Illuminate\Support\Carbon;

/**
 * API Contracts §13.4/§13.12: the maximum posted_at of any qualifying posted fact
 * included in a report's computed content, captured at generation time and recomputed
 * at Close-Gate evaluation time to detect stale evidence. Single source of truth shared
 * by ReportRunService (freeze) and ReportingCloseGateProvider (staleness check).
 *
 * Ledger and Settlement continue to own their respective posted-fact reads; Reporting
 * never queries journal_entries or settlement_allocations directly (AP-001).
 */
final readonly class SourceDataWatermarkCalculator
{
    public function __construct(
        private AccountMovementQuery $movements,
        private AllocationQuery $allocations,
    ) {}

    public function forBasis(string $entityId, string $basis, ?string $to): Carbon
    {
        $max = $basis === 'cash'
            ? $this->allocations->latestPostedAt($entityId, $to)
            : $this->movements->latestPostedAt($entityId, $to);

        return $max !== null ? Carbon::parse($max, 'UTC') : Carbon::now('UTC');
    }
}
