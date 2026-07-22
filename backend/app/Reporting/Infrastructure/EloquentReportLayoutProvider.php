<?php

namespace App\Reporting\Infrastructure;

use App\Models\Reporting\ReportLayoutVersion;
use App\Reporting\Application\ReportLayoutProvider;
use App\Reporting\Domain\ReportLayout;

final class EloquentReportLayoutProvider implements ReportLayoutProvider
{
    public function getEffective(string $entityId, string $reportType, string $atDate): ?ReportLayout
    {
        $version = ReportLayoutVersion::query()->where('entity_id', $entityId)->where('report_type', $reportType)
            ->whereDate('effective_from', '<=', $atDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $atDate))
            ->orderByDesc('version_number')->first();

        return $version === null ? null : new ReportLayout($version->version_number, $version->sections);
    }
}
