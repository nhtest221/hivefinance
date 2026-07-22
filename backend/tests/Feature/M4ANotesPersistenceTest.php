<?php

use App\Models\Identity\Entity;
use App\Models\Payables\Bill;
use App\Models\Payables\Vendor;
use App\Models\Receivables\Customer;
use App\Models\Receivables\Invoice;
use App\Payables\Application\DebitNoteRepository;
use App\Receivables\Application\CreditNoteRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{Entity,Invoice} */
function m4aReceivablesFixture(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M4A Notes '.Str::uuid(), 'functional_currency' => 'BDT']);
    $customer = Customer::query()->create(['entity_id' => $entity->id, 'name' => 'Cust', 'normalized_name' => 'CUST', 'type' => 'local', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $invoice = Invoice::query()->create(['entity_id' => $entity->id, 'document_number' => 'INV-1', 'customer_id' => $customer->id, 'invoice_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '100.0000', 'tax_total' => '0.0000', 'total' => '100.0000', 'open_balance' => '100.0000', 'status' => 'sent', 'version' => 1, 'created_by' => (string) Str::uuid()]);

    return [$entity, $invoice];
}

/** @return array{Entity,Bill} */
function m4aPayablesFixture(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M4A Notes '.Str::uuid(), 'functional_currency' => 'BDT']);
    $vendor = Vendor::query()->create(['entity_id' => $entity->id, 'name' => 'Vend', 'normalized_name' => 'VEND', 'default_currency' => 'BDT', 'payment_terms' => 'net_30', 'status' => 'active', 'version' => 1, 'created_by' => (string) Str::uuid()]);
    $bill = Bill::query()->create(['entity_id' => $entity->id, 'document_number' => 'BILL-1', 'vendor_id' => $vendor->id, 'bill_date' => '2026-07-01', 'due_date' => '2026-07-31', 'currency' => 'BDT', 'subtotal' => '200.0000', 'tax_total' => '0.0000', 'total' => '200.0000', 'open_balance' => '200.0000', 'status' => 'awaiting_payment', 'version' => 1, 'created_by' => (string) Str::uuid()]);

    return [$entity, $bill];
}

/** @return array<string,mixed> */
function m4aDraftAttributes(Entity $entity, Invoice $invoice): array
{
    return ['entity_id' => $entity->id, 'customer_id' => $invoice->customer_id, 'source_invoice_id' => $invoice->id, 'source_document_expected_version' => $invoice->version, 'note_date' => '2026-07-21', 'currency' => 'BDT', 'reason_code' => 'CONFIGURED_REASON', 'proposed_total' => '100.0000', 'state' => 'draft', 'version' => 1, 'created_by' => (string) Str::uuid()];
}

it('creates a draft credit note with lines and keeps it entity-isolated', function (): void {
    [$entityA, $invoiceA] = m4aReceivablesFixture();
    [$entityB] = m4aReceivablesFixture();
    $repo = app(CreditNoteRepository::class);

    $note = $repo->addDraft(m4aDraftAttributes($entityA, $invoiceA), [
        ['source_line_id' => (string) Str::uuid(), 'line_no' => 1, 'description' => 'Correction', 'net_amount' => '100.0000', 'tax_amount' => '0.0000', 'total_amount' => '100.0000'],
    ]);

    expect($note->lines)->toHaveCount(1)
        ->and($repo->getById($entityA->id, $note->id))->not->toBeNull()
        ->and($repo->getById($entityB->id, $note->id))->toBeNull();
});

it('enforces optimistic concurrency on every mutating repository method', function (): void {
    [$entity, $invoice] = m4aReceivablesFixture();
    $repo = app(CreditNoteRepository::class);
    $note = $repo->addDraft(m4aDraftAttributes($entity, $invoice), []);

    // Stale version on saveDraft returns null, never throws, never mutates.
    expect($repo->saveDraft($entity->id, $note->id, ['narrative' => 'x'], [], 99))->toBeNull();
    $unchanged = $repo->getById($entity->id, $note->id);
    expect($unchanged->version)->toBe(1);

    // Correct version succeeds and advances the version.
    $saved = $repo->saveDraft($entity->id, $note->id, ['narrative' => 'Updated'], [], 1);
    expect($saved)->not->toBeNull()->and($saved->version)->toBe(2)->and($saved->narrative)->toBe('Updated');

    // commitPost with a stale version fails safely.
    expect($repo->commitPost($entity->id, $note->id, ['document_number' => 'CN-1', 'posted_amount' => '100.0000', 'undisposed_amount' => '100.0000', 'state' => 'posted', 'period_ref' => '2026-07'], 1))->toBeNull();
    $posted = $repo->commitPost($entity->id, $note->id, ['document_number' => 'CN-1', 'posted_amount' => '100.0000', 'undisposed_amount' => '100.0000', 'state' => 'posted', 'period_ref' => '2026-07'], 2);
    expect($posted)->not->toBeNull()->and($posted->state)->toBe('posted')->and($posted->version)->toBe(3);

    // appendDisposition with a stale version creates NO orphan disposition row.
    expect($repo->appendDisposition($entity->id, $note->id, ['applied_amount' => '40.0000', 'undisposed_amount' => '60.0000'], ['operation' => 'apply', 'amount' => '40.0000', 'functional_amount' => '40.0000', 'occurred_on' => '2026-07-22', 'actor_id' => (string) Str::uuid()], 1))->toBeNull();
    expect(DB::table('receivables_credit_note_dispositions')->count())->toBe(0);

    $disposition = $repo->appendDisposition($entity->id, $note->id, ['applied_amount' => '40.0000', 'undisposed_amount' => '60.0000'], ['operation' => 'apply', 'amount' => '40.0000', 'functional_amount' => '40.0000', 'occurred_on' => '2026-07-22', 'actor_id' => (string) Str::uuid()], 3);
    expect($disposition)->not->toBeNull();
    $afterDisposition = $repo->getById($entity->id, $note->id);
    expect($afterDisposition->applied_amount)->toBe('40.0000')->and($afterDisposition->version)->toBe(4);

    // commitReversal with a stale version fails safely; correct version succeeds once.
    expect($repo->commitReversal($entity->id, $note->id, ['reversal_date' => '2026-07-23', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Approved reversal', 'impact_graph_hash' => 'abc', 'actor_id' => (string) Str::uuid(), 'reversed_at' => now('UTC')], 1))->toBeNull();
    $reversal = $repo->commitReversal($entity->id, $note->id, ['reversal_date' => '2026-07-23', 'reason_code' => 'CONFIGURED_REASON', 'narrative' => 'Approved reversal', 'impact_graph_hash' => 'abc', 'actor_id' => (string) Str::uuid(), 'reversed_at' => now('UTC')], 4);
    expect($reversal)->not->toBeNull();
    expect($repo->getById($entity->id, $note->id)->state)->toBe('reversed');
    expect($repo->findReversal($entity->id, $note->id))->not->toBeNull();
});

it('mirrors the exact same repository behaviour for debit notes', function (): void {
    [$entity, $bill] = m4aPayablesFixture();
    $repo = app(DebitNoteRepository::class);
    $note = $repo->addDraft(['entity_id' => $entity->id, 'vendor_id' => $bill->vendor_id, 'source_bill_id' => $bill->id, 'source_document_expected_version' => $bill->version, 'note_date' => '2026-07-21', 'currency' => 'BDT', 'reason_code' => 'CONFIGURED_REASON', 'proposed_total' => '200.0000', 'state' => 'draft', 'version' => 1, 'created_by' => (string) Str::uuid()], []);

    expect($repo->getById($entity->id, $note->id))->not->toBeNull();
    expect($repo->saveDraft($entity->id, $note->id, ['narrative' => 'x'], [], 99))->toBeNull();

    $posted = $repo->commitPost($entity->id, $note->id, ['document_number' => 'DN-1', 'posted_amount' => '200.0000', 'undisposed_amount' => '200.0000', 'state' => 'posted', 'period_ref' => '2026-07'], 1);
    expect($posted->state)->toBe('posted');
});

it('protects posted note facts from mutation and deletion in PostgreSQL', function (): void {
    [$entity, $invoice] = m4aReceivablesFixture();
    $repo = app(CreditNoteRepository::class);
    $note = $repo->addDraft(m4aDraftAttributes($entity, $invoice), [
        ['source_line_id' => (string) Str::uuid(), 'line_no' => 1, 'net_amount' => '100.0000', 'tax_amount' => '0.0000', 'total_amount' => '100.0000'],
    ]);
    $posted = $repo->commitPost($entity->id, $note->id, ['document_number' => 'CN-1', 'posted_amount' => '100.0000', 'undisposed_amount' => '100.0000', 'state' => 'posted', 'period_ref' => '2026-07'], 1);

    // Each attempt runs in its own nested transaction (Laravel SAVEPOINT): once a statement
    // is rejected, PostgreSQL poisons the enclosing transaction for everything after it, so
    // consecutive independent assertions must each roll back to their own savepoint first.
    expect(fn () => DB::transaction(fn () => DB::table('receivables_credit_notes')->where('id', $posted->id)->update(['reason_code' => 'CHANGED'])))
        ->toThrow(QueryException::class, 'Posted notes are immutable');

    expect(fn () => DB::transaction(fn () => DB::table('receivables_credit_notes')->where('id', $posted->id)->delete()))
        ->toThrow(QueryException::class, 'Posted notes are immutable');

    $lineId = $posted->lines->first()->id;
    expect(fn () => DB::transaction(fn () => DB::table('receivables_credit_note_lines')->where('id', $lineId)->update(['net_amount' => '999.0000'])))
        ->toThrow(QueryException::class, 'M4 note facts are immutable');
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL immutable-fact trigger validation.');

it('rejects an unbalanced five-field equation on post in PostgreSQL', function (): void {
    [$entity, $invoice] = m4aReceivablesFixture();
    $repo = app(CreditNoteRepository::class);
    $note = $repo->addDraft(m4aDraftAttributes($entity, $invoice), []);

    expect(fn () => DB::table('receivables_credit_notes')->where('id', $note->id)->update(['document_number' => 'CN-1', 'state' => 'posted', 'posted_amount' => '100.0000', 'applied_amount' => '0.0000', 'refunded_amount' => '0.0000', 'held_remaining_amount' => '0.0000', 'undisposed_amount' => '50.0000', 'version' => 2]))
        ->toThrow(QueryException::class);
})->skip(fn (): bool => DB::getDriverName() !== 'pgsql', 'PostgreSQL CHECK constraint validation.');
