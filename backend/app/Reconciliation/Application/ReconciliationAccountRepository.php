<?php

namespace App\Reconciliation\Application;

use App\Models\Reconciliation\ReconciliationAccount;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

/** API Contracts §14.4; Repository Contracts §2; Aggregate Design §13. */
interface ReconciliationAccountRepository
{
    public function getById(string $entityId, string $id): ?ReconciliationAccount;

    public function findByLedgerAccount(string $entityId, string $ledgerAccountId): ?ReconciliationAccount;

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): ReconciliationAccount;

    /** @param array<string, mixed> $attributes */
    public function update(string $entityId, string $id, array $attributes, int $expectedVersion): ?ReconciliationAccount;

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, ReconciliationAccount>
     */
    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator;

    /** Every account with reconciliation_enabled=true — the Close-Gate mandatory scope (API Contracts §14.11).
     * @return list<ReconciliationAccount> */
    public function mandatoryForEntity(string $entityId): array;
}
