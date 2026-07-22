<?php

namespace App\Period\Application;

use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Models\Period\AccountingPeriod;
use App\Models\Period\PeriodCloseGateEvidence;
use App\Models\Period\PeriodTransition;
use App\Models\User;
use App\Period\Domain\CloseGateResult;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * M4B — Period Lifecycle and Close-Gate Foundations (API Contracts §12.6; Aggregate
 * Design "AccountingPeriod"; Repository Contracts "AccountingPeriodRepository and
 * CloseGateProvider"). States are exactly Open, SoftClosed, HardClosed, Reopened.
 */
final readonly class PeriodCloseService
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private ApprovalPolicyQuery $approvalPolicy,
        private ApprovalLifecycleService $approvals,
        private CloseGateEvaluator $gates,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    public function softClose(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'periods.soft_close')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $preflight = $this->preflightSoftClose($entityId, $id, $expected);
        if ($preflight instanceof DocumentActionResult) {
            return $preflight;
        }
        if ($this->approvalPolicy->isConfigured($entityId)) {
            return $this->requestApproval($actor, $entityId, 'soft_close', $id, $expected, 'periods.soft_close', $key);
        }

        return $this->executeSoftClose($entityId, $id, $expected, (string) $key, $this->hash($id, $expected), $actor->id, $actor->id, (string) $key);
    }

    public function hardClose(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'periods.hard_close')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $period = $this->find($entityId, $id);
        if ($period === null) {
            return $this->notFound();
        }
        if ($period->state !== 'SoftClosed') {
            return $this->invalidTransition();
        }
        if ($period->version !== $expected) {
            return $this->conflict($period->version);
        }
        // §12.6.4: direct unmet evaluation returns 422 and creates no ApprovalRequest.
        $results = $this->gates->evaluateAll($entityId, $period->id, $period->period_ref, $this->correlation() ?? (string) Str::uuid());
        if (! $this->gates->allSatisfied($results)) {
            return $this->commands->error('invariant_violation', 'Mandatory close gates are not satisfied.', 422, ['rule' => 'close_gate_unmet', 'unmet_gates' => $this->gates->unmetGateTypes($results)]);
        }

        // Four-eyes is mandatory for Hard Close regardless of entity approval-policy configuration.
        return $this->requestApproval($actor, $entityId, 'hard_close', $id, $expected, 'periods.hard_close', $key);
    }

    /** @param array<string,mixed> $data */
    public function reopen(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'periods.reopen')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $period = $this->find($entityId, $id);
        if ($period === null) {
            return $this->notFound();
        }
        if ($period->state !== 'HardClosed') {
            return $this->invalidTransition();
        }
        if ($period->version !== $expected) {
            return $this->conflict($period->version);
        }
        $reasonCodes = config('documents.reason_codes');
        $reason = (string) ($data['reason_code'] ?? '');
        if (! is_array($reasonCodes) || $reason === '' || ! in_array($reason, $reasonCodes, true)) {
            return $this->commands->error('invariant_violation', 'A configured Reopen reason is required.', 422, ['rule' => 'reopen_reason_required']);
        }
        $vatUnlockRequested = $data['vat_unlock_requested'] ?? null;
        if (! is_bool($vatUnlockRequested)) {
            return $this->commands->error('validation', 'vat_unlock_requested must be boolean.', 400);
        }
        if ($vatUnlockRequested) {
            $permitted = config('period.vat_unlock_permitted');
            if ($permitted === null) {
                return $this->commands->error('invariant_violation', 'VAT unlock policy is not configured.', 422, ['rule' => 'vat_unlock_policy_missing']);
            }
            if ($permitted !== true) {
                return $this->commands->error('invariant_violation', 'VAT unlock is not permitted by policy.', 422, ['rule' => 'vat_unlock_not_permitted']);
            }
        }

        // Four-eyes is mandatory for Reopen regardless of entity approval-policy configuration.
        return $this->requestApproval($actor, $entityId, 'reopen', $id, $expected, 'periods.reopen', $key, $data);
    }

    /** @param array<string,mixed> $payload */
    public function executeApproved(string $type, array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $periodId = $payload['resource_id'] ?? null;
        $expected = $payload['expected_version'] ?? null;
        $key = $payload['idempotency_key'] ?? null;
        $data = $payload['data'] ?? [];
        if (! is_string($periodId) || ! is_int($expected) || ! is_string($key) || ! is_array($data)) {
            return $this->commands->error('validation', 'The approved period payload is invalid.', 400);
        }
        $hash = $this->hash($periodId, $expected, $data);

        return match ($type) {
            'soft_close' => $this->executeSoftClose($context->entityId, $periodId, $expected, $key, $hash, $context->makerId, $context->approverId, $context->causationId),
            'hard_close' => $this->executeHardClose($context->entityId, $periodId, $expected, $key, $hash, $context->makerId, $context->approverId, $context->causationId),
            'reopen' => $this->executeReopen($context->entityId, $periodId, $expected, $data, $key, $hash, $context->makerId, $context->approverId, $context->causationId),
            default => $this->commands->error('validation', 'Unsupported Period command.', 400),
        };
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'periods.read')) {
            return $denied;
        }
        $period = $this->find($entityId, $id);
        if ($period === null) {
            return $this->notFound();
        }
        $transitions = PeriodTransition::query()->where('period_id', $period->id)->orderBy('transitioned_at')->orderBy('id')->get();
        $gates = $this->gates->evaluateAll($entityId, $period->id, $period->period_ref, $this->correlation() ?? (string) Str::uuid());

        return new DocumentActionResult(['period' => [
            ...$this->summary($period),
            'transitions' => $transitions->map(fn (PeriodTransition $t): array => [
                'from_state' => $t->from_state, 'to_state' => $t->to_state, 'reason_code' => $t->reason_code,
                'narrative' => $t->narrative, 'vat_status_before' => $t->vat_status_before, 'vat_status_after' => $t->vat_status_after,
                'maker_id' => $t->actor_id, 'approver_id' => $t->approver_id, 'approval_id' => $t->approval_id,
                'transitioned_at' => $t->transitioned_at->toISOString(),
            ])->all(),
            'close_gates' => array_map($this->presentGate(...), $gates),
        ]]);
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'periods.read')) {
            return $denied;
        }
        $limit = (int) ($filters['limit'] ?? 50);
        $binding = ['entity_id' => $entityId, 'filters' => $filters, 'order' => 'starts_on_desc,id_desc'];
        try {
            [$cursor, $boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->commands->error('validation', $exception->getMessage(), 400);
        }
        $query = AccountingPeriod::query()->where('entity_id', $entityId)->where('created_at', '<=', $boundary)
            ->when($filters['state'] ?? null, fn ($q, $v) => $q->where('state', $v))
            ->when($filters['fiscal_year'] ?? null, fn ($q, $v) => $q->where('period_ref', 'like', $v.'%'))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('starts_on', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('starts_on', '<=', $v));
        $page = $query->orderByDesc('starts_on')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return new DocumentActionResult(['periods' => $page->getCollection()->map(function (AccountingPeriod $period) use ($entityId): array {
            $gates = $this->gates->evaluateAll($entityId, $period->id, $period->period_ref, (string) Str::uuid());

            return [...$this->summary($period), 'close_gate_summary' => ['satisfied' => count(array_filter($gates, fn (CloseGateResult $r): bool => $r->satisfied())), 'required' => count($gates)]];
        })->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    private function preflightSoftClose(string $entityId, string $id, int $expected): ?DocumentActionResult
    {
        $period = $this->find($entityId, $id);
        if ($period === null) {
            return $this->notFound();
        }
        if (! in_array($period->state, ['Open', 'Reopened'], true)) {
            return $this->invalidTransition();
        }
        if ($period->version !== $expected) {
            return $this->conflict($period->version);
        }
        $types = config('period.soft_close_adjustment_entry_types');
        if (! is_array($types) || $types === []) {
            return $this->commands->error('invariant_violation', 'Soft Close adjustment configuration is unavailable.', 422, ['rule' => 'missing_close_configuration']);
        }

        return null;
    }

    private function executeSoftClose(string $entityId, string $id, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        if ($replay = $this->commands->replay($makerId, $entityId, 'period_soft_close:'.$id, $key, $hash)) {
            return $replay;
        }

        return DB::transaction(function () use ($entityId, $id, $expected, $key, $hash, $makerId, $executorId, $causationId): DocumentActionResult {
            $period = AccountingPeriod::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if ($period === null) {
                return $this->notFound();
            }
            if (! in_array($period->state, ['Open', 'Reopened'], true)) {
                return $this->commands->error('invariant_violation', $period->state === 'SoftClosed' ? 'The period is already Soft Closed.' : 'The period cannot transition to SoftClosed.', 422, ['rule' => $period->state === 'SoftClosed' ? 'period_already_soft_closed' : 'invalid_period_transition']);
            }
            if ($period->version !== $expected) {
                return $this->conflict($period->version);
            }
            $from = $period->state;
            $updated = AccountingPeriod::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expected)->update(['state' => 'SoftClosed', 'reclose_required' => false, 'version' => $expected + 1, 'updated_at' => now('UTC')]);
            if ($updated !== 1) {
                return $this->conflict((int) AccountingPeriod::query()->whereKey($id)->value('version'));
            }
            $period->refresh();
            PeriodTransition::query()->create(['period_id' => $period->id, 'from_state' => $from, 'to_state' => 'SoftClosed', 'version_before' => $expected, 'version_after' => $period->version, 'vat_status_before' => $period->vat_lock_status, 'vat_status_after' => $period->vat_lock_status, 'actor_id' => $makerId, 'correlation_id' => $this->correlation(), 'causation_id' => $causationId, 'transitioned_at' => now('UTC')]);
            $body = ['period' => $this->summary($period)];
            $this->audit->record('period', 'period_soft_closed', 'accounting_period', $period->id, $executorId, $entityId, before: ['state' => $from, 'version' => $expected], after: $body['period'], correlationId: $this->correlation());
            $this->outbox->record('PeriodSoftClosed', 'AccountingPeriod', $period->id, ['period_ref' => $period->period_ref, 'from_state' => $from, 'to_state' => 'SoftClosed', 'version_before' => $expected, 'version_after' => $period->version, 'vat_status_before' => $period->vat_lock_status, 'vat_status_after' => $period->vat_lock_status, 'maker_id' => $makerId], $entityId, 2, ['causation_id' => $causationId]);
            $this->commands->store($makerId, $entityId, 'period_soft_close:'.$id, $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    private function executeHardClose(string $entityId, string $id, int $expected, string $key, string $hash, string $makerId, string $approverId, string $causationId): DocumentActionResult
    {
        return DB::transaction(function () use ($entityId, $id, $expected, $key, $hash, $makerId, $approverId, $causationId): DocumentActionResult {
            $period = AccountingPeriod::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if ($period === null) {
                return $this->notFound();
            }
            if ($period->state !== 'SoftClosed') {
                return $this->commands->error('invariant_violation', 'The period cannot transition to HardClosed.', 422, ['rule' => 'invalid_period_transition']);
            }
            if ($period->version !== $expected) {
                return $this->conflict($period->version);
            }
            // Re-evaluate the identical gate set at execution time (§12.6.4).
            $results = $this->gates->evaluateAll($entityId, $period->id, $period->period_ref, $causationId);
            if (! $this->gates->allSatisfied($results)) {
                return $this->commands->error('invariant_violation', 'Mandatory close gates are not satisfied.', 422, ['rule' => 'close_gate_unmet', 'unmet_gates' => $this->gates->unmetGateTypes($results)]);
            }
            $closeAttemptId = (string) Str::uuid();
            $now = Carbon::now('UTC');
            foreach ($results as $result) {
                PeriodCloseGateEvidence::query()->create(['entity_id' => $entityId, 'period_id' => $period->id, 'close_attempt_id' => $closeAttemptId, 'gate_type' => $result->gateType, 'status' => $result->status, 'source_context' => $result->sourceContext, 'source_reference' => $result->sourceReference, 'produced_at' => $result->producedAt, 'reviewed_by' => $result->reviewedBy, 'reviewed_at' => $result->reviewedAt, 'evidence_version' => $result->evidenceVersion, 'evidence_hash' => $result->evidenceHash, 'accepted_set_hash' => $this->gates->hash($results), 'accepted_at' => $now]);
            }
            $from = $period->state;
            $vatBefore = $period->vat_lock_status;
            $evidenceHash = $this->gates->hash($results);
            $updated = AccountingPeriod::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expected)->update(['state' => 'HardClosed', 'vat_lock_status' => 'locked', 'close_evidence_set_hash' => $evidenceHash, 'hard_closed_at' => $now, 'hard_closed_by' => $approverId, 'version' => $expected + 1, 'updated_at' => $now]);
            if ($updated !== 1) {
                return $this->conflict((int) AccountingPeriod::query()->whereKey($id)->value('version'));
            }
            $period->refresh();
            PeriodTransition::query()->create(['period_id' => $period->id, 'from_state' => $from, 'to_state' => 'HardClosed', 'version_before' => $expected, 'version_after' => $period->version, 'vat_status_before' => $vatBefore, 'vat_status_after' => 'locked', 'actor_id' => $makerId, 'approver_id' => $approverId, 'correlation_id' => $this->correlation(), 'causation_id' => $causationId, 'transitioned_at' => $now]);
            $body = ['period' => $this->summary($period)];
            $this->audit->record('period', 'period_hard_closed', 'accounting_period', $period->id, $approverId, $entityId, before: ['state' => $from, 'version' => $expected], after: $body['period'], metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
            $this->outbox->record('PeriodHardClosed', 'AccountingPeriod', $period->id, ['period_ref' => $period->period_ref, 'from_state' => $from, 'to_state' => 'HardClosed', 'version_before' => $expected, 'version_after' => $period->version, 'vat_status_before' => $vatBefore, 'vat_status_after' => 'locked', 'maker_id' => $makerId, 'approver_id' => $approverId, 'close_evidence_set_hash' => $evidenceHash], $entityId, 2, ['causation_id' => $causationId]);
            $this->outbox->record('VATPeriodLocked', 'AccountingPeriod', $period->id, ['period_ref' => $period->period_ref, 'vat_status_before' => $vatBefore, 'vat_status_after' => 'locked', 'maker_id' => $makerId, 'approver_id' => $approverId], $entityId, 2, ['causation_id' => $causationId]);
            $this->commands->store($makerId, $entityId, 'period_hard_close:'.$id, $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    /** @param array<string,mixed> $data */
    private function executeReopen(string $entityId, string $id, int $expected, array $data, string $key, string $hash, string $makerId, string $approverId, string $causationId): DocumentActionResult
    {
        return DB::transaction(function () use ($entityId, $id, $expected, $data, $key, $hash, $makerId, $approverId, $causationId): DocumentActionResult {
            $period = AccountingPeriod::query()->where('entity_id', $entityId)->whereKey($id)->lockForUpdate()->first();
            if ($period === null) {
                return $this->notFound();
            }
            if ($period->state !== 'HardClosed') {
                return $this->commands->error('invariant_violation', 'The period cannot transition to Reopened.', 422, ['rule' => 'invalid_period_transition']);
            }
            if ($period->version !== $expected) {
                return $this->conflict($period->version);
            }
            $vatUnlockRequested = (bool) ($data['vat_unlock_requested'] ?? false);
            $vatBefore = $period->vat_lock_status;
            $vatAfter = $vatUnlockRequested ? 'unlocked_for_approved_adjustments' : $vatBefore;
            $now = Carbon::now('UTC');
            $updated = AccountingPeriod::query()->whereKey($id)->where('entity_id', $entityId)->where('version', $expected)->update(['state' => 'Reopened', 'vat_lock_status' => $vatAfter, 'reclose_required' => true, 'version' => $expected + 1, 'updated_at' => $now]);
            if ($updated !== 1) {
                return $this->conflict((int) AccountingPeriod::query()->whereKey($id)->value('version'));
            }
            $period->refresh();
            $reason = (string) $data['reason_code'];
            $narrative = (string) $data['narrative'];
            PeriodTransition::query()->create(['period_id' => $period->id, 'from_state' => 'HardClosed', 'to_state' => 'Reopened', 'reason_code' => $reason, 'narrative' => $narrative, 'version_before' => $expected, 'version_after' => $period->version, 'vat_status_before' => $vatBefore, 'vat_status_after' => $vatAfter, 'actor_id' => $makerId, 'approver_id' => $approverId, 'correlation_id' => $this->correlation(), 'causation_id' => $causationId, 'reclose_required' => true, 'transitioned_at' => $now]);
            $body = ['period' => [...$this->summary($period), 'reclose_required' => true], 'notification' => ['event' => 'PeriodReopened', 'audience' => 'affected_entity_users']];
            $this->audit->record('period', 'period_reopened', 'accounting_period', $period->id, $approverId, $entityId, before: ['state' => 'HardClosed', 'version' => $expected], after: $body['period'], metadata: ['maker_id' => $makerId, 'reason_code' => $reason], correlationId: $this->correlation());
            $this->outbox->record('PeriodReopened', 'AccountingPeriod', $period->id, ['period_ref' => $period->period_ref, 'from_state' => 'HardClosed', 'to_state' => 'Reopened', 'version_before' => $expected, 'version_after' => $period->version, 'reason_code' => $reason, 'vat_status_before' => $vatBefore, 'vat_status_after' => $vatAfter, 'maker_id' => $makerId, 'approver_id' => $approverId, 'reclose_required' => true, 'notification_audience' => 'affected_entity_users'], $entityId, 2, ['causation_id' => $causationId]);
            if ($vatUnlockRequested) {
                $this->outbox->record('VATPeriodUnlocked', 'AccountingPeriod', $period->id, ['period_ref' => $period->period_ref, 'vat_status_before' => $vatBefore, 'vat_status_after' => $vatAfter, 'reason_code' => $reason, 'maker_id' => $makerId, 'approver_id' => $approverId], $entityId, metadata: ['causation_id' => $causationId]);
            }
            $this->commands->store($makerId, $entityId, 'period_reopen:'.$id, $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    /** @param array<string,mixed> $data */
    private function requestApproval(User $actor, string $entityId, string $type, string $id, int $expected, string $capability, ?string $key, array $data = []): DocumentActionResult
    {
        $payload = ['resource_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key, 'data' => $data];
        $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('period_'.$type, 1, $payload, $id, $capability, $expected), 'period_'.$type.':'.$id, (string) $key, $this->correlation() ?? (string) Str::uuid());

        return new DocumentActionResult($result->payload, $result->status, $result->headers);
    }

    private function find(string $entityId, string $id): ?AccountingPeriod
    {
        return AccountingPeriod::query()->where('entity_id', $entityId)->find($id);
    }

    /** @param array<string,mixed> $data */
    private function hash(string $id, int $expected, array $data = []): string
    {
        return $this->commands->hash([$id, $expected, $data]);
    }

    /** @return array<string,mixed> */
    private function summary(AccountingPeriod $period): array
    {
        return [
            'id' => $period->id,
            'period_ref' => $period->period_ref,
            'starts_on' => $period->starts_on->toDateString(),
            'ends_on' => $period->ends_on->toDateString(),
            'state' => $period->state,
            'vat_lock_status' => $period->vat_lock_status,
            'version' => $period->version,
            'close_evidence_set_hash' => $period->close_evidence_set_hash,
            'hard_closed_at' => $period->hard_closed_at?->toISOString(),
            'hard_closed_by' => $period->hard_closed_by,
        ];
    }

    /** @return array<string,mixed> */
    private function presentGate(CloseGateResult $result): array
    {
        return [
            'gate_type' => $result->gateType,
            'status' => $result->status,
            'source_context' => $result->sourceContext,
            'source_reference' => $result->sourceReference,
            'produced_at' => $result->producedAt?->format('Y-m-d\TH:i:s.v\Z'),
            'reviewed_by' => $result->reviewedBy,
            'reviewed_at' => $result->reviewedAt?->format('Y-m-d\TH:i:s.v\Z'),
            'evidence_version' => $result->evidenceVersion,
            'evidence_hash' => $result->evidenceHash,
        ];
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The accounting period was not found.', 404);
    }

    private function invalidTransition(): DocumentActionResult
    {
        return $this->commands->error('invariant_violation', 'The requested period transition is not valid.', 422, ['rule' => 'invalid_period_transition']);
    }

    private function conflict(int $version): DocumentActionResult
    {
        return $this->commands->error('concurrency_conflict', 'The period version has changed.', 409, ['required_version' => $version]);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
