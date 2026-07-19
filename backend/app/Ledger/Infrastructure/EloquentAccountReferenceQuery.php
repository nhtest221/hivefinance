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
}
