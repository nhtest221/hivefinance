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

    /**
     * Latest posted_at among posted JournalEntry rows, entity-scoped, optionally bounded
     * by entry_date. Feeds Reporting's source-data watermark (API Contracts §13.4/§13.12).
     */
    public function latestPostedAt(string $entityId, ?string $to): ?string;

    /**
     * JournalEntry ids that carry at least one line tagged with the given SBU, entity-scoped.
     * Feeds Reporting's Cash View SBU filter without exposing journal_lines directly.
     *
     * @return list<string>
     */
    public function journalEntryIdsTaggedWithSbu(string $entityId, string $sbu): array;
}
