<?php

namespace App\Payables\Application;

use App\CurrencyFx\Application\RateReferenceService;
use App\Identity\Application\ApprovalExecutionContext;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\RecognitionPostingService;
use App\Models\Payables\Bill;
use App\Models\Payables\DebitNote;
use App\Models\Payables\DebitNoteLine;
use App\Models\User;
use App\Period\Application\PeriodQuery;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\DocumentNumberService;
use App\Support\Documents\ExactDecimal;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use App\Tax\Application\DocumentTaxService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

/**
 * M4A — Debit Note create/edit/detail/list/post. Mirror of CreditNoteService with
 * vendor/bill direction (API Contracts §12.4.1-12.4.5). Disposition lives in
 * DebitNoteDispositionService.
 */
final readonly class DebitNoteService
{
    public function __construct(
        private DocumentCommandSupport $commands,
        private DebitNoteRepository $notes,
        private DebitNoteQuery $query,
        private DocumentTaxService $taxes,
        private PeriodQuery $periods,
        private DocumentNumberService $numbers,
        private RecognitionPostingService $posting,
        private RateReferenceService $rates,
        private ApprovalPolicyQuery $approvalPolicy,
        private ApprovalLifecycleService $approvals,
        private AuditLogger $audit,
        private Outbox $outbox,
    ) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'payables.debit_notes.create')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $op = 'POST /v1/debit-notes';
        $hash = $this->commands->hash($data);
        if ($replay = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $replay;
        }
        $prepared = $this->prepare($entityId, $data);
        if ($prepared instanceof DocumentActionResult) {
            return $prepared;
        }

        return DB::transaction(function () use ($actor, $entityId, $data, $prepared, $key, $op, $hash): DocumentActionResult {
            $note = $this->notes->addDraft([
                'entity_id' => $entityId, 'provisional_token' => (string) Str::uuid(), 'vendor_id' => $prepared['bill']->vendor_id,
                'source_bill_id' => $data['source_document_id'], 'source_document_expected_version' => $data['source_document_expected_version'],
                'note_date' => $data['note_date'], 'currency' => $prepared['bill']->currency, 'reason_code' => $data['reason_code'],
                'narrative' => $data['narrative'] ?? null, 'source_rate_record_id' => $prepared['bill']->rate_record_id,
                'source_exchange_rate_reference' => $prepared['bill']->exchange_rate_reference, 'proposed_total' => $prepared['proposed_total'],
                'period_ref' => $prepared['period_ref'], 'posted_amount' => '0.0000', 'applied_amount' => '0.0000', 'refunded_amount' => '0.0000',
                'held_remaining_amount' => '0.0000', 'undisposed_amount' => '0.0000', 'state' => 'draft', 'version' => 1, 'created_by' => $actor->id,
            ], $prepared['lines']);
            $body = ['debit_note' => $this->present($note)];
            $this->audit->record('payables', 'debit_note_draft_created', 'debit_note', $note->id, $actor->id, $entityId, after: $this->safe($note), correlationId: $this->correlation());
            $this->outbox->record('DebitNoteCreated', 'DebitNote', $note->id, ['debitNoteId' => $note->id, 'partyId' => $note->vendor_id, 'sourceDocumentId' => $note->source_bill_id], $entityId);
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'payables.debit_notes.create')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $op = 'PATCH /v1/debit-notes/'.$id;
        $hash = $this->commands->hash([$data, $expected]);
        if ($replay = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $replay;
        }
        $note = $this->notes->getById($entityId, $id);
        if ($note === null) {
            return $this->notFound();
        }
        if ($note->state !== 'draft') {
            return $this->commands->error('invariant_violation', 'Only draft debit notes may be updated.', 422, ['rule' => 'note_not_draft']);
        }
        if ($note->version !== $expected) {
            return $this->conflict($note->version);
        }
        $merged = [...$this->requestData($note), ...$data];
        $prepared = $this->prepare($entityId, $merged);
        if ($prepared instanceof DocumentActionResult) {
            return $prepared;
        }

        return DB::transaction(function () use ($actor, $entityId, $note, $merged, $prepared, $expected, $key, $op, $hash): DocumentActionResult {
            $before = $this->safe($note);
            $saved = $this->notes->saveDraft($entityId, $note->id, [
                'vendor_id' => $prepared['bill']->vendor_id, 'source_bill_id' => $merged['source_document_id'],
                'source_document_expected_version' => $merged['source_document_expected_version'], 'note_date' => $merged['note_date'],
                'currency' => $prepared['bill']->currency, 'reason_code' => $merged['reason_code'], 'narrative' => $merged['narrative'] ?? null,
                'source_rate_record_id' => $prepared['bill']->rate_record_id, 'source_exchange_rate_reference' => $prepared['bill']->exchange_rate_reference,
                'proposed_total' => $prepared['proposed_total'], 'period_ref' => $prepared['period_ref'],
            ], $prepared['lines'], $expected);
            if ($saved === null) {
                return $this->conflict((int) $this->notes->getById($entityId, $note->id)?->version);
            }
            $body = ['debit_note' => $this->present($saved)];
            $this->audit->record('payables', 'debit_note_draft_updated', 'debit_note', $saved->id, $actor->id, $entityId, $before, $this->safe($saved), correlationId: $this->correlation());
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    public function post(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'payables.debit_notes.post')) {
            return $denied;
        }
        if ($error = $this->commands->requireIdempotency($key)) {
            return $error;
        }
        $expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }
        $preflight = $this->preflightPost($entityId, $id, $expected);
        if ($preflight !== null) {
            return $preflight;
        }
        if ($this->approvalPolicy->isConfigured($entityId)) {
            $payload = ['resource_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key];
            $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('debit_note_post', 1, $payload, $id, 'payables.debit_notes.post', $expected), 'debit_note_post:'.$id, (string) $key, $this->correlation() ?? (string) Str::uuid());

            return new DocumentActionResult($result->payload, $result->status, $result->headers);
        }
        $note = $this->notes->getById($entityId, $id);
        if ($note !== null && $note->created_by === $actor->id) {
            return $this->commands->error('sod_exception_required', 'The debit note maker cannot directly post the debit note.', 403);
        }

        return $this->executePost($entityId, $id, $expected, (string) $key, $this->commands->hash([$id, $expected]), $actor->id, $actor->id, (string) $key);
    }

    /** @param array<string,mixed> $payload */
    public function executeApproved(string $type, array $payload, ApprovalExecutionContext $context): DocumentActionResult
    {
        $id = $payload['resource_id'] ?? null;
        $expected = $payload['expected_version'] ?? null;
        $key = $payload['idempotency_key'] ?? null;
        if (! is_string($id) || ! is_int($expected) || ! is_string($key)) {
            return $this->commands->error('validation', 'The approved debit note payload is invalid.', 400);
        }
        $hash = $this->commands->hash([$id, $expected]);

        return match ($type) {
            'post' => $this->executePost($context->entityId, $id, $expected, $key, $hash, $context->makerId, $context->approverId, $context->causationId),
            default => $this->commands->error('validation', 'Unsupported DebitNote command.', 400),
        };
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'payables.debit_notes.read')) {
            return $denied;
        }
        $note = $this->query->getDetail($entityId, $id);

        return $note ? new DocumentActionResult(['debit_note' => $this->presentDetail($note)]) : $this->notFound();
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($denied = $this->commands->authorize($actor, $entityId, 'payables.debit_notes.read')) {
            return $denied;
        }
        $limit = (int) ($filters['limit'] ?? 50);
        $binding = ['entity_id' => $entityId, 'filters' => $filters, 'order' => 'note_date_desc,id_desc'];
        try {
            [$cursor, $boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $exception) {
            return $this->commands->error('validation', $exception->getMessage(), 400);
        }
        unset($filters['limit'], $filters['cursor']);
        $page = $this->query->search($entityId, $filters, $cursor, $limit);

        return new DocumentActionResult(['debit_notes' => $page->getCollection()->map(fn (DebitNote $n): array => $this->summary($n))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    private function preflightPost(string $entityId, string $id, int $expected): ?DocumentActionResult
    {
        $note = $this->notes->getById($entityId, $id);
        if ($note === null) {
            return $this->notFound();
        }
        if ($note->state !== 'draft') {
            return $this->commands->error('invariant_violation', 'Only draft debit notes may be posted.', 422, ['rule' => 'note_not_draft']);
        }
        if ($note->version !== $expected) {
            return $this->conflict($note->version);
        }

        return null;
    }

    private function executePost(string $entityId, string $id, int $expected, string $key, string $hash, string $makerId, string $executorId, string $causationId): DocumentActionResult
    {
        if ($replay = $this->commands->replay($makerId, $entityId, 'debit_note_post:'.$id, $key, $hash)) {
            return $replay;
        }
        $note = $this->notes->getById($entityId, $id);
        if ($note === null) {
            return $this->notFound();
        }
        if ($note->state !== 'draft' || $note->version !== $expected) {
            return $note->state !== 'draft' ? $this->commands->error('invariant_violation', 'Only draft debit notes may be posted.', 422, ['rule' => 'note_not_draft']) : $this->conflict($note->version);
        }
        $bill = Bill::query()->where('entity_id', $entityId)->find($note->source_bill_id);
        if ($bill === null || $bill->version !== $note->source_document_expected_version) {
            return $this->commands->error('invariant_violation', 'The source bill has changed since the draft was created.', 422, ['rule' => 'source_document_version_conflict']);
        }
        $creditAccount = config('settlement.accounts.vendor_credit');
        if (! is_string($creditAccount) || ! Str::isUuid($creditAccount)) {
            return $this->commands->error('missing_posting_configuration', 'Debit note account mapping is unavailable.', 422);
        }
        $draw = $this->numbers->draw('debit_note', $entityId, (string) $note->note_date->toDateString());
        if ($draw === null) {
            return $this->commands->error('missing_numbering_configuration', 'Debit note numbering configuration is unavailable.', 422);
        }
        try {
            return DB::transaction(function () use ($note, $entityId, $creditAccount, $draw, $expected, $key, $hash, $makerId, $executorId, $causationId): DocumentActionResult {
                $reference = $note->source_exchange_rate_reference;
                $postingLines = $this->postingLines($note, $creditAccount, $reference);
                if ($postingLines === null) {
                    return $this->commands->error('missing_posting_configuration', 'Expense or tax account mapping is unavailable.', 422);
                }
                $posted = $this->posting->post($entityId, $note->id, $note->note_date->toDateString(), 'debit_note', $executorId, $postingLines);
                if ($posted->errorCode !== null || $posted->journalId === null) {
                    return $this->postingError($posted->errorCode);
                }
                $totalStr = ExactDecimal::normalize((string) $note->lines->sum('total_amount'));
                $updated = $this->notes->commitPost($entityId, $note->id, [
                    'document_number' => $draw['number'], 'provisional_token' => null, 'posted_amount' => $totalStr, 'applied_amount' => '0.0000',
                    'refunded_amount' => '0.0000', 'held_remaining_amount' => '0.0000', 'undisposed_amount' => $totalStr,
                    'journal_entry_ids' => [$posted->journalId], 'state' => 'posted',
                ], $expected);
                if ($updated === null) {
                    return $this->conflict((int) $this->notes->getById($entityId, $note->id)?->version);
                }
                if ($reference) {
                    $this->rates->markReferenced($entityId, (string) $reference['rate_record_id']);
                }
                foreach ($note->lines as $line) {
                    $this->taxes->markReferenced($entityId, $line->tax_snapshot);
                }
                $body = ['debit_note' => $this->present($updated)];
                $this->audit->record('payables', 'debit_note_posted', 'debit_note', $updated->id, $executorId, $entityId, before: ['state' => 'draft', 'version' => $expected], after: $this->safe($updated), metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
                $this->outbox->record('DebitNoteIssued', 'DebitNote', $updated->id, ['partyType' => 'vendor', 'documentType' => 'bill', 'partyId' => $updated->vendor_id, 'sourceDocumentId' => $updated->source_bill_id, 'documentNumber' => $updated->document_number, 'postedAmount' => $this->money($updated->posted_amount, $updated->currency), 'appliedAmount' => $this->money('0.0000', $updated->currency), 'refundedAmount' => $this->money('0.0000', $updated->currency), 'heldRemainingAmount' => $this->money('0.0000', $updated->currency), 'undisposedAmount' => $this->money($updated->undisposed_amount, $updated->currency), 'periodRef' => $updated->period_ref, 'sourceRateRecordId' => $updated->source_rate_record_id, 'journalEntryIds' => $updated->journal_entry_ids], $entityId, 2, ['causation_id' => $causationId]);
                $this->commands->store($makerId, $entityId, 'debit_note_post:'.$updated->id, $key, $hash, 201, $body);

                return new DocumentActionResult($body, 201);
            });
        } catch (Throwable $throwable) {
            $this->numbers->void($draw);
            throw $throwable;
        }
    }

    /** @param array<string,mixed>|null $reference
     * @return list<array<string,mixed>>|null
     */
    private function postingLines(DebitNote $note, string $creditAccount, ?array $reference): ?array
    {
        $groups = [];
        foreach ($note->lines as $line) {
            $expenseAmount = $line->total_amount;
            $snapshot = $line->tax_snapshot;
            if (is_array($snapshot) && ($snapshot['recoverable'] ?? false) === true && $line->tax_amount !== '0.0000') {
                $expenseAmount = $line->net_amount;
                $inputAccount = $snapshot['gl_mapping']['input_account_id'] ?? null;
                if (! is_string($inputAccount) || ! Str::isUuid($inputAccount)) {
                    return null;
                }
                $groups[$inputAccount] = ExactDecimal::add($groups[$inputAccount] ?? '0.0000', $line->tax_amount);
            }
            $account = $line->expense_account_id;
            if (! is_string($account) || ! Str::isUuid($account)) {
                return null;
            }
            $groups[$account] = ExactDecimal::add($groups[$account] ?? '0.0000', $expenseAmount);
        }
        $lines = [];
        $functionalSum = '0.0000';
        foreach ($groups as $account => $amount) {
            $functional = $this->functional($amount, $reference);
            if ($functional === null) {
                return null;
            }
            $functionalSum = ExactDecimal::add($functionalSum, $functional);
            $lines[] = $this->postingLine($account, 'Debit note expense/tax reversal', '0.0000', $functional, $note->currency, $amount, $reference);
        }
        $total = ExactDecimal::normalize((string) $note->lines->sum('total_amount'));
        $lines[] = $this->postingLine($creditAccount, 'Vendor credit issued', $functionalSum, '0.0000', $note->currency, $total, $reference);

        return $lines;
    }

    /** @param array<string,mixed>|null $reference */
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

    /** @param array<string,mixed>|null $reference
     * @return array<string,mixed>
     */
    private function postingLine(string $account, string $description, string $debit, string $credit, string $documentCurrency, string $foreignAmount, ?array $reference): array
    {
        return ['account_id' => $account, 'description' => $description, 'debit' => $debit, 'credit' => $credit, 'currency' => $reference['quote_currency'] ?? $documentCurrency, 'fx_amount' => $reference ? $foreignAmount : null, 'fx_currency' => $reference ? $documentCurrency : null, 'rate_record_id' => $reference['rate_record_id'] ?? null, 'fx_rate' => $reference['rate'] ?? null, 'fx_rate_effective_date' => $reference['effective_date'] ?? null];
    }

    /** @param array<string,mixed> $data
     * @return array{bill:Bill,lines:list<array<string,mixed>>,proposed_total:string,period_ref:?string}|DocumentActionResult
     */
    private function prepare(string $entityId, array $data): array|DocumentActionResult
    {
        $partyType = $data['party_type'] ?? 'vendor';
        $documentType = $data['document_type'] ?? 'bill';
        if ($partyType !== 'vendor' || $documentType !== 'bill') {
            return $this->commands->error('invariant_violation', 'Debit Note direction is fixed to vendor/bill.', 422, ['rule' => 'note_direction_mismatch']);
        }
        $bill = Bill::query()->with('lines')->where('entity_id', $entityId)->find($data['source_document_id']);
        if (! $bill instanceof Bill || $bill->status !== 'awaiting_payment') {
            return $this->commands->error('invariant_violation', 'The source bill must be an entity-owned, approved, non-void bill.', 422, ['rule' => 'invalid_source_document']);
        }
        if ((string) $bill->vendor_id !== (string) $data['party_id']) {
            return $this->commands->error('invariant_violation', 'The debit note party must match the source bill vendor.', 422, ['rule' => 'invalid_source_document']);
        }
        if ($bill->version !== (int) $data['source_document_expected_version']) {
            return $this->commands->error('invariant_violation', 'The source bill version has changed.', 422, ['rule' => 'source_document_version_conflict']);
        }
        $reasonCodes = config('documents.reason_codes');
        if (! is_array($reasonCodes) || ! in_array($data['reason_code'] ?? null, $reasonCodes, true)) {
            return $this->commands->error('invariant_violation', 'A configured reason_code is required.', 422, ['rule' => 'missing_reason_configuration']);
        }
        if (! is_array($data['lines'] ?? null) || $data['lines'] === []) {
            return $this->commands->error('validation', 'At least one line is required.', 400);
        }
        $sourceLines = $bill->lines->keyBy('id');
        $seen = [];
        $lines = [];
        $proposedTotal = '0.0000';
        foreach ($data['lines'] as $index => $line) {
            $sourceLineId = (string) $line['source_line_id'];
            if (isset($seen[$sourceLineId])) {
                return $this->commands->error('validation', 'source_line_id must be unique within the request.', 400);
            }
            $seen[$sourceLineId] = true;
            $sourceLine = $sourceLines->get($sourceLineId);
            if ($sourceLine === null || ! ExactDecimal::positive((string) $line['net_amount']['amount'])) {
                return $this->commands->error('invariant_violation', 'Every line must reference a source bill line with a positive net_amount.', 422, ['rule' => 'invalid_source_document']);
            }
            $netAmount = ExactDecimal::normalize((string) $line['net_amount']['amount']);
            $priorPosted = $this->priorPostedTotal($entityId, $sourceLineId);
            if (ExactDecimal::compare(ExactDecimal::add($priorPosted, $netAmount), (string) $sourceLine->line_amount) > 0) {
                return $this->commands->error('invariant_violation', 'Prior corrections plus this draft cannot exceed the source line amount.', 422, ['rule' => 'correction_exceeds_source']);
            }
            $tax = $this->applySourceSnapshot($sourceLine->tax_snapshot, $netAmount, (string) $sourceLine->line_amount, (string) $sourceLine->tax_amount);
            $total = ExactDecimal::add($netAmount, $tax);
            $proposedTotal = ExactDecimal::add($proposedTotal, $total);
            $lines[] = ['source_line_id' => $sourceLineId, 'line_no' => $index + 1, 'description' => $line['description'] ?? null, 'expense_account_id' => $sourceLine->expense_account_id, 'net_amount' => $netAmount, 'tax_snapshot' => $sourceLine->tax_snapshot, 'tax_amount' => $tax, 'total_amount' => $total];
        }
        $period = $this->periods->findForDate($entityId, (string) $data['note_date']);

        return ['bill' => $bill, 'lines' => $lines, 'proposed_total' => $proposedTotal, 'period_ref' => $period?->period_ref];
    }

    private function priorPostedTotal(string $entityId, string $sourceLineId): string
    {
        $sum = DebitNoteLine::query()->where('source_line_id', $sourceLineId)
            ->whereHas('debitNote', fn ($q) => $q->where('entity_id', $entityId)->where('state', 'posted'))
            ->sum('net_amount');

        return ExactDecimal::normalize((string) $sum);
    }

    /** @param array<string,mixed>|null $snapshot */
    private function applySourceSnapshot(?array $snapshot, string $netAmount, string $sourceNet, string $sourceTax): string
    {
        if ($snapshot === null || $sourceNet === '0.0000') {
            return '0.0000';
        }
        if (in_array($snapshot['treatment'] ?? null, ['zero_rated', 'exempt'], true)) {
            return '0.0000';
        }
        if ($netAmount === $sourceNet) {
            return $sourceTax;
        }

        return $this->taxes->taxOnNetAmount($netAmount, (string) ($snapshot['rate'] ?? '0.00000000'));
    }

    /** @return array<string,mixed> */
    private function requestData(DebitNote $note): array
    {
        return ['party_type' => 'vendor', 'document_type' => 'bill', 'party_id' => $note->vendor_id, 'source_document_id' => $note->source_bill_id, 'source_document_expected_version' => $note->source_document_expected_version, 'note_date' => $note->note_date->toDateString(), 'reason_code' => $note->reason_code, 'narrative' => $note->narrative, 'lines' => $note->lines->map(fn (DebitNoteLine $l): array => ['source_line_id' => $l->source_line_id, 'description' => $l->description, 'net_amount' => ['amount' => $l->net_amount, 'currency' => $note->currency]])->all()];
    }

    private function postingError(?string $code): DocumentActionResult
    {
        $code ??= 'unbalanced_note_posting';

        return $this->commands->error($code, $code === 'period_locked' ? 'The accounting period is not postable.' : 'Debit note posting could not be balanced.', $code === 'period_locked' ? 423 : 422);
    }

    /** @return array<string,mixed> */
    public function present(DebitNote $note): array
    {
        return [...$this->summary($note), 'provisional_token' => $note->provisional_token, 'narrative' => $note->narrative, 'exchange_rate_reference' => $note->source_exchange_rate_reference, 'lines' => $note->relationLoaded('lines') ? $note->lines->map(fn (DebitNoteLine $l): array => ['id' => $l->id, 'source_line_id' => $l->source_line_id, 'description' => $l->description, 'net_amount' => $this->money($l->net_amount, $note->currency), 'tax_snapshot' => $l->tax_snapshot, 'tax_amount' => $this->money($l->tax_amount, $note->currency), 'total_amount' => $this->money($l->total_amount, $note->currency)])->all() : []];
    }

    /** @return array<string,mixed> */
    private function presentDetail(DebitNote $note): array
    {
        return [...$this->present($note), 'applications' => $note->dispositions->flatMap(fn ($d) => $d->applications)->map(fn ($a): array => ['document_id' => $a->target_document_id, 'amount' => $this->money($a->amount, $note->currency)])->all(), 'held_credit_sources' => [], 'journal_entry_ids' => $note->journal_entry_ids ?? [], 'reversal' => $note->reversal ? ['id' => $note->reversal->id, 'original_note_id' => $note->id, 'reversal_date' => $note->reversal->reversal_date->toDateString()] : null];
    }

    /** @return array<string,mixed> */
    private function summary(DebitNote $note): array
    {
        return ['id' => $note->id, 'party_type' => 'vendor', 'document_type' => 'bill', 'party_id' => $note->vendor_id, 'source_document_id' => $note->source_bill_id, 'document_number' => $note->document_number, 'note_date' => $note->note_date->toDateString(), 'currency' => $note->currency, 'reason_code' => $note->reason_code, 'posted_amount' => $this->money($note->posted_amount, $note->currency), 'applied_amount' => $this->money($note->applied_amount, $note->currency), 'refunded_amount' => $this->money($note->refunded_amount, $note->currency), 'held_remaining_amount' => $this->money($note->held_remaining_amount, $note->currency), 'undisposed_amount' => $this->money($note->undisposed_amount, $note->currency), 'state' => $note->state, 'version' => $note->version];
    }

    /** @return array<string,mixed> */
    private function safe(DebitNote $note): array
    {
        return array_diff_key($this->present($note), array_flip(['lines']));
    }

    /** @return array<string,mixed> */
    private function money(string $amount, string $currency): array
    {
        return ['amount' => $amount, 'currency' => $currency];
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The debit note was not found.', 404);
    }

    private function conflict(int $version): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The debit note version has changed.', 'details' => [], 'required_version' => $version], 409);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
