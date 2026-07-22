<?php

use App\Models\AuditLog;
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Entity,User} */
function m4aVoidActors(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M4A Void '.Str::uuid(), 'functional_currency' => 'BDT']);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'm4a-void-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $maker->entities()->attach($entity->id, ['status' => 'active']);
    $maker->roles()->attach($role->id, ['entity_id' => $entity->id]);
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);

    return [$entity, $maker];
}

it('voids a draft invoice directly with no journal or number consumed', function (): void {
    [$entity, $maker] = m4aVoidActors();
    $customer = Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Cust', 'normalized_name' => 'CUST', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $invoice = Invoice::query()->create(['entity_id' => $entity->id, 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '0.0000', 'status' => 'draft', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    Sanctum::actingAs($maker);

    $this->postJson('/v1/invoices/'.$invoice->id.'/void', ['void_date' => '2026-07-21', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Created in error'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertCreated()
        ->assertJsonPath('invoice.status', 'void')
        ->assertJsonPath('invoice.document_number', null)
        ->assertJsonPath('reversal', null);

    expect(Invoice::query()->whereKey($invoice->id)->value('status'))->toBe('void')
        ->and(AuditLog::query()->where('action', 'invoice_voided')->exists())->toBeTrue();
});

it('safe-window voids an issued invoice with a linked reversal, preserving the number', function (): void {
    [$entity, $maker] = m4aVoidActors();
    $customer = Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Cust', 'normalized_name' => 'CUST', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $revenue = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    $receivable = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1100', 'name' => 'AR', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $journal = JournalEntry::query()->create(['entity_id' => $entity->id, 'period_id' => AccountingPeriod::query()->where('entity_id', $entity->id)->value('id'), 'period_ref' => '2026-07', 'entry_type' => 'invoice', 'entry_date' => '2026-07-01', 'state' => 'posted', 'narration' => 'Invoice recognition', 'source_document_id' => (string) Str::uuid(), 'posted_at' => now('UTC'), 'posted_by' => (string) Str::uuid()]);
    $journal->lines()->create(['entity_id' => $entity->id, 'account_id' => $receivable->id, 'line_no' => 1, 'description' => 'AR', 'debit' => '100.0000', 'credit' => '0.0000', 'currency' => 'BDT']);
    $journal->lines()->create(['entity_id' => $entity->id, 'account_id' => $revenue->id, 'line_no' => 2, 'description' => 'Revenue', 'debit' => '0.0000', 'credit' => '100.0000', 'currency' => 'BDT']);
    $invoice = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '100.0000', 'status' => 'sent', 'journal_entry_id' => $journal->id, 'version' => 1, 'created_by' => (string) Str::uuid()]);
    Sanctum::actingAs($maker);

    $response = $this->postJson('/v1/invoices/'.$invoice->id.'/void', ['void_date' => '2026-07-22', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Duplicate issued invoice'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertCreated()
        ->assertJsonPath('invoice.status', 'void')
        ->assertJsonPath('invoice.document_number', 'INV-1')
        ->json();

    expect($response['reversal']['journal_entry_id'])->not->toBeNull()
        ->and(Invoice::query()->whereKey($invoice->id)->value('document_number'))->toBe('INV-1')
        ->and(OutboxMessage::query()->where('event_type', 'InvoiceVoided')->exists())->toBeTrue();
});

it('rejects voiding a partially paid invoice with void_window_failed', function (): void {
    [$entity, $maker] = m4aVoidActors();
    $customer = Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Cust', 'normalized_name' => 'CUST', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $invoice = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '50.0000', 'status' => 'partially_paid', 'journal_entry_id' => (string) Str::uuid(), 'version' => 1, 'created_by' => (string) Str::uuid()]);
    Sanctum::actingAs($maker);

    $this->postJson('/v1/invoices/'.$invoice->id.'/void', ['void_date' => '2026-07-22', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Attempted void'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertUnprocessable()
        ->assertJsonPath('details.rule', 'void_window_failed')
        ->assertJsonPath('details.failed_conditions.0', 'unpaid');
});

it('voids a draft bill directly and safe-window voids an approved bill', function (): void {
    [$entity, $maker] = m4aVoidActors();
    $vendor = Vendor::query()->create(['entity_id' => $entity->id, 'name' => 'Vend', 'normalized_name' => 'VEND', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $draftBill = Bill::query()->create(['entity_id' => $entity->id, 'vendor_id' => $vendor->id, 'bill_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '0.0000', 'status' => 'draft', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    Sanctum::actingAs($maker);

    $this->postJson('/v1/bills/'.$draftBill->id.'/void', ['void_date' => '2026-07-21', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Created in error'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertCreated()->assertJsonPath('bill.status', 'void');

    $expense = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '5000', 'name' => 'Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']);
    $payable = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2100', 'name' => 'AP', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']);
    $journal = JournalEntry::query()->create(['entity_id' => $entity->id, 'period_id' => AccountingPeriod::query()->where('entity_id', $entity->id)->value('id'), 'period_ref' => '2026-07', 'entry_type' => 'bill', 'entry_date' => '2026-07-01', 'state' => 'posted', 'narration' => 'Bill recognition', 'source_document_id' => (string) Str::uuid(), 'posted_at' => now('UTC'), 'posted_by' => (string) Str::uuid()]);
    $journal->lines()->create(['entity_id' => $entity->id, 'account_id' => $expense->id, 'line_no' => 1, 'description' => 'Expense', 'debit' => '100.0000', 'credit' => '0.0000', 'currency' => 'BDT']);
    $journal->lines()->create(['entity_id' => $entity->id, 'account_id' => $payable->id, 'line_no' => 2, 'description' => 'AP', 'debit' => '0.0000', 'credit' => '100.0000', 'currency' => 'BDT']);
    $approvedBill = Bill::query()->create(['entity_id' => $entity->id, 'document_number' => 'BILL-1', 'vendor_id' => $vendor->id, 'bill_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '100.0000', 'status' => 'awaiting_payment', 'journal_entry_id' => $journal->id, 'version' => 1, 'created_by' => (string) Str::uuid()]);

    $response = $this->postJson('/v1/bills/'.$approvedBill->id.'/void', ['void_date' => '2026-07-22', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Duplicate approved bill'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertCreated()->assertJsonPath('bill.status', 'void')->assertJsonPath('bill.document_number', 'BILL-1')->json();
    expect($response['reversal']['journal_entry_id'])->not->toBeNull()
        ->and(OutboxMessage::query()->where('event_type', 'BillVoided')->exists())->toBeTrue();
});
