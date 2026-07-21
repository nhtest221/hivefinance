<?php

namespace App\Tax\Application;

use Illuminate\Support\Str;

final class WithholdingConfigurationQuery
{
    /** @return array{configuration_reference:array<string,mixed>,account_id:string,posting_side:string}|null */
    public function resolve(string $entityId, string $partyType, string $code, string $date): ?array
    {
        $all = config('settlement.withholding');
        if (! is_array($all)) {
            return null;
        }
        $configured = $all[$entityId][$partyType][$code] ?? null;
        if (! is_array($configured)
            || ($configured['active'] ?? false) !== true
            || ! is_string($configured['configuration_id'] ?? null)
            || ! Str::isUuid($configured['configuration_id'])
            || ! is_int($configured['version'] ?? null)
            || $configured['version'] < 1
            || ! is_string($configured['account_id'] ?? null)
            || ! Str::isUuid($configured['account_id'])
            || ! in_array($configured['posting_side'] ?? null, ['debit', 'credit'], true)
            || (isset($configured['effective_from']) && $date < $configured['effective_from'])
            || (isset($configured['effective_to']) && $date > $configured['effective_to'])) {
            return null;
        }

        return ['configuration_reference' => ['configuration_id' => $configured['configuration_id'], 'version' => $configured['version'], 'code' => $code], 'account_id' => $configured['account_id'], 'posting_side' => $configured['posting_side']];
    }
}
