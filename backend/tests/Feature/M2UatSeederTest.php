<?php

use App\Models\CurrencyFx\RateRecord;
use App\Models\Identity\ApprovalRequest;
use App\Models\Ledger\JournalEntry;
use App\Models\Payables\Bill;
use App\Models\Payables\DebitNote;
use App\Models\Payables\Expense;
use App\Models\Payables\Vendor;
use App\Models\Receivables\CreditNote;
use App\Models\Receivables\Customer;
use App\Models\Receivables\Invoice;
use App\Models\Reconciliation\ReconciliationAccount;
use App\Models\Settlement\Allocation;
use App\Models\Settlement\PartyCreditBalance;
use App\Models\Tax\TaxCodeVersion;
use App\Models\User;
use Database\Seeders\M2UatSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

it('seeds the deterministic local M2 UAT release candidate with balanced postings', function (): void {
    putenv('HIVEFIN_UAT_SEED_ALLOWED=true');
    $_ENV['HIVEFIN_UAT_SEED_ALLOWED'] = 'true';
    $_SERVER['HIVEFIN_UAT_SEED_ALLOWED'] = 'true';

    try {
        $this->seed(M2UatSeeder::class);
    } finally {
        putenv('HIVEFIN_UAT_SEED_ALLOWED');
        unset($_ENV['HIVEFIN_UAT_SEED_ALLOWED'], $_SERVER['HIVEFIN_UAT_SEED_ALLOWED']);
    }

    expect(Customer::query()->where('status', 'active')->count())->toBe(2)
        ->and(Customer::query()->where('status', 'deactivated')->count())->toBe(1)
        ->and(Vendor::query()->where('status', 'active')->count())->toBe(2)
        ->and(Vendor::query()->where('status', 'deactivated')->count())->toBe(1)
        ->and(Expense::query()->count())->toBe(1)
        ->and(ApprovalRequest::query()->where('status', 'approved')->count())->toBe(6)
        ->and(ApprovalRequest::query()->where('status', 'pending')->count())->toBe(1);

    expect(Invoice::query()->where('document_number', 'UAT-INV-2026-1')->value('total'))->toBe('1100.0000')
        ->and(Invoice::query()->where('document_number', 'UAT-INV-2026-2')->value('total'))->toBe('110.0000')
        ->and(Invoice::query()->where('document_number', 'UAT-INV-2026-1')->value('open_balance'))->toBe('0.0000')
        ->and(Bill::query()->where('document_number', 'UAT-BILL-2026-1')->value('total'))->toBe('550.0000')
        ->and(Bill::query()->where('document_number', 'UAT-BILL-2026-1')->value('open_balance'))->toBe('0.0000')
        ->and(Bill::query()->where('vendor_reference', 'UAT-PENDING-BILL')->value('status'))->toBe('draft');

    JournalEntry::query()->with('lines')->get()->each(function (JournalEntry $journal): void {
        $debit = $journal->lines->sum(fn ($line): float => (float) $line->debit);
        $credit = $journal->lines->sum(fn ($line): float => (float) $line->credit);
        expect($debit)->toBe($credit)->and($debit)->toBeGreaterThan(0);
    });

    expect(TaxCodeVersion::query()->where('referenced', true)->count())->toBe(1)
        ->and(RateRecord::query()->where('referenced', true)->count())->toBe(1)
        ->and(Hash::check('UatOnly!ChangeMe2026', User::query()->where('email', 'maker.m2.uat@hivefinance.local')->value('password')))->toBeTrue()
        ->and((string) DB::table('payables_vendors')->where('name', 'UAT Domestic Vendor')->value('bank_details'))->not->toContain('012345678901');

    expect(CreditNote::query()->where('state', 'posted')->count())->toBe(1)
        ->and(DebitNote::query()->where('state', 'posted')->count())->toBe(1)
        ->and(Allocation::query()->where('state', 'posted')->count())->toBe(3)
        ->and(PartyCreditBalance::query()->value('available_balance'))->toBe('200.0000')
        ->and(ReconciliationAccount::query()->count())->toBe(1);

    $this->assertDatabaseCount('journal_entries', 9);
    $this->assertDatabaseHas('outbox_messages', ['event_type' => 'InvoiceIssued']);
    $this->assertDatabaseHas('outbox_messages', ['event_type' => 'BillApproved']);
    $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ExpenseRecorded']);
    $this->assertDatabaseHas('outbox_messages', ['event_type' => 'CreditNoteIssued']);
    $this->assertDatabaseHas('outbox_messages', ['event_type' => 'ReceiptAllocated']);
    $this->assertDatabaseHas('outbox_messages', ['event_type' => 'PaymentAllocated']);
    $this->assertDatabaseHas('audit_logs', ['action' => 'approval_granted']);

    $this->postJson('/v1/auth/login', ['email' => 'maker.m2.uat@hivefinance.local', 'password' => 'UatOnly!ChangeMe2026'])
        ->assertOk()
        ->assertJsonPath('session.active_entity.id', '10000000-0000-4000-8000-000000000001')
        ->assertJsonPath('session.roles.0', 'finance-staff');
});
