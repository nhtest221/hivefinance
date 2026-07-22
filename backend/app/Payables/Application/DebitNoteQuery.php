<?php

namespace App\Payables\Application;

use App\Models\Payables\DebitNote;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/**
 * Repository Contracts §"BillRepository / DebitNoteRepository / ExpenseRepository" —
 * mirror of CreditNoteQuery with vendor/bill direction.
 */
interface DebitNoteQuery
{
    public function getDetail(string $entityId, string $id): ?DebitNote;

    /**
     * @param  array<string,mixed>  $filters
     * @return CursorPaginator<int,DebitNote>
     */
    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator;
}
