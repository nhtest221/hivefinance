<?php

namespace App\CurrencyFx\Application;

use App\CurrencyFx\Domain\RealisedFxCalculator;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Application\EntityReferenceQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\AccountReferenceQuery;
use App\Ledger\Application\ForeignCurrencyPositionQuery;
use App\Ledger\Application\SystemPostingService;
use App\Models\CurrencyFx\RateRecord;
use App\Models\CurrencyFx\RevaluationRun;
use App\Models\IdempotencyRecord;
use App\Models\User;
use App\Period\Application\PeriodQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class FxService
{
    public function __construct(private FxAuthorizationService $authorization, private PeriodQuery $periods, private AuditLogger $audit, private Outbox $outbox, private ApplicableRateQuery $applicableRates, private RealisedFxCalculator $calculator, private EntityReferenceQuery $entities, private SystemPostingService $posting, private ForeignCurrencyPositionQuery $positions, private AccountReferenceQuery $accounts, private RateReferenceService $rateReferences, private ApprovalPolicyQuery $approvalPolicy, private ApprovalLifecycleService $approvals) {}

    /** @param array<string, mixed> $data */
    public function addRate(User $actor, string $entityId, array $data, ?string $key, bool $approved = false): FxActionResult
    {
        if (! $this->authorization->can($actor, $entityId, 'fx.rates.manage')) {
            return $this->authorization->denied('fx.rates.manage');
        }
        if (! is_string($key) || ! Str::isUuid($key)) {
            return $this->error('validation', 'Idempotency-Key must be a UUID.', 400);
        }
        if (! $approved && $this->approvalPolicy->isConfigured($entityId)) {
            $approval = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('fx_rate_create', 1, ['data' => $data, 'idempotency_key' => $key], null, 'fx.rates.manage'), 'POST /v1/fx/rates', $key, (string) request()->attributes->get('correlation_id'));

            return new FxActionResult($approval->payload, $approval->status, $approval->headers);
        }
        if ($data['base_currency'] === $data['quote_currency']) {
            return $this->error('invariant_violation', 'Currencies must be distinct.', 422, ['rule' => 'invalid_currency_pair']);
        }
        if (($data['is_override'] ?? false) && trim((string) ($data['override_reason'] ?? '')) === '') {
            return $this->error('invariant_violation', 'An override reason is required.', 422, ['rule' => 'override_reason_required']);
        }
        $sources = config('valuation.fx.sources');
        if (! is_array($sources) || $sources === [] || ! in_array($data['source'], $sources, true)) {
            return $this->error('invariant_violation', 'The FX source is not configured.', 422, ['rule' => 'missing_fx_source_configuration']);
        }
        $operation = 'POST /v1/fx/rates';
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $replay = $this->replay($actor->id, $entityId, $operation, $key, $hash);
        if ($replay !== null) {
            return $replay;
        }
        try {
            return DB::transaction(function () use ($actor, $entityId, $data, $key, $operation, $hash): FxActionResult {
                $rate = RateRecord::query()->create([...$data, 'entity_id' => $entityId]);
                $presented = self::presentRate($rate);
                $this->audit->record('fx', 'rate_record_added', 'rate_record', $rate->id, $actor->id, $entityId, after: $presented);
                $this->outbox->record('RateRecordAdded', 'RateRecord', $rate->id, ['rateId' => $rate->id, 'pair' => $rate->base_currency.'/'.$rate->quote_currency, 'rate' => $rate->rate, 'effectiveDate' => $rate->effective_date->toDateString(), 'source' => $rate->source], $entityId);
                $body = ['rate_record' => $presented];
                $this->store($actor->id, $entityId, $operation, $key, $hash, 201, $body);

                return new FxActionResult($body, 201);
            });
        } catch (UniqueConstraintViolationException) {
            return $this->error('duplicate_resource', 'The rate record already exists.', 409);
        }
    }

    /** @param array<string, mixed> $filters */
    public function rates(User $actor, string $entityId, array $filters, int $limit, mixed $cursor): FxActionResult
    {
        if (! $this->authorization->can($actor, $entityId, 'fx.rates.read')) {
            return $this->authorization->denied('fx.rates.read');
        }
        if (($filters['base_currency'] === null) !== ($filters['quote_currency'] === null)) {
            return $this->error('validation', 'base_currency and quote_currency must be supplied together.', 400);
        }
        $binding = ['entity_id' => $entityId, 'filters' => $filters, 'order' => 'effective_date_desc,id_desc'];
        try {
            [$decodedCursor, $boundary] = StableCursor::decode(is_string($cursor) ? $cursor : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->error('validation', $exception->getMessage(), 400);
        }
        $page = RateRecord::query()->where('entity_id', $entityId)->where('created_at', '<=', $boundary)
            ->when($filters['base_currency'], fn ($q, $v) => $q->where('base_currency', $v))->when($filters['quote_currency'], fn ($q, $v) => $q->where('quote_currency', $v))
            ->when($filters['effective_from'], fn ($q, $v) => $q->whereDate('effective_date', '>=', $v))->when($filters['effective_to'], fn ($q, $v) => $q->whereDate('effective_date', '<=', $v))
            ->when($filters['source'], fn ($q, $v) => $q->where('source', $v))->when($filters['referenced'] !== null, fn ($q) => $q->where('referenced', $filters['referenced']))
            ->orderByDesc('effective_date')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $decodedCursor);

        return new FxActionResult(['rate_records' => $page->getCollection()->map(fn (RateRecord $rate) => self::presentRate($rate))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    /** @param array<string, mixed> $data */
    public function revalue(User $actor, string $entityId, array $data, ?string $key, bool $approved = false): FxActionResult
    {
        if (! $this->authorization->can($actor, $entityId, 'fx.revaluation.run')) {
            return $this->authorization->denied('fx.revaluation.run');
        }
        if (! is_string($key) || ! Str::isUuid($key)) {
            return $this->error('validation', 'Idempotency-Key must be a UUID.', 400);
        }
        if (! $approved && $this->approvalPolicy->isConfigured($entityId)) {
            $approval = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('fx_revaluation_run', 1, ['data' => $data, 'idempotency_key' => $key], null, 'fx.revaluation.run'), 'POST /v1/fx/revaluation', $key, (string) request()->attributes->get('correlation_id'));

            return new FxActionResult($approval->payload, $approval->status, $approval->headers);
        }
        $operation = 'POST /v1/fx/revaluation';
        $hash = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
        $replay = $this->replay($actor->id, $entityId, $operation, $key, $hash);
        if ($replay !== null) {
            return $replay;
        }
        $period = $this->periods->show($entityId, $data['period_ref']);
        if ($period === null || $period->state !== 'SoftClosed') {
            return $this->error('period_locked', 'Revaluation requires a Soft Close period.', 423);
        }
        foreach (['source_precedence', 'rounding_mode', 'rounding_scale', 'unrealised_gain_account_id', 'unrealised_loss_account_id'] as $setting) {
            if (config('valuation.fx.'.$setting) === null || config('valuation.fx.'.$setting) === []) {
                return $this->error('invariant_violation', 'Required revaluation policy is not configured.', 422, ['rule' => 'missing_period_end_rate', 'configuration' => $setting]);
            }
        }
        foreach (['unrealised_gain_account_id', 'unrealised_loss_account_id'] as $setting) {
            if (! $this->accounts->isOwnedByEntity($entityId, (string) config('valuation.fx.'.$setting))) {
                return $this->error('invariant_violation', 'The configured revaluation account does not belong to the entity.', 422, ['rule' => 'invalid_revaluation_account', 'configuration' => $setting]);
            }
        }
        if (RevaluationRun::query()->where('entity_id', $entityId)->where('period_ref', $period->period_ref)->exists()) {
            return $this->error('duplicate_resource', 'A revaluation run already exists for the period.', 409, ['rule' => 'revaluation_already_exists']);
        }
        $functionalCurrency = $this->entities->functionalCurrency($entityId);
        if ($functionalCurrency === null) {
            return $this->error('invariant_violation', 'Entity functional currency is not configured.', 422);
        }
        $valuation = $this->buildBankRevaluation($entityId, $period->ends_on->toDateString(), $functionalCurrency);
        if ($valuation === null) {
            return $this->error('invariant_violation', 'A required period-end rate is missing.', 422, ['rule' => 'missing_period_end_rate']);
        }
        $result = DB::transaction(function () use ($actor, $entityId, $period, $valuation, $operation, $key, $hash): FxActionResult {
            $target = $this->periods->findForDate($entityId, $period->ends_on->addDay()->toDateString());
            $run = RevaluationRun::query()->create(['entity_id' => $entityId, 'period_ref' => $period->period_ref, 'status' => 'posted', 'figures' => $valuation['figures'], 'rate_record_ids' => $valuation['rate_record_ids'], 'journal_entry_ids' => [], 'reversal_status' => 'scheduled', 'target_period_ref' => $target?->period_ref, 'reversal_journal_entry_ids' => [], 'posted_at' => Carbon::now('UTC')]);
            foreach ($valuation['rate_record_ids'] as $rateRecordId) {
                $this->rateReferences->markReferenced($entityId, $rateRecordId);
            }
            $journalIds = [];
            if ($valuation['lines'] !== []) {
                $journalIds[] = $this->posting->postRevaluation($entityId, $period->id, $period->period_ref, $period->ends_on->toDateString(), $run->id, $actor->id, $valuation['lines']);
            }
            $run->journal_entry_ids = $journalIds;
            $run->save();
            $this->audit->record('fx', 'revaluation_run_posted', 'revaluation_run', $run->id, $actor->id, $entityId, after: self::presentRun($run));
            $this->outbox->record('UnrealisedFXRevalued', 'RevaluationRun', $run->id, ['runId' => $run->id, 'periodRef' => $run->period_ref, 'figures' => $run->figures], $entityId);
            $body = ['revaluation_run' => self::presentRun($run)];
            $this->store($actor->id, $entityId, $operation, $key, $hash, 201, $body);

            return new FxActionResult($body, 201);
        });

        return $result;
    }

    public function revaluations(User $actor, string $entityId, string $periodRef, ?string $status): FxActionResult
    {
        if (! $this->authorization->can($actor, $entityId, 'fx.revaluation.read')) {
            return $this->authorization->denied('fx.revaluation.read');
        }
        $runs = RevaluationRun::query()->where('entity_id', $entityId)->where('period_ref', $periodRef)->when($status, fn ($q, $v) => $q->where('status', $v))->get();

        return new FxActionResult(['revaluation_runs' => $runs->map(fn (RevaluationRun $run) => self::presentRun($run))->all()]);
    }

    public function reverseRevaluation(string $entityId, string $runId, string $actorId): bool
    {
        $run = RevaluationRun::query()->where('entity_id', $entityId)->whereKey($runId)->first();
        if (! $run instanceof RevaluationRun || $run->status !== 'posted' || $run->reversal_status !== 'scheduled' || $run->target_period_ref === null) {
            return false;
        }
        $target = $this->periods->show($entityId, $run->target_period_ref);
        if ($target === null || $target->state !== 'Open') {
            return false;
        }
        DB::transaction(function () use ($run, $entityId, $actorId, $target): void {
            $ids = $this->posting->reverseRevaluation($entityId, $target->id, $target->period_ref, $target->starts_on->toDateString(), $run->id, $actorId, $run->journal_entry_ids);
            $run->status = 'reversed';
            $run->reversal_status = 'posted';
            $run->reversal_journal_entry_ids = $ids;
            $run->reversed_at = Carbon::now('UTC');
            $run->version++;
            $run->save();
            $this->audit->record('fx', 'revaluation_reversed', 'revaluation_run', $run->id, $actorId, $entityId, after: self::presentRun($run));
            $this->outbox->record('RevaluationReversed', 'RevaluationRun', $run->id, ['runId' => $run->id], $entityId);
        });

        return true;
    }

    /** @return array<string, mixed> */
    public static function presentRate(RateRecord $rate): array
    {
        return ['id' => $rate->id, 'base_currency' => $rate->base_currency, 'quote_currency' => $rate->quote_currency, 'rate' => $rate->rate, 'effective_date' => $rate->effective_date->toDateString(), 'source' => $rate->source, 'is_override' => $rate->is_override, 'override_reason' => $rate->override_reason, 'referenced' => $rate->referenced];
    }

    /** @return array<string, mixed> */
    public static function presentRun(RevaluationRun $run): array
    {
        return ['id' => $run->id, 'period_ref' => $run->period_ref, 'status' => $run->status, 'figures' => $run->figures, 'rate_record_ids' => $run->rate_record_ids, 'journal_entry_ids' => $run->journal_entry_ids, 'reversal' => ['status' => $run->reversal_status, 'target_period_ref' => $run->target_period_ref, 'reversal_run_id' => $run->reversal_run_id, 'journal_entry_ids' => $run->reversal_journal_entry_ids, 'reversed_at' => $run->reversed_at?->toISOString()], 'version' => $run->version, 'posted_at' => $run->posted_at?->toISOString()];
    }

    /** @return array{figures:array<int, array<string, mixed>>,rate_record_ids:array<int, string>,lines:array<int, array<string, mixed>>}|null */
    private function buildBankRevaluation(string $entityId, string $date, string $functionalCurrency): ?array
    {
        $figures = [];
        $rateIds = [];
        $postingLines = [];
        foreach ($this->positions->bankPositions($entityId, $date, $functionalCurrency) as $position) {
            $foreignCurrency = $position['foreign_currency'];
            $rate = $this->applicableRates->find($entityId, $foreignCurrency, $functionalCurrency, $date);
            if ($rate === null) {
                return null;
            }
            $calculated = $this->calculator->calculate($position['foreign_amount'], '0.00000000', (string) $rate['rate'], (int) config('valuation.fx.rounding_scale'), (string) config('valuation.fx.rounding_mode'));
            $difference = $this->calculator->subtract($calculated['settlement_functional'], $position['functional_amount'], (int) config('valuation.fx.rounding_scale'));
            if ($this->calculator->isZero($difference, (int) config('valuation.fx.rounding_scale'))) {
                continue;
            }
            $gain = ! str_starts_with($difference, '-');
            $amount = ltrim($difference, '-');
            $offset = (string) config($gain ? 'valuation.fx.unrealised_gain_account_id' : 'valuation.fx.unrealised_loss_account_id');
            $common = ['currency' => $functionalCurrency, 'fx_amount' => null, 'fx_currency' => null, 'rate_record_id' => $rate['id'], 'fx_rate' => $rate['rate'], 'fx_rate_effective_date' => $rate['effective_date']];
            $postingLines[] = [...$common, 'account_id' => $position['account_id'], 'description' => 'FX revaluation', 'debit' => $gain ? $amount : '0.0000', 'credit' => $gain ? '0.0000' : $amount];
            $postingLines[] = [...$common, 'account_id' => $offset, 'description' => 'Unrealised FX '.($gain ? 'gain' : 'loss'), 'debit' => $gain ? '0.0000' : $amount, 'credit' => $gain ? $amount : '0.0000'];
            $figures[] = ['account_id' => $position['account_id'], 'amount' => ['amount' => $difference, 'currency' => $functionalCurrency]];
            $rateIds[] = (string) $rate['id'];
        }

        return ['figures' => $figures, 'rate_record_ids' => array_values(array_unique($rateIds)), 'lines' => $postingLines];
    }

    private function replay(string $actorId, string $entityId, string $operation, string $key, string $hash): ?FxActionResult
    {
        return $this->replayRecord($actorId, $entityId, $operation, $key, $hash);
    }

    private function replayRecord(string $actorId, string $entityId, string $operation, string $key, string $hash): ?FxActionResult
    {
        $record = IdempotencyRecord::query()->where('actor_id', $actorId)->where('entity_id', $entityId)->where('operation', $operation)->where('idempotency_key', $key)->first();
        if ($record === null) {
            return null;
        }
        if ($record->request_hash !== $hash) {
            return $this->error('idempotency_conflict', 'The idempotency key was used for another request.', 409);
        }

        return new FxActionResult($record->response_body, $record->response_status, ['Idempotent-Replay' => 'true']);
    }

    /** @param array<string, mixed> $body */
    private function store(string $actorId, string $entityId, string $operation, string $key, string $hash, int $status, array $body): void
    {
        IdempotencyRecord::query()->create(['actor_id' => $actorId, 'entity_id' => $entityId, 'operation' => $operation, 'idempotency_key' => $key, 'request_hash' => $hash, 'response_status' => $status, 'response_body' => $body]);
    }

    /** @param array<string, mixed> $details */
    private function error(string $code, string $message, int $status, array $details = []): FxActionResult
    {
        return new FxActionResult(['error_code' => $code, 'message' => $message, 'details' => $details], $status);
    }
}
