<?php

namespace App\Period\Application;

use App\Models\Period\AccountingPeriod;

interface PeriodQuery
{
    public function findForDate(string $entityId, string $date): ?AccountingPeriod;

    public function postablePeriodForDate(string $entityId, string $date, string $entryType = 'manual'): ?AccountingPeriod;

    public function show(string $entityId, string $periodRef): ?AccountingPeriod;
}
