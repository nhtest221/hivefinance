<?php

namespace App\Ledger\Application;

interface AccountReferenceQuery
{
    public function isOwnedByEntity(string $entityId, string $accountId): bool;
}
