<?php

use App\Models\AuditLog;
use App\Models\CurrencyFx\RateRecord;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\OutboxMessage;
use App\Models\Payables\Bill;
use App\Models\Payables\Vendor;
use App\Models\Period\AccountingPeriod;
use App\Models\Receivables\Customer;
use App\Models\Receivables\Invoice;
use App\Models\Settlement\Allocation;
use App\Models\Settlement\CreditConsumption;
use App\Models\Settlement\CreditTranche;
use App\Models\Settlement\PartyCreditBalance;
use App\Models\User;
use App\Support\Documents\ExactDecimal;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{User,User,Entity,array<string,LedgerAccount>} */
function m3Actors(bool $approval = false): array
{
    $entity = Entity::query()->create(['legal_name' => 'M3 '.Str::uuid(), 'functional_currency' => 'BDT', 'fiscal_year_start_month' => 7, 'fiscal_year_start_day' => 1, 'approval_policy' => $approval ? ['configured' => true] : []]);
    $maker = User::query()->create(['name' => 'Settlement Maker', 'email' => 'm3-maker-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $approver = User::query()->create(['name' => 'Settlement Approver', 'email' => 'm3-approver-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    foreach ([$maker, $approver] as $actor) {
        $actor->entities()->attach($entity->id, ['status' => 'active']);
        $actor->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => 'FY26-P01', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'open']);
    $accounts = [
        'bank' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active', 'bank_attributes' => ['currency' => 'BDT']]),
        'ar' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1100', 'name' => 'AR', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']),
        'customer_credit' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2060', 'name' => 'Customer Credit', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']),
        'vendor_credit' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1075', 'name' => 'Vendor Advance', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']),
        'ap' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2100', 'name' => 'AP', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']),
        'withholding_asset' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1070', 'name' => 'Withholding Asset', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']),
        'withholding_liability' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2070', 'name' => 'Withholding Liability', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']),
        'fx_gain' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4200', 'name' => 'FX Gain', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']),
        'fx_loss' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '5200', 'name' => 'FX Loss', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']),
    ];
    config()->set('documents.invoice.receivable_account_id', $accounts['ar']->id);
    config()->set('documents.bill.payable_account_id', $accounts['ap']->id);
    config()->set('valuation.fx.rounding_scale', 4);
    config()->set('valuation.fx.rounding_mode', 'half_up');
    config()->set('settlement.receipt', ['number_prefix' => 'RCPT', 'number_format' => '{prefix}-{fiscal_year}-{sequence}']);
    config()->set('settlement.payment', ['number_prefix' => 'PAY', 'number_format' => '{prefix}-{fiscal_year}-{sequence}']);
    config()->set('settlement.refund', ['number_prefix' => 'REF', 'number_format' => '{prefix}-{fiscal_year}-{sequence}']);
    config()->set('settlement.accounts', ['customer_credit' => $accounts['customer_credit']->id, 'vendor_credit' => $accounts['vendor_credit']->id, 'realised_fx_gain' => $accounts['fx_gain']->id, 'realised_fx_loss' => $accounts['fx_loss']->id]);
    config()->set('settlement.withholding', [$entity->id => [
        'customer' => ['WHT' => ['active' => true, 'configuration_id' => (string) Str::uuid(), 'version' => 1, 'account_id' => $accounts['withholding_asset']->id, 'posting_side' => 'debit']],
        'vendor' => ['WHT' => ['active' => true, 'configuration_id' => (string) Str::uuid(), 'version' => 1, 'account_id' => $accounts['withholding_liability']->id, 'posting_side' => 'credit']],
    ]]);

    return [$maker->refresh(), $approver->refresh(), $entity, $accounts];
}

function m3Customer(User $actor, Entity $entity, string $currency = 'BDT'): Customer
{
    return Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Customer', 'normalized_name' => 'CUSTOMER', 'type' => $currency === 'BDT' ? 'local' : 'foreign', 'default_currency' => $currency, 'payment_terms' => 'NET30', 'status' => 'active', 'version' => 1, 'created_by' => $actor->id]);
}

function m3Vendor(User $actor, Entity $entity, string $currency = 'BDT'): Vendor
{
    return Vendor::query()->create(['entity_id' => $entity->id, 'name' => 'Vendor', 'normalized_name' => 'VENDOR', 'default_currency' => $currency, 'payment_terms' => 'NET30', 'status' => 'active', 'version' => 1, 'created_by' => $actor->id]);
}

function m3Invoice(User $actor, Entity $entity, Customer $customer, string $amount = '100.0000'): Invoice
{
    return Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-'.Str::random(8), 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => $amount, 'tax_total' => '0.0000', 'total' => $amount, 'open_balance' => $amount, 'status' => 'sent', 'version' => 2, 'created_by' => $actor->id]);
}

function m3Bill(User $actor, Entity $entity, Vendor $vendor, string $amount = '100.0000'): Bill
{
    return Bill::query()->create(['entity_id' => $entity->id, 'document_number' => 'BILL-'.Str::random(8), 'vendor_id' => $vendor->id, 'bill_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => $amount, 'tax_total' => '0.0000', 'total' => $amount, 'open_balance' => $amount, 'status' => 'awaiting_payment', 'version' => 2, 'created_by' => $actor->id]);
}

/** @return array<string,mixed> */
function m3CashPayload(string $partyKey, string $partyId, string $documentKey, string $documentId, string $bankId, string $gross = '100.0000', string $bank = '100.0000', string $withholding = '0.0000', string $unapplied = '0.0000'): array
{
    $payload = [$partyKey => $partyId, 'settlement_date' => '2026-07-20', 'bank_account_id' => $bankId, 'gross_amount' => ['amount' => $gross, 'currency' => 'BDT'], 'bank_amount' => ['amount' => $bank, 'currency' => 'BDT'], 'withholding_amount' => ['amount' => $withholding, 'currency' => 'BDT'], 'unapplied_amount' => ['amount' => $unapplied, 'currency' => 'BDT'], 'rate_record_id' => null, 'withholding_lines' => $withholding === '0.0000' ? [] : [['withholding_code' => 'WHT', 'amount' => ['amount' => $withholding, 'currency' => 'BDT']]], 'allocations' => $documentId === '' ? [] : [[$documentKey => $documentId, 'applied_amount' => ['amount' => ExactDecimal::subtract($gross, $unapplied), 'currency' => 'BDT'], 'expected_version' => 2]]];
    if ($unapplied !== '0.0000') {
        $payload['party_credit_expected_version'] = 0;
    }

    return $payload;
}

function m3JournalSide(JournalEntry $journal, string $side): string
{
    return $journal->lines->reduce(fn (string $total, $line): string => ExactDecimal::add($total, $line->{$side}), '0.0000');
}

it('registers exactly the seven frozen M3 public endpoints', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())->map(fn ($route): string => implode('|', $route->methods()).' /'.$route->uri())->filter(fn (string $route): bool => preg_match('#/(receipts|payments|credits|allocations)(?:/|$)#', $route) === 1)->values();
    expect($routes)->toHaveCount(7);
});

it('posts atomic balanced receipts with withholding, partial allocation and immutable credit tranche', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $customer = m3Customer($maker, $entity);
    $invoice = m3Invoice($maker, $entity, $customer);
    Sanctum::actingAs($maker);
    $payload = m3CashPayload('customer_id', $customer->id, 'invoice_id', $invoice->id, $accounts['bank']->id, '120.0000', '108.0000', '12.0000', '20.0000');
    $key = (string) Str::uuid();
    $response = $this->postJson('/v1/receipts', $payload, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key])->assertCreated()->assertJsonPath('receipt.state', 'posted')->assertJsonPath('party_credit.available_balance.amount', '20.0000');
    $this->postJson('/v1/receipts', $payload, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key])->assertCreated()->assertHeader('Idempotent-Replay', 'true');

    $invoice->refresh();
    $allocation = Allocation::query()->firstOrFail();
    $journal = JournalEntry::query()->with('lines')->findOrFail($allocation->journal_entry_ids[0]);
    expect($invoice->open_balance)->toBe('0.0000')
        ->and(m3JournalSide($journal, 'debit'))->toBe(m3JournalSide($journal, 'credit'))
        ->and(CreditTranche::query()->value('remaining_amount'))->toBe('20.0000')
        ->and(PartyCreditBalance::query()->value('available_balance'))->toBe('20.0000')
        ->and(OutboxMessage::query()->where('event_type', 'CreditHeld')->where('event_version', 2)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'ReceiptAllocated')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'JournalPosted')->firstOrFail()->metadata['causation_id'])->toBe($key)
        ->and(AuditLog::query()->where('action', 'receipt_posted')->count())->toBe(1)
        ->and($response->json('receipt.gross_amount.amount'))->toBe('120.0000')
        ->and($response->json('receipt.links.0.open_document.document_number'))->toBe($invoice->document_number)
        ->and($response->json('receipt.links.0.open_document.party_id'))->toBe($customer->id);
});

it('posts partial vendor payments and rejects inconsistent equations atomically', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $vendor = m3Vendor($maker, $entity);
    $bill = m3Bill($maker, $entity, $vendor, '150.0000');
    Sanctum::actingAs($maker);
    $bad = m3CashPayload('vendor_id', $vendor->id, 'bill_id', $bill->id, $accounts['bank']->id, '100.0000', '80.0000', '10.0000');
    $this->postJson('/v1/payments', $bad, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('details.rule', 'amount_equation_mismatch');
    expect(Allocation::query()->count())->toBe(0)->and(Bill::query()->findOrFail($bill->id)->open_balance)->toBe('150.0000');

    $good = m3CashPayload('vendor_id', $vendor->id, 'bill_id', $bill->id, $accounts['bank']->id);
    $this->postJson('/v1/payments', $good, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    expect(Bill::query()->findOrFail($bill->id)->open_balance)->toBe('50.0000')->and(Bill::query()->findOrFail($bill->id)->status)->toBe('partially_paid');
});

it('posts foreign cash settlement with exact document and settlement rates', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $customer = m3Customer($maker, $entity, 'USD');
    $documentRate = RateRecord::query()->create(['entity_id' => $entity->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '100.00000000', 'effective_date' => '2026-07-01', 'source' => 'TEST_SOURCE']);
    $settlementRate = RateRecord::query()->create(['entity_id' => $entity->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '110.00000000', 'effective_date' => '2026-07-20', 'source' => 'TEST_SOURCE']);
    $invoice = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-CASH-FX', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'USD', 'rate_record_id' => $documentRate->id, 'exchange_rate_reference' => ['rate_record_id' => $documentRate->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '100.00000000', 'effective_date' => '2026-07-01'], 'subtotal' => '10.0000', 'tax_total' => '0.0000', 'total' => '10.0000', 'open_balance' => '10.0000', 'status' => 'sent', 'version' => 2, 'created_by' => $maker->id]);
    Sanctum::actingAs($maker);
    $payload = ['customer_id' => $customer->id, 'settlement_date' => '2026-07-20', 'bank_account_id' => $accounts['bank']->id, 'gross_amount' => ['amount' => '10.0000', 'currency' => 'USD'], 'bank_amount' => ['amount' => '10.0000', 'currency' => 'USD'], 'withholding_amount' => ['amount' => '0.0000', 'currency' => 'USD'], 'unapplied_amount' => ['amount' => '0.0000', 'currency' => 'USD'], 'rate_record_id' => $settlementRate->id, 'withholding_lines' => [], 'allocations' => [['invoice_id' => $invoice->id, 'applied_amount' => ['amount' => '10.0000', 'currency' => 'USD'], 'expected_version' => 2]]];

    $allocation = $this->postJson('/v1/receipts', $payload, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('receipt.links.0.realised_fx_result.realised_fx.amount', '100.0000')
        ->assertJsonPath('receipt.links.0.realised_fx_result.classification', 'gain')
        ->json('receipt');
    $journal = JournalEntry::query()->with('lines')->findOrFail($allocation['journal_entry_ids'][0]);

    expect(m3JournalSide($journal, 'debit'))->toBe('1100.0000')
        ->and(m3JournalSide($journal, 'debit'))->toBe(m3JournalSide($journal, 'credit'))
        ->and($settlementRate->refresh()->referenced)->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'RealisedFXRecognised')->count())->toBe(1);
});

it('requires explicit versioned credit sources for application and restores exact tranches on reversal', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $customer = m3Customer($maker, $entity);
    Sanctum::actingAs($maker);
    $held = m3CashPayload('customer_id', $customer->id, 'invoice_id', '', $accounts['bank']->id, '25.0000', '25.0000', '0.0000', '25.0000');
    $this->postJson('/v1/receipts', $held, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $tranche = CreditTranche::query()->firstOrFail();
    $invoice = m3Invoice($maker, $entity, $customer, '10.0000');
    $application = ['party_type' => 'customer', 'currency' => 'BDT', 'application_date' => '2026-07-21', 'credit_sources' => [['credit_tranche_id' => $tranche->id, 'amount' => ['amount' => '10.0000', 'currency' => 'BDT'], 'expected_version' => 1]], 'allocations' => [['invoice_id' => $invoice->id, 'credit_tranche_id' => $tranche->id, 'applied_amount' => ['amount' => '10.0000', 'currency' => 'BDT'], 'expected_version' => 2]]];
    $applicationKey = (string) Str::uuid();
    $applied = $this->postJson('/v1/credits/'.$customer->id.'/apply', $application, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $applicationKey])->assertCreated()->assertJsonPath('consumed_credit_sources.0.remaining_amount.amount', '15.0000')->json('allocation');
    expect(CreditConsumption::query()->where('operation', 'application')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'CreditApplied')->where('event_version', 2)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'InvoiceStatusChanged')->where('aggregate_id', $invoice->id)->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'JournalPosted')->where('payload->sourceDocumentId', $applied['id'])->firstOrFail()->metadata['causation_id'])->toBe($applicationKey);

    $this->postJson('/v1/allocations/'.$applied['id'].'/reverse', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertCreated()->assertJsonPath('restored_credit_sources.0.restored_amount.amount', '10.0000');
    $tranche->refresh();
    expect($tranche->remaining_amount)->toBe('25.0000')->and($tranche->version)->toBe(3)
        ->and(Invoice::query()->findOrFail($invoice->id)->open_balance)->toBe('10.0000')
        ->and(CreditConsumption::query()->where('operation', 'restoration')->count())->toBe(1)
        ->and(OutboxMessage::query()->where('event_type', 'AllocationReversed')->where('event_version', 2)->count())->toBe(1);
});

it('refunds only named credit tranches and enforces source and projection concurrency', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $customer = m3Customer($maker, $entity);
    Sanctum::actingAs($maker);
    $this->postJson('/v1/receipts', m3CashPayload('customer_id', $customer->id, 'invoice_id', '', $accounts['bank']->id, '25.0000', '25.0000', '0.0000', '25.0000'), ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $tranche = CreditTranche::query()->firstOrFail();
    $refund = ['party_type' => 'customer', 'refund_date' => '2026-07-21', 'bank_account_id' => $accounts['bank']->id, 'refund_amount' => ['amount' => '10.0000', 'currency' => 'BDT'], 'expected_available_balance' => ['amount' => '25.0000', 'currency' => 'BDT'], 'rate_record_id' => null, 'credit_sources' => [['credit_tranche_id' => $tranche->id, 'amount' => ['amount' => '10.0000', 'currency' => 'BDT'], 'expected_version' => 9]]];
    $this->postJson('/v1/credits/'.$customer->id.'/refund', $refund, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertConflict()->assertJsonPath('details.rule', 'credit_tranche_concurrency_conflict');
    $refund['credit_sources'][0]['expected_version'] = 1;
    $this->postJson('/v1/credits/'.$customer->id.'/refund', $refund, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    expect(CreditTranche::query()->firstOrFail()->remaining_amount)->toBe('15.0000')->and(OutboxMessage::query()->where('event_type', 'CreditRefunded')->where('event_version', 2)->count())->toBe(1);
});

it('uses exact source and comparison RateRecords for foreign credit application and refund realised FX', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $customer = m3Customer($maker, $entity, 'USD');
    $sourceRate = RateRecord::query()->create(['entity_id' => $entity->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '100.00000000', 'effective_date' => '2026-07-10', 'source' => 'TEST_SOURCE']);
    $documentRate = RateRecord::query()->create(['entity_id' => $entity->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '90.00000000', 'effective_date' => '2026-07-15', 'source' => 'TEST_SOURCE']);
    $refundRate = RateRecord::query()->create(['entity_id' => $entity->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '120.00000000', 'effective_date' => '2026-07-21', 'source' => 'TEST_SOURCE']);
    Sanctum::actingAs($maker);

    $held = ['customer_id' => $customer->id, 'settlement_date' => '2026-07-10', 'bank_account_id' => $accounts['bank']->id, 'gross_amount' => ['amount' => '20.0000', 'currency' => 'USD'], 'bank_amount' => ['amount' => '20.0000', 'currency' => 'USD'], 'withholding_amount' => ['amount' => '0.0000', 'currency' => 'USD'], 'unapplied_amount' => ['amount' => '20.0000', 'currency' => 'USD'], 'rate_record_id' => $sourceRate->id, 'party_credit_expected_version' => 0, 'withholding_lines' => [], 'allocations' => []];
    $this->postJson('/v1/receipts', $held, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $tranche = CreditTranche::query()->firstOrFail();
    expect($tranche->original_functional_amount)->toBe('2000.0000')->and($tranche->source_rate_record_id)->toBe($sourceRate->id);

    $invoice = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-FOREIGN', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-15', 'due_date' => '2026-07-31', 'currency' => 'USD', 'rate_record_id' => $documentRate->id, 'exchange_rate_reference' => ['rate_record_id' => $documentRate->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '90.00000000', 'effective_date' => '2026-07-15'], 'subtotal' => '10.0000', 'tax_total' => '0.0000', 'total' => '10.0000', 'open_balance' => '10.0000', 'status' => 'sent', 'version' => 2, 'created_by' => $maker->id]);
    $application = ['party_type' => 'customer', 'currency' => 'USD', 'application_date' => '2026-07-21', 'credit_sources' => [['credit_tranche_id' => $tranche->id, 'amount' => ['amount' => '10.0000', 'currency' => 'USD'], 'expected_version' => 1]], 'allocations' => [['invoice_id' => $invoice->id, 'credit_tranche_id' => $tranche->id, 'applied_amount' => ['amount' => '10.0000', 'currency' => 'USD'], 'expected_version' => 2]]];
    $applied = $this->postJson('/v1/credits/'.$customer->id.'/apply', $application, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('realised_fx_results.0.realised_fx.amount', '100.0000')->assertJsonPath('realised_fx_results.0.classification', 'gain')->json('allocation');
    $applicationJournal = JournalEntry::query()->with('lines')->findOrFail($applied['journal_entry_ids'][0]);
    expect(m3JournalSide($applicationJournal, 'debit'))->toBe(m3JournalSide($applicationJournal, 'credit'));

    $refund = ['party_type' => 'customer', 'refund_date' => '2026-07-21', 'bank_account_id' => $accounts['bank']->id, 'refund_amount' => ['amount' => '10.0000', 'currency' => 'USD'], 'expected_available_balance' => ['amount' => '10.0000', 'currency' => 'USD'], 'rate_record_id' => $refundRate->id, 'credit_sources' => [['credit_tranche_id' => $tranche->id, 'amount' => ['amount' => '10.0000', 'currency' => 'USD'], 'expected_version' => 2]]];
    $refunded = $this->postJson('/v1/credits/'.$customer->id.'/refund', $refund, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->assertJsonPath('realised_fx_results.0.realised_fx.amount', '-200.0000')->assertJsonPath('realised_fx_results.0.classification', 'loss')->json('allocation');
    $refundJournal = JournalEntry::query()->with('lines')->findOrFail($refunded['journal_entry_ids'][0]);
    expect(m3JournalSide($refundJournal, 'debit'))->toBe(m3JournalSide($refundJournal, 'credit'))
        ->and($sourceRate->refresh()->referenced)->toBeTrue()
        ->and($refundRate->refresh()->referenced)->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'RealisedFXRecognised')->count())->toBe(2);
});

it('uses durable maker-checker approval without premature settlement effects', function (): void {
    [$maker, $approver, $entity, $accounts] = m3Actors(true);
    $customer = m3Customer($maker, $entity);
    $invoice = m3Invoice($maker, $entity, $customer);
    Sanctum::actingAs($maker);
    $payload = m3CashPayload('customer_id', $customer->id, 'invoice_id', $invoice->id, $accounts['bank']->id);
    $pending = $this->postJson('/v1/receipts', $payload, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertStatus(202)->assertJsonPath('approval.status', 'pending')->json('approval');
    expect(Allocation::query()->count())->toBe(0)->and(Invoice::query()->findOrFail($invoice->id)->open_balance)->toBe('100.0000');
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertForbidden()->assertJsonPath('error_code', 'maker_cannot_approve');
    Sanctum::actingAs($approver);
    $approvalKey = (string) Str::uuid();
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $approvalKey, 'If-Match' => '1'])->assertOk()->assertJsonPath('command_result.status', 201);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $approvalKey, 'If-Match' => '1'])->assertOk()->assertHeader('Idempotent-Replay', 'true');
    expect(Allocation::query()->count())->toBe(1)->and(OutboxMessage::query()->where('event_type', 'ReceiptAllocated')->count())->toBe(1);
});

it('rejects unknown fields, bad correlation, stale reversal and cross-entity reads', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $customer = m3Customer($maker, $entity);
    $invoice = m3Invoice($maker, $entity, $customer);
    Sanctum::actingAs($maker);
    $payload = m3CashPayload('customer_id', $customer->id, 'invoice_id', $invoice->id, $accounts['bank']->id);
    $this->postJson('/v1/receipts', [...$payload, 'automatic_matching' => true], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertBadRequest()->assertJsonPath('error_code', 'validation');
    $this->getJson('/v1/allocations', ['X-Entity-Id' => $entity->id, 'X-Correlation-Id' => 'bad'])->assertBadRequest()->assertJsonPath('error_code', 'validation');
    $allocation = $this->postJson('/v1/receipts', $payload, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('receipt');
    $this->postJson('/v1/allocations/'.$allocation['id'].'/reverse', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertStatus(428)->assertJsonPath('error_code', 'precondition_required');
    $this->postJson('/v1/allocations/'.$allocation['id'].'/reverse', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])->assertConflict();
    [, , $other] = m3Actors();
    $maker->entities()->attach($other->id, ['status' => 'active']);
    $maker->roles()->attach(Role::query()->where('entity_id', $other->id)->where('slug', 'owner')->firstOrFail()->id, ['entity_id' => $other->id]);
    $this->getJson('/v1/credits/'.$customer->id.'?party_type=customer', ['X-Entity-Id' => $other->id])->assertNotFound();
});

it('enforces idempotency conflict, source preconditions and configuration failure rollback', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $customer = m3Customer($maker, $entity);
    Sanctum::actingAs($maker);
    $payload = m3CashPayload('customer_id', $customer->id, 'invoice_id', '', $accounts['bank']->id, '20.0000', '20.0000', '0.0000', '20.0000');
    $key = (string) Str::uuid();
    $this->postJson('/v1/receipts', $payload, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key])->assertCreated();
    $changed = $payload;
    $changed['gross_amount']['amount'] = '21.0000';
    $changed['bank_amount']['amount'] = '21.0000';
    $changed['unapplied_amount']['amount'] = '21.0000';
    $this->postJson('/v1/receipts', $changed, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => $key])->assertConflict()->assertJsonPath('error_code', 'idempotency_conflict');

    $tranche = CreditTranche::query()->firstOrFail();
    $invoice = m3Invoice($maker, $entity, $customer, '5.0000');
    $withoutVersion = ['party_type' => 'customer', 'currency' => 'BDT', 'application_date' => '2026-07-21', 'credit_sources' => [['credit_tranche_id' => $tranche->id, 'amount' => ['amount' => '5.0000', 'currency' => 'BDT']]], 'allocations' => [['invoice_id' => $invoice->id, 'credit_tranche_id' => $tranche->id, 'applied_amount' => ['amount' => '5.0000', 'currency' => 'BDT'], 'expected_version' => 2]]];
    $this->postJson('/v1/credits/'.$customer->id.'/apply', $withoutVersion, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertStatus(428)->assertJsonPath('error_code', 'precondition_required');

    $before = Allocation::query()->count();
    config()->set('settlement.receipt.number_format', null);
    $secondCustomer = m3Customer($maker, $entity);
    $this->postJson('/v1/receipts', m3CashPayload('customer_id', $secondCustomer->id, 'invoice_id', '', $accounts['bank']->id, '5.0000', '5.0000', '0.0000', '5.0000'), ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonPath('details.rule', 'missing_numbering_configuration');
    expect(Allocation::query()->count())->toBe($before);
});

it('protects posted allocations, links, consumptions and tranche source facts in PostgreSQL', function (): void {
    [$maker, , $entity, $accounts] = m3Actors();
    $customer = m3Customer($maker, $entity);
    Sanctum::actingAs($maker);
    $allocation = $this->postJson('/v1/receipts', m3CashPayload('customer_id', $customer->id, 'invoice_id', '', $accounts['bank']->id, '25.0000', '25.0000', '0.0000', '25.0000'), ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('receipt');
    expect(fn () => DB::table('settlement_allocations')->where('id', $allocation['id'])->update(['gross_amount' => '999.0000']))->toThrow(QueryException::class)
        ->and(fn () => DB::table('settlement_credit_tranches')->where('source_allocation_id', $allocation['id'])->update(['original_amount' => '999.0000']))->toThrow(QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL immutable-fact trigger validation.');
