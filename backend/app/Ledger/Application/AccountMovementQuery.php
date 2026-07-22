<?php

namespace App\Ledger\Application;

/**
 * Internal Ledger-owned contract for Reporting's classified statements (P&L, Balance
 * Sheet). Ledger continues to own JournalLine reads; Reporting never queries
 * journal_lines directly (AP-001; API Contracts §13.14).
 */
interface AccountMovementQuery
{
    /**
     * Net posted debit-minus-credit movement per account within an optional date range,
     * as an exact decimal string. A null `$asOf`/`$from` bound is unbounded on that side.
     *
     * @param  list<string>  $accountIds
     * @return array<string, string> accountId => exact decimal net movement
     */
    public function movementByAccount(string $entityId, array $accountIds, ?string $from, ?string $to, ?string $sbu): array;
}
