<?php

namespace App\Reporting\Application;

use App\Ledger\Application\LedgerActionResult;
use App\Models\User;

/**
 * Repository Contracts §3.1: an adapter over Ledger's already-implemented, tested
 * LedgerReportService — not a new computation. Ledger continues to own this report.
 */
interface TrialBalanceQuery
{
    public function fetch(User $actor, string $entityId, ?string $asOf, ?string $periodRef, ?string $sbu): LedgerActionResult;
}
