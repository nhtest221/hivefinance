<?php

namespace App\Reporting\Application;

use App\Reporting\Domain\AccountClassificationMap;

interface AccountClassificationProvider
{
    public function getEffective(string $entityId, string $atDate): ?AccountClassificationMap;
}
