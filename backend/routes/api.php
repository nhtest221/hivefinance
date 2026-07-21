<?php

use App\Http\Controllers\Auth\ApprovalController;
use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EntitySessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RoleController;
use App\Http\Controllers\CurrencyFx\FxController;
use App\Http\Controllers\HealthCheckController;
use App\Http\Controllers\Ledger\AccountController;
use App\Http\Controllers\Ledger\JournalController;
use App\Http\Controllers\Payables\BillController;
use App\Http\Controllers\Payables\ExpenseController;
use App\Http\Controllers\Payables\VendorController;
use App\Http\Controllers\Period\PeriodController;
use App\Http\Controllers\Receivables\CustomerController;
use App\Http\Controllers\Receivables\InvoiceController;
use App\Http\Controllers\Reports\LedgerReportController;
use App\Http\Controllers\Settlement\SettlementController;
use App\Http\Controllers\Tax\TaxController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health');

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/mfa', [AuthController::class, 'mfa'])->name('auth.mfa');
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])->name('auth.password.forgot');
    Route::post('/password/reset', [PasswordResetController::class, 'reset'])->name('auth.password.reset');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/session', [AuthController::class, 'session'])->name('auth.session');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/entities', [EntitySessionController::class, 'index'])->name('entities.index');
    Route::post('/entities/switch', [EntitySessionController::class, 'switch'])->name('entities.switch');
    Route::get('/roles', RoleController::class)->name('roles.index');
    Route::post('/approvals/{id}/approve', [ApprovalController::class, 'approve'])->name('approvals.approve');

    Route::get('/periods/postable', [PeriodController::class, 'postable'])->name('periods.postable');
    Route::get('/periods/{ref}', [PeriodController::class, 'show'])->name('periods.show');

    Route::get('/accounts', [AccountController::class, 'index'])->name('accounts.index');
    Route::post('/accounts', [AccountController::class, 'store'])->name('accounts.store');
    Route::patch('/accounts/{id}', [AccountController::class, 'update'])->name('accounts.update');
    Route::post('/accounts/{id}/deactivate', [AccountController::class, 'deactivate'])->name('accounts.deactivate');
    Route::get('/accounts/{id}/balance', [LedgerReportController::class, 'accountBalance'])->name('accounts.balance');

    Route::get('/journals', [JournalController::class, 'index'])->name('journals.index');
    Route::post('/journals', [JournalController::class, 'store'])->name('journals.store');
    Route::post('/journals/{id}/post', [JournalController::class, 'post'])->name('journals.post');
    Route::post('/journals/{id}/reverse', [JournalController::class, 'reverse'])->name('journals.reverse');

    Route::get('/reports/trial-balance', [LedgerReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('/reports/general-ledger', [LedgerReportController::class, 'generalLedger'])->name('reports.general-ledger');

    Route::get('/tax/codes', [TaxController::class, 'index'])->name('tax.codes.index');
    Route::post('/tax/codes', [TaxController::class, 'store'])->name('tax.codes.store');
    Route::get('/tax/codes/{id}', [TaxController::class, 'show'])->name('tax.codes.show');
    Route::post('/tax/codes/{id}/versions', [TaxController::class, 'version'])->name('tax.codes.versions.store');
    Route::post('/tax/packs', [TaxController::class, 'pack'])->name('tax.packs.store');

    Route::get('/fx/rates', [FxController::class, 'rates'])->name('fx.rates.index');
    Route::post('/fx/rates', [FxController::class, 'storeRate'])->name('fx.rates.store');
    Route::get('/fx/revaluation', [FxController::class, 'revaluations'])->name('fx.revaluation.index');
    Route::post('/fx/revaluation', [FxController::class, 'revalue'])->name('fx.revaluation.store');

    Route::post('/customers', [CustomerController::class, 'store'])->name('customers.store');
    Route::patch('/customers/{id}', [CustomerController::class, 'update'])->name('customers.update');
    Route::post('/customers/{id}/deactivate', [CustomerController::class, 'deactivate'])->name('customers.deactivate');
    Route::get('/customers/{id}', [CustomerController::class, 'show'])->name('customers.show');
    Route::get('/customers', [CustomerController::class, 'index'])->name('customers.index');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('invoices.store');
    Route::patch('/invoices/{id}', [InvoiceController::class, 'update'])->name('invoices.update');
    Route::get('/invoices/{id}', [InvoiceController::class, 'show'])->name('invoices.show');
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
    Route::post('/invoices/{id}/issue', [InvoiceController::class, 'issue'])->name('invoices.issue');
    Route::get('/invoices/{id}/pdf', [InvoiceController::class, 'pdf'])->name('invoices.pdf');

    Route::post('/vendors', [VendorController::class, 'store'])->name('vendors.store');
    Route::patch('/vendors/{id}', [VendorController::class, 'update'])->name('vendors.update');
    Route::post('/vendors/{id}/deactivate', [VendorController::class, 'deactivate'])->name('vendors.deactivate');
    Route::get('/vendors/{id}', [VendorController::class, 'show'])->name('vendors.show');
    Route::get('/vendors', [VendorController::class, 'index'])->name('vendors.index');
    Route::post('/bills', [BillController::class, 'store'])->name('bills.store');
    Route::patch('/bills/{id}', [BillController::class, 'update'])->name('bills.update');
    Route::get('/bills/{id}', [BillController::class, 'show'])->name('bills.show');
    Route::get('/bills', [BillController::class, 'index'])->name('bills.index');
    Route::post('/bills/{id}/approve', [BillController::class, 'approve'])->name('bills.approve');
    Route::post('/expenses', [ExpenseController::class, 'store'])->name('expenses.store');
    Route::get('/expenses/{id}', [ExpenseController::class, 'show'])->name('expenses.show');
    Route::get('/expenses', [ExpenseController::class, 'index'])->name('expenses.index');

    Route::post('/receipts', [SettlementController::class, 'receipt'])->name('settlement.receipts.store');
    Route::post('/payments', [SettlementController::class, 'payment'])->name('settlement.payments.store');
    Route::post('/credits/{party}/apply', [SettlementController::class, 'apply'])->name('settlement.credits.apply');
    Route::post('/credits/{party}/refund', [SettlementController::class, 'refund'])->name('settlement.credits.refund');
    Route::post('/allocations/{id}/reverse', [SettlementController::class, 'reverse'])->name('settlement.allocations.reverse');
    Route::get('/allocations', [SettlementController::class, 'allocations'])->name('settlement.allocations.index');
    Route::get('/credits/{party}', [SettlementController::class, 'credits'])->name('settlement.credits.show');
});
