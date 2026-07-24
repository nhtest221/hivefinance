<?php

namespace Database\Seeders;

use App\Identity\Application\ApprovalLifecycleService;
use App\Models\CurrencyFx\RateRecord;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\LedgerAccount;
use App\Models\Payables\Bill;
use App\Models\Period\AccountingPeriod;
use App\Models\Receivables\Invoice;
use App\Models\Reporting\AccountClassificationVersion;
use App\Models\Reporting\AgeingBucketSetVersion;
use App\Models\Reporting\CashViewPolicyVersion;
use App\Models\Reporting\ReportLayoutVersion;
use App\Models\Tax\TaxCode;
use App\Models\Tax\TaxCodeVersion;
use App\Models\Tax\TaxPack;
use App\Models\User;
use App\Payables\Application\BillService;
use App\Payables\Application\DebitNoteService;
use App\Payables\Application\ExpenseService;
use App\Payables\Application\VendorService;
use App\Receivables\Application\CreditNoteService;
use App\Receivables\Application\CustomerService;
use App\Receivables\Application\InvoiceService;
use App\Reconciliation\Application\ReconciliationAccountService;
use App\Settlement\Application\SettlementService;
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

    private const string CUSTOMER_CREDIT_ACCOUNT = '20000000-0000-4000-8000-000000000008';

    private const string VENDOR_CREDIT_ACCOUNT = '20000000-0000-4000-8000-000000000009';

    private const string FX_GAIN_ACCOUNT = '20000000-0000-4000-8000-00000000000a';

    private const string FX_LOSS_ACCOUNT = '20000000-0000-4000-8000-00000000000b';

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
            'receivables.customers.manage', 'receivables.customers.read', 'receivables.invoices.create', 'receivables.invoices.issue', 'receivables.invoices.read', 'receivables.invoices.void',
            'payables.vendors.manage', 'payables.vendors.read', 'payables.bills.create', 'payables.bills.approve', 'payables.bills.read', 'payables.bills.void', 'payables.expenses.create', 'payables.expenses.read',
            'ledger.accounts.read', 'ledger.journals.read', 'ledger.reports.read', 'periods.read', 'tax.codes.read', 'fx.rates.read',
            'settlement.receipts.create', 'settlement.payments.create', 'settlement.allocations.read', 'settlement.allocations.reverse', 'settlement.credits.read', 'settlement.credits.apply', 'settlement.credits.refund',
            'receivables.credit_notes.read', 'receivables.credit_notes.create', 'receivables.credit_notes.post', 'receivables.credit_notes.hold', 'receivables.credit_notes.apply', 'receivables.credit_notes.refund', 'receivables.credit_notes.reverse',
            'payables.debit_notes.read', 'payables.debit_notes.create', 'payables.debit_notes.post', 'payables.debit_notes.hold', 'payables.debit_notes.apply', 'payables.debit_notes.refund', 'payables.debit_notes.reverse',
            'reconciliation.accounts.read', 'reconciliation.accounts.configure',
            'reconciliation.reconciliations.read', 'reconciliation.reconciliations.open', 'reconciliation.reconciliations.import', 'reconciliation.reconciliations.generate_suggestions', 'reconciliation.reconciliations.match', 'reconciliation.reconciliations.confirm', 'reconciliation.reconciliations.create_bank_entry', 'reconciliation.reconciliations.complete', 'reconciliation.reconciliations.reopen',
            // Periods, Tax, FX, and Reporting UAT scope (2026-07 gap-closure pass).
            'periods.soft_close', 'periods.hard_close', 'periods.reopen',
            'tax.codes.manage', 'fx.rates.manage',
            'reporting.report_runs.generate', 'reporting.report_runs.approve', 'reporting.report_runs.read',
            'reporting.ar_ageing.read', 'reporting.ap_ageing.read', 'reporting.profit_and_loss.read', 'reporting.balance_sheet.read', 'reporting.tax_summary.read', 'reporting.fx_revaluation.read', 'reporting.cash_view.read',
            // UAT-only: lets the Maker complete an approval a distinct actor (the Checker) submitted,
            // e.g. the ReportRun-approve action is itself approval-gated (report_run_approve), so a
            // *third* distinct actor is needed to complete it. Self-approval remains blocked
            // (ApprovalRequest.maker_id !== approver check) regardless of this grant. Never a
            // production default — see the class-level UAT-only warning.
            'identity.approvals.approve',
        ]);
        $checkerRole = $this->role('50000000-0000-4000-8000-000000000002', $entity, 'M2 UAT Finance Approver', 'accountant', [
            'identity.approvals.approve', 'payables.bills.approve', 'payables.bills.read', 'payables.bills.void', 'payables.vendors.read', 'payables.expenses.read',
            'receivables.customers.read', 'receivables.invoices.read', 'receivables.invoices.void', 'ledger.accounts.read', 'ledger.journals.read', 'ledger.reports.read', 'periods.read', 'tax.codes.read', 'fx.rates.read',
            // approve() additionally requires the checker to hold the exact gated capability of whatever they are approving (ApprovalLifecycleService::canApprove), not just identity.approvals.approve.
            'settlement.receipts.create', 'settlement.payments.create', 'settlement.allocations.read', 'settlement.credits.read',
            'receivables.credit_notes.read', 'receivables.credit_notes.post', 'payables.debit_notes.read', 'payables.debit_notes.post',
            'reconciliation.accounts.read', 'reconciliation.reconciliations.read',
            'reconciliation.reconciliations.create_bank_entry', 'reconciliation.reconciliations.complete', 'reconciliation.reconciliations.reopen',
            // Periods, Tax, FX, and Reporting UAT scope (2026-07 gap-closure pass).
            'periods.soft_close', 'periods.hard_close', 'periods.reopen',
            'tax.codes.manage', 'fx.rates.manage',
            'reporting.report_runs.approve', 'reporting.report_runs.read',
            'reporting.ar_ageing.read', 'reporting.ap_ageing.read', 'reporting.profit_and_loss.read', 'reporting.balance_sheet.read', 'reporting.tax_summary.read', 'reporting.fx_revaluation.read', 'reporting.cash_view.read',
        ]);
        $auditorRole = $this->role('50000000-0000-4000-8000-000000000003', $entity, 'M2 UAT Read-only Auditor', 'auditor', [
            'receivables.customers.read', 'receivables.invoices.read', 'payables.vendors.read', 'payables.bills.read', 'payables.expenses.read',
            'ledger.accounts.read', 'ledger.journals.read', 'ledger.reports.read', 'periods.read', 'tax.codes.read', 'fx.rates.read',
            'settlement.allocations.read', 'settlement.credits.read', 'receivables.credit_notes.read', 'payables.debit_notes.read', 'reconciliation.accounts.read', 'reconciliation.reconciliations.read',
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
        $this->account(self::CUSTOMER_CREDIT_ACCOUNT, $entity, '2110', 'Customer Credit (Unapplied)', 'liability', 'credit');
        $this->account(self::VENDOR_CREDIT_ACCOUNT, $entity, '1150', 'Vendor Advance (Unapplied)', 'asset', 'debit');
        $this->account(self::FX_GAIN_ACCOUNT, $entity, '4200', 'Realised FX Gain', 'revenue', 'credit');
        $this->account(self::FX_LOSS_ACCOUNT, $entity, '5200', 'Realised FX Loss', 'expense', 'debit');
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
        $approvals = app(ApprovalLifecycleService::class);
        $approvedBill = $this->resource($bills->create($maker, $entity->id, $this->billData($primaryVendor['id'], '500.0000', 'UAT-DOMESTIC-BILL', self::TAX_CODE_ID), '80000000-0000-4000-8000-000000000031'), 'bill');
        $this->approveIfPending($approvals, $checker, $entity->id, $bills->approve($maker, $entity->id, $approvedBill['id'], '80000000-0000-4000-8000-000000000032', '1'), '80000000-0000-4000-8000-000000000033', '70000000-0000-4000-8000-000000000033');
        $pendingBill = $this->resource($bills->create($maker, $entity->id, $this->billData($secondaryVendor['id'], '250.0000', 'UAT-PENDING-BILL', null), '80000000-0000-4000-8000-000000000034'), 'bill');
        $pendingApproval = $this->expect($bills->approve($maker, $entity->id, $pendingBill['id'], '80000000-0000-4000-8000-000000000035', '1'))->payload['approval'];

        $expenses = app(ExpenseService::class);
        $this->expect($expenses->create($maker, $entity->id, ['expense_date' => '2026-07-18', 'description' => 'UAT office supplies', 'category_account_id' => self::EXPENSE_ACCOUNT, 'settlement_type' => 'cash', 'bank_account_id' => self::BANK_ACCOUNT, 'currency' => 'BDT', 'amount' => ['amount' => '120.0000', 'currency' => 'BDT'], 'tax_code_id' => null, 'ait' => null, 'sbu_allocations' => [['sbu_code' => 'OPS', 'weight' => '1.0000']]], '80000000-0000-4000-8000-000000000041'));

        $this->seedReportingConfiguration($entity);

        $this->seedNotesSettlementAndReconciliation($entity, $maker, $checker, $domesticCustomer['id'], $domesticInvoice['id'], $primaryVendor['id'], $approvedBill['id']);

        $this->command?->newLine();
        $this->command?->info('M0-M6 UAT seed complete (LOCAL/UAT ONLY).');
        $this->command?->line('Entity ID: '.self::ENTITY_ID);
        $this->command?->line('Pending bill approval ID: '.$pendingApproval['id']);
        $this->command?->line('Shared UAT password: '.self::PASSWORD);
        $this->command?->line('Seeded: customers/vendors/invoices/bills/expenses (M2), a posted credit note and debit note (M4A), receipts/a customer credit advance/a payment (M3), and a reconciliation account (M6).');
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
        config()->set('valuation.tax.jurisdictions', ['BD']);
        config()->set('valuation.fx.sources', ['uat_manual']);
        config()->set('valuation.fx.source_precedence', ['uat_manual']);
        config()->set('valuation.fx.rounding_mode', 'half_up');
        config()->set('valuation.fx.rounding_scale', 4);
        config()->set('documents.reason_codes', ['UAT_SERVICE_ADJUSTMENT']);
        config()->set('documents.credit_note.number_prefix', 'UAT-CN');
        config()->set('documents.credit_note.number_format', '{prefix}-{fiscal_year}-{sequence}');
        config()->set('documents.debit_note.number_prefix', 'UAT-DN');
        config()->set('documents.debit_note.number_format', '{prefix}-{fiscal_year}-{sequence}');
        config()->set('settlement.receipt', ['number_prefix' => 'UAT-RCPT', 'number_format' => '{prefix}-{fiscal_year}-{sequence}']);
        config()->set('settlement.payment', ['number_prefix' => 'UAT-PAY', 'number_format' => '{prefix}-{fiscal_year}-{sequence}']);
        config()->set('settlement.refund', ['number_prefix' => 'UAT-REF', 'number_format' => '{prefix}-{fiscal_year}-{sequence}']);
        config()->set('settlement.accounts', [
            'customer_credit' => self::CUSTOMER_CREDIT_ACCOUNT,
            'vendor_credit' => self::VENDOR_CREDIT_ACCOUNT,
            'realised_fx_gain' => self::FX_GAIN_ACCOUNT,
            'realised_fx_loss' => self::FX_LOSS_ACCOUNT,
        ]);
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

    /** Notes are drafted/posted against the domestic invoice and approved bill while
     * their status still permits it (CreditNoteService/DebitNoteService require the
     * source document to still be 'sent'/'awaiting_payment'); Settlement then fully
     * settles both afterward. Reversing this order would leave the source documents
     * 'paid' before the note commands could validate against them. */
    private function seedNotesSettlementAndReconciliation(Entity $entity, User $maker, User $checker, string $domesticCustomerId, string $domesticInvoiceId, string $primaryVendorId, string $approvedBillId): void
    {
        $approvals = app(ApprovalLifecycleService::class);

        $invoice = Invoice::query()->with('lines')->findOrFail($domesticInvoiceId);
        $creditNotes = app(CreditNoteService::class);
        $creditNote = $this->resource($creditNotes->create($maker, $entity->id, [
            'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $domesticCustomerId,
            'source_document_id' => $invoice->id, 'source_document_expected_version' => $invoice->version,
            'note_date' => '2026-07-21', 'reason_code' => 'UAT_SERVICE_ADJUSTMENT', 'narrative' => 'UAT illustrative service correction',
            'lines' => [['source_line_id' => $invoice->lines->first()->id, 'description' => 'UAT correction', 'net_amount' => ['amount' => '50.0000', 'currency' => 'BDT']]],
        ], '80000000-0000-4000-8000-000000000061'), 'credit_note');
        $this->approveIfPending($approvals, $checker, $entity->id, $creditNotes->post($maker, $entity->id, $creditNote['id'], '80000000-0000-4000-8000-000000000062', '1'), '80000000-0000-4000-8000-000000000063', '70000000-0000-4000-8000-000000000063');

        $bill = Bill::query()->with('lines')->findOrFail($approvedBillId);
        $debitNotes = app(DebitNoteService::class);
        $debitNote = $this->resource($debitNotes->create($maker, $entity->id, [
            'party_type' => 'vendor', 'document_type' => 'bill', 'party_id' => $primaryVendorId,
            'source_document_id' => $bill->id, 'source_document_expected_version' => $bill->version,
            'note_date' => '2026-07-21', 'reason_code' => 'UAT_SERVICE_ADJUSTMENT', 'narrative' => 'UAT illustrative service correction',
            'lines' => [['source_line_id' => $bill->lines->first()->id, 'description' => 'UAT correction', 'net_amount' => ['amount' => '25.0000', 'currency' => 'BDT']]],
        ], '80000000-0000-4000-8000-000000000064'), 'debit_note');
        $this->approveIfPending($approvals, $checker, $entity->id, $debitNotes->post($maker, $entity->id, $debitNote['id'], '80000000-0000-4000-8000-000000000065', '1'), '80000000-0000-4000-8000-000000000066', '70000000-0000-4000-8000-000000000066');

        $settlement = app(SettlementService::class);
        $invoice->refresh();
        $this->approveIfPending($approvals, $checker, $entity->id, $settlement->receipt($maker, $entity->id, [
            'customer_id' => $domesticCustomerId, 'settlement_date' => '2026-07-22', 'bank_account_id' => self::BANK_ACCOUNT,
            'gross_amount' => ['amount' => $invoice->open_balance, 'currency' => 'BDT'], 'bank_amount' => ['amount' => $invoice->open_balance, 'currency' => 'BDT'],
            'withholding_amount' => ['amount' => '0.0000', 'currency' => 'BDT'], 'unapplied_amount' => ['amount' => '0.0000', 'currency' => 'BDT'],
            'rate_record_id' => null, 'withholding_lines' => [],
            'allocations' => [['invoice_id' => $invoice->id, 'applied_amount' => ['amount' => $invoice->open_balance, 'currency' => 'BDT'], 'expected_version' => $invoice->version]],
        ], '80000000-0000-4000-8000-000000000067'), '80000000-0000-4000-8000-000000000068', '70000000-0000-4000-8000-000000000068');

        $this->approveIfPending($approvals, $checker, $entity->id, $settlement->receipt($maker, $entity->id, [
            'customer_id' => $domesticCustomerId, 'settlement_date' => '2026-07-22', 'bank_account_id' => self::BANK_ACCOUNT,
            'gross_amount' => ['amount' => '200.0000', 'currency' => 'BDT'], 'bank_amount' => ['amount' => '200.0000', 'currency' => 'BDT'],
            'withholding_amount' => ['amount' => '0.0000', 'currency' => 'BDT'], 'unapplied_amount' => ['amount' => '200.0000', 'currency' => 'BDT'],
            'rate_record_id' => null, 'withholding_lines' => [], 'allocations' => [], 'party_credit_expected_version' => 0,
        ], '80000000-0000-4000-8000-000000000069'), '80000000-0000-4000-8000-00000000006a', '70000000-0000-4000-8000-00000000006a');

        // A second, smaller advance receipt (distinct from the one above) so the
        // reconciliation UAT scenario has four independent bank-account allocations to
        // work with — enough to demonstrate one-to-one, one-to-many, and many-to-one
        // statement-line matching simultaneously without any allocation being reused
        // across patterns.
        $this->approveIfPending($approvals, $checker, $entity->id, $settlement->receipt($maker, $entity->id, [
            'customer_id' => $domesticCustomerId, 'settlement_date' => '2026-07-23', 'bank_account_id' => self::BANK_ACCOUNT,
            'gross_amount' => ['amount' => '300.0000', 'currency' => 'BDT'], 'bank_amount' => ['amount' => '300.0000', 'currency' => 'BDT'],
            'withholding_amount' => ['amount' => '0.0000', 'currency' => 'BDT'], 'unapplied_amount' => ['amount' => '300.0000', 'currency' => 'BDT'],
            'rate_record_id' => null, 'withholding_lines' => [], 'allocations' => [], 'party_credit_expected_version' => 1,
        ], '80000000-0000-4000-8000-00000000006e'), '80000000-0000-4000-8000-00000000006f', '70000000-0000-4000-8000-00000000006f');

        $bill->refresh();
        $this->approveIfPending($approvals, $checker, $entity->id, $settlement->payment($maker, $entity->id, [
            'vendor_id' => $primaryVendorId, 'settlement_date' => '2026-07-22', 'bank_account_id' => self::BANK_ACCOUNT,
            'gross_amount' => ['amount' => $bill->open_balance, 'currency' => 'BDT'], 'bank_amount' => ['amount' => $bill->open_balance, 'currency' => 'BDT'],
            'withholding_amount' => ['amount' => '0.0000', 'currency' => 'BDT'], 'unapplied_amount' => ['amount' => '0.0000', 'currency' => 'BDT'],
            'rate_record_id' => null, 'withholding_lines' => [],
            'allocations' => [['bill_id' => $bill->id, 'applied_amount' => ['amount' => $bill->open_balance, 'currency' => 'BDT'], 'expected_version' => $bill->version]],
        ], '80000000-0000-4000-8000-00000000006b'), '80000000-0000-4000-8000-00000000006c', '70000000-0000-4000-8000-00000000006c');

        $this->expect(app(ReconciliationAccountService::class)->configure($maker, $entity->id, [
            'ledger_account_id' => self::BANK_ACCOUNT, 'currency' => 'BDT', 'display_name' => 'UAT Operating Bank Reconciliation',
            'masked_bank_identifier' => '****4821',
        ], '80000000-0000-4000-8000-00000000006d'));
    }

    /** M5 Reporting requires its own versioned, entity-scoped configuration (report
     * layout, account classification map, ageing bucket set, Cash View policy) before
     * Profit and Loss, Balance Sheet, AR/AP Ageing, or Cash View will generate — these
     * are not Laravel config() values like the rest of this seeder's illustrative
     * policy, but real rows in report_layout_versions/account_classification_versions/
     * ageing_bucket_set_versions/cash_view_policy_versions. The classification map is
     * a direct, non-judgmental restatement of each seeded account's own `type` column
     * (asset/liability/revenue/expense), not an invented accounting policy. */
    private function seedReportingConfiguration(Entity $entity): void
    {
        ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'profit_and_loss', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);
        ReportLayoutVersion::query()->create(['entity_id' => $entity->id, 'report_type' => 'balance_sheet', 'version_number' => 1, 'sections' => [], 'effective_from' => '2026-01-01', 'effective_to' => null]);

        AccountClassificationVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'effective_from' => '2026-01-01', 'effective_to' => null, 'entries' => [
            ['account_id' => self::AR_ACCOUNT, 'code' => '1100', 'classification' => 'asset_current'],
            ['account_id' => self::BANK_ACCOUNT, 'code' => '1200', 'classification' => 'asset_current'],
            ['account_id' => self::INPUT_TAX_ACCOUNT, 'code' => '1300', 'classification' => 'asset_current'],
            ['account_id' => self::VENDOR_CREDIT_ACCOUNT, 'code' => '1150', 'classification' => 'asset_current'],
            ['account_id' => self::AP_ACCOUNT, 'code' => '2100', 'classification' => 'liability_current'],
            ['account_id' => self::OUTPUT_TAX_ACCOUNT, 'code' => '2200', 'classification' => 'liability_current'],
            ['account_id' => self::CUSTOMER_CREDIT_ACCOUNT, 'code' => '2110', 'classification' => 'liability_current'],
            ['account_id' => self::REVENUE_ACCOUNT, 'code' => '4100', 'classification' => 'sales_revenue'],
            ['account_id' => self::EXPENSE_ACCOUNT, 'code' => '5100', 'classification' => 'operating_expense'],
            ['account_id' => self::FX_GAIN_ACCOUNT, 'code' => '4200', 'classification' => 'non_operating_income'],
            ['account_id' => self::FX_LOSS_ACCOUNT, 'code' => '5200', 'classification' => 'non_operating_expense'],
        ]]);

        AgeingBucketSetVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'effective_from' => '2026-01-01', 'effective_to' => null, 'buckets' => [
            ['bucket_id' => 'not_due', 'label' => 'Not Due', 'lower_days' => null, 'upper_days' => -1, 'order' => 1],
            ['bucket_id' => 'overdue_0_30', 'label' => '0-30', 'lower_days' => 0, 'upper_days' => 30, 'order' => 2],
            ['bucket_id' => 'overdue_31_60', 'label' => '31-60', 'lower_days' => 31, 'upper_days' => 60, 'order' => 3],
            ['bucket_id' => 'overdue_61_90', 'label' => '61-90', 'lower_days' => 61, 'upper_days' => 90, 'order' => 4],
            ['bucket_id' => 'overdue_90_plus', 'label' => '91+', 'lower_days' => 91, 'upper_days' => null, 'order' => 5],
        ]]);

        CashViewPolicyVersion::query()->create(['entity_id' => $entity->id, 'version_number' => 1, 'effective_from' => '2026-01-01', 'effective_to' => null, 'policy' => ['recognition_date_source' => 'settlement_date']]);
    }

    /** Every command above runs against an entity with a non-empty approval_policy, so
     * every gated action returns a 202 pending approval rather than executing inline —
     * this mirrors the checker-approval replay already used for the M2 bill fixture. */
    private function approveIfPending(ApprovalLifecycleService $approvals, User $checker, string $entityId, DocumentActionResult $result, string $key, string $correlationId): void
    {
        $this->expect($result);
        if ($result->status !== 202) {
            return;
        }
        $approval = $result->payload['approval'] ?? null;
        if (! is_array($approval) || ! isset($approval['id'])) {
            throw new RuntimeException('Expected a pending approval payload from a UAT seed command.');
        }
        $approved = $approvals->approve($checker, $entityId, (string) $approval['id'], $key, '1', $correlationId);
        if (! $approved->ok) {
            throw new RuntimeException('M2 UAT seed approval replay failed: '.json_encode($approved->payload, JSON_THROW_ON_ERROR));
        }
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
