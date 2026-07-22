<?php

namespace App\Period\Application;

use App\Period\Domain\CloseGateResult;
use Illuminate\Support\Carbon;

/**
 * CloseGateProvider v1 (Repository Contracts; API Contracts §12.7). Implemented by
 * Reporting/Reconciliation adapters and consumed only by Period. Never HTTP, never
 * cross-context repository access.
 */
interface CloseGateProvider
{
    public function evaluate(
        int $contractVersion,
        string $entityId,
        string $periodId,
        string $periodRef,
        string $gateType,
        string $correlationId,
        Carbon $evaluatedAt,
    ): CloseGateResult;
}
