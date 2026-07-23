<?php

namespace App\Settlement\Application;

use App\Models\Settlement\Allocation;
use Illuminate\Support\Collection;

/**
 * Internal Settlement-owned contract for Reconciliation's match-candidate search (API
 * Contracts §14.6, §14.13). Settlement continues to own `settlement_allocations` reads;
 * Reconciliation never queries the table directly (AP-001).
 */
interface AllocationQuery
{
    /**
     * Posted (and reversed) allocations for one bank ledger account, in one currency, whose
     * settlement_date falls within the window — the exact match-candidate pool (API Contracts
     * §14.6). Never filters by tolerance; the caller applies exact-amount comparison itself.
     *
     * @return Collection<int, Allocation>
     */
    public function candidatesForBankAccount(string $entityId, string $ledgerAccountId, string $currency, string $from, string $to): Collection;

    /** @param list<string> $ids
     * @return Collection<int, Allocation> */
    public function findByIds(string $entityId, array $ids): Collection;

    /** Latest posted_at among posted/reversed allocations referencing this bank ledger account
     * within the date range — feeds Reconciliation's staleness check (API Contracts §14.11). */
    public function latestActivityAt(string $entityId, string $ledgerAccountId, string $from, string $to): ?string;

    /**
     * Posted allocations whose settlement_date falls within the window, entity-scoped
     * (no bank-account/currency filter) — the exact source-fact pool for Reporting's Cash
     * View (API Contracts §13.11). Reporting never queries settlement_allocations directly.
     *
     * @return Collection<int, Allocation>
     */
    public function postedWithinSettlementDateRange(string $entityId, string $from, string $to): Collection;

    /** Latest posted_at among posted allocations, entity-scoped, optionally bounded by
     * settlement_date — feeds Reporting's source-data watermark (API Contracts §13.4/§13.12). */
    public function latestPostedAt(string $entityId, ?string $to): ?string;
}
