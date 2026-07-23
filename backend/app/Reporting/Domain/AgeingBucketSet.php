<?php

namespace App\Reporting\Domain;

/** API Contracts §13.9: the approved default five-bucket set, identical for AR and AP. */
final readonly class AgeingBucketSet
{
    /** @param list<array{bucket_id: string, label: string, lower_days: int|null, upper_days: int|null, order: int}> $buckets */
    public function __construct(public int $versionNumber, public array $buckets) {}

    /** @return list<string> bucket_id values in display order */
    public function bucketIds(): array
    {
        $sorted = $this->buckets;
        usort($sorted, fn (array $a, array $b): int => $a['order'] <=> $b['order']);

        return array_map(fn (array $b): string => $b['bucket_id'], $sorted);
    }

    public function bucketFor(int $daysOverdue): ?string
    {
        foreach ($this->buckets as $bucket) {
            $lower = $bucket['lower_days'] ?? PHP_INT_MIN;
            $upper = $bucket['upper_days'] ?? PHP_INT_MAX;
            if ($daysOverdue >= $lower && $daysOverdue <= $upper) {
                return $bucket['bucket_id'];
            }
        }

        return null;
    }
}
