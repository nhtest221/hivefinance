<?php

use App\Models\AuditLog;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\LedgerAccount;
use App\Models\OutboxMessage;
use App\Models\Payables\Bill;
use App\Models\Payables\BillLine;
use App\Models\Payables\Vendor;
use App\Models\Period\AccountingPeriod;
use App\Models\Receivables\Customer;
use App\Models\Receivables\Invoice;
use App\Models\Receivables\InvoiceLine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{User,User,Entity} */
function m4aActors(array $permissions, bool $approval = false): array
{
    $entity = Entity::query()->create(['legal_name' => 'M4A '.Str::uuid(), 'functional_currency' => 'BDT', 'approval_policy' => $approval ? ['configured' => true] : []]);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'm4a-maker-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $approver = User::query()->create(['name' => 'Approver', 'email' => 'm4a-approver-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    foreach ([$maker, $approver] as $actor) {
        $actor->entities()->attach($entity->id, ['status' => 'active']);
        $actor->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);

    return [$maker->refresh(), $approver->refresh(), $entity];
}

/** @return array<string,mixed> */
function m4aTaxSnapshot(string $outputAccountId, string $inputAccountId): array
{
    // DocumentTaxService::percent() computes amount * rate / 100 — `rate` is a percentage
    // number (15 means 15%), not a fraction.
    return ['treatment' => 'standard', 'rate' => '15.00000000', 'calculation_method' => 'exclusive', 'recoverable' => true, 'gl_mapping' => ['output_account_id' => $outputAccountId, 'input_account_id' => $inputAccountId]];
}

/** @return array{Entity,Invoice,InvoiceLine,User,User} */
function m4aInvoiceFixture(): array
{
    [$maker, $checker, $entity] = m4aActors(['receivables.credit_notes.create', 'receivables.credit_notes.read', 'receivables.credit_notes.post']);
    $customer = Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Cust', 'normalized_name' => 'CUST', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $revenue = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    $outputVat = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2050', 'name' => 'Output VAT', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']);
    $customerCredit = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2060', 'name' => 'Customer Credit', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']);
    config()->set('documents.invoice.revenue_account_id', $revenue->id);
    config()->set('settlement.accounts.customer_credit', $customerCredit->id);
    config()->set('documents.reason_codes', ['CONFIGURED_REASON']);
    config()->set('valuation.tax.exclusive_methods', ['exclusive']);
    config()->set('valuation.tax.inclusive_methods', ['inclusive']);
    config()->set('valuation.fx.rounding_scale', 4);
    config()->set('valuation.fx.rounding_mode', 'half_up');
    config()->set('CREDIT_NOTE_NUMBER_PREFIX', null);
    config()->set('documents.credit_note.number_prefix', 'CN');
    config()->set('documents.credit_note.number_format', '{prefix}-{sequence}');

    $invoice = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '15.0000', 'total' => '115.0000', 'open_balance' => '115.0000', 'status' => 'sent', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $line = $invoice->lines()->create(['entity_id' => $entity->id, 'line_no' => 1, 'description' => 'Service', 'quantity' => '1.0000', 'unit_price' => '100.0000', 'tax_snapshot' => m4aTaxSnapshot($outputVat->id, (string) Str::uuid()), 'line_amount' => '100.0000', 'tax_amount' => '15.0000', 'total_amount' => '115.0000']);

    return [$entity, $invoice, $line, $maker->refresh(), $checker->refresh()];
}

it('creates, edits, and posts a credit note with correct tax and ledger effects', function (): void {
    [$entity, $invoice, $line, $maker, $checker] = m4aInvoiceFixture();
    Sanctum::actingAs($maker);

    $created = $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $invoice->customer_id,
        'source_document_id' => $invoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Partial service correction',
        'lines' => [['source_line_id' => $line->id, 'description' => 'Correction', 'net_amount' => ['amount' => '40.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('credit_note.state', 'draft')
        ->assertJsonPath('credit_note.document_number', null)
        ->assertJsonPath('credit_note.version', 1)
        ->json('credit_note');

    // 40 net * 15% = 6 tax -> 46 total, matches proportional application of the copied snapshot.
    expect($created['lines'][0]['tax_amount']['amount'])->toBe('6.0000')
        ->and($created['lines'][0]['total_amount']['amount'])->toBe('46.0000');

    $updated = $this->patchJson('/v1/credit-notes/'.$created['id'], ['narrative' => 'Revised correction'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()->assertJsonPath('credit_note.version', 2)->json('credit_note');
    expect($updated['narrative'])->toBe('Revised correction');

    // Posting is a distinct actor from the one who drafted the note (sod_exception_required
    // otherwise — see "blocks the credit note maker from posting their own draft" below).
    Sanctum::actingAs($checker);
    $posted = $this->postJson('/v1/credit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertCreated()
        ->assertJsonPath('credit_note.state', 'posted')
        ->assertJsonPath('credit_note.document_number', 'CN-1')
        ->assertJsonPath('credit_note.posted_amount.amount', '46.0000')
        ->assertJsonPath('credit_note.undisposed_amount.amount', '46.0000')
        ->assertJsonPath('credit_note.applied_amount.amount', '0.0000')
        ->json('credit_note');

    expect(OutboxMessage::query()->where('event_type', 'CreditNoteIssued')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'credit_note_posted')->exists())->toBeTrue();

    $this->getJson('/v1/credit-notes/'.$posted['id'], ['X-Entity-Id' => $entity->id])
        ->assertOk()->assertJsonPath('credit_note.state', 'posted')->assertJsonPath('credit_note.reversal', null);

    $this->getJson('/v1/credit-notes?state=posted&limit=10', ['X-Entity-Id' => $entity->id])
        ->assertOk()->assertJsonPath('credit_notes.0.id', $posted['id']);
});

it('blocks the credit note maker from posting their own draft when no approval policy is configured', function (): void {
    [$entity, $invoice, $line, $maker] = m4aInvoiceFixture();
    Sanctum::actingAs($maker);

    $created = $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $invoice->customer_id,
        'source_document_id' => $invoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $line->id, 'net_amount' => ['amount' => '40.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('credit_note');

    $this->postJson('/v1/credit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertForbidden()->assertJsonPath('error_code', 'sod_exception_required');
});

it('rejects a direction mismatch and a correction that exceeds the source line', function (): void {
    [$entity, $invoice, $line] = m4aInvoiceFixture();
    $maker = User::query()->where('active_entity_id', $entity->id)->first();
    Sanctum::actingAs($maker);

    $this->postJson('/v1/credit-notes', [
        'party_type' => 'vendor', 'document_type' => 'invoice', 'party_id' => $invoice->customer_id,
        'source_document_id' => $invoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $line->id, 'net_amount' => ['amount' => '10.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'note_direction_mismatch');

    $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $invoice->customer_id,
        'source_document_id' => $invoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $line->id, 'net_amount' => ['amount' => '150.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'correction_exceeds_source');
});

it('routes credit note posting through durable maker-checker approval when configured', function (): void {
    [$entity, $invoice, $line] = m4aInvoiceFixture();
    $entity->approval_policy = ['configured' => true];
    $entity->save();
    $maker = User::query()->where('active_entity_id', $entity->id)->first();
    $approver = User::query()->where('active_entity_id', $entity->id)->where('id', '!=', $maker->id)->first();
    Sanctum::actingAs($maker);

    $created = $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $invoice->customer_id,
        'source_document_id' => $invoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $line->id, 'net_amount' => ['amount' => '40.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->json('credit_note');

    $pending = $this->postJson('/v1/credit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertStatus(202)->assertJsonPath('approval.status', 'pending')->json('approval');

    Sanctum::actingAs($approver);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()->assertJsonPath('command_result.status', 201)->assertJsonPath('command_result.body.credit_note.state', 'posted');
});

it('mirrors create, edit and post for debit notes with vendor/bill direction', function (): void {
    [$maker, $checker, $entity] = m4aActors(['payables.debit_notes.create', 'payables.debit_notes.read', 'payables.debit_notes.post']);
    $vendor = Vendor::query()->create(['entity_id' => $entity->id, 'name' => 'Vend', 'normalized_name' => 'VEND', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $expense = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '5000', 'name' => 'Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']);
    $inputVat = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1050', 'name' => 'Input VAT', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $vendorCredit = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1075', 'name' => 'Vendor Credit', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    config()->set('settlement.accounts.vendor_credit', $vendorCredit->id);
    config()->set('documents.reason_codes', ['CONFIGURED_REASON']);
    config()->set('valuation.tax.exclusive_methods', ['exclusive']);
    config()->set('valuation.tax.inclusive_methods', ['inclusive']);
    config()->set('valuation.fx.rounding_scale', 4);
    config()->set('valuation.fx.rounding_mode', 'half_up');
    config()->set('documents.debit_note.number_prefix', 'DN');
    config()->set('documents.debit_note.number_format', '{prefix}-{sequence}');

    $bill = Bill::query()->create(['entity_id' => $entity->id, 'document_number' => 'BILL-1', 'vendor_id' => $vendor->id, 'bill_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '200.0000', 'tax_total' => '30.0000', 'total' => '230.0000', 'open_balance' => '230.0000', 'status' => 'awaiting_payment', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $line = BillLine::query()->create(['bill_id' => $bill->id, 'entity_id' => $entity->id, 'line_no' => 1, 'description' => 'Goods', 'quantity' => '1.0000', 'unit_price' => '200.0000', 'expense_account_id' => $expense->id, 'tax_snapshot' => m4aTaxSnapshot((string) Str::uuid(), $inputVat->id), 'line_amount' => '200.0000', 'tax_amount' => '30.0000', 'total_amount' => '230.0000']);

    Sanctum::actingAs($maker);

    $created = $this->postJson('/v1/debit-notes', [
        'party_type' => 'vendor', 'document_type' => 'bill', 'party_id' => $bill->vendor_id,
        'source_document_id' => $bill->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $line->id, 'net_amount' => ['amount' => '100.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->assertJsonPath('debit_note.state', 'draft')->json('debit_note');

    expect($created['lines'][0]['tax_amount']['amount'])->toBe('15.0000');

    // Posting is a distinct actor from the one who drafted the note (sod_exception_required
    // otherwise — see "blocks the debit note maker from posting their own draft" below).
    Sanctum::actingAs($checker);
    $posted = $this->postJson('/v1/debit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertCreated()
        ->assertJsonPath('debit_note.state', 'posted')
        ->assertJsonPath('debit_note.document_number', 'DN-1')
        ->assertJsonPath('debit_note.posted_amount.amount', '115.0000')
        ->assertJsonPath('debit_note.undisposed_amount.amount', '115.0000')
        ->json('debit_note');

    expect(OutboxMessage::query()->where('event_type', 'DebitNoteIssued')->exists())->toBeTrue();

    $this->getJson('/v1/debit-notes/'.$posted['id'], ['X-Entity-Id' => $entity->id])->assertOk()->assertJsonPath('debit_note.state', 'posted');
});

it('blocks the debit note maker from posting their own draft when no approval policy is configured', function (): void {
    [$maker, , $entity] = m4aActors(['payables.debit_notes.create', 'payables.debit_notes.read', 'payables.debit_notes.post']);
    $vendor = Vendor::query()->create(['entity_id' => $entity->id, 'name' => 'Vend', 'normalized_name' => 'VEND', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $expense = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '5000', 'name' => 'Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']);
    $inputVat = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1050', 'name' => 'Input VAT', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    config()->set('documents.reason_codes', ['CONFIGURED_REASON']);
    config()->set('valuation.tax.exclusive_methods', ['exclusive']);
    config()->set('valuation.fx.rounding_scale', 4);
    config()->set('valuation.fx.rounding_mode', 'half_up');
    config()->set('documents.debit_note.number_prefix', 'DN');
    config()->set('documents.debit_note.number_format', '{prefix}-{sequence}');
    $bill = Bill::query()->create(['entity_id' => $entity->id, 'document_number' => 'BILL-2', 'vendor_id' => $vendor->id, 'bill_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '200.0000', 'tax_total' => '30.0000', 'total' => '230.0000', 'open_balance' => '230.0000', 'status' => 'awaiting_payment', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $line = BillLine::query()->create(['bill_id' => $bill->id, 'entity_id' => $entity->id, 'line_no' => 1, 'description' => 'Goods', 'quantity' => '1.0000', 'unit_price' => '200.0000', 'expense_account_id' => $expense->id, 'tax_snapshot' => m4aTaxSnapshot((string) Str::uuid(), $inputVat->id), 'line_amount' => '200.0000', 'tax_amount' => '30.0000', 'total_amount' => '230.0000']);
    Sanctum::actingAs($maker);

    $created = $this->postJson('/v1/debit-notes', [
        'party_type' => 'vendor', 'document_type' => 'bill', 'party_id' => $bill->vendor_id,
        'source_document_id' => $bill->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $line->id, 'net_amount' => ['amount' => '100.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('debit_note');

    $this->postJson('/v1/debit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertForbidden()->assertJsonPath('error_code', 'sod_exception_required');
});
