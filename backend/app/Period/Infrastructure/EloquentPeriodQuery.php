<?php

namespace App\Period\Infrastructure;

use App\Models\Period\AccountingPeriod;
use App\Period\Application\PeriodQuery;
use Illuminate\Support\Carbon;

final class EloquentPeriodQuery implements PeriodQuery
{
    public function findForDate(string $entityId, string $date): ?AccountingPeriod
    {
        $day = Carbon::parse($date)->toDateString();

        return AccountingPeriod::query()->where('entity_id', $entityId)
            ->whereDate('starts_on', '<=', $day)->whereDate('ends_on', '>=', $day)->first();
    }

    public function postablePeriodForDate(string $entityId, string $date, string $entryType = 'manual'): ?AccountingPeriod
    {
        $period = $this->findForDate($entityId, $date);
        if ($period?->state === 'Open') {
            return $period;
        }
        if ($period?->state === 'SoftClosed' && in_array($entryType, ['adjusting', 'revaluation'], true)) {
            return $period;
        }

        return null;
    }

    public function show(string $entityId, string $periodRef): ?AccountingPeriod
    {
        return AccountingPeriod::query()->where('entity_id', $entityId)->where('period_ref', $periodRef)->first();
    }
}
