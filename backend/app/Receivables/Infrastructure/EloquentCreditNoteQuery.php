<?php

namespace App\Receivables\Infrastructure;

use App\Models\Receivables\CreditNote;
use App\Receivables\Application\CreditNoteQuery;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

final class EloquentCreditNoteQuery implements CreditNoteQuery
{
    public function getDetail(string $entityId, string $id): ?CreditNote
    {
        return CreditNote::query()->with(['lines', 'dispositions.applications', 'reversal'])->where('entity_id', $entityId)->find($id);
    }

    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator
    {
        $query = CreditNote::query()->where('entity_id', $entityId)
            ->when($filters['party'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))
            ->when($filters['source_document'] ?? null, fn ($q, $v) => $q->where('source_invoice_id', $v))
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->where('state', $v))
            ->when($filters['reason_code'] ?? null, fn ($q, $v) => $q->where('reason_code', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('note_date', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('note_date', '<=', $v));

        return $query->orderByDesc('note_date')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }
}
