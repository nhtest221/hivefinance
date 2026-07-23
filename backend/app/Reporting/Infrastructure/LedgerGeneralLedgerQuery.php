<?php

namespace App\Reporting\Infrastructure;

use App\Ledger\Application\LedgerActionResult;
use App\Ledger\Application\LedgerReportService;
use App\Models\User;
use App\Reporting\Application\GeneralLedgerQuery;

final readonly class LedgerGeneralLedgerQuery implements GeneralLedgerQuery
{
    public function __construct(private LedgerReportService $ledgerReports) {}

    public function fetch(User $actor, string $entityId, string $accountId, ?string $from, ?string $to, int $limit, ?string $cursor, ?string $sbu): LedgerActionResult
    {
        return $this->ledgerReports->generalLedger($actor, $entityId, $accountId, $from, $to, $limit, $cursor, $sbu);
    }
}
