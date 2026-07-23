<?php

namespace App\Reporting\Application;

use App\CurrencyFx\Application\RevaluationRunQuery;
use App\Models\User;
use App\Period\Application\PeriodQuery;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;

/**
 * Reads already-posted FX RevaluationRun facts (M1/CurrencyFx-owned) via RevaluationRunQuery;
 * invents no figure. CurrencyFx continues to own fx_revaluation_runs (AP-001).
 */
final readonly class FXRevaluationQuery
{
    public function __construct(private DocumentCommandSupport $commands, private PeriodQuery $periods, private RevaluationRunQuery $revaluationRuns) {}

    public function fetch(User $actor, string $entityId, string $periodRef): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.fx_revaluation.read')) {
            return $denied;
        }
        if ($this->periods->show($entityId, $periodRef) === null) {
            return $this->commands->error('not_found', 'The period was not found.', 404);
        }
        $runs = $this->revaluationRuns->postedForPeriod($entityId, $periodRef);

        $total = '0.0000';
        $currency = '';
        $accountFigures = [];
        foreach ($runs as $run) {
            foreach ($run->figures as $figure) {
                if (! is_array($figure) || ! isset($figure['account_id'], $figure['amount']['amount'], $figure['amount']['currency'])) {
                    continue;
                }
                $total = ExactDecimal::add($total, (string) $figure['amount']['amount']);
                $currency = (string) $figure['amount']['currency'];
                $accountFigures[] = ['account_id' => $figure['account_id'], 'amount' => ['amount' => (string) $figure['amount']['amount'], 'currency' => $currency], 'revaluation_run_id' => $run->id];
            }
        }

        return new DocumentActionResult([
            'period_ref' => $periodRef,
            'net_revaluation' => ['amount' => $total, 'currency' => $currency],
            'figures' => $accountFigures,
            'revaluation_run_ids' => $runs->pluck('id')->all(),
        ]);
    }
}
