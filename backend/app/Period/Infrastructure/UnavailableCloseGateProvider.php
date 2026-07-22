<?php

namespace App\Period\Infrastructure;

use App\Period\Application\CloseGateProvider;
use App\Period\Domain\CloseGateResult;
use Illuminate\Support\Carbon;

/**
 * Honest placeholder for gates whose owning context (M5 Reporting, M6 Reconciliation)
 * does not exist yet. Always reports `unmet` with no evidence — matching API Contracts
 * §12.7's example exactly. M4 introduces no evidence fabrication and no bypass.
 */
final readonly class UnavailableCloseGateProvider implements CloseGateProvider
{
    public function __construct(private string $sourceContext) {}

    public function evaluate(
        int $contractVersion,
        string $entityId,
        string $periodId,
        string $periodRef,
        string $gateType,
        string $correlationId,
        Carbon $evaluatedAt,
    ): CloseGateResult {
        return new CloseGateResult($gateType, 'unmet', $this->sourceContext, null, null, null, null, null, null);
    }
}
