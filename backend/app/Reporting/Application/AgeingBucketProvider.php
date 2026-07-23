<?php

namespace App\Reporting\Application;

use App\Reporting\Domain\AgeingBucketSet;

interface AgeingBucketProvider
{
    public function getEffective(string $entityId, string $atDate): ?AgeingBucketSet;
}
