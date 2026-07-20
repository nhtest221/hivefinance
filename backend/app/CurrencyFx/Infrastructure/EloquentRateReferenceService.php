<?php

namespace App\CurrencyFx\Infrastructure;

use App\CurrencyFx\Application\RateReferenceService;
use App\CurrencyFx\Domain\RealisedFxCalculator;
use App\Models\CurrencyFx\RateRecord;

final readonly class EloquentRateReferenceService implements RateReferenceService
{
    public function __construct(private RealisedFxCalculator $calculator) {}

    public function isExactReference(string $entityId, array $reference, string $baseCurrency, string $quoteCurrency, string $effectiveDate): bool
    {
        return RateRecord::query()->where('entity_id', $entityId)->whereKey($reference['rate_record_id'] ?? null)
            ->where('base_currency', $baseCurrency)->where('quote_currency', $quoteCurrency)->where('rate', $reference['rate'] ?? null)
            ->whereDate('effective_date', $reference['effective_date'] ?? '')->whereDate('effective_date', '<=', $effectiveDate)->exists();
    }

    public function markReferenced(string $entityId, string $rateRecordId): void
    {
        RateRecord::query()->where('entity_id', $entityId)->whereKey($rateRecordId)->where('referenced', false)->update(['referenced' => true]);
    }

    public function matchesFunctionalAmount(string $entityId, array $reference, string $foreignAmount, string $functionalAmount): bool
    {
        $rate = RateRecord::query()->where('entity_id', $entityId)->whereKey($reference['rate_record_id'] ?? null)->value('rate');
        $scale = config('valuation.fx.rounding_scale');
        $mode = config('valuation.fx.rounding_mode');
        if (! is_numeric($rate) || ! is_numeric($scale) || ! is_string($mode)) {
            return false;
        }

        $calculated = $this->calculator->calculate($foreignAmount, (string) $rate, (string) $rate, (int) $scale, $mode);

        return hash_equals($calculated['document_functional'], $functionalAmount);
    }

    public function exactById(string $entityId, string $rateRecordId, string $baseCurrency, string $quoteCurrency, string $effectiveDate): ?array
    {
        $rate = RateRecord::query()->where('entity_id', $entityId)->whereKey($rateRecordId)->where('base_currency', $baseCurrency)->where('quote_currency', $quoteCurrency)->whereDate('effective_date', '<=', $effectiveDate)->first();
        if (! $rate instanceof RateRecord) {
            return null;
        }

        return ['rate_record_id' => $rate->id, 'base_currency' => $rate->base_currency, 'quote_currency' => $rate->quote_currency, 'rate' => $rate->rate, 'effective_date' => $rate->effective_date->toDateString()];
    }
}
