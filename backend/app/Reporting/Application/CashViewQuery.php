<?php

namespace App\Reporting\Application;

use App\Identity\Application\EntityReferenceQuery;
use App\Ledger\Application\AccountMovementQuery;
use App\Models\Settlement\Allocation;
use App\Models\User;
use App\Period\Application\PeriodQuery;
use App\Settlement\Application\AllocationQuery;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\ExactDecimal;

/**
 * API Contracts §13.11: Cash View — a derived, rebuildable management report, never a
 * second Ledger. Implements ADR-001's frozen algorithm (re-time to settlement date,
 * pro-rate by settled proportion, value at settlement-date RateRecord, exclude VAT,
 * net of withholding) plus the approved M5-GOV-001 rules. A GET call creates no
 * journal, posting, audit event, or business outbox event — it only reads already-posted
 * Settlement facts (Allocation/AllocationLink).
 */
final readonly class CashViewQuery
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private PeriodQuery $periods,
        private CashViewPolicyProvider $policies,
        private EntityReferenceQuery $entities,
        private AllocationQuery $allocationQuery,
        private AccountMovementQuery $movements,
    ) {}

    public function fetch(User $actor, string $entityId, string $periodRef, ?string $sbu): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.cash_view.read')) {
            return $denied;
        }
        $period = $this->periods->show($entityId, $periodRef);
        if ($period === null) {
            return $this->commands->error('not_found', 'The period was not found.', 404);
        }
        $to = $period->ends_on->toDateString();
        $policy = $this->policies->getEffective($entityId, $to);
        if ($policy === null) {
            return $this->commands->error('missing_cash_view_policy', 'No effective Cash View policy is configured.', 422);
        }
        $from = $period->starts_on->toDateString();
        $currency = $this->entities->functionalCurrency($entityId) ?? '';

        // Rule 1/2: re-time to settlement date, pro-rate by settled proportion — each
        // Allocation already represents exactly one settled tranche (M3), so summing
        // posted Allocations within [from, to] by settlement_date directly implements
        // both rules without re-deriving partial-payment proportions.
        $allocations = $this->allocationQuery->postedWithinSettlementDateRange($entityId, $from, $to);

        if ($sbu !== null) {
            // SBU allocation follows the source document's exact-sum split as already
            // posted into the settlement journal entry's per-SBU lines (journal_lines.sbu_tag) —
            // the same mechanism TB/GL/P&L/BS already use; no separate SBU rule is invented.
            $taggedEntryIds = $this->movements->journalEntryIdsTaggedWithSbu($entityId, $sbu);
            $allocations = $allocations->filter(fn (Allocation $allocation): bool => array_intersect($allocation->journal_entry_ids, $taggedEntryIds) !== []);
        }

        $collections = $payments = $refunds = $withheldExcluded = $unappliedCash = '0.0000';
        foreach ($allocations as $allocation) {
            // Rule 4/5: VAT is never a Money field on Allocation (it is stripped at the
            // Tax layer before settlement); withholding is excluded from cash and shown
            // separately, never counted as bank cash.
            $withheldExcluded = ExactDecimal::add($withheldExcluded, $allocation->withholding_amount);
            $bank = $allocation->bank_amount;
            match ($allocation->operation) {
                'receipt' => $collections = ExactDecimal::add($collections, $bank),
                'payment' => $payments = ExactDecimal::add($payments, $bank),
                'credit_refund' => $refunds = ExactDecimal::subtract($refunds, $bank),
                'reversal' => $this->applyReversal($entityId, $allocation, $bank, $collections, $payments, $refunds),
                default => null,
            };
            if (ExactDecimal::compare($allocation->unapplied_amount, '0.0000') !== 0) {
                // Rule: unapplied party credit appears in reconciliation only, never as
                // Cash View revenue/expense. Later application is attributed to this
                // same original settlement date (rule already satisfied by not creating
                // any second entry when the credit is later applied to a document).
                $unappliedCash = ExactDecimal::add($unappliedCash, $allocation->unapplied_amount);
            }
        }

        $netCashFlow = ExactDecimal::subtract(ExactDecimal::add($collections, $refunds), $payments);

        return new DocumentActionResult([
            'period_ref' => $periodRef,
            'basis' => 'cash',
            'policy_version' => $policy->versionNumber,
            'cash_in_bank' => ['amount' => ExactDecimal::add($collections, $refunds), 'currency' => $currency],
            'collections' => ['amount' => $collections, 'currency' => $currency],
            'payments' => ['amount' => $payments, 'currency' => $currency],
            'net_cash_flow' => ['amount' => $netCashFlow, 'currency' => $currency],
            'withheld_excluded' => ['amount' => $withheldExcluded, 'currency' => $currency],
            'unapplied_cash' => ['amount' => $unappliedCash, 'currency' => $currency],
            'refunds' => ['amount' => $refunds, 'currency' => $currency],
            'residual' => ['amount' => '0.0000', 'currency' => $currency],
        ]);
    }

    private function applyReversal(string $entityId, Allocation $reversal, string $bank, string &$collections, string &$payments, string &$refunds): void
    {
        $original = $reversal->reversal_of_id !== null
            ? $this->allocationQuery->findByIds($entityId, [$reversal->reversal_of_id])->first()
            : null;
        match ($original?->operation) {
            'receipt' => $collections = ExactDecimal::subtract($collections, $bank),
            'payment' => $payments = ExactDecimal::subtract($payments, $bank),
            'credit_refund' => $refunds = ExactDecimal::add($refunds, $bank),
            default => null,
        };
    }
}
