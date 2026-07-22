<?php

namespace App\Period\Application;

use App\Period\Domain\CloseGateResult;
use Illuminate\Support\Carbon;

final readonly class CloseGateEvaluator
{
    public function __construct(private CloseGateProviderRegistry $providers) {}

    /**
     * Evaluates every configured mandatory gate. A gate whose owning context/provider
     * is unavailable, errors, or times out is `unmet` — never skipped (API Contracts §12.6.4).
     *
     * @return list<CloseGateResult>
     */
    public function evaluateAll(string $entityId, string $periodId, string $periodRef, string $correlationId): array
    {
        $gates = config('period.close_gates');
        if (! is_array($gates) || $gates === []) {
            return [];
        }
        $now = Carbon::now('UTC');
        $results = [];
        foreach ($gates as $gateType => $sourceContext) {
            $provider = $this->providers->resolve((string) $sourceContext);
            $results[] = $provider === null
                ? new CloseGateResult((string) $gateType, 'unmet', (string) $sourceContext, null, null, null, null, null, null)
                : $provider->evaluate(1, $entityId, $periodId, $periodRef, (string) $gateType, $correlationId, $now);
        }

        return $results;
    }

    /** @param list<CloseGateResult> $results
     * @return list<string>
     */
    public function unmetGateTypes(array $results): array
    {
        return array_values(array_map(fn (CloseGateResult $r): string => $r->gateType, array_filter($results, fn (CloseGateResult $r): bool => ! $r->satisfied())));
    }

    /** @param list<CloseGateResult> $results */
    public function allSatisfied(array $results): bool
    {
        return $this->unmetGateTypes($results) === [];
    }

    /**
     * Deterministic hash of the accepted evidence set (API Contracts §12.6.4
     * `close_evidence_set_hash`; Database Design `accepted_set_hash`).
     *
     * @param  list<CloseGateResult>  $results
     */
    public function hash(array $results): string
    {
        $sorted = $results;
        usort($sorted, fn (CloseGateResult $a, CloseGateResult $b): int => $a->gateType <=> $b->gateType);
        $canonical = array_map(fn (CloseGateResult $r): array => [
            'gate_type' => $r->gateType,
            'status' => $r->status,
            'source_context' => $r->sourceContext,
            'source_reference' => $r->sourceReference,
            'evidence_version' => $r->evidenceVersion,
            'evidence_hash' => $r->evidenceHash,
        ], $sorted);

        return hash('sha256', json_encode($canonical, JSON_THROW_ON_ERROR));
    }
}
