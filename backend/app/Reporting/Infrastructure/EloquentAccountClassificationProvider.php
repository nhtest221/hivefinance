<?php

namespace App\Reporting\Infrastructure;

use App\Models\Reporting\AccountClassificationVersion;
use App\Reporting\Application\AccountClassificationProvider;
use App\Reporting\Domain\AccountClassificationMap;

final class EloquentAccountClassificationProvider implements AccountClassificationProvider
{
    public function getEffective(string $entityId, string $atDate): ?AccountClassificationMap
    {
        $version = AccountClassificationVersion::query()->where('entity_id', $entityId)
            ->whereDate('effective_from', '<=', $atDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $atDate))
            ->orderByDesc('version_number')->first();

        return $version === null ? null : new AccountClassificationMap($version->version_number, $version->entries);
    }
}
