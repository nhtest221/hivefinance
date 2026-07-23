<?php

namespace App\CurrencyFx\Infrastructure;

use App\CurrencyFx\Application\RevaluationRunQuery;
use App\Models\CurrencyFx\RevaluationRun;
use Illuminate\Support\Collection;

final class EloquentRevaluationRunQuery implements RevaluationRunQuery
{
    /** @return Collection<int, RevaluationRun> */
    public function postedForPeriod(string $entityId, string $periodRef): Collection
    {
        return RevaluationRun::query()
            ->where('entity_id', $entityId)
            ->where('period_ref', $periodRef)
            ->where('status', 'posted')
            ->get();
    }
}
