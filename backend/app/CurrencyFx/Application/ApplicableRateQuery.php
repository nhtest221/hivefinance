<?php

namespace App\CurrencyFx\Application;

use App\Models\CurrencyFx\RateRecord;

final class ApplicableRateQuery
{
    /** @return array<string, mixed>|null */
    public function find(string $entityId, string $base, string $quote, string $date): ?array
    {
        $precedence = config('valuation.fx.source_precedence');
        if (! is_array($precedence) || $precedence === []) {
            return null;
        }
        foreach ($precedence as $source) {
            $rate = RateRecord::query()->where('entity_id', $entityId)->where('base_currency', $base)->where('quote_currency', $quote)->where('source', $source)->whereDate('effective_date', '<=', $date)->orderByDesc('effective_date')->orderByDesc('id')->first();
            if ($rate instanceof RateRecord) {
                return FxService::presentRate($rate);
            }
        }

        return null;
    }
}
