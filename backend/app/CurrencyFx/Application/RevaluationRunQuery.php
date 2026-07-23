<?php

namespace App\CurrencyFx\Application;

use App\Models\CurrencyFx\RevaluationRun;
use Illuminate\Support\Collection;

/**
 * CurrencyFx-owned contract for Reporting's FX Revaluation view (API Contracts §13.9).
 * CurrencyFx continues to own fx_revaluation_runs reads; Reporting never queries the
 * table directly (AP-001).
 */
interface RevaluationRunQuery
{
    /**
     * Posted RevaluationRun facts for one entity and period — the exact source set the
     * FX Revaluation report sums, never re-derived or estimated.
     *
     * @return Collection<int, RevaluationRun>
     */
    public function postedForPeriod(string $entityId, string $periodRef): Collection;
}
