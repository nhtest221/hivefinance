<?php

namespace App\Ledger\Application;

interface ForeignCurrencyPositionQuery
{
    /** @return list<array{account_id:string,foreign_currency:string,foreign_amount:string,functional_amount:string}> */
    public function bankPositions(string $entityId, string $asOf, string $functionalCurrency): array;
}
