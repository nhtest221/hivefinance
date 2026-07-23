<?php

namespace App\Reporting\Infrastructure;

use App\Models\Reporting\CashViewPolicyVersion;
use App\Reporting\Application\CashViewPolicyProvider;
use App\Reporting\Domain\CashViewPolicy;

final class EloquentCashViewPolicyProvider implements CashViewPolicyProvider
{
    public function getEffective(string $entityId, string $atDate): ?CashViewPolicy
    {
        $version = CashViewPolicyVersion::query()->where('entity_id', $entityId)
            ->whereDate('effective_from', '<=', $atDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $atDate))
            ->orderByDesc('version_number')->first();

        return $version === null ? null : new CashViewPolicy($version->version_number, $version->policy);
    }
}
