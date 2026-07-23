<?php

namespace App\Ledger\Infrastructure;

use App\Ledger\Application\AccountReferenceQuery;
use App\Models\Ledger\LedgerAccount;

final class EloquentAccountReferenceQuery implements AccountReferenceQuery
{
    public function isOwnedByEntity(string $entityId, string $accountId): bool
    {
        return LedgerAccount::query()->where('entity_id', $entityId)->whereKey($accountId)->exists();
    }

    public function isActiveExpense(string $entityId, string $accountId): bool
    {
        return LedgerAccount::query()->where('entity_id', $entityId)->whereKey($accountId)->where('type', 'expense')->where('status', 'active')->exists();
    }

    public function isActiveBank(string $entityId, string $accountId): bool
    {
        return LedgerAccount::query()->where('entity_id', $entityId)->whereKey($accountId)->where('type', 'asset')->where('status', 'active')->whereNotNull('bank_attributes')->exists();
    }

    public function isActiveAsset(string $entityId, string $accountId): bool
    {
        return LedgerAccount::query()->where('entity_id', $entityId)->whereKey($accountId)->where('type', 'asset')->where('status', 'active')->exists();
    }
}
