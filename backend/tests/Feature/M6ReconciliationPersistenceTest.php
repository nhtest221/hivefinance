<?php

use App\Models\Identity\Entity;
use App\Reconciliation\Application\BankReconciliationRepository;
use App\Reconciliation\Application\ReconciliationAccountRepository;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function m6Entity(): Entity
{
    return Entity::query()->create(['legal_name' => 'M6 Reconciliation '.Str::uuid(), 'functional_currency' => 'BDT']);
}

it('creates a ReconciliationAccount and keeps it entity-isolated', function (): void {
    $entityA = m6Entity();
    $entityB = m6Entity();
    $repo = app(ReconciliationAccountRepository::class);

    $account = $repo->create(['entity_id' => $entityA->id, 'ledger_account_id' => (string) Str::uuid(), 'currency' => 'BDT', 'display_name' => 'NRB Current', 'reconciliation_enabled' => true]);

    expect($account->version)->toBe(1)
        ->and($repo->getById($entityA->id, $account->id))->not->toBeNull()
        ->and($repo->getById($entityB->id, $account->id))->toBeNull();
});

it('enforces optimistic concurrency on ReconciliationAccount updates', function (): void {
    $entity = m6Entity();
    $repo = app(ReconciliationAccountRepository::class);
    $account = $repo->create(['entity_id' => $entity->id, 'ledger_account_id' => (string) Str::uuid(), 'currency' => 'BDT', 'display_name' => 'NRB Current', 'reconciliation_enabled' => true]);

    $stale = $repo->update($entity->id, $account->id, ['display_name' => 'Stale'], 99);
    expect($stale)->toBeNull();

    $updated = $repo->update($entity->id, $account->id, ['display_name' => 'NRB Current (Renamed)'], 1);
    expect($updated)->not->toBeNull()->and($updated->display_name)->toBe('NRB Current (Renamed)')->and($updated->version)->toBe(2);
});

it('finds only mandatory (reconciliation_enabled) accounts for an entity', function (): void {
    $entity = m6Entity();
    $repo = app(ReconciliationAccountRepository::class);
    $repo->create(['entity_id' => $entity->id, 'ledger_account_id' => (string) Str::uuid(), 'currency' => 'BDT', 'display_name' => 'Enabled', 'reconciliation_enabled' => true]);
    $repo->create(['entity_id' => $entity->id, 'ledger_account_id' => (string) Str::uuid(), 'currency' => 'BDT', 'display_name' => 'Disabled', 'reconciliation_enabled' => false]);

    $mandatory = $repo->mandatoryForEntity($entity->id);
    expect($mandatory)->toHaveCount(1)->and($mandatory[0]->display_name)->toBe('Enabled');
});

it('rejects a duplicate statement line by identity and enforces entity isolation on BankReconciliation', function (): void {
    $entityA = m6Entity();
    $entityB = m6Entity();
    $accounts = app(ReconciliationAccountRepository::class);
    $reconciliations = app(BankReconciliationRepository::class);
    $account = $accounts->create(['entity_id' => $entityA->id, 'ledger_account_id' => (string) Str::uuid(), 'currency' => 'BDT', 'display_name' => 'NRB', 'reconciliation_enabled' => true]);
    $reconciliation = $reconciliations->addDraft(['entity_id' => $entityA->id, 'reconciliation_account_id' => $account->id, 'period_ref' => '2026-07', 'opening_balance' => '0.0000', 'closing_balance' => '1000.0000', 'opened_by' => (string) Str::uuid()]);

    expect($reconciliations->getById($entityA->id, $reconciliation->id))->not->toBeNull()
        ->and($reconciliations->getById($entityB->id, $reconciliation->id))->toBeNull();

    $line = ['source_line_identity' => 'row-1', 'transaction_date' => '2026-07-05', 'narration' => 'NEFT Northstar', 'normalized_narration' => 'neft northstar', 'amount' => '1000.0000', 'currency' => 'BDT', 'external_bank_reference' => 'REF1'];
    $first = $reconciliations->appendImportedLines($entityA->id, $reconciliation->id, $account->id, ['file_hash' => 'hash-1', 'imported_by' => (string) Str::uuid(), 'imported_at' => now('UTC')], [$line]);
    expect($first['imported'])->toBe(1)->and($first['conflicts'])->toBe([]);

    $second = $reconciliations->appendImportedLines($entityA->id, $reconciliation->id, $account->id, ['file_hash' => 'hash-2', 'imported_by' => (string) Str::uuid(), 'imported_at' => now('UTC')], [$line]);
    expect($second['imported'])->toBe(0)->and($second['conflicts'])->toHaveCount(1)
        ->and($second['conflicts'][0]['source_line_identity'])->toBe('row-1');
});

it('protects Completed BankReconciliation facts and Reconciled statement lines from mutation in PostgreSQL', function (): void {
    if (DB::getDriverName() !== 'pgsql') {
        $this->markTestSkipped('PostgreSQL-only immutability guard.');
    }
    $entity = m6Entity();
    $accounts = app(ReconciliationAccountRepository::class);
    $reconciliations = app(BankReconciliationRepository::class);
    $account = $accounts->create(['entity_id' => $entity->id, 'ledger_account_id' => (string) Str::uuid(), 'currency' => 'BDT', 'display_name' => 'NRB', 'reconciliation_enabled' => true]);
    $reconciliation = $reconciliations->addDraft(['entity_id' => $entity->id, 'reconciliation_account_id' => $account->id, 'period_ref' => '2026-07', 'opening_balance' => '0.0000', 'closing_balance' => '0.0000', 'opened_by' => (string) Str::uuid()]);
    $reconciliations->bumpToInProgress($entity->id, $reconciliation->id);
    $completed = $reconciliations->commitCompletion($entity->id, $reconciliation->id, 2, now('UTC'), hash('sha256', 'seed'), (string) Str::uuid());

    expect(fn () => DB::transaction(fn () => DB::table('bank_reconciliations')->where('id', $completed->id)->update(['opening_balance' => '999.0000'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('bank_reconciliations')->where('id', $completed->id)->delete()))
        ->toThrow(QueryException::class);

    $line = ['source_line_identity' => 'row-1', 'transaction_date' => '2026-07-05', 'narration' => 'x', 'normalized_narration' => 'x', 'amount' => '0.0000', 'currency' => 'BDT', 'external_bank_reference' => null];
    $reconciliations->appendImportedLines($entity->id, $reconciliation->id, $account->id, ['file_hash' => 'h', 'imported_by' => (string) Str::uuid(), 'imported_at' => now('UTC')], [$line]);
    $created = $reconciliations->linesFor($reconciliation->id)->first();
    $reconciliations->commitMatch($created->id, [], $created->version);
    $reconciled = $reconciliations->commitConfirm($created->id, $created->version + 1);

    expect(fn () => DB::transaction(fn () => DB::table('reconciliation_statement_lines')->where('id', $reconciled->id)->update(['amount' => '999.0000'])))
        ->toThrow(QueryException::class);
    expect(fn () => DB::transaction(fn () => DB::table('reconciliation_statement_lines')->where('id', $reconciled->id)->delete()))
        ->toThrow(QueryException::class);

    $import = DB::table('reconciliation_import_batches')->where('reconciliation_id', $reconciliation->id)->first();
    expect(fn () => DB::transaction(fn () => DB::table('reconciliation_import_batches')->where('id', $import->id)->update(['line_count' => 99])))
        ->toThrow(QueryException::class);
});
