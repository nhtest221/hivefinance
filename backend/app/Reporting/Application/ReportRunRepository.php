<?php

namespace App\Reporting\Application;

use App\Models\Reporting\ReportRun;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * API Contracts §13.4; Repository Contracts §3.2; Aggregate Design §16. Reporting's one
 * write aggregate. Implements no business rule beyond version-guarded conditional saves.
 */
interface ReportRunRepository
{
    public function getById(string $entityId, string $id): ?ReportRun;

    /** @param array<string, mixed> $attributes */
    public function addGenerated(array $attributes): ReportRun;

    /** @param array<string, mixed> $attributes */
    public function commitApproval(string $entityId, string $id, array $attributes, int $expectedVersion): ?ReportRun;

    public function commitRejection(string $entityId, string $id, int $expectedVersion): ?ReportRun;

    public function commitSupersession(string $entityId, string $id, string $supersededByReportRunId, int $expectedVersion): ?ReportRun;

    /**
     * The current, gate-eligible run for an exact reproducibility key
     * (entity_id, report_type, basis, period_ref-or-as_of, filters) — API Contracts §13.4, §13.12.
     *
     * @param  array<string, mixed>  $filters
     */
    public function findCurrentApproved(string $entityId, string $reportType, string $basis, ?string $periodRef, ?string $asOf, array $filters): ?ReportRun;

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, ReportRun>
     */
    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator;
}
