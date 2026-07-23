<?php

namespace App\Settlement\Infrastructure;

use App\Models\Settlement\Allocation;
use App\Settlement\Application\AllocationQuery;
use Illuminate\Support\Collection;

final class EloquentAllocationQuery implements AllocationQuery
{
    /** @return Collection<int, Allocation> */
    public function candidatesForBankAccount(string $entityId, string $ledgerAccountId, string $currency, string $from, string $to): Collection
    {
        return Allocation::query()
            ->where('entity_id', $entityId)
            ->where('bank_account_id', $ledgerAccountId)
            ->where('currency', $currency)
            ->whereIn('state', ['posted', 'reversed'])
            ->whereDate('settlement_date', '>=', $from)
            ->whereDate('settlement_date', '<=', $to)
            ->get();
    }

    /** @param list<string> $ids
     * @return Collection<int, Allocation> */
    public function findByIds(string $entityId, array $ids): Collection
    {
        return Allocation::query()->where('entity_id', $entityId)->whereIn('id', $ids)->get();
    }

    public function latestActivityAt(string $entityId, string $ledgerAccountId, string $from, string $to): ?string
    {
        $max = Allocation::query()
            ->where('entity_id', $entityId)
            ->where('bank_account_id', $ledgerAccountId)
            ->whereIn('state', ['posted', 'reversed'])
            ->whereDate('settlement_date', '>=', $from)
            ->whereDate('settlement_date', '<=', $to)
            ->max('posted_at');

        return is_string($max) ? $max : null;
    }

    /** @return Collection<int, Allocation> */
    public function postedWithinSettlementDateRange(string $entityId, string $from, string $to): Collection
    {
        return Allocation::query()
            ->where('entity_id', $entityId)
            ->where('state', 'posted')
            ->whereBetween('settlement_date', [$from, $to])
            ->get();
    }

    public function latestPostedAt(string $entityId, ?string $to): ?string
    {
        $max = Allocation::query()
            ->where('entity_id', $entityId)
            ->where('state', 'posted')
            ->when($to !== null, fn ($query) => $query->whereDate('settlement_date', '<=', $to))
            ->max('posted_at');

        return is_string($max) ? $max : null;
    }
}
