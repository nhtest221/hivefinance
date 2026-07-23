<?php

namespace App\Reconciliation\Infrastructure;

use App\Models\Reconciliation\BankReconciliation;
use App\Models\Reconciliation\ReconciliationAccount;
use App\Period\Application\CloseGateProvider;
use App\Period\Domain\CloseGateResult;
use App\Reconciliation\Application\BankReconciliationRepository;
use App\Reconciliation\Application\ReconciliationAccountRepository;
use App\Settlement\Application\AllocationQuery;
use Illuminate\Support\Carbon;

/**
 * M6 — the M6 implementation of M4's unchanged CloseGateProvider v1 interface (API
 * Contracts §14.11; §12.7). Handles only bank_reconciliation_completed; never a
 * Reporting-owned gate. Mandatory scope is every ReconciliationAccount with
 * reconciliation_enabled=true; zero configured accounts is vacuously satisfied (the
 * Product Owner's own definition of "mandatory" is "configured", M6-GOV-001 item 8).
 */
final readonly class ReconciliationCloseGateProvider implements CloseGateProvider
{
    private const string GATE_TYPE = 'bank_reconciliation_completed';

    public function __construct(
        private ReconciliationAccountRepository $accounts,
        private BankReconciliationRepository $reconciliations,
        private AllocationQuery $allocations,
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
        if ($gateType !== self::GATE_TYPE) {
            return $this->unmet();
        }
        $mandatory = $this->accounts->mandatoryForEntity($entityId);
        if ($mandatory === []) {
            return new CloseGateResult(self::GATE_TYPE, 'satisfied', 'reconciliation', null, $evaluatedAt->toDateTimeImmutable(), null, null, 0, hash('sha256', ''));
        }

        $qualifying = [];
        foreach ($mandatory as $account) {
            $run = $this->reconciliations->findCurrentCompleted($entityId, $account->id, $periodRef);
            if ($run === null || $this->isStale($entityId, $account, $run, $evaluatedAt)) {
                return $this->unmet();
            }
            $qualifying[] = $run;
        }

        usort($qualifying, fn (BankReconciliation $a, BankReconciliation $b): int => ($a->completed_at ?? $a->created_at) <=> ($b->completed_at ?? $b->created_at));
        $controlling = end($qualifying);
        $digest = array_map(fn (BankReconciliation $r): string => $r->reconciliation_account_id.':'.$r->id.':'.$r->version, $qualifying);
        sort($digest);

        return new CloseGateResult(
            self::GATE_TYPE,
            'satisfied',
            'reconciliation',
            $controlling->id,
            $controlling->completed_at?->toDateTimeImmutable(),
            $controlling->completed_by,
            $controlling->completed_at?->toDateTimeImmutable(),
            count($qualifying),
            hash('sha256', implode("\n", $digest)),
        );
    }

    private function isStale(string $entityId, ReconciliationAccount $account, BankReconciliation $run, Carbon $evaluatedAt): bool
    {
        if ($run->source_data_watermark === null) {
            return true;
        }
        $latest = $this->allocations->latestActivityAt($entityId, $account->ledger_account_id, '1900-01-01', $evaluatedAt->toDateString());

        return $latest !== null && Carbon::parse($latest, 'UTC')->gt($run->source_data_watermark);
    }

    private function unmet(): CloseGateResult
    {
        return new CloseGateResult(self::GATE_TYPE, 'unmet', 'reconciliation', null, null, null, null, null, null);
    }
}
