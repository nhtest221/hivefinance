<?php

namespace App\Reporting\Application;

use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Application\EntityReferenceQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\LedgerActionResult;
use App\Models\Reporting\ReportRun;
use App\Models\User;
use App\Period\Application\PeriodQuery;
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
 * M5A/M5B — ReportRun generation, retrieval, listing, and durable four-eyes approval
 * (API Contracts §13.4; Aggregate Design §16; Repository Contracts §3.2).
 */
final readonly class ReportRunService
{
    private const array REPORT_TYPES = ['trial_balance', 'general_ledger', 'profit_and_loss', 'balance_sheet', 'ar_ageing', 'ap_ageing', 'tax_summary', 'fx_revaluation', 'cash_view'];

    public function __construct(
        private DocumentCommandSupport $commands,
        private ReportRunRepository $runs,
        private ApprovalPolicyQuery $approvalPolicy,
        private ApprovalLifecycleService $approvals,
        private AuditLogger $audit,
        private Outbox $outbox,
        private EntityReferenceQuery $entities,
        private PeriodQuery $periods,
        private SourceDataWatermarkCalculator $watermarks,
        private TrialBalanceQuery $trialBalanceQuery,
        private GeneralLedgerQuery $generalLedgerQuery,
        private ProfitAndLossQuery $profitAndLossQuery,
        private BalanceSheetQuery $balanceSheetQuery,
        private ARAgeingQuery $arAgeingQuery,
        private APAgeingQuery $apAgeingQuery,
        private TaxSummaryQuery $taxSummaryQuery,
        private FXRevaluationQuery $fxRevaluationQuery,
        private CashViewQuery $cashViewQuery,
    ) {}

    /** @param array<string, mixed> $data */
    public function generate(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.report_runs.generate')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $reportType = $data['report_type'] ?? null;
        if (! is_string($reportType) || ! in_array($reportType, self::REPORT_TYPES, true)) {
            return $this->commands->error('validation', 'report_type must be one of the frozen M5 report types.', 400);
        }
        $op = 'POST /v1/report-runs';
        $hash = $this->commands->hash($data);
        if ($replay = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $replay;
        }

        $filters = is_array($data['filters'] ?? null) ? $data['filters'] : [];
        $periodRef = isset($data['period_ref']) && is_string($data['period_ref']) ? $data['period_ref'] : null;
        $asOf = isset($data['as_of']) && is_string($data['as_of']) ? $data['as_of'] : null;

        $computed = $this->compute($actor, $entityId, $reportType, $periodRef, $asOf, $filters);
        if ($computed instanceof DocumentActionResult) {
            return $computed;
        }

        $watermarkTo = $asOf ?? $computed['rangeTo'] ?? $this->periods->show($entityId, (string) $periodRef)?->ends_on->toDateString();
        $watermark = $this->watermarks->forBasis($entityId, $computed['basis'], $watermarkTo);
        $currency = $this->entities->functionalCurrency($entityId) ?? '';
        $contentHash = hash('sha256', json_encode($computed['content'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($entityId, $reportType, $periodRef, $asOf, $computed, $watermark, $currency, $contentHash, $filters, $actor, $op, $key, $hash): DocumentActionResult {
            $run = $this->runs->addGenerated([
                'entity_id' => $entityId, 'report_type' => $reportType, 'period_ref' => $periodRef, 'as_of' => $asOf,
                'range_from' => $computed['rangeFrom'], 'range_to' => $computed['rangeTo'], 'basis' => $computed['basis'], 'functional_currency' => $currency,
                'filters' => $filters, 'layout_version' => $computed['layoutVersion'], 'classification_version' => $computed['classificationVersion'],
                'policy_version' => $computed['policyVersion'], 'source_data_watermark' => $watermark, 'content' => $computed['content'],
                'content_hash' => $contentHash, 'generated_by' => $actor->id, 'generated_at' => Carbon::now('UTC'),
            ]);
            $body = ['report_run' => $this->summary($run)];
            $this->audit->record('reporting', 'report_run_generated', 'report_run', $run->id, $actor->id, $entityId, after: $body['report_run'], correlationId: $this->correlation());
            $this->outbox->record('ReportRunGenerated', 'ReportRun', $run->id, ['reportRunId' => $run->id, 'reportType' => $reportType, 'entityId' => $entityId, 'periodRef' => $periodRef, 'asOf' => $asOf, 'basis' => $computed['basis'], 'sourceDataWatermark' => $watermark->toISOString(), 'contentHash' => $contentHash], $entityId);
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    public function approve(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.report_runs.approve')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $preflight = $this->preflightApprove($entityId, $id, $expected);
        if ($preflight !== null) {
            return $preflight;
        }
        if ($this->approvalPolicy->isConfigured($entityId)) {
            $payload = ['resource_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key];
            $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('report_run_approve', 1, $payload, $id, 'reporting.report_runs.approve', $expected), 'report_run_approve:'.$id, (string) $key, $this->correlation() ?? (string) Str::uuid());

            return new DocumentActionResult($result->payload, $result->status, $result->headers);
        }
        $run = $this->runs->getById($entityId, $id);
        if ($run !== null && $run->generated_by === $actor->id) {
            return $this->commands->error('sod_exception_required', 'The generator cannot approve their own ReportRun.', 403);
        }

        return $this->executeApprove($entityId, $id, $expected, actorId: $actor->id, key: (string) $key, causationId: (string) $key);
    }

    /** @param array<string,mixed> $payload */
    public function executeApproved(array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $id = $payload['resource_id'] ?? null;
        $expected = $payload['expected_version'] ?? null;
        $key = $payload['idempotency_key'] ?? null;
        if (! is_string($id) || ! is_int($expected) || ! is_string($key)) {
            return $this->commands->error('validation', 'The approved ReportRun payload is invalid.', 400);
        }

        return $this->executeApprove($context->entityId, $id, $expected, actorId: $context->approverId, key: $key, causationId: $context->causationId);
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.report_runs.read')) {
            return $denied;
        }
        $run = $this->runs->getById($entityId, $id);

        return $run === null ? $this->notFound() : new DocumentActionResult(['report_run' => $this->detail($run)]);
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'reporting.report_runs.read')) {
            return $denied;
        }
        $limit = (int) ($filters['limit'] ?? 50);
        $binding = ['entity_id' => $entityId, 'filters' => $filters, 'order' => 'generated_at_desc,id_desc'];
        try {
            [$cursor, $boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->commands->error('validation', $exception->getMessage(), 400);
        }
        unset($filters['limit'], $filters['cursor']);
        $page = $this->runs->search($entityId, $filters, $cursor, $limit);

        return new DocumentActionResult(['report_runs' => $page->getCollection()->map(fn (ReportRun $r): array => $this->summary($r))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    private function executeApprove(string $entityId, string $id, int $expected, string $actorId, string $key, string $causationId): DocumentActionResult
    {
        $op = 'report_run_approve:'.$id;
        $hash = $this->commands->hash([$id, $expected]);
        if ($replay = $this->commands->replay($actorId, $entityId, $op, $key, $hash)) {
            return $replay;
        }
        $preflight = $this->preflightApprove($entityId, $id, $expected);
        if ($preflight !== null) {
            return $preflight;
        }
        $run = $this->runs->getById($entityId, $id);
        if ($run === null) {
            return $this->notFound();
        }

        return DB::transaction(function () use ($entityId, $run, $expected, $actorId, $causationId, $key, $hash, $op): DocumentActionResult {
            $now = Carbon::now('UTC');
            $approved = $this->runs->commitApproval($entityId, $run->id, ['state' => 'Approved', 'approved_by' => $actorId, 'approved_at' => $now, 'reviewed_by' => $actorId, 'reviewed_at' => $now], $expected);
            if ($approved === null) {
                return $this->conflict((int) $this->runs->getById($entityId, $run->id)?->version);
            }
            $superseded = $this->runs->findCurrentApproved($entityId, $approved->report_type, $approved->basis, $approved->period_ref, $approved->as_of?->toDateString(), $approved->filters);
            if ($superseded !== null && $superseded->id !== $approved->id) {
                $this->runs->commitSupersession($entityId, $superseded->id, $approved->id, $superseded->version);
                $this->outbox->record('ReportRunSuperseded', 'ReportRun', $superseded->id, ['reportRunId' => $superseded->id, 'supersededByReportRunId' => $approved->id], $entityId, metadata: ['causation_id' => $causationId]);
            }
            $body = ['report_run' => $this->summary($approved)];
            $this->audit->record('reporting', 'report_run_approved', 'report_run', $approved->id, $actorId, $entityId, after: $body['report_run'], correlationId: $this->correlation());
            $this->outbox->record('ReportRunApproved', 'ReportRun', $approved->id, ['reportRunId' => $approved->id, 'approvedBy' => $actorId, 'reviewedBy' => $actorId, 'evidenceVersion' => $approved->version, 'evidenceHash' => $approved->content_hash], $entityId, metadata: ['causation_id' => $causationId]);
            $this->commands->store($actorId, $entityId, $op, $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    private function preflightApprove(string $entityId, string $id, int $expected): ?DocumentActionResult
    {
        $run = $this->runs->getById($entityId, $id);
        if ($run === null) {
            return $this->notFound();
        }
        if (in_array($run->state, ['Approved', 'Superseded'], true)) {
            return $this->commands->error('report_run_already_approved', 'This ReportRun has already been approved.', 422, ['rule' => 'report_run_already_approved']);
        }
        if ($run->state === 'Rejected') {
            return $this->commands->error('report_run_rejected', 'This ReportRun was rejected and cannot be approved.', 422, ['rule' => 'report_run_rejected']);
        }
        if ($run->version !== $expected) {
            return $this->conflict($run->version);
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{content: array<string,mixed>, basis: string, layoutVersion: int|null, classificationVersion: int|null, policyVersion: int|null, rangeFrom: string|null, rangeTo: string|null}|DocumentActionResult
     */
    private function compute(User $actor, string $entityId, string $reportType, ?string $periodRef, ?string $asOf, array $filters): array|DocumentActionResult
    {
        $sbu = isset($filters['sbu']) ? (string) $filters['sbu'] : null;

        return match ($reportType) {
            'trial_balance' => $this->fromLedgerResult($this->trialBalanceQuery->fetch($actor, $entityId, $asOf, $periodRef, $sbu), 'accrual'),
            'general_ledger' => $this->generalLedgerCompute($actor, $entityId, $filters, $sbu),
            'profit_and_loss' => $this->fromDocumentResult($this->profitAndLossQuery->fetch($actor, $entityId, (string) $periodRef, $sbu, isset($filters['basis']) ? (string) $filters['basis'] : 'accrual', isset($filters['compare_to']) ? (string) $filters['compare_to'] : null), 'accrual', hasLayout: true),
            'balance_sheet' => $this->fromDocumentResult($this->balanceSheetQuery->fetch($actor, $entityId, (string) $asOf, $sbu, isset($filters['compare_to']) ? (string) $filters['compare_to'] : null), 'accrual', hasLayout: true),
            'ar_ageing' => $this->fromDocumentResult($this->arAgeingQuery->fetch($actor, $entityId, (string) $asOf, isset($filters['customer']) ? (string) $filters['customer'] : null), 'accrual', hasBucketSet: true),
            'ap_ageing' => $this->fromDocumentResult($this->apAgeingQuery->fetch($actor, $entityId, (string) $asOf, isset($filters['vendor']) ? (string) $filters['vendor'] : null), 'accrual', hasBucketSet: true),
            'tax_summary' => $this->fromDocumentResult($this->taxSummaryQuery->fetch($actor, $entityId, (string) $periodRef), 'accrual'),
            'fx_revaluation' => $this->fromDocumentResult($this->fxRevaluationQuery->fetch($actor, $entityId, (string) $periodRef), 'accrual'),
            'cash_view' => $this->fromDocumentResult($this->cashViewQuery->fetch($actor, $entityId, (string) $periodRef, $sbu), 'cash', hasPolicy: true),
            default => $this->commands->error('validation', 'Unsupported report_type.', 400),
        };
    }

    /** @param array<string, mixed> $filters
     * @return array{content: array<string,mixed>, basis: string, layoutVersion: int|null, classificationVersion: int|null, policyVersion: int|null, rangeFrom: string|null, rangeTo: string|null}|DocumentActionResult
     */
    private function generalLedgerCompute(User $actor, string $entityId, array $filters, ?string $sbu): array|DocumentActionResult
    {
        $account = isset($filters['account']) ? (string) $filters['account'] : null;
        $range = isset($filters['range']) ? (string) $filters['range'] : null;
        if ($account === null || $range === null || ! str_contains($range, '..')) {
            return $this->commands->error('validation', 'General Ledger generation requires filters.account and filters.range.', 400);
        }
        [$from, $to] = explode('..', $range, 2);
        $result = $this->generalLedgerQuery->fetch($actor, $entityId, $account, $from !== '' ? $from : null, $to !== '' ? $to : null, 100, null, $sbu);
        $computed = $this->fromLedgerResult($result, 'accrual');
        if (is_array($computed)) {
            $computed['rangeFrom'] = $from !== '' ? $from : null;
            $computed['rangeTo'] = $to !== '' ? $to : null;
        }

        return $computed;
    }

    /** @return array{content: array<string,mixed>, basis: string, layoutVersion: int|null, classificationVersion: int|null, policyVersion: int|null, rangeFrom: string|null, rangeTo: string|null}|DocumentActionResult */
    private function fromLedgerResult(LedgerActionResult $result, string $basis): array|DocumentActionResult
    {
        if ($result->status >= 400) {
            return new DocumentActionResult($result->payload, $result->status, $result->headers);
        }

        return ['content' => $result->payload, 'basis' => $basis, 'layoutVersion' => null, 'classificationVersion' => null, 'policyVersion' => null, 'rangeFrom' => null, 'rangeTo' => null];
    }

    /** @return array{content: array<string,mixed>, basis: string, layoutVersion: int|null, classificationVersion: int|null, policyVersion: int|null, rangeFrom: string|null, rangeTo: string|null}|DocumentActionResult */
    private function fromDocumentResult(DocumentActionResult $result, string $basis, bool $hasLayout = false, bool $hasBucketSet = false, bool $hasPolicy = false): array|DocumentActionResult
    {
        if ($result->status >= 400) {
            return $result;
        }
        $layoutVersion = $hasLayout && is_int($result->payload['layout_version'] ?? null) ? $result->payload['layout_version'] : null;
        $classificationVersion = $hasLayout && is_int($result->payload['classification_version'] ?? null) ? $result->payload['classification_version'] : null;
        $policyVersion = $hasBucketSet && is_int($result->payload['bucket_set_version'] ?? null) ? $result->payload['bucket_set_version']
            : ($hasPolicy && is_int($result->payload['policy_version'] ?? null) ? $result->payload['policy_version'] : null);

        return ['content' => $result->payload, 'basis' => $basis, 'layoutVersion' => $layoutVersion, 'classificationVersion' => $classificationVersion, 'policyVersion' => $policyVersion, 'rangeFrom' => null, 'rangeTo' => null];
    }

    /** @return array<string, mixed> */
    private function summary(ReportRun $run): array
    {
        return [
            'id' => $run->id, 'report_type' => $run->report_type, 'period_ref' => $run->period_ref, 'as_of' => $run->as_of?->toDateString(),
            'basis' => $run->basis, 'state' => $run->state, 'version' => $run->version, 'content_hash' => $run->content_hash,
            'source_data_watermark' => $run->source_data_watermark->toISOString(), 'generated_by' => $run->generated_by, 'generated_at' => $run->generated_at->toISOString(),
            'reviewed_by' => $run->reviewed_by, 'reviewed_at' => $run->reviewed_at?->toISOString(), 'approved_by' => $run->approved_by, 'approved_at' => $run->approved_at?->toISOString(),
            'superseded_by_report_run_id' => $run->superseded_by_report_run_id,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(ReportRun $run): array
    {
        return [...$this->summary($run), 'entity_id' => $run->entity_id, 'functional_currency' => $run->functional_currency, 'filters' => $run->filters, 'layout_version' => $run->layout_version, 'classification_version' => $run->classification_version, 'policy_version' => $run->policy_version, 'content' => $run->content];
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The ReportRun was not found.', 404);
    }

    private function conflict(int $version): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The ReportRun version has changed.', 'details' => [], 'required_version' => $version], 409);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
