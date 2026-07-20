<?php

namespace App\Payables\Application;

use App\CurrencyFx\Application\RateReferenceService;
use App\Ledger\Application\AccountReferenceQuery;
use App\Ledger\Application\RecognitionPostingService;
use App\Models\Payables\Expense;
use App\Models\Payables\Vendor;
use App\Models\User;
use App\Support\Audit\AuditLogger;
use App\Support\Documents\DocumentActionResult;
use App\Support\Documents\DocumentCommandSupport;
use App\Support\Documents\DocumentValuationService;
use App\Support\Documents\ExactDecimal;
use App\Support\Outbox\Outbox;
use App\Support\Pagination\StableCursor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

final readonly class ExpenseService
{
    public function __construct(private DocumentCommandSupport $commands, private DocumentValuationService $valuation, private AccountReferenceQuery $accounts, private RecognitionPostingService $posting, private RateReferenceService $rates, private AuditLogger $audit, private Outbox $outbox) {}

    /** @param array<string,mixed> $data */
    public function create(User $actor, string $entityId, array $data, ?string $key): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.expenses.create')) {
            return $d;
        }if ($e = $this->commands->requireIdempotency($key)) {
            return $e;
        }$op = 'POST /v1/expenses';
        $hash = $this->commands->hash($data);
        if ($r = $this->commands->replay($actor->id, $entityId, $op, (string) $key, $hash)) {
            return $r;
        }
        if (! in_array($data['currency'], (array) config('documents.supported_currencies'), true) || $data['amount']['currency'] !== $data['currency'] || ! ExactDecimal::positive((string) $data['amount']['amount'])) {
            return $this->commands->error('invalid_document_currency', 'Expense amount and currency are invalid.', 422);
        }if (! $this->accounts->isActiveExpense($entityId, $data['category_account_id'])) {
            return $this->commands->error('invalid_expense_account', 'The expense category account is invalid.', 422);
        }if (! $this->validAllocations($data['sbu_allocations'])) {
            return $this->commands->error('sbu_allocation_invalid', 'SBU allocations must total exactly 1.0000.', 422);
        }
        $vendor = null;
        $creditAccount = null;
        if ($data['settlement_type'] === 'cash') {
            if (! isset($data['bank_account_id']) || isset($data['vendor_id']) || ! $this->accounts->isActiveBank($entityId, (string) $data['bank_account_id'])) {
                return $this->commands->error('invalid_bank_account', 'Cash expense requires an active entity bank account and no vendor.', 422);
            }$creditAccount = $data['bank_account_id'];
        } elseif ($data['settlement_type'] === 'accrued') {
            $vendor = Vendor::query()->where('entity_id', $entityId)->find($data['vendor_id'] ?? null);
            if (! $vendor || $vendor->status !== 'active' || isset($data['bank_account_id'])) {
                return $this->commands->error('vendor_inactive', 'Accrued expense requires an active vendor and no bank account.', 422);
            }$creditAccount = config('documents.expense.payable_account_id');
            if (! is_string($creditAccount) || ! Str::isUuid($creditAccount)) {
                return $this->commands->error('missing_posting_configuration', 'Accrued expense payable mapping is unavailable.', 422);
            }
        } else {
            return $this->commands->error('invalid_settlement_type', 'Settlement type is invalid.', 422);
        }
        if (isset($data['ait']) && $data['ait']['currency'] !== $data['currency']) {
            return $this->commands->error('invalid_document_currency', 'AIT must use the document currency.', 422);
        }
        $line = [['description' => $data['description'], 'quantity' => '1.0000', 'unit_price' => $data['amount'], 'tax_code_id' => $data['tax_code_id'] ?? null]];
        $value = $this->valuation->value($entityId, $vendor?->jurisdiction, $data['expense_date'], $data['currency'], $line, null);
        if ($value === null) {
            $code = $this->valuation->requiresRate($entityId, $data['currency']) ? 'missing_rate_reference' : 'missing_tax_configuration';

            return $this->commands->error($code, 'Required immutable tax or FX configuration could not be resolved.', 422);
        }

        return DB::transaction(function () use ($actor, $entityId, $data, $value, $creditAccount, $key, $op, $hash): DocumentActionResult {
            $id = (string) Str::uuid();
            $reference = $value['rate'];
            $postingLines = [['account_id' => $data['category_account_id'], 'description' => $data['description'], 'debit' => $value['functional_total'], 'credit' => '0.0000'], ['account_id' => $creditAccount, 'description' => $data['description'], 'debit' => '0.0000', 'credit' => $value['functional_total']]];
            foreach ($postingLines as &$line) {
                $line['currency'] = $reference['quote_currency'] ?? $data['currency'];
                $line['fx_amount'] = $reference ? $value['total'] : null;
                $line['fx_currency'] = $reference ? $data['currency'] : null;
                $line['rate_record_id'] = $reference['rate_record_id'] ?? null;
                $line['fx_rate'] = $reference['rate'] ?? null;
                $line['fx_rate_effective_date'] = $reference['effective_date'] ?? null;
                $line['sbu_tag'] = count($data['sbu_allocations']) === 1 ? $data['sbu_allocations'][0]['sbu_code'] : null;
            }unset($line);
            $posted = $this->posting->post($entityId, $id, $data['expense_date'], 'expense', $actor->id, $postingLines);
            if ($posted->errorCode) {
                return $this->postingError($posted->errorCode);
            }$expense = Expense::query()->create(['id' => $id, 'entity_id' => $entityId, 'expense_date' => $data['expense_date'], 'description' => $data['description'], 'vendor_id' => $data['vendor_id'] ?? null, 'category_account_id' => $data['category_account_id'], 'settlement_type' => $data['settlement_type'], 'bank_account_id' => $data['bank_account_id'] ?? null, 'currency' => $data['currency'], 'amount' => ExactDecimal::normalize((string) $data['amount']['amount']), 'tax_code_id' => $data['tax_code_id'] ?? null, 'tax_snapshot' => $value['lines'][0]['tax_snapshot'], 'ait' => $data['ait']['amount'] ?? null, 'sbu_allocations' => array_map(fn (array $a): array => ['sbu_code' => $a['sbu_code'], 'weight' => ExactDecimal::normalize((string) $a['weight'])], $data['sbu_allocations']), 'rate_record_id' => $reference['rate_record_id'] ?? null, 'exchange_rate_reference' => $reference, 'journal_entry_id' => $posted->journalId, 'status' => 'recorded', 'version' => 1, 'created_by' => $actor->id, 'recorded_at' => now('UTC')]);
            if ($reference) {
                $this->rates->markReferenced($entityId, (string) $reference['rate_record_id']);
            }
            $this->valuation->markTaxSnapshotsReferenced($entityId, $value['lines']);
            $body = ['expense' => $this->present($expense)];
            $this->audit->record('payables', 'expense_recorded', 'expense', $expense->id, $actor->id, $entityId, after: $this->safe($expense), correlationId: $this->correlation());
            $this->outbox->record('ExpenseRecorded', 'Expense', $expense->id, ['expense_id' => $expense->id, 'settlement_type' => $expense->settlement_type, 'amount' => ['amount' => $expense->amount, 'currency' => $expense->currency], 'journal_entry_id' => $expense->journal_entry_id], $entityId);
            if ($expense->tax_snapshot !== null) {
                $this->outbox->record('TaxDetermined', 'Expense', $expense->id, ['document_id' => $expense->id, 'document_type' => 'expense'], $entityId);
            }$this->commands->store($actor->id, $entityId, $op, (string) $key, $hash, 201, $body);

            return new DocumentActionResult($body, 201);
        });
    }

    public function show(User $actor, string $entityId, string $id): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.expenses.read')) {
            return $d;
        }$expense = Expense::query()->where('entity_id', $entityId)->find($id);

        return $expense ? new DocumentActionResult(['expense' => $this->present($expense)]) : $this->notFound();
    }

    /** @param array<string,mixed> $filters */
    public function list(User $actor, string $entityId, array $filters): DocumentActionResult
    {
        if ($d = $this->commands->authorize($actor, $entityId, 'payables.expenses.read')) {
            return $d;
        }$limit = (int) ($filters['limit'] ?? 50);
        $binding = ['entity_id' => $entityId, 'filters' => $filters, 'order' => 'expense_date_desc,id_desc'];
        try {
            [$cursor,$boundary] = StableCursor::decode(isset($filters['cursor']) ? (string) $filters['cursor'] : null, $binding);
        } catch (InvalidArgumentException $e) {
            return $this->commands->error('validation', $e->getMessage(), 400);
        }$q = Expense::query()->where('entity_id', $entityId)->where('created_at', '<=', $boundary)->when($filters['vendor'] ?? null, fn ($q, $v) => $q->where('vendor_id', $v))->when($filters['category_account_id'] ?? null, fn ($q, $v) => $q->where('category_account_id', $v))->when($filters['settlement_type'] ?? null, fn ($q, $v) => $q->where('settlement_type', $v))->when($filters['from'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '>=', $v))->when($filters['to'] ?? null, fn ($q, $v) => $q->whereDate('expense_date', '<=', $v));
        if (isset($filters['sbu_code'])) {
            $q->whereJsonContains('sbu_allocations', ['sbu_code' => $filters['sbu_code']]);
        }$page = $q->orderByDesc('expense_date')->orderByDesc('id')->cursorPaginate($limit, ['*'], 'cursor', $cursor);

        return new DocumentActionResult(['expenses' => $page->getCollection()->map(fn (Expense $e) => $this->summary($e))->all(), 'page' => ['limit' => $limit, 'next_cursor' => StableCursor::encode($page->nextCursor(), $boundary, $binding)]]);
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

    /** @return array<string,mixed> */
    public function present(Expense $e): array
    {
        return [...$this->summary($e), 'description' => $e->description, 'vendor_id' => $e->vendor_id, 'category_account_id' => $e->category_account_id, 'bank_account_id' => $e->bank_account_id, 'tax_code_id' => $e->tax_code_id, 'tax_snapshot' => $e->tax_snapshot, 'ait' => $e->ait !== null ? ['amount' => $e->ait, 'currency' => $e->currency] : null, 'sbu_allocations' => $e->sbu_allocations, 'exchange_rate_reference' => $e->exchange_rate_reference, 'journal_entry_id' => $e->journal_entry_id, 'recorded_at' => $e->recorded_at->toISOString()];
    }

    /** @return array<string,mixed> */
    private function summary(Expense $e): array
    {
        return ['id' => $e->id, 'expense_date' => $e->expense_date->toDateString(), 'description' => $e->description, 'category_account_id' => $e->category_account_id, 'settlement_type' => $e->settlement_type, 'currency' => $e->currency, 'amount' => ['amount' => $e->amount, 'currency' => $e->currency], 'status' => $e->status, 'version' => $e->version];
    }

    /** @return array<string,mixed> */
    private function safe(Expense $e): array
    {
        return array_diff_key($this->present($e), array_flip(['description']));
    }

    private function notFound(): DocumentActionResult
    {
        return $this->commands->error('not_found', 'The expense was not found.', 404);
    }

    private function postingError(string $code): DocumentActionResult
    {
        return $this->commands->error($code, $code === 'period_locked' ? 'The accounting period is not postable.' : 'Expense recognition could not be posted.', $code === 'period_locked' ? 423 : 422);
    }

    private function correlation(): ?string
    {
        return app()->bound('request') ? (request()->attributes->get('correlation_id') ?: null) : null;
    }
}
