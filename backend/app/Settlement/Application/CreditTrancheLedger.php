<?php

namespace App\Settlement\Application;

use App\CurrencyFx\Domain\RealisedFxCalculator;
use App\Identity\Application\EntityReferenceQuery;
use App\Models\Settlement\CreditConsumption;
use App\Models\Settlement\CreditTranche;
use App\Support\Documents\ExactDecimal;
use Illuminate\Support\Carbon;

/**
 * Settlement-owned (AP-001) entry point for M3 CreditTranche operations triggered by M4A
 * Notes (hold/apply-from-held/refund-from-held/reverse). Receivables/Payables never touch
 * Settlement's tables directly — they call this service, which is the only writer.
 *
 * This deliberately does not reuse SettlementService's private consumption/restoration
 * logic (extracting it would mean refactoring already-tested M3 code); it reimplements the
 * same exact/version-guarded mechanics against the same tables, scoped to what note
 * disposition needs.
 */
final readonly class CreditTrancheLedger
{
    public function __construct(
        private EntityReferenceQuery $entities,
        private RealisedFxCalculator $fx,
    ) {}

    /**
     * Moves value into an immutable note-sourced CreditTranche (API Contracts §12.3.7/
     * §12.4.7). No Allocation, no Ledger movement — a hold is neither cash nor a document
     * application.
     *
     * @param  array<string,mixed>|null  $rateReference
     * @return array{tranche:CreditTranche,functional_amount:string}|array{error:array{code:string,message:string,status:int}}
     */
    public function holdFromNote(string $entityId, string $partyType, string $partyId, string $currency, string $amount, ?array $rateReference, string $noteId, ?string $sourceReference): array
    {
        $functional = $this->entities->functionalCurrency($entityId);
        if ($functional === null) {
            return ['error' => ['code' => 'missing_rate_reference', 'message' => 'Entity functional currency is unavailable.', 'status' => 422]];
        }
        $functionalAmount = $this->functional($amount, $rateReference);
        if ($functionalAmount === null) {
            return ['error' => ['code' => 'missing_rate_reference', 'message' => 'A required RateRecord is unavailable.', 'status' => 422]];
        }
        $tranche = CreditTranche::query()->create([
            'entity_id' => $entityId, 'party_type' => $partyType, 'party_id' => $partyId, 'currency' => $currency,
            'original_amount' => $amount, 'remaining_amount' => $amount, 'original_functional_amount' => $functionalAmount,
            'remaining_functional_amount' => $functionalAmount, 'source_rate_record_id' => $rateReference['rate_record_id'] ?? null,
            'source_exchange_rate_reference' => $rateReference, 'source_allocation_id' => null, 'source_note_id' => $noteId,
            'source_reference' => $sourceReference, 'version' => 1,
        ]);

        return ['tranche' => $tranche, 'functional_amount' => $functionalAmount];
    }

    /**
     * Consumes named, caller-validated tranches (application against a document, or
     * refund via bank) within a real settlement_allocations row the caller owns. Never
     * selects a source — every tranche id, amount, and expected_version is explicit
     * (API Contracts §12.2 "no automatic tranche selection").
     *
     * @param  list<array{tranche:CreditTranche,amount:string,expected_version:int}>  $sources
     * @param  array<string,mixed>|null  $comparisonReference
     * @return array{consumed:list<array<string,mixed>>,fx_results:list<array<string,mixed>>,functional_total:string,comparison_total:string}|array{error:array{code:string,message:string,status:int}}
     */
    public function consume(string $entityId, string $partyType, array $sources, ?array $comparisonReference, string $allocationId, string $operation, ?string $documentId = null): array
    {
        $functional = $this->entities->functionalCurrency($entityId);
        if ($functional === null) {
            return ['error' => ['code' => 'missing_rate_reference', 'message' => 'Entity functional currency is unavailable.', 'status' => 422]];
        }
        $consumed = [];
        $fxResults = [];
        $functionalTotal = '0.0000';
        $comparisonTotal = '0.0000';
        foreach ($sources as $source) {
            $tranche = $source['tranche'];
            $amount = ExactDecimal::normalize($source['amount']);
            // Full consumption of the remainder: use the exact stored remaining functional
            // value (avoids rounding drift). Partial: reapply the tranche's own immutable
            // source rate reference to the partial amount — never a fresh determination.
            $carrying = $amount === $tranche->remaining_amount
                ? $tranche->remaining_functional_amount
                : $this->functional($amount, $tranche->source_exchange_rate_reference);
            if ($carrying === null) {
                return ['error' => ['code' => 'missing_credit_rate_reference', 'message' => 'Credit source RateRecord is unavailable.', 'status' => 422]];
            }
            $result = $this->fx->calculateCredit($amount, (string) ($tranche->source_exchange_rate_reference['rate'] ?? '1.00000000'), (string) ($comparisonReference['rate'] ?? $tranche->source_exchange_rate_reference['rate'] ?? '1.00000000'), $partyType, 4, (string) config('valuation.fx.rounding_mode'));
            $comparison = $result['comparison_functional'];
            $consumption = CreditConsumption::query()->create([
                'entity_id' => $entityId, 'credit_tranche_id' => $tranche->id, 'allocation_id' => $allocationId, 'operation' => $operation,
                'amount' => $amount, 'functional_amount' => $carrying, 'source_rate_record_id' => $tranche->source_rate_record_id,
                'comparison_rate_record_id' => $comparisonReference['rate_record_id'] ?? null, 'document_id' => $documentId, 'occurred_at' => Carbon::now('UTC'),
            ]);
            $updated = CreditTranche::query()->whereKey($tranche->id)->where('entity_id', $entityId)->where('version', $source['expected_version'])->where('remaining_amount', $tranche->remaining_amount)
                ->update(['remaining_amount' => ExactDecimal::subtract($tranche->remaining_amount, $amount), 'remaining_functional_amount' => ExactDecimal::subtract($tranche->remaining_functional_amount, $carrying), 'version' => $source['expected_version'] + 1, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                return ['error' => ['code' => 'credit_tranche_concurrency_conflict', 'message' => 'Credit tranche version is stale.', 'status' => 409]];
            }
            $tranche->refresh();
            $consumed[] = ['credit_tranche_id' => $tranche->id, 'amount' => $amount, 'functional_amount' => $carrying, 'remaining_amount' => $tranche->remaining_amount, 'remaining_functional_amount' => $tranche->remaining_functional_amount, 'version' => $tranche->version, 'consumption_id' => $consumption->id];
            if ($result['classification'] !== 'none') {
                $fxResults[] = [...$result, 'credit_tranche_id' => $tranche->id];
            }
            $functionalTotal = ExactDecimal::add($functionalTotal, $carrying);
            $comparisonTotal = ExactDecimal::add($comparisonTotal, $comparison);
        }

        return ['consumed' => $consumed, 'fx_results' => $fxResults, 'functional_total' => $functionalTotal, 'comparison_total' => $comparisonTotal];
    }

    /**
     * Restores exact consumption facts for a note reversal, appending one restoration per
     * original consumption and restoring its recorded transaction/functional values to the
     * same source tranche without recalculation (API Contracts §12.2).
     *
     * @param  list<string>  $consumptionIds
     * @return array{restored:list<array<string,mixed>>}|array{error:array{code:string,message:string,status:int}}
     */
    public function restore(string $entityId, array $consumptionIds, string $reversalAllocationId): array
    {
        $consumptions = CreditConsumption::query()->where('entity_id', $entityId)->whereIn('id', $consumptionIds)->orderBy('id')->get();
        $trancheIds = $consumptions->pluck('credit_tranche_id')->unique()->sort()->values();
        $tranches = CreditTranche::query()->where('entity_id', $entityId)->whereIn('id', $trancheIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $restored = [];
        foreach ($consumptions as $consumption) {
            $tranche = $tranches->get($consumption->credit_tranche_id);
            if ($tranche === null || ExactDecimal::compare(ExactDecimal::add($tranche->remaining_amount, $consumption->amount), $tranche->original_amount) > 0) {
                return ['error' => ['code' => 'credit_balance_conflict', 'message' => 'The exact credit source cannot be restored.', 'status' => 422]];
            }
            $restoration = CreditConsumption::query()->create([
                'entity_id' => $entityId, 'credit_tranche_id' => $tranche->id, 'allocation_id' => $reversalAllocationId, 'operation' => 'restoration',
                'amount' => $consumption->amount, 'functional_amount' => $consumption->functional_amount, 'source_rate_record_id' => $consumption->source_rate_record_id,
                'comparison_rate_record_id' => $consumption->comparison_rate_record_id, 'document_id' => $consumption->document_id,
                'reverses_consumption_id' => $consumption->id, 'occurred_at' => Carbon::now('UTC'),
            ]);
            if (CreditTranche::query()->whereKey($tranche->id)->where('version', $tranche->version)->update(['remaining_amount' => ExactDecimal::add($tranche->remaining_amount, $consumption->amount), 'remaining_functional_amount' => ExactDecimal::add($tranche->remaining_functional_amount, $consumption->functional_amount), 'version' => $tranche->version + 1, 'updated_at' => now('UTC')]) !== 1) {
                return ['error' => ['code' => 'credit_tranche_concurrency_conflict', 'message' => 'Credit tranche version is stale.', 'status' => 409]];
            }
            $tranche->refresh();
            $tranches->put($tranche->id, $tranche);
            $restored[] = ['credit_tranche_id' => $tranche->id, 'restored_amount' => $consumption->amount, 'restored_functional_amount' => $consumption->functional_amount, 'original_consumption_id' => $consumption->id, 'restoration_id' => $restoration->id, 'new_version' => $tranche->version];
        }

        return ['restored' => $restored];
    }

    /** @param  array<string,mixed>|null  $reference */
    private function functional(string $amount, ?array $reference): ?string
    {
        if ($reference === null) {
            return ExactDecimal::normalize($amount);
        }
        $scale = config('valuation.fx.rounding_scale');
        if (! is_numeric($scale)) {
            return null;
        }

        return ExactDecimal::multiply($amount, (string) $reference['rate'], (int) $scale);
    }
}
