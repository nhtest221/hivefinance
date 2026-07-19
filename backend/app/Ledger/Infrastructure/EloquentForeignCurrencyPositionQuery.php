<?php

namespace App\Ledger\Infrastructure;

use App\Ledger\Application\ForeignCurrencyPositionQuery;
use App\Ledger\Domain\DecimalAmount;
use App\Models\Ledger\JournalLine;
use App\Models\Ledger\LedgerAccount;

final class EloquentForeignCurrencyPositionQuery implements ForeignCurrencyPositionQuery
{
    public function bankPositions(string $entityId, string $asOf, string $functionalCurrency): array
    {
        $positions = [];
        $accounts = LedgerAccount::query()->where('entity_id', $entityId)->whereNotNull('bank_attributes')->get();
        foreach ($accounts as $account) {
            $foreignCurrency = is_array($account->bank_attributes) ? ($account->bank_attributes['currency'] ?? null) : null;
            if (! is_string($foreignCurrency) || $foreignCurrency === $functionalCurrency) {
                continue;
            }
            $foreign = DecimalAmount::zero();
            $functional = DecimalAmount::zero();
            $lines = JournalLine::query()->where('entity_id', $entityId)->where('account_id', $account->id)->whereNotNull('fx_amount')
                ->whereHas('journalEntry', fn ($query) => $query->where('state', 'posted')->whereDate('entry_date', '<=', $asOf))->get();
            foreach ($lines as $line) {
                $foreignAmount = DecimalAmount::fromString($line->fx_amount);
                $foreign = $foreign->add(DecimalAmount::fromString($line->debit)->isZero() ? $foreignAmount->negate() : $foreignAmount);
                $functional = $functional->add(DecimalAmount::fromString($line->debit))->subtract(DecimalAmount::fromString($line->credit));
            }
            if (! $foreign->isZero()) {
                $positions[] = ['account_id' => $account->id, 'foreign_currency' => $foreignCurrency, 'foreign_amount' => $foreign->toString(), 'functional_amount' => $functional->toString()];
            }
        }

        return $positions;
    }
}
