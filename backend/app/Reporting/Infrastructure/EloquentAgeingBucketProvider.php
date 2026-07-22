<?php

namespace App\Reporting\Infrastructure;

use App\Models\Reporting\AgeingBucketSetVersion;
use App\Reporting\Application\AgeingBucketProvider;
use App\Reporting\Domain\AgeingBucketSet;

final class EloquentAgeingBucketProvider implements AgeingBucketProvider
{
    public function getEffective(string $entityId, string $atDate): ?AgeingBucketSet
    {
        $version = AgeingBucketSetVersion::query()->where('entity_id', $entityId)
            ->whereDate('effective_from', '<=', $atDate)
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhereDate('effective_to', '>=', $atDate))
            ->orderByDesc('version_number')->first();

        return $version === null ? null : new AgeingBucketSet($version->version_number, $version->buckets);
    }
}
