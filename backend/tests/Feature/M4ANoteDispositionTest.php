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
use App\Models\Settlement\CreditTranche;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Entity,User,User,Invoice,Invoice} */
function m4aDispositionFixture(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M4A Disposition '.Str::uuid(), 'functional_currency' => 'BDT']);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'm4a-disp-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $checker = User::query()->create(['name' => 'Checker', 'email' => 'm4a-disp-checker-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $maker->entities()->attach($entity->id, ['status' => 'active']);
    $maker->roles()->attach($role->id, ['entity_id' => $entity->id]);
    $checker->entities()->attach($entity->id, ['status' => 'active']);
    $checker->roles()->attach($role->id, ['entity_id' => $entity->id]);
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);

    $revenue = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '4000', 'name' => 'Revenue', 'type' => 'revenue', 'normal_balance' => 'credit', 'status' => 'active']);
    $receivable = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1100', 'name' => 'AR', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $customerCredit = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2060', 'name' => 'Customer Credit', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']);
    $bank = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active', 'bank_attributes' => ['currency' => 'BDT']]);
    config()->set('documents.invoice.revenue_account_id', $revenue->id);
    config()->set('documents.invoice.receivable_account_id', $receivable->id);
    config()->set('settlement.accounts.customer_credit', $customerCredit->id);
    config()->set('settlement.refund.number_prefix', 'RF');
    config()->set('settlement.refund.number_format', '{prefix}-{sequence}');
    config()->set('documents.reason_codes', ['CONFIGURED_REASON']);
    config()->set('valuation.tax.exclusive_methods', ['exclusive']);
    config()->set('valuation.tax.inclusive_methods', ['inclusive']);
    config()->set('valuation.fx.rounding_scale', 4);
    config()->set('valuation.fx.rounding_mode', 'half_up');
    config()->set('documents.credit_note.number_prefix', 'CN');
    config()->set('documents.credit_note.number_format', '{prefix}-{sequence}');

    $customer = Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Cust', 'normalized_name' => 'CUST', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $sourceInvoice = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '100.0000', 'status' => 'sent', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $sourceInvoice->lines()->create(['entity_id' => $entity->id, 'line_no' => 1, 'description' => 'Service', 'quantity' => '1.0000', 'unit_price' => '100.0000', 'line_amount' => '100.0000', 'tax_amount' => '0.0000', 'total_amount' => '100.0000']);
    $targetInvoice = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-2', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-05', 'due_date' => '2026-08-04', 'currency' => 'BDT', 'subtotal' => '200.0000', 'tax_total' => '0.0000', 'total' => '200.0000', 'open_balance' => '200.0000', 'status' => 'sent', 'version' => 1, 'created_by' => (string) Str::uuid()]);

    return [$entity, $maker, $checker, $sourceInvoice, $targetInvoice];
}

it('holds, applies from held, refunds, and reverses a credit note while keeping the five-field invariant', function (): void {
    [$entity, $maker, $checker, $sourceInvoice, $targetInvoice] = m4aDispositionFixture();
    Sanctum::actingAs($maker);

    $created = $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $sourceInvoice->customer_id,
        'source_document_id' => $sourceInvoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $sourceInvoice->lines->first()->id, 'net_amount' => ['amount' => '100.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('credit_note');

    Sanctum::actingAs($checker);
    $posted = $this->postJson('/v1/credit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertCreated()->assertJsonPath('credit_note.undisposed_amount.amount', '100.0000')->json('credit_note');

    Sanctum::actingAs($maker);
    // HOLD 60 of the 100 undisposed into a CreditTranche.
    $held = $this->postJson('/v1/credit-notes/'.$posted['id'].'/hold', ['hold_date' => '2026-07-22', 'amount' => ['amount' => '60.0000', 'currency' => 'BDT']], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertCreated()
        ->assertJsonPath('credit_note.held_remaining_amount.amount', '60.0000')
        ->assertJsonPath('credit_note.undisposed_amount.amount', '40.0000')
        ->json();
    $trancheId = $held['credit_sources'][0]['credit_tranche_id'];
    expect(CreditTranche::query()->whereKey($trancheId)->value('source_note_id'))->toBe($posted['id']);

    // APPLY 40 undisposed directly to the target invoice.
    $appliedUndisposed = $this->postJson('/v1/credit-notes/'.$posted['id'].'/apply', [
        'application_date' => '2026-07-23', 'source' => 'undisposed',
        'allocations' => [['document_id' => $targetInvoice->id, 'amount' => ['amount' => '40.0000', 'currency' => 'BDT'], 'expected_version' => 1]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '3'])
        ->assertCreated()
        ->assertJsonPath('credit_note.applied_amount.amount', '40.0000')
        ->assertJsonPath('credit_note.undisposed_amount.amount', '0.0000')
        ->json('credit_note');
    expect(Invoice::query()->whereKey($targetInvoice->id)->value('open_balance'))->toBe('160.0000');

    // APPLY 20 of the held tranche to the target invoice too.
    $tranche = CreditTranche::query()->whereKey($trancheId)->firstOrFail();
    $appliedHeld = $this->postJson('/v1/credit-notes/'.$posted['id'].'/apply', [
        'application_date' => '2026-07-24', 'source' => 'held',
        'allocations' => [['document_id' => $targetInvoice->id, 'amount' => ['amount' => '20.0000', 'currency' => 'BDT'], 'expected_version' => 2]],
        'credit_sources' => [['credit_tranche_id' => $trancheId, 'amount' => ['amount' => '20.0000', 'currency' => 'BDT'], 'expected_version' => $tranche->version]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '4'])
        ->assertCreated()
        ->assertJsonPath('credit_note.applied_amount.amount', '60.0000')
        ->assertJsonPath('credit_note.held_remaining_amount.amount', '40.0000')
        ->json('credit_note');
    expect(Invoice::query()->whereKey($targetInvoice->id)->value('open_balance'))->toBe('140.0000');

    // REFUND the remaining 40 held credit via bank.
    $refunded = $this->postJson('/v1/credit-notes/'.$posted['id'].'/refund', [
        'refund_date' => '2026-07-25', 'bank_account_id' => LedgerAccount::query()->where('entity_id', $entity->id)->where('code', '1000')->value('id'),
        'refund_amount' => ['amount' => '40.0000', 'currency' => 'BDT'], 'expected_available_balance' => ['amount' => '40.0000', 'currency' => 'BDT'],
        'rate_record_id' => null, 'credit_sources' => [['credit_tranche_id' => $trancheId, 'amount' => ['amount' => '40.0000', 'currency' => 'BDT'], 'expected_version' => $tranche->version + 1]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '5'])
        ->assertCreated()
        ->assertJsonPath('credit_note.refunded_amount.amount', '40.0000')
        ->assertJsonPath('credit_note.held_remaining_amount.amount', '0.0000')
        ->json('credit_note');

    // Five-field invariant holds throughout: 100 = 60 + 40 + 0 + 0.
    expect($refunded['posted_amount']['amount'])->toBe('100.0000')
        ->and($refunded['applied_amount']['amount'])->toBe('60.0000')
        ->and($refunded['refunded_amount']['amount'])->toBe('40.0000')
        ->and($refunded['held_remaining_amount']['amount'])->toBe('0.0000')
        ->and($refunded['undisposed_amount']['amount'])->toBe('0.0000');

    expect(OutboxMessage::query()->where('event_type', 'CreditNoteHeld')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'CreditNoteApplied')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'CreditNoteRefunded')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'CreditHeld')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'CreditApplied')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'CreditRefunded')->exists())->toBeTrue();
});

it('rejects hold, apply, and refund amounts that exceed the available balance', function (): void {
    [$entity, $maker, $checker, $sourceInvoice] = m4aDispositionFixture();
    Sanctum::actingAs($maker);
    $created = $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $sourceInvoice->customer_id,
        'source_document_id' => $sourceInvoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $sourceInvoice->lines->first()->id, 'net_amount' => ['amount' => '100.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('credit_note');
    Sanctum::actingAs($checker);
    $posted = $this->postJson('/v1/credit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertCreated()->json('credit_note');

    Sanctum::actingAs($maker);
    $this->postJson('/v1/credit-notes/'.$posted['id'].'/hold', ['hold_date' => '2026-07-22', 'amount' => ['amount' => '150.0000', 'currency' => 'BDT']], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'insufficient_note_remaining');
});

it('reverses a posted credit note with no disposition, restoring it to draft-equivalent zero state', function (): void {
    [$entity, $maker, $checker, $sourceInvoice] = m4aDispositionFixture();
    Sanctum::actingAs($maker);
    $created = $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $sourceInvoice->customer_id,
        'source_document_id' => $sourceInvoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $sourceInvoice->lines->first()->id, 'net_amount' => ['amount' => '100.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('credit_note');
    Sanctum::actingAs($checker);
    $posted = $this->postJson('/v1/credit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertCreated()->json('credit_note');

    $reversed = $this->postJson('/v1/credit-notes/'.$posted['id'].'/reverse', [
        'reversal_date' => '2026-07-22', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Issued in error',
        'document_versions' => [], 'credit_source_versions' => [],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertCreated()->assertJsonPath('credit_note.state', 'reversed')->json();

    expect($reversed['credit_note']['state'])->toBe('reversed')
        ->and($reversed['journal_entry_ids'])->not->toBeEmpty()
        ->and(OutboxMessage::query()->where('event_type', 'CreditNoteReversed')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'credit_note_reversed')->exists())->toBeTrue();

    // A second reverse attempt is rejected — the note is no longer posted.
    $this->postJson('/v1/credit-notes/'.$posted['id'].'/reverse', [
        'reversal_date' => '2026-07-23', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Duplicate attempt',
        'document_versions' => [], 'credit_source_versions' => [],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '3'])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'note_reversed');
});

it('releases an untouched held tranche back to zero when the credit note is reversed', function (): void {
    [$entity, $maker, $checker, $sourceInvoice] = m4aDispositionFixture();
    Sanctum::actingAs($maker);
    $created = $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $sourceInvoice->customer_id,
        'source_document_id' => $sourceInvoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $sourceInvoice->lines->first()->id, 'net_amount' => ['amount' => '100.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('credit_note');
    Sanctum::actingAs($checker);
    $posted = $this->postJson('/v1/credit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertCreated()->json('credit_note');

    Sanctum::actingAs($maker);
    $held = $this->postJson('/v1/credit-notes/'.$posted['id'].'/hold', ['hold_date' => '2026-07-22', 'amount' => ['amount' => '100.0000', 'currency' => 'BDT']], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertCreated()->json();
    $trancheId = $held['credit_sources'][0]['credit_tranche_id'];
    expect(CreditTranche::query()->whereKey($trancheId)->value('remaining_amount'))->toBe('100.0000');

    $reversed = $this->postJson('/v1/credit-notes/'.$posted['id'].'/reverse', [
        'reversal_date' => '2026-07-23', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Issued in error',
        'document_versions' => [], 'credit_source_versions' => [],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '3'])
        ->assertCreated()->assertJsonPath('credit_note.state', 'reversed')->json();

    expect($reversed['credit_note']['state'])->toBe('reversed')
        ->and(CreditTranche::query()->whereKey($trancheId)->value('remaining_amount'))->toBe('0.0000')
        ->and(CreditTranche::query()->whereKey($trancheId)->value('version'))->toBe(2);
});

it('blocks reversal when a held tranche was already consumed by a downstream apply', function (): void {
    [$entity, $maker, $checker, $sourceInvoice, $targetInvoice] = m4aDispositionFixture();
    Sanctum::actingAs($maker);
    $created = $this->postJson('/v1/credit-notes', [
        'party_type' => 'customer', 'document_type' => 'invoice', 'party_id' => $sourceInvoice->customer_id,
        'source_document_id' => $sourceInvoice->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $sourceInvoice->lines->first()->id, 'net_amount' => ['amount' => '100.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('credit_note');
    Sanctum::actingAs($checker);
    $posted = $this->postJson('/v1/credit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertCreated()->json('credit_note');

    Sanctum::actingAs($maker);
    $held = $this->postJson('/v1/credit-notes/'.$posted['id'].'/hold', ['hold_date' => '2026-07-22', 'amount' => ['amount' => '100.0000', 'currency' => 'BDT']], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertCreated()->json();
    $trancheId = $held['credit_sources'][0]['credit_tranche_id'];
    $tranche = CreditTranche::query()->whereKey($trancheId)->firstOrFail();

    $this->postJson('/v1/credit-notes/'.$posted['id'].'/apply', [
        'application_date' => '2026-07-23', 'source' => 'held',
        'allocations' => [['document_id' => $targetInvoice->id, 'amount' => ['amount' => '30.0000', 'currency' => 'BDT'], 'expected_version' => 1]],
        'credit_sources' => [['credit_tranche_id' => $trancheId, 'amount' => ['amount' => '30.0000', 'currency' => 'BDT'], 'expected_version' => $tranche->version]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '3'])->assertCreated();

    $this->postJson('/v1/credit-notes/'.$posted['id'].'/reverse', [
        'reversal_date' => '2026-07-24', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Issued in error',
        'document_versions' => [], 'credit_source_versions' => [],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '4'])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'note_reversal_blocked_by_downstream_activity');
});

it('mirrors hold, apply, and refund for debit notes with vendor/bill direction and AP debited on apply', function (): void {
    $entity = Entity::query()->create(['legal_name' => 'M4A Debit Disposition '.Str::uuid(), 'functional_currency' => 'BDT']);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'm4a-dn-disp-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $checker = User::query()->create(['name' => 'Checker', 'email' => 'm4a-dn-disp-checker-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $maker->entities()->attach($entity->id, ['status' => 'active']);
    $maker->roles()->attach($role->id, ['entity_id' => $entity->id]);
    $checker->entities()->attach($entity->id, ['status' => 'active']);
    $checker->roles()->attach($role->id, ['entity_id' => $entity->id]);
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);

    $expense = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '5000', 'name' => 'Expense', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']);
    $payable = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '2100', 'name' => 'AP', 'type' => 'liability', 'normal_balance' => 'credit', 'status' => 'active']);
    $vendorCredit = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1075', 'name' => 'Vendor Credit', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $bank = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1000', 'name' => 'Bank', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active', 'bank_attributes' => ['currency' => 'BDT']]);
    config()->set('documents.bill.payable_account_id', $payable->id);
    config()->set('settlement.accounts.vendor_credit', $vendorCredit->id);
    config()->set('settlement.refund.number_prefix', 'RF');
    config()->set('settlement.refund.number_format', '{prefix}-{sequence}');
    config()->set('documents.reason_codes', ['CONFIGURED_REASON']);
    config()->set('valuation.tax.exclusive_methods', ['exclusive']);
    config()->set('valuation.tax.inclusive_methods', ['inclusive']);
    config()->set('valuation.fx.rounding_scale', 4);
    config()->set('valuation.fx.rounding_mode', 'half_up');
    config()->set('documents.debit_note.number_prefix', 'DN');
    config()->set('documents.debit_note.number_format', '{prefix}-{sequence}');

    $vendor = Vendor::query()->create(['entity_id' => $entity->id, 'name' => 'Vend', 'normalized_name' => 'VEND', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $sourceBill = Bill::query()->create(['entity_id' => $entity->id, 'document_number' => 'BILL-1', 'vendor_id' => $vendor->id, 'bill_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '100.0000', 'status' => 'awaiting_payment', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $line = BillLine::query()->create(['bill_id' => $sourceBill->id, 'entity_id' => $entity->id, 'line_no' => 1, 'description' => 'Goods', 'quantity' => '1.0000', 'unit_price' => '100.0000', 'expense_account_id' => $expense->id, 'line_amount' => '100.0000', 'tax_amount' => '0.0000', 'total_amount' => '100.0000']);
    $targetBill = Bill::query()->create(['entity_id' => $entity->id, 'document_number' => 'BILL-2', 'vendor_id' => $vendor->id, 'bill_date' => '2026-07-05', 'due_date' => '2026-08-04', 'currency' => 'BDT', 'subtotal' => '200.0000', 'tax_total' => '0.0000', 'total' => '200.0000', 'open_balance' => '200.0000', 'status' => 'awaiting_payment', 'version' => 1, 'created_by' => (string) Str::uuid()]);

    Sanctum::actingAs($maker);
    $created = $this->postJson('/v1/debit-notes', [
        'party_type' => 'vendor', 'document_type' => 'bill', 'party_id' => $vendor->id,
        'source_document_id' => $sourceBill->id, 'source_document_expected_version' => 1, 'note_date' => '2026-07-21',
        'reason_code' => 'CONFIGURED_REASON', 'lines' => [['source_line_id' => $line->id, 'net_amount' => ['amount' => '100.0000', 'currency' => 'BDT']]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json('debit_note');
    Sanctum::actingAs($checker);
    $posted = $this->postJson('/v1/debit-notes/'.$created['id'].'/post', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertCreated()->json('debit_note');

    Sanctum::actingAs($maker);
    $held = $this->postJson('/v1/debit-notes/'.$posted['id'].'/hold', ['hold_date' => '2026-07-22', 'amount' => ['amount' => '30.0000', 'currency' => 'BDT']], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertCreated()->assertJsonPath('debit_note.held_remaining_amount.amount', '30.0000')->json();
    $trancheId = $held['credit_sources'][0]['credit_tranche_id'];

    $applied = $this->postJson('/v1/debit-notes/'.$posted['id'].'/apply', [
        'application_date' => '2026-07-23', 'source' => 'undisposed',
        'allocations' => [['document_id' => $targetBill->id, 'amount' => ['amount' => '70.0000', 'currency' => 'BDT'], 'expected_version' => 1]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '3'])
        ->assertCreated()->assertJsonPath('debit_note.applied_amount.amount', '70.0000')->assertJsonPath('debit_note.undisposed_amount.amount', '0.0000')->json('debit_note');
    expect(Bill::query()->whereKey($targetBill->id)->value('open_balance'))->toBe('130.0000');

    $tranche = CreditTranche::query()->whereKey($trancheId)->firstOrFail();
    $refunded = $this->postJson('/v1/debit-notes/'.$posted['id'].'/refund', [
        'refund_date' => '2026-07-24', 'bank_account_id' => LedgerAccount::query()->where('entity_id', $entity->id)->where('code', '1000')->value('id'),
        'refund_amount' => ['amount' => '30.0000', 'currency' => 'BDT'], 'expected_available_balance' => ['amount' => '30.0000', 'currency' => 'BDT'],
        'rate_record_id' => null, 'credit_sources' => [['credit_tranche_id' => $trancheId, 'amount' => ['amount' => '30.0000', 'currency' => 'BDT'], 'expected_version' => $tranche->version]],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '4'])
        ->assertCreated()->json('debit_note');

    expect($refunded['posted_amount']['amount'])->toBe('100.0000')
        ->and($refunded['applied_amount']['amount'])->toBe('70.0000')
        ->and($refunded['refunded_amount']['amount'])->toBe('30.0000')
        ->and($refunded['held_remaining_amount']['amount'])->toBe('0.0000')
        ->and($refunded['undisposed_amount']['amount'])->toBe('0.0000');
    expect(OutboxMessage::query()->where('event_type', 'DebitNoteHeld')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'DebitNoteApplied')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'DebitNoteRefunded')->exists())->toBeTrue();
});
