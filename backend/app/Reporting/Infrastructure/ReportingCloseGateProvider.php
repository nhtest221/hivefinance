<?php

namespace App\Reporting\Infrastructure;

use App\Models\Reporting\ReportRun;
use App\Period\Application\CloseGateProvider;
use App\Period\Application\PeriodQuery;
use App\Period\Domain\CloseGateResult;
use App\Reporting\Application\ReportRunRepository;
use App\Reporting\Application\SourceDataWatermarkCalculator;
use Illuminate\Support\Carbon;

/**
 * M5B — the M5 implementation of M4's unchanged CloseGateProvider v1 interface
 * (API Contracts §13.12; §12.7). Handles only the four Reporting-owned gates; never
 * bank_reconciliation_completed, which remains M6-owned. A stale, superseded, rejected,
 * or unapproved ReportRun can never satisfy a gate — the lookup only ever considers the
 * current Approved run for the exact reproducibility key, and staleness is re-checked
 * against a freshly recomputed source-data watermark on every evaluation.
 */
final readonly class ReportingCloseGateProvider implements CloseGateProvider
{
    private const array GATE_REPORT_TYPES = [
        'trial_balance_reviewed' => 'trial_balance',
        'profit_and_loss_approved' => 'profit_and_loss',
        'balance_sheet_approved' => 'balance_sheet',
        'vat_outputs_approved' => 'tax_summary',
    ];

    /** Trial Balance and Balance Sheet are as-of (point-in-time) ReportRuns; Profit and
     *  Loss and Tax Summary are period_ref ReportRuns — API Contracts §13.5/§13.7-§13.10. */
    private const array AS_OF_REPORT_TYPES = ['trial_balance', 'balance_sheet'];

    public function __construct(
        private ReportRunRepository $runs,
        private SourceDataWatermarkCalculator $watermarks,
        private PeriodQuery $periods,
    ) {}

    public function evaluate(
        int $contractVersion,
        string $entityId,
        string $periodId,
        string $periodRef,
        string $gateType,
        string $correlationId,
        Carbon $evaluatedAt,
    ): CloseGateResult {
        $reportType = self::GATE_REPORT_TYPES[$gateType] ?? null;
        if ($reportType === null) {
            return $this->unmet($gateType);
        }

        $periodEnd = $this->periods->show($entityId, $periodRef)?->ends_on->toDateString();
        $isAsOf = in_array($reportType, self::AS_OF_REPORT_TYPES, true);
        $run = $this->runs->findCurrentApproved($entityId, $reportType, 'accrual', $isAsOf ? null : $periodRef, $isAsOf ? $periodEnd : null, []);
        if (! $run instanceof ReportRun) {
            return $this->unmet($gateType);
        }

        $freshWatermark = $this->watermarks->forBasis($entityId, 'accrual', $periodEnd);
        if ($freshWatermark->gt($run->source_data_watermark)) {
            // API Contracts §13.4/§13.12: new qualifying postings exist that this
            // Approved run never saw — stale evidence, reported unmet, never fabricated.
            return $this->unmet($gateType);
        }

        return new CloseGateResult(
            $gateType,
            'satisfied',
            'reporting',
            $run->id,
            $run->generated_at->toDateTimeImmutable(),
            $run->reviewed_by,
            $run->reviewed_at?->toDateTimeImmutable(),
            $run->version,
            $run->content_hash,
        );
    }

    private function unmet(string $gateType): CloseGateResult
    {
        return new CloseGateResult($gateType, 'unmet', 'reporting', null, null, null, null, null, null);
    }
}
