<?php

namespace App\Reporting\Application;

use App\Reporting\Domain\CashViewPolicy;

interface CashViewPolicyProvider
{
    public function getEffective(string $entityId, string $atDate): ?CashViewPolicy;
}
