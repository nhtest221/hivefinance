<?php

namespace App\Ledger\Infrastructure;

use App\Ledger\Application\AccountMovementQuery;
use App\Models\Ledger\JournalLine;
use App\Support\Documents\ExactDecimal;

final class EloquentAccountMovementQuery implements AccountMovementQuery
{
    /**
     * @param  list<string>  $accountIds
     * @return array<string, string>
     */
    public function movementByAccount(string $entityId, array $accountIds, ?string $from, ?string $to, ?string $sbu): array
    {
        if ($accountIds === []) {
            return [];
        }
        $lines = JournalLine::query()
            ->where('entity_id', $entityId)
            ->whereIn('account_id', $accountIds)
            ->when($sbu !== null, fn ($query) => $query->where('sbu_tag', $sbu))
            ->whereHas('journalEntry', fn ($query) => $query->where('state', 'posted')
                ->when($from !== null, fn ($query) => $query->whereDate('entry_date', '>=', $from))
                ->when($to !== null, fn ($query) => $query->whereDate('entry_date', '<=', $to)))
            ->get(['account_id', 'debit', 'credit']);

        $totals = array_fill_keys($accountIds, '0.0000');
        foreach ($lines as $line) {
            $totals[$line->account_id] = ExactDecimal::subtract(ExactDecimal::add($totals[$line->account_id], $line->debit), $line->credit);
        }

        return $totals;
    }
}
