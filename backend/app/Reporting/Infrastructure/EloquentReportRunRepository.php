<?php

namespace App\Reporting\Infrastructure;

use App\Models\Reporting\ReportRun;
use App\Reporting\Application\ReportRunRepository;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

final class EloquentReportRunRepository implements ReportRunRepository
{
    public function getById(string $entityId, string $id): ?ReportRun
    {
        return ReportRun::query()->where('entity_id', $entityId)->find($id);
    }

    /** @param array<string, mixed> $attributes */
    public function addGenerated(array $attributes): ReportRun
    {
        return ReportRun::query()->create([...$attributes, 'state' => 'Generated', 'version' => 1]);
    }

    /** @param array<string, mixed> $attributes */
    public function commitApproval(string $entityId, string $id, array $attributes, int $expectedVersion): ?ReportRun
    {
        $affected = ReportRun::query()->where('entity_id', $entityId)->where('id', $id)->where('version', $expectedVersion)
            ->update([...$attributes, 'version' => $expectedVersion + 1]);

        return $affected === 1 ? $this->getById($entityId, $id) : null;
    }

    public function commitRejection(string $entityId, string $id, int $expectedVersion): ?ReportRun
    {
        $affected = ReportRun::query()->where('entity_id', $entityId)->where('id', $id)->where('version', $expectedVersion)
            ->update(['state' => 'Rejected', 'version' => $expectedVersion + 1]);

        return $affected === 1 ? $this->getById($entityId, $id) : null;
    }

    public function commitSupersession(string $entityId, string $id, string $supersededByReportRunId, int $expectedVersion): ?ReportRun
    {
        $affected = ReportRun::query()->where('entity_id', $entityId)->where('id', $id)->where('version', $expectedVersion)
            ->update(['state' => 'Superseded', 'superseded_by_report_run_id' => $supersededByReportRunId, 'version' => $expectedVersion + 1]);

        return $affected === 1 ? $this->getById($entityId, $id) : null;
    }

    /** @param array<string, mixed> $filters */
    public function findCurrentApproved(string $entityId, string $reportType, string $basis, ?string $periodRef, ?string $asOf, array $filters): ?ReportRun
    {
        $candidates = ReportRun::query()->where('entity_id', $entityId)->where('report_type', $reportType)->where('basis', $basis)
            ->where('state', 'Approved')
            ->when($periodRef !== null, fn ($q) => $q->where('period_ref', $periodRef))
            ->when($periodRef === null, fn ($q) => $q->whereNull('period_ref'))
            ->when($asOf !== null, fn ($q) => $q->whereDate('as_of', $asOf))
            ->when($asOf === null, fn ($q) => $q->whereNull('as_of'))
            ->get();

        return $candidates->first(fn (ReportRun $run): bool => $run->filters === $filters);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, ReportRun>
     */
    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator
    {
        $query = ReportRun::query()->where('entity_id', $entityId)
            ->when($filters['report_type'] ?? null, fn ($q, $v) => $q->where('report_type', $v))
            ->when($filters['period'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub->where('period_ref', $v)->orWhereDate('as_of', $v)))
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->where('state', $v));

        return $query->orderByDesc('generated_at')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }
}
