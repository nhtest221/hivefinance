<?php

namespace App\Reporting\Infrastructure;

use App\Ledger\Application\LedgerActionResult;
use App\Ledger\Application\LedgerReportService;
use App\Models\User;
use App\Reporting\Application\TrialBalanceQuery;

final readonly class LedgerTrialBalanceQuery implements TrialBalanceQuery
{
    public function __construct(private LedgerReportService $ledgerReports) {}

    public function fetch(User $actor, string $entityId, ?string $asOf, ?string $periodRef, ?string $sbu): LedgerActionResult
    {
        return $this->ledgerReports->trialBalance($actor, $entityId, $asOf, $periodRef, $sbu);
    }
}
