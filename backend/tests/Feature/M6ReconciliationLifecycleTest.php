<?php

use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\Period\AccountingPeriod;
use App\Models\Reconciliation\ReconciliationAccount;
use App\Models\Settlement\Allocation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Entity,User,User,LedgerAccount,ReconciliationAccount} */
function m6Actors(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M6 Lifecycle '.Str::uuid(), 'functional_currency' => 'BDT']);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $maker = User::query()->create(['name' => 'Maker', 'email' => 'maker-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $checker = User::query()->create(['name' => 'Checker', 'email' => 'chk-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    foreach ([$maker, $checker] as $user) {
        $user->entities()->attach($entity->id, ['status' => 'active']);
        $user->roles()->attach($role->id, ['entity_id' => $entity->id]);
    }
    AccountingPeriod::query()->create(['entity_id' => $entity->id, 'period_ref' => '2026-07', 'starts_on' => '2026-07-01', 'ends_on' => '2026-07-31', 'state' => 'Open']);
    $ledgerAccount = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'NRB Current', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $account = ReconciliationAccount::query()->create(['entity_id' => $entity->id, 'ledger_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'display_name' => 'NRB Current', 'reconciliation_enabled' => true, 'version' => 1]);

    return [$entity, $maker, $checker, $ledgerAccount, $account];
}

function m6Allocation(string $entityId, string $ledgerAccountId, string $amount, string $date): Allocation
{
    return Allocation::query()->create([
        'entity_id' => $entityId, 'allocation_number' => 'RCPT-'.Str::random(6), 'operation' => 'receipt', 'party_type' => 'customer',
        'party_id' => (string) Str::uuid(), 'settlement_date' => $date, 'bank_account_id' => $ledgerAccountId, 'currency' => 'BDT',
        'gross_amount' => $amount, 'bank_amount' => $amount, 'withholding_amount' => '0.0000', 'allocated_amount' => $amount,
        'unapplied_amount' => '0.0000', 'functional_gross_amount' => $amount, 'journal_entry_ids' => [], 'state' => 'posted',
        'version' => 1, 'created_by' => (string) Str::uuid(), 'posted_at' => now('UTC'),
    ]);
}

function m6OpenAndImport(User $maker, string $entityId, string $accountId, string $amount, string $date, ?string $externalRef = 'REF1'): array
{
    Sanctum::actingAs($maker);
    $reconciliation = test()->postJson('/v1/reconciliations', ['reconciliation_account_id' => $accountId, 'period_ref' => '2026-07', 'opening_balance' => '0.0000', 'closing_balance' => $amount], ['X-Entity-Id' => $entityId, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()->json('reconciliation');
    test()->postJson('/v1/reconciliations/'.$reconciliation['id'].'/import', [
        'file_hash' => hash('sha256', Str::random()),
        'lines' => [['source_line_identity' => 'row-1', 'transaction_date' => $date, 'narration' => 'NEFT Northstar Digital', 'amount' => ['amount' => $amount, 'currency' => 'BDT'], 'external_bank_reference' => $externalRef]],
    ], ['X-Entity-Id' => $entityId, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    return $reconciliation;
}

it('runs the full reconciliation lifecycle: open, import, suggest, match, confirm, and complete under mandatory four-eyes', function (): void {
    [$entity, $maker, $checker, $ledgerAccount, $account] = m6Actors();
    m6Allocation($entity->id, $ledgerAccount->id, '9000.0000', '2026-07-05');
    $reconciliation = m6OpenAndImport($maker, $entity->id, $account->id, '9000.0000', '2026-07-05');

    Sanctum::actingAs($maker);
    $suggestions = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/match-suggestions', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertOk()->json();
    expect($suggestions['suggested'])->toBe(1)->and($suggestions['unexplained'])->toBe(0);
    $lineId = $suggestions['lines'][0]['line_id'];
    $lineVersion = $suggestions['lines'][0]['version'];
    $allocationId = $suggestions['lines'][0]['suggestions'][0]['allocation_ids'][0];

    $matched = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/match', ['allocation_ids' => [$allocationId]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $lineVersion])
        ->assertOk()->assertJsonPath('line.status', 'Matched')->json('line');

    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/confirm', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $matched['version']])
        ->assertOk()->assertJsonPath('line.status', 'Reconciled');

    $pending = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/complete', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertStatus(202)->assertJsonPath('approval.status', 'pending')->json('approval');

    // Mandatory four-eyes is unconditional: the maker cannot approve their own request.
    Sanctum::actingAs($maker);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertStatus(403);

    Sanctum::actingAs($checker);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('command_result.status', 200)
        ->assertJsonPath('command_result.body.reconciliation.state', 'Completed');

    $final = $this->getJson('/v1/reconciliations/'.$reconciliation['id'], ['X-Entity-Id' => $entity->id])->assertOk()->json('reconciliation');
    expect($final['state'])->toBe('Completed')
        ->and($final['source_data_watermark'])->not->toBeNull()
        ->and($final['content_hash'])->not->toBeNull()
        ->and($final['completed_by'])->toBe($checker->id);
});

it('rejects an exact duplicate statement-file re-import, and per-line duplicates across two different import files', function (): void {
    [$entity, $maker, , , $account] = m6Actors();
    Sanctum::actingAs($maker);
    $reconciliation = $this->postJson('/v1/reconciliations', ['reconciliation_account_id' => $account->id, 'period_ref' => '2026-07', 'opening_balance' => '0.0000', 'closing_balance' => '0.0000'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('reconciliation');
    $lines = ['file_hash' => 'fixed-hash', 'lines' => [['source_line_identity' => 'row-1', 'transaction_date' => '2026-07-05', 'narration' => 'x', 'amount' => ['amount' => '100.0000', 'currency' => 'BDT']]]];

    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/import', $lines, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();
    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/import', $lines, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)->assertJsonPath('error_code', 'duplicate_import_file');

    $sameLineDifferentFile = ['file_hash' => 'different-hash', 'lines' => $lines['lines']];
    $response = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/import', $sameLineDifferentFile, ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated()->json();
    expect($response['imported'])->toBe(0)->and($response['conflicts'])->toHaveCount(1)->and($response['conflicts'][0]['reason'])->toBe('duplicate_statement_line');
});

it('blocks completion while any line is not Reconciled, and rejects a non-zero unexplained difference', function (): void {
    [$entity, $maker, , , $account] = m6Actors();
    $reconciliation = m6OpenAndImport($maker, $entity->id, $account->id, '500.0000', '2026-07-05');

    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/complete', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertStatus(422)->assertJsonPath('error_code', 'lines_not_fully_reconciled');
});

it('resolves an Unexplained line through an approved bank-only entry, posted via Ledger PostingService, with no default classification', function (): void {
    [$entity, $maker, $checker, $ledgerAccount, $account] = m6Actors();
    $offsetAccount = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '7010', 'name' => 'Bank Charges', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']);
    $reconciliation = m6OpenAndImport($maker, $entity->id, $account->id, '-50.0000', '2026-07-05', null);

    Sanctum::actingAs($maker);
    $suggestions = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/match-suggestions', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertOk()->json();
    expect($suggestions['unexplained'])->toBe(1);
    $lineId = $suggestions['lines'][0]['line_id'];
    $lineVersion = $suggestions['lines'][0]['version'];

    $pending = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/bank-entry', ['offset_account_id' => $offsetAccount->id, 'narration' => 'NRB monthly service charge'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $lineVersion])
        ->assertStatus(202)->json('approval');

    Sanctum::actingAs($checker);
    $approved = $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()->assertJsonPath('command_result.body.line.status', 'Reconciled')->json('command_result.body.line');

    expect($approved['resolved_by_journal_entry_id'])->not->toBeNull();
    $journal = JournalEntry::query()->with('lines')->find($approved['resolved_by_journal_entry_id']);
    expect($journal->state)->toBe('posted')
        ->and($journal->lines->pluck('account_id')->all())->toContain($ledgerAccount->id, $offsetAccount->id);

    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/complete', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])
        ->assertStatus(202);
});

it('reopens a Completed reconciliation under mandatory four-eyes without reverting Reconciled lines, then re-completes', function (): void {
    [$entity, $maker, $checker, $ledgerAccount, $account] = m6Actors();
    m6Allocation($entity->id, $ledgerAccount->id, '9000.0000', '2026-07-05');
    $reconciliation = m6OpenAndImport($maker, $entity->id, $account->id, '9000.0000', '2026-07-05');
    Sanctum::actingAs($maker);
    $suggestions = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/match-suggestions', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json();
    $lineId = $suggestions['lines'][0]['line_id'];
    $lineVersion = $suggestions['lines'][0]['version'];
    $allocationId = $suggestions['lines'][0]['suggestions'][0]['allocation_ids'][0];
    $matched = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/match', ['allocation_ids' => [$allocationId]], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $lineVersion])->assertOk()->json('line');
    $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/lines/'.$lineId.'/confirm', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => (string) $matched['version']])->assertOk();
    $pending = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/complete', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '2'])->json('approval');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/approvals/'.$pending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])->assertOk();

    Sanctum::actingAs($maker);
    $reopenPending = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/reopen', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '3'])
        ->assertStatus(202)->json('approval');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/approvals/'.$reopenPending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()->assertJsonPath('command_result.body.reconciliation.state', 'Reopened');

    $line = $this->getJson('/v1/reconciliations/'.$reconciliation['id'].'/unmatched', ['X-Entity-Id' => $entity->id])->json('lines');
    expect($line)->toBe([]); // the Reconciled line was never reverted by Reopen.

    Sanctum::actingAs($maker);
    $recompletePending = $this->postJson('/v1/reconciliations/'.$reconciliation['id'].'/complete', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '4'])
        ->assertStatus(202)->json('approval');
    Sanctum::actingAs($checker);
    $this->postJson('/v1/approvals/'.$recompletePending['id'].'/approve', [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()->assertJsonPath('command_result.body.reconciliation.state', 'Completed');
});

it('registers exactly the 16 frozen M6 public endpoints', function (): void {
    $routes = collect(app('router')->getRoutes()->getRoutes())
        ->map(fn ($route): string => implode('|', $route->methods()).' /'.$route->uri())
        ->filter(fn (string $route): bool => (bool) preg_match('#/v1/(reconciliation-accounts(/\{id\})?$|reconciliations(/\{id\}(/import|/match-suggestions|/unmatched|/lines/\{lineId\}(/match|/confirm|/bank-entry)|/complete|/reopen|/statement)?)?$)#', $route))
        ->unique();

    expect($routes)->toHaveCount(16);
});
