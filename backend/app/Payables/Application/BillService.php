<?php

namespace App\Payables\Application;

use App\CurrencyFx\Application\RateReferenceService;
use App\Identity\Application\ApprovalLifecycleService;
use App\Identity\Application\ApprovalPolicyQuery;
use App\Identity\Domain\OriginatingCommand;
use App\Ledger\Application\AccountReferenceQuery;
use App\Ledger\Application\RecognitionPostingService;
use App\Models\Payables\Bill;
use App\Models\Payables\BillLine;
use App\Models\Payables\Vendor;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\DocumentNumberService;
use App\Support\Documents\DocumentValuationService;
use App\Support\Documents\ExactDecimal;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Throwable;

final readonly class BillService
{
    public function __construct(private DocumentCommandSupport $commands, private DocumentValuationService $valuation, private DocumentNumberService $numbers, private RecognitionPostingService $posting, private RateReferenceService $rates, private AccountReferenceQuery $accounts, private ApprovalPolicyQuery $approvalPolicy, private ApprovalLifecycleService $approvals, private AuditLogger $audit, private Outbox $outbox) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.bills.create')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$op = 'POST /v1/bills';
        $hash = $this->commands->hash($data);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }$prepared = $this->prepare($entityId, $data);
        if ($prepared instanceof DocumentActionResult) {
            return $prepared;
        }

        return DB::transaction(function () use ($actor, $entityId, $data, $prepared, $key, $op, $hash): DocumentActionResult {
            $bill = Bill::query()->create(['entity_id' => $entityId, 'provisional_token' => (string) Str::uuid(), 'vendor_id' => $data['vendor_id'], 'vendor_reference' => $data['vendor_reference'] ?? null, 'notes' => $data['notes'] ?? null, 'bill_date' => $data['bill_date'], 'due_date' => $prepared['due_date'], 'currency' => $data['currency'], 'rate_record_id' => $data['rate_record_id'] ?? null, 'ait' => $data['ait']['amount'] ?? null, 'vds' => $data['vds']['amount'] ?? null, 'subtotal' => $prepared['value']['subtotal'], 'tax_total' => $prepared['value']['tax_total'], 'total' => $prepared['value']['total'], 'open_balance' => '0.0000', 'status' => 'draft', 'version' => 1, 'created_by' => $actor->id]);
            $this->replaceChildren($bill, $prepared['value']['lines'], $data['sbu_allocations']);
            $bill->load(['lines', 'sbuAllocations']);
            $body = ['bill' => $this->present($bill)];
            $this->audit->record('payables', 'bill_draft_created', 'bill', $bill->id, $actor->id, $entityId, after: $this->safe($bill), correlationId: $this->correlation());
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.bills.create')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }$op = 'PATCH /v1/bills/'.$id;
        $hash = $this->commands->hash([$data, $expected]);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }$bill = Bill::query()->with(['lines', 'sbuAllocations'])->where('entity_id', $entityId)->find($id);
        if (! $bill) {
            return $this->notFound();
        }if ($bill->status !== 'draft') {
            return $this->commands->error('invariant_violation', 'Only draft bills may be updated.', 422, ['rule' => 'bill_not_draft']);
        }if ($bill->version !== $expected) {
            return $this->conflict($bill->version);
        }$merged = [...$this->requestData($bill), ...$data];
        $prepared = $this->prepare($entityId, $merged);
        if ($prepared instanceof DocumentActionResult) {
            return $prepared;
        }

        return DB::transaction(function () use ($actor, $entityId, $bill, $merged, $prepared, $expected, $key, $op, $hash): DocumentActionResult {
            $before = $this->safe($bill);
            if (Bill::query()->whereKey($bill->id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'draft')->update(['vendor_id' => $merged['vendor_id'], 'vendor_reference' => $merged['vendor_reference'] ?? null, 'notes' => $merged['notes'] ?? null, 'bill_date' => $merged['bill_date'], 'due_date' => $prepared['due_date'], 'currency' => $merged['currency'], 'rate_record_id' => $merged['rate_record_id'] ?? null, 'exchange_rate_reference' => null, 'ait' => $merged['ait']['amount'] ?? null, 'vds' => $merged['vds']['amount'] ?? null, 'subtotal' => $prepared['value']['subtotal'], 'tax_total' => $prepared['value']['tax_total'], 'total' => $prepared['value']['total'], 'version' => $expected + 1, 'updated_at' => now('UTC')]) !== 1) {
                return $this->conflict((int) Bill::query()->whereKey($bill->id)->value('version'));
            }$bill->lines()->delete();
            $bill->sbuAllocations()->delete();
            $bill->refresh();
            $this->replaceChildren($bill, $prepared['value']['lines'], $merged['sbu_allocations']);
            $bill->load(['lines', 'sbuAllocations']);
            $body = ['bill' => $this->present($bill)];
            $this->audit->record('payables', 'bill_draft_updated', 'bill', $bill->id, $actor->id, $entityId, $before, $this->safe($bill), correlationId: $this->correlation());
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    public function approve(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.bills.approve')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }$bill = Bill::query()->where('entity_id', $entityId)->find($id);
        if (! $bill) {
            return $this->notFound();
        }if ($bill->status !== 'draft') {
            return $this->commands->error('invariant_violation', 'Only draft bills may be approved.', 422, ['rule' => 'bill_not_draft']);
        }if ($bill->version !== $expected) {
            return $this->conflict($bill->version);
        }if ($this->approvalPolicy->isConfigured($entityId)) {
            $correlation = (string) request()->attributes->get('correlation_id');
            $result = $this->approvals->requestApproval($actor, $entityId, new OriginatingCommand('bill_approve', 1, ['bill_id' => $id, 'expected_version' => $expected, 'idempotency_key' => $key], $id, 'payables.bills.approve', $expected), 'POST /v1/bills/'.$id.'/approve', (string) $key, $correlation);

            return new DocumentActionResult($result->payload, $result->status, $result->headers);
        }if ($bill->created_by === $actor->id) {
            return $this->commands->error('sod_exception_required', 'The bill maker cannot directly approve the bill.', 403);
        }

        return $this->executeApproval($entityId, $id, $expected, $actor->id, $actor->id, (string) $key);
    }

    public function executeApproval(string $entityId, string $id, int $expected, string $makerId, string $approverId, string $key): DocumentActionResult
    {
        $op = 'POST /v1/bills/'.$id.'/approve';
        $hash = $this->commands->hash([$id, $expected]);
        if ($r = $this->commands->replay($makerId, $entityId, $op, $key, $hash)) {
            return $r;
        }$bill = Bill::query()->with(['lines', 'sbuAllocations'])->where('entity_id', $entityId)->find($id);
        if (! $bill) {
            return $this->notFound();
        }if ($bill->status !== 'draft') {
            return $this->commands->error('invariant_violation', 'Only draft bills may be approved.', 422, ['rule' => 'bill_not_draft']);
        }if ($bill->version !== $expected) {
            return $this->conflict($bill->version);
        }$vendor = Vendor::query()->where('entity_id', $entityId)->find($bill->vendor_id);
        if (! $vendor || $vendor->status !== 'active') {
            return $this->commands->error('vendor_inactive', 'The bill vendor must be active.', 422);
        }$request = $this->requestData($bill);
        $prepared = $this->prepare($entityId, $request);
        if ($prepared instanceof DocumentActionResult) {
            return $prepared;
        }$draw = $this->numbers->draw('bill', $entityId, $bill->bill_date->toDateString());
        if ($draw === null) {
            return $this->commands->error('missing_numbering_configuration', 'Bill numbering configuration is unavailable.', 422);
        }$result = null;
        try {
            $result = DB::transaction(function () use ($entityId, $bill, $prepared, $draw, $expected, $key, $op, $hash, $makerId, $approverId): DocumentActionResult {
                $payable = config('documents.bill.payable_account_id');
                if (! is_string($payable) || ! Str::isUuid($payable)) {
                    return $this->commands->error('missing_posting_configuration', 'Bill payable account mapping is unavailable.', 422);
                }
                $reference = $prepared['value']['rate'];
                $postingLines = $this->postingLines($bill, $prepared['value'], $payable);
                if ($postingLines === null) {
                    return $this->commands->error('missing_posting_configuration', 'Tax or expense account mapping is unavailable.', 422);
                }
                $posted = $this->posting->post($entityId, $bill->id, $bill->bill_date->toDateString(), 'bill', $approverId, $postingLines);
                if ($posted->errorCode) {
                    return $this->postingError($posted->errorCode);
                }$bill->lines()->delete();
                $this->replaceLines($bill, $prepared['value']['lines']);
                if (Bill::query()->whereKey($bill->id)->where('entity_id', $entityId)->where('status', 'draft')->where('version', $expected)->update(['document_number' => $draw['number'], 'provisional_token' => null, 'exchange_rate_reference' => $reference, 'rate_record_id' => $reference['rate_record_id'] ?? null, 'subtotal' => $prepared['value']['subtotal'], 'tax_total' => $prepared['value']['tax_total'], 'total' => $prepared['value']['total'], 'open_balance' => $prepared['value']['total'], 'journal_entry_id' => $posted->journalId, 'status' => 'awaiting_payment', 'approved_by' => $approverId, 'approved_at' => now('UTC'), 'version' => $expected + 1, 'updated_at' => now('UTC')]) !== 1) {
                    return $this->conflict((int) Bill::query()->whereKey($bill->id)->value('version'));
                }if ($reference) {
                    $this->rates->markReferenced($entityId, (string) $reference['rate_record_id']);
                }
                $this->valuation->markTaxSnapshotsReferenced($entityId, $prepared['value']['lines']);
                $bill->refresh()->load(['lines', 'sbuAllocations']);
                $body = ['bill' => $this->present($bill)];
                $this->audit->record('payables', 'bill_approved', 'bill', $bill->id, $approverId, $entityId, before: ['status' => 'draft', 'version' => $expected], after: $this->safe($bill), metadata: ['maker_id' => $makerId], correlationId: $this->correlation());
                $this->outbox->record('BillApproved', 'Bill', $bill->id, ['bill_id' => $bill->id, 'document_number' => $bill->document_number, 'vendor_id' => $bill->vendor_id, 'total' => ['amount' => $bill->total, 'currency' => $bill->currency], 'journal_entry_id' => $bill->journal_entry_id], $entityId);
                if ($bill->lines->contains(fn (BillLine $l) => $l->tax_snapshot !== null)) {
                    $this->outbox->record('TaxDetermined', 'Bill', $bill->id, ['document_id' => $bill->id, 'document_type' => 'bill'], $entityId);
                }$this->commands->store($makerId, $entityId, $op, $key, $hash, 201, $body);

                return new DocumentActionResult($body, 201);
            });
        } catch (Throwable $e) {
            $this->numbers->void($draw);
            throw $e;
        }if ($result->status >= 400) {
            $this->numbers->void($draw);
        }

        return $result;
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.bills.read')) {
            return $d;
        }$bill = Bill::query()->with(['lines', 'sbuAllocations'])->where('entity_id', $entityId)->find($id);

        return $bill ? new DocumentActionResult(['bill' => $this->present($bill)]) : $this->notFound();
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.bills.read')) {
            return $d;
        }$limit = (int) ($filters['limit'] ?? 50);
        $binding = ['entity_id' => $entityId, 'filters' => $filters, 'order' => 'bill_date_desc,id_desc'];
        try {
            [$cursor,$boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $e) {
            return $this->commands->error('validation', $e->getMessage(), 400);
        }$q = Bill::query()->where('entity_id', $entityId)->where('created_at', '<=', $boundary)->when($filters['vendor'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('bill_date', '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('bill_date', '<=', $v));
        if (array_key_exists('overdue', $filters)) {
            $today = Carbon::today('UTC')->toDateString();
            $filters['overdue'] ? $q->whereDate('due_date', '<', $today)->where('open_balance', '>', '0') : $q->where(fn ($q) => $q->whereDate('due_date', '>=', $today)->orWhere('open_balance', '<=', '0'));
        }$page = $q->orderByDesc('bill_date')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return new DocumentActionResult(['bills' => $page->getCollection()->map(fn (Bill $b) => $this->summary($b))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    /** @param array<string,mixed> $data
     * @return array{due_date:string,value:array<string,mixed>}|DocumentActionResult
     */
    private function prepare(string $entityId, array $data): array|DocumentActionResult
    {
        $vendor = Vendor::query()->where('entity_id', $entityId)->find($data['vendor_id']);
        if (! $vendor || $vendor->status !== 'active') {
            return $this->commands->error('vendor_inactive', 'The bill vendor must be active.', 422);
        }if (! in_array($data['currency'], (array) config('documents.supported_currencies'), true)) {
            return $this->commands->error('invalid_document_currency', 'The document currency is not configured.', 422);
        }foreach ($data['lines'] as $line) {
            if (! $this->accounts->isActiveExpense($entityId, (string) $line['expense_account_id'])) {
                return $this->commands->error('invalid_expense_account', 'A bill line expense account is invalid.', 422);
            }if (! ExactDecimal::positive((string) $line['quantity']) || ! ExactDecimal::positive((string) $line['unit_price']['amount']) || $line['unit_price']['currency'] !== $data['currency']) {
                return $this->commands->error('invalid_document_currency', 'Line amounts must be positive and use the document currency.', 422);
            }
        }if (! $this->validAllocations($data['sbu_allocations'])) {
            return $this->commands->error('sbu_allocation_invalid', 'SBU allocations must total exactly 1.0000.', 422);
        }foreach (['ait', 'vds'] as $field) {
            if (isset($data[$field]) && $data[$field]['currency'] !== $data['currency']) {
                return $this->commands->error('invalid_document_currency', strtoupper($field).' must use the document currency.', 422);
            }
        }$due = $data['due_date'] ?? null;
        if ($due === null) {
            $days = config('documents.payment_terms.'.$vendor->payment_terms);
            if (! is_int($days) || $days < 0) {
                return $this->commands->error('missing_payment_terms_configuration', 'Vendor payment terms are unavailable.', 422);
            }$due = Carbon::parse($data['bill_date'])->addDays($days)->toDateString();
        }if ($due < $data['bill_date']) {
            return $this->commands->error('validation', 'due_date must not precede bill_date.', 400);
        }$value = $this->valuation->value($entityId, $vendor->jurisdiction, $data['bill_date'], $data['currency'], $data['lines'], $data['rate_record_id'] ?? null);
        if ($value === null) {
            $code = $this->valuation->requiresRate($entityId, $data['currency']) ? 'missing_rate_reference' : 'missing_tax_configuration';

            return $this->commands->error($code, 'Required immutable tax or FX configuration could not be resolved.', 422);
        }

        return ['due_date' => $due, 'value' => $value];
    }

    /** @param list<array<string,mixed>> $allocations */
    private function validAllocations(array $allocations): bool
    {
        $total = '0.0000';
        $codes = [];
        try {
            foreach ($allocations as $a) {
                if (isset($codes[$a['sbu_code']]) || ! ExactDecimal::positive((string) $a['weight'])) {
                    return false;
                }$codes[$a['sbu_code']] = true;
                $total = ExactDecimal::add($total, (string) $a['weight']);
            }
        } catch (InvalidArgumentException) {
            return false;
        }

        return $total === '1.0000';
    }

    /** @param list<array<string,mixed>> $lines
     * @param  list<array<string,mixed>>  $allocations
     */
    private function replaceChildren(Bill $bill, array $lines, array $allocations): void
    {
        $this->replaceLines($bill, $lines);
        foreach ($allocations as $a) {
            $bill->sbuAllocations()->create(['entity_id' => $bill->entity_id, 'sbu_code' => $a['sbu_code'], 'weight' => ExactDecimal::normalize((string) $a['weight'])]);
        }
    }

    /** @param list<array<string,mixed>> $lines */
    private function replaceLines(Bill $bill, array $lines): void
    {
        foreach ($lines as $line) {
            $bill->lines()->create(['entity_id' => $bill->entity_id, 'line_no' => $line['line_no'], 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'expense_account_id' => $line['expense_account_id'], 'tax_code_id' => $line['tax_code_id'], 'tax_snapshot' => $line['tax_snapshot'], 'line_amount' => $line['line_amount'], 'tax_amount' => $line['tax_amount'], 'total_amount' => $line['total_amount']]);
        }
    }

    /** @return array<string,mixed> */
    private function requestData(Bill $b): array
    {
        return ['vendor_id' => $b->vendor_id, 'vendor_reference' => $b->vendor_reference, 'notes' => $b->notes, 'bill_date' => $b->bill_date->toDateString(), 'due_date' => $b->due_date->toDateString(), 'currency' => $b->currency, 'rate_record_id' => $b->rate_record_id, 'lines' => $b->lines->map(fn (BillLine $l) => ['description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => ['amount' => $l->unit_price, 'currency' => $b->currency], 'expense_account_id' => $l->expense_account_id, 'tax_code_id' => $l->tax_code_id])->all(), 'sbu_allocations' => $b->sbuAllocations->map(fn ($a) => ['sbu_code' => $a->sbu_code, 'weight' => $a->weight])->all(), 'ait' => $b->ait !== null ? ['amount' => $b->ait, 'currency' => $b->currency] : null, 'vds' => $b->vds !== null ? ['amount' => $b->vds, 'currency' => $b->currency] : null];
    }

    /**
     * @param  array<string,mixed>  $value
     * @return list<array<string,mixed>>|null
     */
    private function postingLines(Bill $bill, array $value, string $payableAccount): ?array
    {
        $documentGroups = [];
        foreach ($value['lines'] as $line) {
            $expenseAmount = $line['total_amount'];
            $snapshot = $line['tax_snapshot'];
            if (is_array($snapshot) && ($snapshot['recoverable'] ?? false) === true && $line['tax_amount'] !== '0.0000') {
                $expenseAmount = $line['line_amount'];
                $inputAccount = $snapshot['gl_mapping']['input_account_id'] ?? null;
                if (! is_string($inputAccount) || ! Str::isUuid($inputAccount)) {
                    return null;
                }
                $documentGroups[$inputAccount] = ExactDecimal::add($documentGroups[$inputAccount] ?? '0.0000', $line['tax_amount']);
            }
            $account = (string) $line['expense_account_id'];
            $documentGroups[$account] = ExactDecimal::add($documentGroups[$account] ?? '0.0000', $expenseAmount);
        }
        $reference = $value['rate'];
        $debits = [];
        $functionalSum = '0.0000';
        foreach ($documentGroups as $account => $amount) {
            $functional = $this->valuation->functional($amount, $reference);
            if ($functional === null) {
                return null;
            }
            $functionalSum = ExactDecimal::add($functionalSum, $functional);
            $debits[] = $this->postingLine($bill, $account, 'Bill expense or recoverable tax', $functional, '0.0000', $amount, $reference);
        }
        if ($debits === []) {
            return null;
        }
        $roundingDelta = ExactDecimal::subtract($value['functional_total'], $functionalSum);
        $debits[0]['debit'] = ExactDecimal::add((string) $debits[0]['debit'], $roundingDelta);
        $debits[] = $this->postingLine($bill, $payableAccount, 'Bill payable', '0.0000', $value['functional_total'], $value['total'], $reference);

        return $debits;
    }

    /**
     * @param  array<string,mixed>|null  $reference
     * @return array<string,mixed>
     */
    private function postingLine(Bill $bill, string $account, string $description, string $debit, string $credit, string $foreignAmount, ?array $reference): array
    {
        return ['account_id' => $account, 'description' => $description, 'debit' => $debit, 'credit' => $credit, 'currency' => $reference['quote_currency'] ?? $bill->currency, 'fx_amount' => $reference ? $foreignAmount : null, 'fx_currency' => $reference ? $bill->currency : null, 'rate_record_id' => $reference['rate_record_id'] ?? null, 'fx_rate' => $reference['rate'] ?? null, 'fx_rate_effective_date' => $reference['effective_date'] ?? null, 'sbu_tag' => $bill->sbuAllocations->count() === 1 ? $bill->sbuAllocations->first()->sbu_code : null];
    }

    /** @return array<string,mixed> */
    public function present(Bill $b): array
    {
        return [...$this->summary($b), 'provisional_token' => $b->provisional_token, 'vendor_reference' => $b->vendor_reference, 'notes' => $b->notes, 'lines' => $b->relationLoaded('lines') ? $b->lines->map(fn (BillLine $l) => ['id' => $l->id, 'description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => ['amount' => $l->unit_price, 'currency' => $b->currency], 'expense_account_id' => $l->expense_account_id, 'tax_code_id' => $l->tax_code_id, 'tax_snapshot' => $l->tax_snapshot, 'line_amount' => ['amount' => $l->line_amount, 'currency' => $b->currency], 'tax_amount' => ['amount' => $l->tax_amount, 'currency' => $b->currency], 'total_amount' => ['amount' => $l->total_amount, 'currency' => $b->currency]])->all() : [], 'sbu_allocations' => $b->relationLoaded('sbuAllocations') ? $b->sbuAllocations->map(fn ($a) => ['sbu_code' => $a->sbu_code, 'weight' => $a->weight])->all() : [], 'ait' => $b->ait !== null ? ['amount' => $b->ait, 'currency' => $b->currency] : null, 'vds' => $b->vds !== null ? ['amount' => $b->vds, 'currency' => $b->currency] : null, 'subtotal' => ['amount' => $b->subtotal, 'currency' => $b->currency], 'tax_total' => ['amount' => $b->tax_total, 'currency' => $b->currency], 'exchange_rate_reference' => $b->exchange_rate_reference, 'journal_entry_id' => $b->journal_entry_id, 'created_at' => $b->created_at?->toISOString(), 'updated_at' => $b->updated_at?->toISOString()];
    }

    /** @return array<string,mixed> */
    private function summary(Bill $b): array
    {
        return ['id' => $b->id, 'document_number' => $b->document_number, 'vendor_id' => $b->vendor_id, 'bill_date' => $b->bill_date->toDateString(), 'due_date' => $b->due_date->toDateString(), 'currency' => $b->currency, 'total' => ['amount' => $b->total, 'currency' => $b->currency], 'open_balance' => ['amount' => $b->open_balance, 'currency' => $b->currency], 'status' => $b->status, 'version' => $b->version];
    }

    /** @return array<string,mixed> */
    private function safe(Bill $b): array
    {
        return array_diff_key($this->present($b), array_flip(['notes', 'lines']));
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The bill was not found.', 404);
    }

    private function conflict(int $v): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The bill version has changed.', 'details' => [], 'required_version' => $v], 409);
    }

    private function postingError(string $code): DocumentActionResult
    {
        return $this->commands->error($code, $code === 'period_locked' ? 'The accounting period is not postable.' : 'Bill recognition could not be posted.', $code === 'period_locked' ? 423 : 422);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
