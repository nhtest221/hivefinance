<?php

namespace App\Ledger\Application;

use App\Models\Period\AccountingPeriod;
use Illuminate\Support\Carbon;

final class PeriodService
{
    public function findForDate(string $entityId, string $date): ?AccountingPeriod
    {
        $postingDate = Carbon::parse($date)->toDateString();

        return AccountingPeriod::query()
            ->where('entity_id', $entityId)
            ->whereDate('starts_on', '<=', $postingDate)
            ->whereDate('ends_on', '>=', $postingDate)
            ->first();
    }

    public function postablePeriodForDate(string $entityId, string $date, string $entryType = 'manual'): ?AccountingPeriod
    {
        $period = $this->findForDate($entityId, $date);

        if ($period === null) {
            return null;
        }

        if ($period->state === 'open') {
            return $period;
        }

        if ($period->state === 'soft_closed' && in_array($entryType, ['adjusting', 'revaluation'], true)) {
            return $period;
        }

        return null;
    }

    public function show(string $entityId, string $periodRef): ?AccountingPeriod
    {
        return AccountingPeriod::query()
            ->where('entity_id', $entityId)
            ->where('period_ref', $periodRef)
            ->first();
    }
}
