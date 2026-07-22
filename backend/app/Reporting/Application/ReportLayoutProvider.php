<?php

namespace App\Reporting\Application;

use App\Reporting\Domain\ReportLayout;

interface ReportLayoutProvider
{
    public function getEffective(string $entityId, string $reportType, string $atDate): ?ReportLayout;
}
