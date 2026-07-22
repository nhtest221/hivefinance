<?php

namespace App\Receivables\Application;

use App\Models\Receivables\CreditNote;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * Repository Contracts §"InvoiceRepository / CreditNoteRepository" — CreditNoteQuery.
 * Read-side, persistence-agnostic from the caller's perspective; never mutates state.
 */
interface CreditNoteQuery
{
    public function getDetail(string $entityId, string $id): ?CreditNote;

    /**
     * @param  array<string,mixed>  $filters
     * @return CursorPaginator<int,CreditNote>
     */
    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator;
}
