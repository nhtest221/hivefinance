<?php

namespace App\Ledger\Application;

interface AccountReferenceQuery
{
    public function isOwnedByEntity(string $entityId, string $accountId): bool;

    public function isActiveExpense(string $entityId, string $accountId): bool;

    public function isActiveBank(string $entityId, string $accountId): bool;

    public function isActiveAsset(string $entityId, string $accountId): bool;
}
