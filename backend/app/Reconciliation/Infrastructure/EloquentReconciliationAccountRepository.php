<?php

namespace App\Reconciliation\Infrastructure;

use App\Models\Reconciliation\ReconciliationAccount;
use App\Reconciliation\Application\ReconciliationAccountRepository;
use Illuminate\Pagination\Cursor;
use Illuminate\Pagination\CursorPaginator;

final class EloquentReconciliationAccountRepository implements ReconciliationAccountRepository
{
    public function getById(string $entityId, string $id): ?ReconciliationAccount
    {
        return ReconciliationAccount::query()->where('entity_id', $entityId)->find($id);
    }

    public function findByLedgerAccount(string $entityId, string $ledgerAccountId): ?ReconciliationAccount
    {
        return ReconciliationAccount::query()->where('entity_id', $entityId)->where('ledger_account_id', $ledgerAccountId)->first();
    }

    /** @param array<string, mixed> $attributes */
    public function create(array $attributes): ReconciliationAccount
    {
        return ReconciliationAccount::query()->create([...$attributes, 'version' => 1]);
    }

    /** @param array<string, mixed> $attributes */
    public function update(string $entityId, string $id, array $attributes, int $expectedVersion): ?ReconciliationAccount
    {
        $affected = ReconciliationAccount::query()->where('entity_id', $entityId)->where('id', $id)->where('version', $expectedVersion)
            ->update([...$attributes, 'version' => $expectedVersion + 1]);

        return $affected === 1 ? $this->getById($entityId, $id) : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return CursorPaginator<int, ReconciliationAccount>
     */
    public function search(string $entityId, array $filters, ?Cursor $cursor, int $limit): CursorPaginator
    {
        $query = ReconciliationAccount::query()->where('entity_id', $entityId)
            ->when(array_key_exists('reconciliation_enabled', $filters), fn ($q) => $q->where('reconciliation_enabled', (bool) $filters['reconciliation_enabled']));

        return $query->orderByDesc('created_at')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);
    }

    /** @return list<ReconciliationAccount> */
    public function mandatoryForEntity(string $entityId): array
    {
        return ReconciliationAccount::query()->where('entity_id', $entityId)->where('reconciliation_enabled', true)->get()->all();
    }
}
