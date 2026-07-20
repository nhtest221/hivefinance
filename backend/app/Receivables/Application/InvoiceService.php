<?php

namespace App\Receivables\Application;

use App\CurrencyFx\Application\RateReferenceService;
use App\Ledger\Application\RecognitionPostingService;
use App\Models\Receivables\Customer;
use App\Models\Receivables\Invoice;
use App\Models\Receivables\InvoiceLine;
use App\Models\User;
use App\Receivables\Infrastructure\InvoicePdfRenderer;
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

final readonly class InvoiceService
{
    public function __construct(private DocumentCommandSupport $commands, private DocumentValuationService $valuation, private DocumentNumberService $numbers, private RecognitionPostingService $posting, private RateReferenceService $rates, private AuditLogger $audit, private Outbox $outbox, private InvoicePdfRenderer $pdf) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'receivables.invoices.create')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$op = 'POST /v1/invoices';
        $hash = $this->commands->hash($data);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }$prepared = $this->prepare($entityId, $data);
        if ($prepared instanceof DocumentActionResult) {
            return $prepared;
        }

        return DB::transaction(function () use ($actor, $entityId, $data, $prepared, $key, $op, $hash): DocumentActionResult {
            $invoice = Invoice::query()->create(['entity_id' => $entityId, 'provisional_token' => (string) Str::uuid(), 'customer_id' => $data['customer_id'], 'invoice_date' => $data['invoice_date'], 'due_date' => $prepared['due_date'], 'currency' => $data['currency'], 'reference' => $data['reference'] ?? null, 'notes' => $data['notes'] ?? null, 'payment_instructions_ref' => $data['payment_instructions_ref'] ?? null, 'rate_record_id' => $data['rate_record_id'] ?? null, 'subtotal' => $prepared['value']['subtotal'], 'tax_total' => $prepared['value']['tax_total'], 'total' => $prepared['value']['total'], 'open_balance' => '0.0000', 'status' => 'draft', 'version' => 1, 'created_by' => $actor->id]);
            $this->replaceLines($invoice, $prepared['value']['lines']);
            $invoice->load('lines');
            $body = ['invoice' => $this->present($invoice)];
            $this->audit->record('receivables', 'invoice_draft_created', 'invoice', $invoice->id, $actor->id, $entityId, after: $this->safe($invoice), correlationId: $this->correlation());
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    /** @param array<string,mixed> $data */
    public function update(User $actor, string $entityId, string $id, array $data, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'receivables.invoices.create')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }$op = 'PATCH /v1/invoices/'.$id;
        $hash = $this->commands->hash([$data, $expected]);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }$invoice = Invoice::query()->with('lines')->where('entity_id', $entityId)->find($id);
        if (! $invoice) {
            return $this->notFound();
        }if ($invoice->status !== 'draft') {
            return $this->commands->error('invariant_violation', 'Only draft invoices may be updated.', 422, ['rule' => 'invoice_not_draft']);
        }if ($invoice->version !== $expected) {
            return $this->conflict($invoice->version);
        }
        $merged = [...$this->requestData($invoice), ...$data];
        $prepared = $this->prepare($entityId, $merged);
        if ($prepared instanceof DocumentActionResult) {
            return $prepared;
        }

        return DB::transaction(function () use ($actor, $entityId, $invoice, $merged, $prepared, $expected, $key, $op, $hash): DocumentActionResult {
            $before = $this->safe($invoice);
            $changes = ['customer_id' => $merged['customer_id'], 'invoice_date' => $merged['invoice_date'], 'due_date' => $prepared['due_date'], 'currency' => $merged['currency'], 'reference' => $merged['reference'] ?? null, 'notes' => $merged['notes'] ?? null, 'payment_instructions_ref' => $merged['payment_instructions_ref'] ?? null, 'rate_record_id' => $merged['rate_record_id'] ?? null, 'exchange_rate_reference' => null, 'subtotal' => $prepared['value']['subtotal'], 'tax_total' => $prepared['value']['tax_total'], 'total' => $prepared['value']['total'], 'version' => $expected + 1, 'updated_at' => now('UTC')];
            if (Invoice::query()->whereKey($invoice->id)->where('entity_id', $entityId)->where('version', $expected)->where('status', 'draft')->update($changes) !== 1) {
                return $this->conflict((int) Invoice::query()->whereKey($invoice->id)->value('version'));
            }$invoice->lines()->delete();
            $invoice->refresh();
            $this->replaceLines($invoice, $prepared['value']['lines']);
            $invoice->load('lines');
            $body = ['invoice' => $this->present($invoice)];
            $this->audit->record('receivables', 'invoice_draft_updated', 'invoice', $invoice->id, $actor->id, $entityId, $before, $this->safe($invoice), correlationId: $this->correlation());
            $this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 200, $body);

            return new DocumentActionResult($body);
        });
    }

    public function issue(User $actor, string $entityId, string $id, ?string $key, ?string $ifMatch): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'receivables.invoices.issue')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$expected = $this->commands->expectedVersion($ifMatch);
        if ($expected instanceof DocumentActionResult) {
            return $expected;
        }$op = 'POST /v1/invoices/'.$id.'/issue';
        $hash = $this->commands->hash([$id, $expected]);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }$invoice = Invoice::query()->with('lines')->where('entity_id', $entityId)->find($id);
        if (! $invoice) {
            return $this->notFound();
        }if ($invoice->status !== 'draft') {
            return $this->commands->error('invariant_violation', 'Only draft invoices may be issued.', 422, ['rule' => 'invoice_not_draft']);
        }if ($invoice->version !== $expected) {
            return $this->conflict($invoice->version);
        }$customer = Customer::query()->where('entity_id', $entityId)->find($invoice->customer_id);
        if (! $customer || $customer->status !== 'active') {
            return $this->commands->error('customer_inactive', 'The invoice customer must be active.', 422);
        }
        $request = $this->requestData($invoice);
        $prepared = $this->prepare($entityId, $request);
        if ($prepared instanceof DocumentActionResult) {
            return $prepared;
        }$draw = $this->numbers->draw('invoice', $entityId, $invoice->invoice_date->toDateString());
        if ($draw === null) {
            return $this->commands->error('missing_numbering_configuration', 'Invoice numbering configuration is unavailable.', 422);
        }$result = null;
        try {
            $result = DB::transaction(function () use ($actor, $entityId, $invoice, $prepared, $draw, $expected, $key, $op, $hash): DocumentActionResult {
                $accounts = [config('documents.invoice.receivable_account_id'), config('documents.invoice.revenue_account_id')];
                if (! is_string($accounts[0]) || ! Str::isUuid($accounts[0]) || ! is_string($accounts[1]) || ! Str::isUuid($accounts[1])) {
                    return $this->commands->error('missing_posting_configuration', 'Invoice account mapping is unavailable.', 422);
                }
                $reference = $prepared['value']['rate'];
                $postingLines = $this->postingLines($invoice, $prepared['value'], $accounts[0], $accounts[1]);
                if ($postingLines === null) {
                    return $this->commands->error('missing_posting_configuration', 'Tax or revenue account mapping is unavailable.', 422);
                }
                $posted = $this->posting->post($entityId, $invoice->id, $invoice->invoice_date->toDateString(), 'invoice', $actor->id, $postingLines);
                if ($posted->errorCode !== null) {
                    return $this->postingError($posted->errorCode);
                }$invoice->lines()->delete();
                $this->replaceLines($invoice, $prepared['value']['lines']);
                $updated = Invoice::query()->whereKey($invoice->id)->where('entity_id', $entityId)->where('status', 'draft')->where('version', $expected)->update(['document_number' => $draw['number'], 'provisional_token' => null, 'exchange_rate_reference' => $reference, 'rate_record_id' => $reference['rate_record_id'] ?? null, 'subtotal' => $prepared['value']['subtotal'], 'tax_total' => $prepared['value']['tax_total'], 'total' => $prepared['value']['total'], 'open_balance' => $prepared['value']['total'], 'journal_entry_id' => $posted->journalId, 'status' => 'sent', 'version' => $expected + 1, 'updated_at' => now('UTC')]);
                if ($updated !== 1) {
                    return $this->conflict((int) Invoice::query()->whereKey($invoice->id)->value('version'));
                }if ($reference) {
                    $this->rates->markReferenced($entityId, (string) $reference['rate_record_id']);
                }
                $this->valuation->markTaxSnapshotsReferenced($entityId, $prepared['value']['lines']);
                $invoice->refresh()->load('lines');
                $body = ['invoice' => $this->present($invoice)];
                $this->audit->record('receivables', 'invoice_issued', 'invoice', $invoice->id, $actor->id, $entityId, before: ['status' => 'draft', 'version' => $expected], after: $this->safe($invoice), correlationId: $this->correlation());
                $this->outbox->record('InvoiceIssued', 'Invoice', $invoice->id, ['invoice_id' => $invoice->id, 'document_number' => $invoice->document_number, 'customer_id' => $invoice->customer_id, 'total' => ['amount' => $invoice->total, 'currency' => $invoice->currency], 'journal_entry_id' => $invoice->journal_entry_id], $entityId);
                if ($invoice->lines->contains(fn (InvoiceLine $l) => $l->tax_snapshot !== null)) {
                    $this->outbox->record('TaxDetermined', 'Invoice', $invoice->id, ['document_id' => $invoice->id, 'document_type' => 'invoice'], $entityId);
                }$this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

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
        if ($d = $this->commands->authorize($actor, $entityId, 'receivables.invoices.read')) {
            return $d;
        }$invoice = Invoice::query()->with('lines')->where('entity_id', $entityId)->find($id);

        return $invoice ? new DocumentActionResult(['invoice' => $this->present($invoice)]) : $this->notFound();
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'receivables.invoices.read')) {
            return $d;
        }$limit = (int) ($filters['limit'] ?? 50);
        $binding = ['entity_id' => $entityId, 'filters' => $filters, 'order' => 'invoice_date_desc,id_desc'];
        try {
            [$cursor,$boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $e) {
            return $this->commands->error('validation', $e->getMessage(), 400);
        }$q = Invoice::query()->where('entity_id', $entityId)->where('created_at', '<=', $boundary)->when($filters['customer'] ?? null, fn ($q, $v) => $q->where('customer_id', $v))->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('invoice_date', '<=', $v));
        if (array_key_exists('overdue', $filters)) {
            $today = Carbon::today('UTC')->toDateString();
            $filters['overdue'] ? $q->whereDate('due_date', '<', $today)->where('open_balance', '>', '0') : $q->where(fn ($q) => $q->whereDate('due_date', '>=', $today)->orWhere('open_balance', '<=', '0'));
        }$page = $q->orderByDesc('invoice_date')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return new DocumentActionResult(['invoices' => $page->getCollection()->map(fn (Invoice $i) => $this->summary($i))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
    }

    /** @return array{content:string,etag:string,number:string}|DocumentActionResult */
    public function pdf(User $actor, string $entityId, string $id): array|DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'receivables.invoices.read')) {
            return $d;
        }$invoice = Invoice::query()->where('entity_id', $entityId)->find($id);
        if (! $invoice) {
            return $this->notFound();
        }if ($invoice->status !== 'sent') {
            return $this->commands->error('invoice_not_issued', 'Only issued invoices have a PDF.', 422);
        }$content = $this->pdf->render($invoice);

        return ['content' => $content, 'etag' => '"'.hash('sha256', $content).'"', 'number' => (string) $invoice->document_number];
    }

    /** @param array<string,mixed> $data
     * @return array{customer:Customer,due_date:string,value:array<string,mixed>}|DocumentActionResult
     */
    private function prepare(string $entityId, array $data): array|DocumentActionResult
    {
        $customer = Customer::query()->where('entity_id', $entityId)->find($data['customer_id']);
        if (! $customer || $customer->status !== 'active') {
            return $this->commands->error('customer_inactive', 'The invoice customer must be active.', 422);
        }if (! in_array($data['currency'], (array) config('documents.supported_currencies'), true)) {
            return $this->commands->error('invalid_document_currency', 'The document currency is not configured.', 422);
        }foreach ($data['lines'] as $line) {
            if (! ExactDecimal::positive((string) $line['quantity']) || ! ExactDecimal::positive((string) $line['unit_price']['amount']) || $line['unit_price']['currency'] !== $data['currency']) {
                return $this->commands->error('invalid_document_currency', 'Line amounts must be positive and use the document currency.', 422);
            }
        }$due = $data['due_date'] ?? null;
        if ($due === null) {
            $days = config('documents.payment_terms.'.$customer->payment_terms);
            if (! is_int($days) || $days < 0) {
                return $this->commands->error('missing_payment_terms_configuration', 'Customer payment terms are unavailable.', 422);
            }$due = Carbon::parse($data['invoice_date'])->addDays($days)->toDateString();
        }if ($due < $data['invoice_date']) {
            return $this->commands->error('validation', 'due_date must not precede invoice_date.', 400);
        }$value = $this->valuation->value($entityId, $customer->jurisdiction, $data['invoice_date'], $data['currency'], $data['lines'], $data['rate_record_id'] ?? null);
        if ($value === null) {
            $code = ($data['currency'] !== $customer->default_currency) ? 'missing_rate_reference' : 'missing_tax_configuration';

            return $this->commands->error($code, 'Required immutable valuation configuration could not be resolved.', 422);
        }

        return ['customer' => $customer, 'due_date' => $due, 'value' => $value];
    }

    /** @param list<array<string,mixed>> $lines */
    private function replaceLines(Invoice $invoice, array $lines): void
    {
        foreach ($lines as $line) {
            $invoice->lines()->create(['entity_id' => $invoice->entity_id, 'line_no' => $line['line_no'], 'description' => $line['description'], 'quantity' => $line['quantity'], 'unit_price' => $line['unit_price'], 'tax_code_id' => $line['tax_code_id'], 'tax_snapshot' => $line['tax_snapshot'], 'line_amount' => $line['line_amount'], 'tax_amount' => $line['tax_amount'], 'total_amount' => $line['total_amount']]);
        }
    }

    /** @return array<string,mixed> */
    private function requestData(Invoice $i): array
    {
        return ['customer_id' => $i->customer_id, 'invoice_date' => $i->invoice_date->toDateString(), 'due_date' => $i->due_date->toDateString(), 'currency' => $i->currency, 'reference' => $i->reference, 'notes' => $i->notes, 'payment_instructions_ref' => $i->payment_instructions_ref, 'rate_record_id' => $i->rate_record_id, 'lines' => $i->lines->map(fn (InvoiceLine $l) => ['description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => ['amount' => $l->unit_price, 'currency' => $i->currency], 'tax_code_id' => $l->tax_code_id])->all()];
    }

    /**
     * @param  array<string,mixed>  $value
     * @return list<array<string,mixed>>|null
     */
    private function postingLines(Invoice $invoice, array $value, string $receivableAccount, string $revenueAccount): ?array
    {
        $reference = $value['rate'];
        $taxGroups = [];
        foreach ($value['lines'] as $line) {
            if ($line['tax_amount'] === '0.0000') {
                continue;
            }
            $account = $line['tax_snapshot']['gl_mapping']['output_account_id'] ?? null;
            if (! is_string($account) || ! Str::isUuid($account)) {
                return null;
            }
            $taxGroups[$account] = ExactDecimal::add($taxGroups[$account] ?? '0.0000', $line['tax_amount']);
        }
        $functionalTax = '0.0000';
        $lines = [];
        foreach ($taxGroups as $account => $amount) {
            $converted = $this->valuation->functional($amount, $reference);
            if ($converted === null) {
                return null;
            }
            $functionalTax = ExactDecimal::add($functionalTax, $converted);
            $lines[] = $this->postingLine($account, 'Invoice output tax', '0.0000', $converted, $invoice->currency, $amount, $reference);
        }
        $functionalRevenue = ExactDecimal::subtract($value['functional_total'], $functionalTax);
        array_unshift($lines, $this->postingLine($revenueAccount, 'Invoice revenue', '0.0000', $functionalRevenue, $invoice->currency, $value['subtotal'], $reference));
        array_unshift($lines, $this->postingLine($receivableAccount, 'Invoice receivable', $value['functional_total'], '0.0000', $invoice->currency, $value['total'], $reference));

        return $lines;
    }

    /**
     * @param  array<string,mixed>|null  $reference
     * @return array<string,mixed>
     */
    private function postingLine(string $account, string $description, string $debit, string $credit, string $documentCurrency, string $foreignAmount, ?array $reference): array
    {
        return ['account_id' => $account, 'description' => $description, 'debit' => $debit, 'credit' => $credit, 'currency' => $reference['quote_currency'] ?? $documentCurrency, 'fx_amount' => $reference ? $foreignAmount : null, 'fx_currency' => $reference ? $documentCurrency : null, 'rate_record_id' => $reference['rate_record_id'] ?? null, 'fx_rate' => $reference['rate'] ?? null, 'fx_rate_effective_date' => $reference['effective_date'] ?? null];
    }

    /** @return array<string,mixed> */
    public function present(Invoice $i): array
    {
        return [...$this->summary($i), 'provisional_token' => $i->provisional_token, 'reference' => $i->reference, 'notes' => $i->notes, 'payment_instructions_ref' => $i->payment_instructions_ref, 'lines' => $i->relationLoaded('lines') ? $i->lines->map(fn (InvoiceLine $l) => ['id' => $l->id, 'description' => $l->description, 'quantity' => $l->quantity, 'unit_price' => ['amount' => $l->unit_price, 'currency' => $i->currency], 'tax_code_id' => $l->tax_code_id, 'tax_snapshot' => $l->tax_snapshot, 'line_amount' => ['amount' => $l->line_amount, 'currency' => $i->currency], 'tax_amount' => ['amount' => $l->tax_amount, 'currency' => $i->currency], 'total_amount' => ['amount' => $l->total_amount, 'currency' => $i->currency]])->all() : [], 'subtotal' => ['amount' => $i->subtotal, 'currency' => $i->currency], 'tax_total' => ['amount' => $i->tax_total, 'currency' => $i->currency], 'exchange_rate_reference' => $i->exchange_rate_reference, 'journal_entry_id' => $i->journal_entry_id, 'created_at' => $i->created_at?->toISOString(), 'updated_at' => $i->updated_at?->toISOString()];
    }

    /** @return array<string,mixed> */
    private function summary(Invoice $i): array
    {
        return ['id' => $i->id, 'document_number' => $i->document_number, 'customer_id' => $i->customer_id, 'invoice_date' => $i->invoice_date->toDateString(), 'due_date' => $i->due_date->toDateString(), 'currency' => $i->currency, 'total' => ['amount' => $i->total, 'currency' => $i->currency], 'open_balance' => ['amount' => $i->open_balance, 'currency' => $i->currency], 'status' => $i->status, 'version' => $i->version];
    }

    /** @return array<string,mixed> */
    private function safe(Invoice $i): array
    {
        return array_diff_key($this->present($i), array_flip(['notes', 'lines']));
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The invoice was not found.', 404);
    }

    private function conflict(int $v): DocumentActionResult
    {
        return new DocumentActionResult(['error_code' => 'concurrency_conflict', 'message' => 'The invoice version has changed.', 'details' => [], 'required_version' => $v], 409);
    }

    private function postingError(string $code): DocumentActionResult
    {
        return $this->commands->error($code, $code === 'period_locked' ? 'The accounting period is not postable.' : 'Invoice recognition could not be posted.', $code === 'period_locked' ? 423 : 422);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
