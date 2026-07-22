<?php

namespace Database\Seeders;

use App\Identity\Application\ApprovalLifecycleService;
use App\Models\CurrencyFx\RateRecord;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\LedgerAccount;
use App\Models\Period\AccountingPeriod;
use App\Models\Tax\TaxCode;
use App\Models\Tax\TaxCodeVersion;
use App\Models\Tax\TaxPack;
use App\Models\User;
use App\Payables\Application\BillService;
use App\Payables\Application\ExpenseService;
use App\Payables\Application\VendorService;
use App\Receivables\Application\CustomerService;
use App\Receivables\Application\InvoiceService;
use App\Support\Documents\DocumentActionResult;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Local/UAT-only deterministic data. Never run this seeder against a shared or
 * production database. It deliberately uses illustrative policy values.
 */
final class M2UatSeeder extends Seeder
{
    private const string ENTITY_ID = '10000000-0000-4000-8000-000000000001';

    private const string MAKER_ID = '10000000-0000-4000-8000-000000000101';

    private const string CHECKER_ID = '10000000-0000-4000-8000-000000000102';

    private const string AUDITOR_ID = '10000000-0000-4000-8000-000000000103';

    private const string AR_ACCOUNT = '20000000-0000-4000-8000-000000000001';

    private const string BANK_ACCOUNT = '20000000-0000-4000-8000-000000000002';

    private const string INPUT_TAX_ACCOUNT = '20000000-0000-4000-8000-000000000003';

    private const string AP_ACCOUNT = '20000000-0000-4000-8000-000000000004';

    private const string OUTPUT_TAX_ACCOUNT = '20000000-0000-4000-8000-000000000005';

    private const string REVENUE_ACCOUNT = '20000000-0000-4000-8000-000000000006';

    private const string EXPENSE_ACCOUNT = '20000000-0000-4000-8000-000000000007';

    private const string TAX_CODE_ID = '30000000-0000-4000-8000-000000000001';

    private const string TAX_VERSION_ID = '30000000-0000-4000-8000-000000000002';

    private const string TAX_PACK_ID = '30000000-0000-4000-8000-000000000003';

    private const string USD_RATE_ID = '40000000-0000-4000-8000-000000000001';

    private const string PASSWORD = 'UatOnly!ChangeMe2026';

    public function run(): void
    {
        if (! in_array(app()->environment(), ['local', 'testing'], true) || filter_var(env('HIVEFIN_UAT_SEED_ALLOWED'), FILTER_VALIDATE_BOOL) !== true) {
            throw new RuntimeException('M2UatSeeder is restricted to local/testing with HIVEFIN_UAT_SEED_ALLOWED=true.');
        }
        if (Entity::query()->whereKey(self::ENTITY_ID)->exists()) {
            throw new RuntimeException('The M2 UAT entity already exists. Use a dedicated database and migrate:fresh before reseeding.');
        }

        Carbon::setTestNow('2026-07-20T10:00:00Z');
        request()->attributes->set('correlation_id', '70000000-0000-4000-8000-000000000001');

        try {
            DB::transaction(fn () => $this->seedReleaseCandidate());
        } finally {
            Carbon::setTestNow();
        }
    }

    private function seedReleaseCandidate(): void
    {
        $entity = $this->withId(new Entity, self::ENTITY_ID, [
            'legal_name' => 'HiveFinance M2 UAT — LOCAL ONLY',
            'functional_currency' => 'BDT',
            'fiscal_year_start_month' => 7,
            'fiscal_year_start_day' => 1,
            'approval_policy' => ['bill_approve' => ['maker_checker_required' => true]],
            'settings' => ['uat_only' => true, 'sbu_codes' => ['OPS', 'SALES']],
        ]);

        $maker = $this->user(self::MAKER_ID, 'M2 UAT Finance Preparer', 'maker.m2.uat@hivefinance.local');
        $checker = $this->user(self::CHECKER_ID, 'M2 UAT Finance Approver', 'checker.m2.uat@hivefinance.local');
        $auditor = $this->user(self::AUDITOR_ID, 'M2 UAT Auditor', 'auditor.m2.uat@hivefinance.local');
        $makerRole = $this->role('50000000-0000-4000-8000-000000000001', $entity, 'M2 UAT Finance Preparer', 'finance-staff', [
            'receivables.customers.manage', 'receivables.customers.read', 'receivables.invoices.create', 'receivables.invoices.issue', 'receivables.invoices.read',
            'payables.vendors.manage', 'payables.vendors.read', 'payables.bills.create', 'payables.bills.approve', 'payables.bills.read', 'payables.expenses.create', 'payables.expenses.read',
            'ledger.accounts.read', 'ledger.journals.read', 'ledger.reports.read', 'periods.read', 'tax.codes.read', 'fx.rates.read',
        ]);
        $checkerRole = $this->role('50000000-0000-4000-8000-000000000002', $entity, 'M2 UAT Finance Approver', 'accountant', [
            'identity.approvals.approve', 'payables.bills.approve', 'payables.bills.read', 'payables.vendors.read', 'payables.expenses.read',
            'receivables.customers.read', 'receivables.invoices.read', 'ledger.accounts.read', 'ledger.journals.read', 'ledger.reports.read', 'periods.read', 'tax.codes.read', 'fx.rates.read',
        ]);
        $auditorRole = $this->role('50000000-0000-4000-8000-000000000003', $entity, 'M2 UAT Read-only Auditor', 'auditor', [
            'receivables.customers.read', 'receivables.invoices.read', 'payables.vendors.read', 'payables.bills.read', 'payables.expenses.read',
            'ledger.accounts.read', 'ledger.journals.read', 'ledger.reports.read', 'periods.read', 'tax.codes.read', 'fx.rates.read',
        ]);
        foreach ([[$maker, $makerRole], [$checker, $checkerRole], [$auditor, $auditorRole]] as [$user, $role]) {
            $user->entities()->attach($entity->id, ['status' => 'active']);
            $user->roles()->attach($role->id, ['entity_id' => $entity->id]);
        }

        $this->withId(new AccountingPeriod, '60000000-0000-4000-8000-000000000001', ['entity_id' => $entity->id, 'period_ref' => 'FY2026-P01', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open', 'vat_lock_status' => 'unlocked', 'version' => 1]);
        $this->account(self::AR_ACCOUNT, $entity, '1100', 'Trade Receivables', 'asset', 'debit');
        $this->account(self::BANK_ACCOUNT, $entity, '1200', 'UAT Operating Bank', 'asset', 'debit', ['currency' => 'BDT']);
        $this->account(self::INPUT_TAX_ACCOUNT, $entity, '1300', 'Recoverable Input VAT', 'asset', 'debit');
        $this->account(self::AP_ACCOUNT, $entity, '2100', 'Trade Payables', 'liability', 'credit');
        $this->account(self::OUTPUT_TAX_ACCOUNT, $entity, '2200', 'Output VAT Payable', 'liability', 'credit');
        $this->account(self::REVENUE_ACCOUNT, $entity, '4100', 'Service Revenue', 'revenue', 'credit');
        $this->account(self::EXPENSE_ACCOUNT, $entity, '5100', 'Operating Expense', 'expense', 'debit');
        $this->taxAndFx($entity);
        $this->configureApplication();

        $customers = app(CustomerService::class);
        $domesticCustomer = $this->resource($customers->create($maker, $entity->id, ['name' => 'UAT Domestic Customer', 'type' => 'local', 'jurisdiction' => 'BD', 'tax_identifier' => 'UAT-CUST-001', 'default_currency' => 'BDT', 'payment_terms' => 'NET30'], '80000000-0000-4000-8000-000000000001'), 'customer');
        $foreignCustomer = $this->resource($customers->create($maker, $entity->id, ['name' => 'UAT Foreign Customer', 'type' => 'foreign', 'jurisdiction' => 'BD', 'tax_identifier' => 'UAT-CUST-002', 'default_currency' => 'USD', 'payment_terms' => 'NET15'], '80000000-0000-4000-8000-000000000002'), 'customer');
        $inactiveCustomer = $this->resource($customers->create($maker, $entity->id, ['name' => 'UAT Deactivated Customer', 'type' => 'local', 'jurisdiction' => 'BD', 'tax_identifier' => 'UAT-CUST-003', 'default_currency' => 'BDT', 'payment_terms' => 'NET30'], '80000000-0000-4000-8000-000000000003'), 'customer');
        $this->expect($customers->deactivate($maker, $entity->id, $inactiveCustomer['id'], '80000000-0000-4000-8000-000000000004', '1'));

        $vendors = app(VendorService::class);
        $primaryVendor = $this->resource($vendors->create($maker, $entity->id, ['name' => 'UAT Domestic Vendor', 'jurisdiction' => 'BD', 'tax_identifier' => 'UAT-VEND-001', 'default_currency' => 'BDT', 'payment_terms' => 'NET30', 'bank_details' => ['account_name' => 'UAT Vendor', 'institution_name' => 'UAT Bank', 'account_identifier' => '012345678901', 'routing_identifier' => 'UAT-ROUTE-01']], '80000000-0000-4000-8000-000000000011'), 'vendor');
        $secondaryVendor = $this->resource($vendors->create($maker, $entity->id, ['name' => 'UAT Approval Vendor', 'jurisdiction' => 'BD', 'tax_identifier' => 'UAT-VEND-002', 'default_currency' => 'BDT', 'payment_terms' => 'NET15'], '80000000-0000-4000-8000-000000000012'), 'vendor');
        $inactiveVendor = $this->resource($vendors->create($maker, $entity->id, ['name' => 'UAT Deactivated Vendor', 'jurisdiction' => 'BD', 'tax_identifier' => 'UAT-VEND-003', 'default_currency' => 'BDT', 'payment_terms' => 'NET30'], '80000000-0000-4000-8000-000000000013'), 'vendor');
        $this->expect($vendors->deactivate($maker, $entity->id, $inactiveVendor['id'], '80000000-0000-4000-8000-000000000014', '1'));

        $invoices = app(InvoiceService::class);
        $domesticInvoice = $this->resource($invoices->create($maker, $entity->id, ['customer_id' => $domesticCustomer['id'], 'invoice_date' => '2026-07-15', 'currency' => 'BDT', 'reference' => 'UAT-DOMESTIC-INVOICE', 'lines' => [['description' => 'Domestic consulting', 'quantity' => '1.0000', 'unit_price' => ['amount' => '1000.0000', 'currency' => 'BDT'], 'tax_code_id' => self::TAX_CODE_ID]]], '80000000-0000-4000-8000-000000000021'), 'invoice');
        $this->expect($invoices->issue($maker, $entity->id, $domesticInvoice['id'], '80000000-0000-4000-8000-000000000022', '1'));
        $foreignInvoice = $this->resource($invoices->create($maker, $entity->id, ['customer_id' => $foreignCustomer['id'], 'invoice_date' => '2026-07-16', 'currency' => 'USD', 'reference' => 'UAT-FOREIGN-INVOICE', 'rate_record_id' => self::USD_RATE_ID, 'lines' => [['description' => 'Foreign consulting', 'quantity' => '1.0000', 'unit_price' => ['amount' => '100.0000', 'currency' => 'USD'], 'tax_code_id' => self::TAX_CODE_ID]]], '80000000-0000-4000-8000-000000000023'), 'invoice');
        $this->expect($invoices->issue($maker, $entity->id, $foreignInvoice['id'], '80000000-0000-4000-8000-000000000024', '1'));

        $bills = app(BillService::class);
        $approvedBill = $this->resource($bills->create($maker, $entity->id, $this->billData($primaryVendor['id'], '500.0000', 'UAT-DOMESTIC-BILL', self::TAX_CODE_ID), '80000000-0000-4000-8000-000000000031'), 'bill');
        $approval = $this->expect($bills->approve($maker, $entity->id, $approvedBill['id'], '80000000-0000-4000-8000-000000000032', '1'))->payload['approval'];
        $approvalResult = app(ApprovalLifecycleService::class)->approve($checker, $entity->id, $approval['id'], '80000000-0000-4000-8000-000000000033', '1', '70000000-0000-4000-8000-000000000033');
        if (! $approvalResult->ok) {
            throw new RuntimeException('Approved UAT bill replay failed: '.json_encode($approvalResult->payload, JSON_THROW_ON_ERROR));
        }
        $pendingBill = $this->resource($bills->create($maker, $entity->id, $this->billData($secondaryVendor['id'], '250.0000', 'UAT-PENDING-BILL', null), '80000000-0000-4000-8000-000000000034'), 'bill');
        $pendingApproval = $this->expect($bills->approve($maker, $entity->id, $pendingBill['id'], '80000000-0000-4000-8000-000000000035', '1'))->payload['approval'];

        $expenses = app(ExpenseService::class);
        $this->expect($expenses->create($maker, $entity->id, ['expense_date' => '2026-07-18', 'description' => 'UAT office supplies', 'category_account_id' => self::EXPENSE_ACCOUNT, 'settlement_type' => 'cash', 'bank_account_id' => self::BANK_ACCOUNT, 'currency' => 'BDT', 'amount' => ['amount' => '120.0000', 'currency' => 'BDT'], 'tax_code_id' => null, 'ait' => null, 'sbu_allocations' => [['sbu_code' => 'OPS', 'weight' => '1.0000']]], '80000000-0000-4000-8000-000000000041'));

        $this->command?->newLine();
        $this->command?->info('M2 UAT seed complete (LOCAL/UAT ONLY).');
        $this->command?->line('Entity ID: '.self::ENTITY_ID);
        $this->command?->line('Pending bill approval ID: '.$pendingApproval['id']);
        $this->command?->line('Shared UAT password: '.self::PASSWORD);
    }

    private function configureApplication(): void
    {
        config()->set('documents.supported_currencies', ['BDT', 'USD']);
        config()->set('documents.payment_terms', ['NET15' => 15, 'NET30' => 30]);
        config()->set('documents.invoice.number_prefix', 'UAT-INV');
        config()->set('documents.invoice.number_format', '{prefix}-{fiscal_year}-{sequence}');
        config()->set('documents.invoice.revenue_account_id', self::REVENUE_ACCOUNT);
        config()->set('documents.invoice.receivable_account_id', self::AR_ACCOUNT);
        config()->set('documents.bill.number_prefix', 'UAT-BILL');
        config()->set('documents.bill.number_format', '{prefix}-{fiscal_year}-{sequence}');
        config()->set('documents.bill.payable_account_id', self::AP_ACCOUNT);
        config()->set('documents.expense.payable_account_id', self::AP_ACCOUNT);
        config()->set('valuation.tax.exclusive_methods', ['exclusive']);
        config()->set('valuation.tax.inclusive_methods', []);
        config()->set('valuation.fx.sources', ['uat_manual']);
        config()->set('valuation.fx.source_precedence', ['uat_manual']);
        config()->set('valuation.fx.rounding_mode', 'half_up');
        config()->set('valuation.fx.rounding_scale', 4);
    }

    private function taxAndFx(Entity $entity): void
    {
        $tax = $this->withId(new TaxCode, self::TAX_CODE_ID, ['entity_id' => $entity->id, 'code' => 'UAT-VAT10', 'name' => 'UAT-only illustrative VAT 10%', 'jurisdiction' => 'BD', 'status' => 'active', 'version' => 1]);
        $this->withId(new TaxCodeVersion, self::TAX_VERSION_ID, ['tax_code_id' => $tax->id, 'entity_id' => $entity->id, 'version_number' => 1, 'treatment' => 'standard', 'rate' => '10.00000000', 'recoverable' => true, 'calculation_method' => 'exclusive', 'gl_mapping' => ['input_account_id' => self::INPUT_TAX_ACCOUNT, 'output_account_id' => self::OUTPUT_TAX_ACCOUNT], 'return_box_mapping' => ['output' => 'UAT-OUTPUT', 'input' => 'UAT-INPUT'], 'effective_from' => '2026-07-01', 'effective_to' => null, 'referenced' => false]);
        $this->withId(new TaxPack, self::TAX_PACK_ID, ['entity_id' => $entity->id, 'jurisdiction' => 'BD', 'name' => 'M2 UAT TaxPack', 'tax_code_ids' => [$tax->id], 'return_template' => ['template_key' => 'uat-only'], 'policy' => ['uat_only' => true], 'version' => 1]);
        $this->withId(new RateRecord, self::USD_RATE_ID, ['entity_id' => $entity->id, 'base_currency' => 'USD', 'quote_currency' => 'BDT', 'rate' => '120.00000000', 'effective_date' => '2026-07-01', 'source' => 'uat_manual', 'is_override' => false, 'override_reason' => null, 'referenced' => false]);
    }

    /** @return array<string, mixed> */
    private function billData(string $vendorId, string $amount, string $reference, ?string $taxCodeId): array
    {
        return ['vendor_id' => $vendorId, 'vendor_reference' => $reference, 'bill_date' => '2026-07-17', 'currency' => 'BDT', 'lines' => [['description' => $reference, 'quantity' => '1.0000', 'unit_price' => ['amount' => $amount, 'currency' => 'BDT'], 'expense_account_id' => self::EXPENSE_ACCOUNT, 'tax_code_id' => $taxCodeId]], 'sbu_allocations' => [['sbu_code' => 'OPS', 'weight' => '1.0000']]];
    }

    private function user(string $id, string $name, string $email): User
    {
        return $this->withId(new User, $id, ['name' => $name, 'email' => $email, 'password' => self::PASSWORD, 'status' => 'active', 'active_entity_id' => self::ENTITY_ID, 'mfa_required' => false, 'mfa_enabled' => false]);
    }

    /** @param list<string> $permissions */
    private function role(string $id, Entity $entity, string $name, string $slug, array $permissions): Role
    {
        $role = $this->withId(new Role, $id, ['entity_id' => $entity->id, 'name' => $name, 'slug' => $slug, 'is_system' => true, 'rank' => 10]);
        $role->permissions()->createMany(array_map(fn (string $permission): array => ['permission' => $permission], $permissions));

        return $role;
    }

    /** @param array<string, mixed>|null $bankAttributes */
    private function account(string $id, Entity $entity, string $code, string $name, string $type, string $normalBalance, ?array $bankAttributes = null): LedgerAccount
    {
        return $this->withId(new LedgerAccount, $id, ['entity_id' => $entity->id, 'code' => $code, 'name' => $name, 'description' => 'Local M2 UAT only', 'type' => $type, 'normal_balance' => $normalBalance, 'status' => 'active', 'bank_attributes' => $bankAttributes, 'version' => 1]);
    }

    private function expect(DocumentActionResult $result): DocumentActionResult
    {
        if ($result->status >= 400) {
            throw new RuntimeException('M2 UAT seed command failed: '.json_encode($result->payload, JSON_THROW_ON_ERROR));
        }

        return $result;
    }

    /** @return array<string, mixed> */
    private function resource(DocumentActionResult $result, string $key): array
    {
        $payload = $this->expect($result)->payload[$key] ?? null;
        if (! is_array($payload)) {
            throw new RuntimeException("M2 UAT seed response did not contain {$key}.");
        }

        return $payload;
    }

    /** @template T of Model
     * @param  T  $model
     * @param  array<string, mixed>  $attributes
     * @return T
     */
    private function withId(Model $model, string $id, array $attributes): Model
    {
        $model->forceFill(['id' => $id, ...$attributes])->save();

        return $model;
    }
}
