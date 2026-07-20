<?php

use App\Models\AuditLog;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\OutboxMessage;
use App\Models\Payables\Bill;
use App\Models\Period\AccountingPeriod;
use App\Models\Receivables\Invoice;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{User,User,Entity,array<string,LedgerAccount>} */
function m2Actors(bool $approval = false): array
{
    $entity = Entity::query()->create(['legal_name' => 'M2 '.Str::uuid(), 'functional_currency' => 'BDT', 'fiscal_year_start_month' => 7, 'fiscal_year_start_day' => 1, 'approval_policy' => $approval ? ['configured' => true] : []]);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'm2-maker-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $approver = User::query()->create(['name' => 'Approver', 'email' => 'm2-approver-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    foreach ([$maker, $approver] as $actor) {
        $actor->entities()->attach($entity->id, ['status' => 'active']);
        $actor->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => 'FY26-P01', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'open']);
    $accounts = [
        'ar' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1100', 'name' => 'Accounts Receivable', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']),
        'revenue' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4100', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']),
        'ap' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2100', 'name' => 'Accounts Payable', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']),
        'expense' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '5100', 'name' => 'Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']),
        'bank' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active', 'bank_attributes' => ['currency' => 'BDT']]),
    ];
    config()->set('documents.supported_currencies', ['BDT', 'USD']);
    config()->set('documents.payment_terms', ['NET30' => 30]);
    config()->set('documents.invoice.number_prefix', 'INV');
    config()->set('documents.invoice.number_format', '{prefix}-{fiscal_year}-{sequence}');
    config()->set('documents.invoice.receivable_account_id', $accounts['ar']->id);
    config()->set('documents.invoice.revenue_account_id', $accounts['revenue']->id);
    config()->set('documents.bill.number_prefix', 'BILL');
    config()->set('documents.bill.number_format', '{prefix}-{fiscal_year}-{sequence}');
    config()->set('documents.bill.payable_account_id', $accounts['ap']->id);
    config()->set('documents.expense.payable_account_id', $accounts['ap']->id);
    config()->set('valuation.fx.rounding_scale', 4);
    config()->set('valuation.fx.rounding_mode', 'half_up');

    return [$maker->refresh(), $approver->refresh(), $entity, $accounts];
}

/** @return array<string,mixed> */
function createCustomer($test, User $actor, Entity $entity, array $overrides = []): array
{
    Sanctum::actingAs($actor);

    return $test->postJson('/v1/customers', [...['name' => 'Customer', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'NET30'], ...$overrides], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('customer');
}

/** @return array<string,mixed> */
function createVendor($test, User $actor, Entity $entity, array $overrides = []): array
{
    Sanctum::actingAs($actor);

    return $test->postJson('/v1/vendors', [...['name' => 'Vendor', 'default_currency' => 'BDT', 'payment_terms' => 'NET30'], ...$overrides], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('vendor');
}

it('implements exactly the approved 24 M2 public routes', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => implode('|', $route->methods()).' /'.$route->uri())->filter(fn (string $route): bool => preg_match('#/(customers|invoices|vendors|bills|expenses)(?:/|$)#', $route) === 1)->values();
    expect($routes)->toHaveCount(24)
        ->and($routes->filter(fn (string $route): bool => str_contains($route, 'attachment')))->toHaveCount(0);
});

it('enforces normalized tax identity, idempotency, concurrency, deactivation and entity isolation', function (): void {
    [$maker, , $entity] = m2Actors();
    $customer = createCustomer($this, $maker, $entity, ['name' => 'Same Name', 'jurisdiction' => 'BD', 'tax_identifier' => '  ab-12  ']);
    createCustomer($this, $maker, $entity, ['name' => 'Same Name']);
    $this->postJson('/v1/customers', ['name' => 'Different', 'type' => 'local', 'jurisdiction' => 'BD', 'tax_identifier' => 'AB-12', 'default_currency' => 'BDT', 'payment_terms' => 'NET30'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('error_code', 'duplicate_resource');

    $key = (string) Str::uuid();
    $this->patchJson('/v1/customers/'.$customer['id'], ['name' => 'Updated'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key, 'If-Match' => '1'])->assertOk()->assertJsonPath('customer.version', 2);
    $this->patchJson('/v1/customers/'.$customer['id'], ['name' => 'Updated'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key, 'If-Match' => '1'])->assertOk()->assertHeader('Idempotent-Replay', 'true');
    $this->postJson('/v1/customers/'.$customer['id'].'/deactivate', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertConflict();
    $this->postJson('/v1/customers/'.$customer['id'].'/deactivate', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])->assertOk()->assertJsonPath('customer.status', 'deactivated');

    [, , $other] = m2Actors();
    $this->getJson('/v1/customers/'.$customer['id'], ['X-Entity-Id' => $other->id])->assertForbidden();
    expect(OutboxMessage::query()->where('event_type', 'CustomerDeactivated')->count())->toBe(1);
});

it('creates and edits invoice drafts without posting then issues once with balanced posting and PDF', function (): void {
    [$maker, , $entity] = m2Actors();
    $customer = createCustomer($this, $maker, $entity);
    $key = (string) Str::uuid();
    $draft = $this->postJson('/v1/invoices', ['customer_id' => $customer['id'], 'invoice_date' => '2026-07-15', 'currency' => 'BDT', 'lines' => [['description' => 'Service', 'quantity' => '2.0000', 'unit_price' => ['amount' => '50.0000', 'currency' => 'BDT'], 'tax_code_id' => null]]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key])->assertCreated()->assertJsonPath('invoice.status', 'draft')->json('invoice');
    expect(JournalEntry::query()->count())->toBe(0)->and(OutboxMessage::query()->where('event_type', 'InvoiceIssued')->count())->toBe(0);
    $this->patchJson('/v1/invoices/'.$draft['id'], ['notes' => 'Updated draft'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk()->assertJsonPath('invoice.version', 2);
    $issued = $this->postJson('/v1/invoices/'.$draft['id'].'/issue', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])->assertCreated()->assertJsonPath('invoice.status', 'sent')->json('invoice');
    $journal = JournalEntry::query()->with('lines')->findOrFail($issued['journal_entry_id']);
    expect($journal->lines->sum(fn ($line): float => (float) $line->debit))->toBe($journal->lines->sum(fn ($line): float => (float) $line->credit))
        ->and(OutboxMessage::query()->where('event_type', 'InvoiceIssued')->count())->toBe(1);
    $this->patchJson('/v1/invoices/'.$draft['id'], ['notes' => 'Forbidden'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '3'])->assertUnprocessable()->assertJsonPath('details.rule', 'invoice_not_draft');
    $this->get('/v1/invoices/'.$draft['id'].'/pdf', ['X-Entity-Id' => $entity->id, 'Accept' => 'application/pdf'])->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

it('protects vendor bank data and bill approval with maker checker and durable replay', function (): void {
    [$maker, $approver, $entity, $accounts] = m2Actors(true);
    $vendor = createVendor($this, $maker, $entity, ['bank_details' => ['account_name' => 'Vendor', 'institution_name' => 'Bank', 'account_identifier' => '1234567890', 'routing_identifier' => '12345678']]);
    expect($vendor['bank_details']['account_identifier_masked'])->toBe('****7890');
    $raw = (string) DB::table('payables_vendors')->where('id', $vendor['id'])->value('bank_details');
    expect($raw)->not->toContain('1234567890')->and(json_encode(AuditLog::query()->get()->toArray()))->not->toContain('1234567890');
    $bill = $this->postJson('/v1/bills', ['vendor_id' => $vendor['id'], 'bill_date' => '2026-07-15', 'currency' => 'BDT', 'lines' => [['description' => 'Service', 'quantity' => '1.0000', 'unit_price' => ['amount' => '100.0000', 'currency' => 'BDT'], 'expense_account_id' => $accounts['expense']->id, 'tax_code_id' => null]], 'sbu_allocations' => [['sbu_code' => 'OPS', 'weight' => '1.0000']]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('bill');
    $pending = $this->postJson('/v1/bills/'.$bill['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertStatus(202)->assertJsonPath('approval.status', 'pending')->json('approval');
    expect(Bill::query()->findOrFail($bill['id'])->status)->toBe('draft')->and(JournalEntry::query()->count())->toBe(0);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertForbidden()->assertJsonPath('error_code', 'maker_cannot_approve');
    Sanctum::actingAs($approver);
    $approvedKey = (string) Str::uuid();
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $approvedKey, 'If-Match' => '1'])->assertOk()->assertJsonPath('command_result.status', 201);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $approvedKey, 'If-Match' => '1'])->assertOk()->assertHeader('Idempotent-Replay', 'true');
    expect(Bill::query()->findOrFail($bill['id'])->status)->toBe('awaiting_payment')->and(JournalEntry::query()->count())->toBe(1)->and(OutboxMessage::query()->where('event_type', 'BillApproved')->count())->toBe(1);
});

it('records cash and accrued expenses atomically and validates SBU, accounts and unknown fields', function (): void {
    [$maker, , $entity, $accounts] = m2Actors();
    $vendor = createVendor($this, $maker, $entity);
    $base = ['expense_date' => '2026-07-15', 'description' => 'Operations', 'category_account_id' => $accounts['expense']->id, 'settlement_type' => 'cash', 'bank_account_id' => $accounts['bank']->id, 'currency' => 'BDT', 'amount' => ['amount' => '25.0000', 'currency' => 'BDT'], 'tax_code_id' => null, 'ait' => null, 'sbu_allocations' => [['sbu_code' => 'OPS', 'weight' => '1.0000']]];
    $this->postJson('/v1/expenses', [...$base, 'attachment' => 'forbidden'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertBadRequest()->assertJsonPath('error_code', 'validation');
    $expense = $this->postJson('/v1/expenses', $base, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('expense.status', 'recorded')->json('expense');
    $this->getJson('/v1/expenses/'.$expense['id'], ['X-Entity-Id' => $entity->id])->assertOk();
    $this->postJson('/v1/expenses', [...$base, 'settlement_type' => 'accrued', 'bank_account_id' => null, 'vendor_id' => $vendor['id'], 'sbu_allocations' => [['sbu_code' => 'OPS', 'weight' => '0.5000']]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('error_code', 'sbu_allocation_invalid');
    expect(OutboxMessage::query()->where('event_type', 'ExpenseRecorded')->count())->toBe(1)->and(AuditLog::query()->where('action', 'expense_recorded')->count())->toBe(1);
});

it('fails numbering, posting, correlation and unknown query input safely without partial effects', function (): void {
    [$maker, , $entity] = m2Actors();
    $customer = createCustomer($this, $maker, $entity);
    $draft = $this->postJson('/v1/invoices', ['customer_id' => $customer['id'], 'invoice_date' => '2026-07-15', 'currency' => 'BDT', 'lines' => [['description' => 'Service', 'quantity' => '1.0000', 'unit_price' => ['amount' => '10.0000', 'currency' => 'BDT'], 'tax_code_id' => null]]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('invoice');
    config()->set('documents.invoice.number_format', null);
    $this->postJson('/v1/invoices/'.$draft['id'].'/issue', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertUnprocessable()->assertJsonPath('error_code', 'missing_numbering_configuration');
    expect(Invoice::query()->findOrFail($draft['id'])->status)->toBe('draft')->and(JournalEntry::query()->count())->toBe(0);
    $this->getJson('/v1/invoices?unexpected=true', ['X-Entity-Id' => $entity->id])->assertBadRequest()->assertJsonPath('error_code', 'validation');
    $this->getJson('/v1/invoices', ['X-Entity-Id' => $entity->id, 'X-Correlation-Id' => 'not-a-uuid'])->assertBadRequest()->assertJsonPath('error_code', 'validation');
});

it('enforces recognized document immutability in PostgreSQL', function (): void {
    [$maker, , $entity] = m2Actors();
    $customer = createCustomer($this, $maker, $entity);
    $draft = $this->postJson('/v1/invoices', ['customer_id' => $customer['id'], 'invoice_date' => '2026-07-15', 'currency' => 'BDT', 'lines' => [['description' => 'Service', 'quantity' => '1.0000', 'unit_price' => ['amount' => '10.0000', 'currency' => 'BDT'], 'tax_code_id' => null]]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('invoice');
    $this->postJson('/v1/invoices/'.$draft['id'].'/issue', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertCreated();

    expect(fn () => DB::table('receivables_invoices')->where('id', $draft['id'])->update(['total' => '999.0000']))->toThrow(QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL trigger validation.');
