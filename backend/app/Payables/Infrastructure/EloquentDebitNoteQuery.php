<?php

namespace App\Payables\Infrastructure;

use App\Models\Payables\DebitNote;
use App\Payables\Application\DebitNoteQuery;
use Illuminate\Pagination\CursorPaginator;

final class EloquentDebitNoteQuery implements DebitNoteQuery
{
    public function getDetail(string $entityId, string $id): ?DebitNote
    {
        return DebitNote::query()->with(['lines', 'dispositions.applications', 'reversal'])->where('entity_id', $entityId)->find($id);
    }

    public function search(string $entityId, array $filters, ?string $cursor, int $limit): CursorPaginator
    {
        $query = DebitNote::query()->where('entity_id', $entityId)
            ->when($filters['party'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))
            ->when($filters['source_document'] ?? null, fn ($q, $v) => $q->where('source_bill_id', $v))
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->where('state', $v))
            ->when($filters['reason_code'] ?? null, fn ($q, $v) => $q->where('reason_code', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('note_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('note_date', '<=', $v));

        return $query->orderByDesc('note_date')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }
}
