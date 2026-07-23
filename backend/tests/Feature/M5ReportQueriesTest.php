<?php

use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\Period\AccountingPeriod;
use App\Models\Receivables\Customer;
use App\Models\Receivables\Invoice;
use App\Models\Reporting\AccountClassificationVersion;
use App\Models\Reporting\AgeingBucketSetVersion;
use App\Models\Reporting\CashViewPolicyVersion;
use App\Models\Reporting\ReportLayoutVersion;
use App\Models\Settlement\Allocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Entity,User} */
function m5Actors(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M5 Reports '.Str::uuid(), 'functional_currency' => 'BDT']);
    $owner = User::query()->create(['name' => 'Owner', 'email' => 'm5-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $owner->entities()->attach($entity->id, ['status' => 'active']);
    $owner->roles()->attach($role->id, ['entity_id' => $entity->id]);
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);

    return [$entity, $owner];
}

/** @return array<string, LedgerAccount> */
function m5ChartOfAccounts(Entity $entity): array
{
    return [
        'cash' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']),
        'ar' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1060', 'name' => 'AR', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']),
        'ap' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2010', 'name' => 'AP', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']),
        'equity' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '3010', 'name' => 'Share Capital', 'type' => 'equity', 'normal_balance' => 'credit', 'status' => 'active']),
        'revenue' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4010', 'name' => 'Client Service Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']),
        'cogs' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '5010', 'name' => 'Media Buying', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']),
        'opex' => LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '6010', 'name' => 'Salaries', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']),
    ];
}

function m5Journal(Entity $entity, string $debitAccountId, string $creditAccountId, string $amount, string $date, ?string $sbu = null): JournalEntry
{
    $period = AccountingPeriod::query()->where('entity_id', $entity->id)->first();
    $entry = JournalEntry::query()->create(['entity_id' => $entity->id, 'period_id' => $period->id, 'period_ref' => $period->period_ref, 'entry_type' => 'manual', 'entry_date' => $date, 'state' => 'posted', 'narration' => 'M5 fixture', 'source_document_id' => (string) Str::uuid(), 'posted_at' => now('UTC'), 'posted_by' => (string) Str::uuid()]);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $debitAccountId, 'line_no' => 1, 'description' => 'Debit', 'debit' => $amount, 'credit' => '0.0000', 'currency' => 'BDT', 'sbu_tag' => $sbu]);
    $entry->lines()->create(['entity_id' => $entity->id, 'account_id' => $creditAccountId, 'line_no' => 2, 'description' => 'Credit', 'debit' => '0.0000', 'credit' => $amount, 'currency' => 'BDT', 'sbu_tag' => $sbu]);

    return $entry;
}

it('computes a Trial Balance with period movement and keeps debits equal to credits', function (): void {
    [$entity, $owner] = m5Actors();
    $accounts = m5ChartOfAccounts($entity);
    m5Journal($entity, $accounts['ar']->id, $accounts['revenue']->id, '10000.0000', '2026-07-15');
    m5Journal($entity, $accounts['cogs']->id, $accounts['cash']->id, '4000.0000', '2026-07-16');
    Sanctum::actingAs($owner);

    $this->getJson('/v1/reports/trial-balance?asOf=2026-07-31&period_ref=2026-07', ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('totals.balanced', true)
        ->assertJsonPath('totals.debit', '14000.0000')
        ->assertJsonPath('totals.credit', '14000.0000');
});

it('computes Profit and Loss using the frozen skeleton and versioned classification, never inferring from names', function (): void {
    [$entity, $owner] = m5Actors();
    $accounts = m5ChartOfAccounts($entity);
    m5Journal($entity, $accounts['ar']->id, $accounts['revenue']->id, '10000.0000', '2026-07-15');
    m5Journal($entity, $accounts['cogs']->id, $accounts['cash']->id, '4000.0000', '2026-07-16');
    m5Journal($entity, $accounts['opex']->id, $accounts['cash']->id, '1000.0000', '2026-07-17');
    ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'profit_and_loss', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    AccountClassificationVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'entries' => [
        ['account_id' => $accounts['revenue']->id, 'code' => '4010', 'classification' => 'sales_revenue'],
        ['account_id' => $accounts['cogs']->id, 'code' => '5010', 'classification' => 'cost_of_sales'],
        ['account_id' => $accounts['opex']->id, 'code' => '6010', 'classification' => 'operating_expense'],
        ['account_id' => $accounts['ar']->id, 'code' => '1060', 'classification' => 'asset_current'],
        ['account_id' => $accounts['cash']->id, 'code' => '1010', 'classification' => 'asset_current'],
    ], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    Sanctum::actingAs($owner);

    $response = $this->getJson('/v1/reports/profit-loss?period=2026-07&basis=accrual', ['X-Entity-Id' => $entity->id])->assertOk()->json();
    $lines = collect($response['lines'])->keyBy('section_id');
    expect($lines['sales_revenue']['amount']['amount'])->toBe('10000.0000')
        ->and($lines['total_cost_of_sales']['amount']['amount'])->toBe('4000.0000')
        ->and($lines['gross_profit']['amount']['amount'])->toBe('6000.0000')
        ->and($lines['gross_profit_pct']['percentage'])->toBe('60.0000')
        ->and($lines['total_operating_expense']['amount']['amount'])->toBe('1000.0000')
        ->and($lines['operating_profit']['amount']['amount'])->toBe('5000.0000')
        ->and($lines['net_profit']['amount']['amount'])->toBe('5000.0000')
        ->and($lines['net_profit_pct']['percentage'])->toBe('50.0000');

    $this->getJson('/v1/reports/profit-loss?period=2026-07&basis=cash', ['X-Entity-Id' => $entity->id])
        ->assertUnprocessable()->assertJsonPath('error_code', 'unsupported_basis');
});

it('rejects Profit and Loss generation for an unclassified posted-to account', function (): void {
    [$entity, $owner] = m5Actors();
    $accounts = m5ChartOfAccounts($entity);
    m5Journal($entity, $accounts['ar']->id, $accounts['revenue']->id, '500.0000', '2026-07-15');
    ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'profit_and_loss', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    AccountClassificationVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'entries' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    Sanctum::actingAs($owner);

    $this->getJson('/v1/reports/profit-loss?period=2026-07&basis=accrual', ['X-Entity-Id' => $entity->id])
        ->assertUnprocessable()->assertJsonPath('error_code', 'unclassified_account');
});

it('balances the Balance Sheet with Assets = Liabilities + Equity, including current-year result', function (): void {
    [$entity, $owner] = m5Actors();
    $accounts = m5ChartOfAccounts($entity);
    m5Journal($entity, $accounts['cash']->id, $accounts['equity']->id, '20000.0000', '2026-07-01');
    m5Journal($entity, $accounts['ar']->id, $accounts['revenue']->id, '10000.0000', '2026-07-15');
    m5Journal($entity, $accounts['cogs']->id, $accounts['cash']->id, '4000.0000', '2026-07-16');
    ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'balance_sheet', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    AccountClassificationVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'entries' => [
        ['account_id' => $accounts['cash']->id, 'code' => '1010', 'classification' => 'asset_current'],
        ['account_id' => $accounts['ar']->id, 'code' => '1060', 'classification' => 'asset_current'],
        ['account_id' => $accounts['equity']->id, 'code' => '3010', 'classification' => 'equity'],
        ['account_id' => $accounts['revenue']->id, 'code' => '4010', 'classification' => 'sales_revenue'],
        ['account_id' => $accounts['cogs']->id, 'code' => '5010', 'classification' => 'cost_of_sales'],
    ], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    Sanctum::actingAs($owner);

    $response = $this->getJson('/v1/reports/balance-sheet?asOf=2026-07-31', ['X-Entity-Id' => $entity->id])->assertOk()->json();
    expect($response['difference']['amount'])->toBe('0.0000')
        ->and($response['total_assets']['amount'])->toBe('26000.0000')
        ->and($response['total_equity']['amount'])->toBe('26000.0000');
});

it('ages open receivables into the frozen five buckets and keeps unapplied credit separate', function (): void {
    [$entity, $owner] = m5Actors();
    $customer = Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Cust', 'normalized_name' => 'CUST', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $current = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-08-05', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '100.0000', 'status' => 'sent', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $overdue = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-2', 'customer_id' => $customer->id, 'invoice_date' => '2026-05-01', 'due_date' => '2026-05-15', 'currency' => 'BDT', 'subtotal' => '200.0000', 'tax_total' => '0.0000', 'total' => '200.0000', 'open_balance' => '200.0000', 'status' => 'sent', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    AgeingBucketSetVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'buckets' => [
        ['bucket_id' => 'not_due', 'label' => 'Not Due', 'lower_days' => null, 'upper_days' => -1, 'order' => 1],
        ['bucket_id' => 'overdue_0_30', 'label' => '0-30', 'lower_days' => 0, 'upper_days' => 30, 'order' => 2],
        ['bucket_id' => 'overdue_31_60', 'label' => '31-60', 'lower_days' => 31, 'upper_days' => 60, 'order' => 3],
        ['bucket_id' => 'overdue_61_90', 'label' => '61-90', 'lower_days' => 61, 'upper_days' => 90, 'order' => 4],
        ['bucket_id' => 'overdue_90_plus', 'label' => '91+', 'lower_days' => 91, 'upper_days' => null, 'order' => 5],
    ], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    Sanctum::actingAs($owner);

    $response = $this->getJson('/v1/reports/ar-ageing?asOf=2026-07-31', ['X-Entity-Id' => $entity->id])->assertOk()->json();
    $byInvoice = collect($response['detail'])->keyBy('invoice_id');
    expect($byInvoice[$current->id]['bucket_id'])->toBe('not_due')
        ->and($byInvoice[$overdue->id]['bucket_id'])->toBe('overdue_61_90');
});

it('rejects ageing generation with no configured bucket set', function (): void {
    [$entity, $owner] = m5Actors();
    Sanctum::actingAs($owner);

    $this->getJson('/v1/reports/ar-ageing?asOf=2026-07-31', ['X-Entity-Id' => $entity->id])
        ->assertUnprocessable()->assertJsonPath('error_code', 'missing_ageing_bucket_set');
});

it('sums Cash View collections and payments from posted Allocations within the period', function (): void {
    [$entity, $owner] = m5Actors();
    $customer = Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Cust', 'normalized_name' => 'CUST', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    Allocation::query()->create(['entity_id' => $entity->id, 'operation' => 'receipt', 'party_type' => 'customer', 'party_id' => $customer->id, 'settlement_date' => '2026-07-10', 'currency' => 'BDT', 'gross_amount' => '1000.0000', 'bank_amount' => '950.0000', 'withholding_amount' => '50.0000', 'allocated_amount' => '1000.0000', 'unapplied_amount' => '0.0000', 'functional_gross_amount' => '1000.0000', 'journal_entry_ids' => [], 'state' => 'posted', 'version' => 1, 'posted_at' => now('UTC'), 'created_by' => (string) Str::uuid()]);
    CashViewPolicyVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'policy' => ['recognition_date_source' => 'settlement_date'], 'effective_from' => '2026-01-01', 'effective_to' => null]);
    Sanctum::actingAs($owner);

    $this->getJson('/v1/reports/cash-view?period=2026-07', ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('collections.amount', '950.0000')
        ->assertJsonPath('withheld_excluded.amount', '50.0000');
});
