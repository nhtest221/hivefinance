<?php

use App\Models\AuditLog;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\JournalEntry;
use App\Models\Ledger\LedgerAccount;
use App\Models\OutboxMessage;
use App\Models\Period\AccountingPeriod;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createLedgerActor(array $permissions = []): array
{
    static $actorSequence = 0;

    $actorSequence++;

    $entity = Entity::query()->create([
        'legal_name' => 'NotionHive Bangladesh',
        'functional_currency' => 'BDT',
    ]);

    $user = User::query()->create([
        'name' => 'Ledger User',
        'email' => sprintf('ledger-user-%d@example.test', $actorSequence),
        'password' => 'correct-horse-battery',
        'status' => 'active',
        'active_entity_id' => $entity->id,
    ]);

    $role = Role::query()->create([
        'entity_id' => $entity->id,
        'name' => 'Accountant',
        'slug' => 'accountant',
        'is_system' => true,
    ]);

    foreach ($permissions as $permission) {
        $role->permissions()->create(['permission' => $permission]);
    }

    $user->entities()->attach($entity->id, ['status' => 'active']);
    $user->roles()->attach($role->id, ['entity_id' => $entity->id]);

    AccountingPeriod::query()->create([
        'entity_id' => $entity->id,
        'period_ref' => '2026-07',
        'starts_on' => '2026-07-01',
        'ends_on' => '2026-07-31',
        'state' => 'open',
    ]);

    return [$user->refresh(), $entity, $role];
}

function createLedgerAccounts(string $entityId): array
{
    $cash = LedgerAccount::query()->create([
        'entity_id' => $entityId,
        'code' => '1000',
        'name' => 'Cash in Bank',
        'type' => 'asset',
        'normal_balance' => 'debit',
        'status' => 'active',
    ]);

    $revenue = LedgerAccount::query()->create([
        'entity_id' => $entityId,
        'code' => '4000',
        'name' => 'Service Revenue',
        'type' => 'revenue',
        'normal_balance' => 'credit',
        'status' => 'active',
    ]);

    return [$cash, $revenue];
}

it('creates and deactivates chart of account records with audit logging', function (): void {
    [$user, $entity] = createLedgerActor(['ledger.accounts.manage', 'ledger.accounts.read']);
    Sanctum::actingAs($user);

    $accountId = $this->postJson('/v1/accounts', [
        'code' => '1010',
        'name' => 'Operating Bank',
        'type' => 'asset',
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => '30000000-0000-4000-8000-000000000001'])
        ->assertCreated()
        ->assertJsonPath('account.normal_balance', 'debit')
        ->json('account.id');

    $this->postJson("/v1/accounts/{$accountId}/deactivate", [], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => '30000000-0000-4000-8000-000000000002', 'If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('account.status', 'deactivated');

    expect(AuditLog::query()->where('action', 'account_created')->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'account_deactivated')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'AccountCreated')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'AccountDeactivated')->exists())->toBeTrue();
});

it('denies ledger writes without the required RBAC permission', function (): void {
    [$user, $entity] = createLedgerActor();
    Sanctum::actingAs($user);

    $this->postJson('/v1/accounts', [
        'code' => '1010',
        'name' => 'Operating Bank',
        'type' => 'asset',
    ], ['X-Entity-Id' => $entity->id])
        ->assertForbidden()
        ->assertJsonPath('error_code', 'authorization');
});

it('posts a balanced manual journal, records audit and outbox events, and serves GL and trial balance', function (): void {
    [$user, $entity] = createLedgerActor([
        'ledger.journals.create',
        'ledger.journals.post',
        'ledger.journals.read',
        'ledger.reports.read',
    ]);
    [$cash, $revenue] = createLedgerAccounts($entity->id);
    Sanctum::actingAs($user);

    $journalId = $this->postJson('/v1/journals', [
        'entry_date' => '2026-07-15',
        'narration' => 'Manual revenue accrual',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => ['amount' => '100.1000', 'currency' => 'BDT'], 'credit' => null],
            ['account_id' => $revenue->id, 'debit' => null, 'credit' => ['amount' => '100.1000', 'currency' => 'BDT']],
        ],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => '10000000-0000-4000-8000-000000000001'])
        ->assertCreated()
        ->assertJsonPath('journal.state', 'Draft')
        ->json('journal.id');

    $this->postJson("/v1/journals/{$journalId}/post", [], [
        'X-Entity-Id' => $entity->id,
        'Idempotency-Key' => '20000000-0000-4000-8000-000000000001', 'If-Match' => '1',
    ])
        ->assertOk()
        ->assertJsonPath('journal.state', 'Posted');

    $this->getJson("/v1/reports/general-ledger?account={$cash->id}&range=2026-07-01..2026-07-31", ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('entries.0.running_balance.amount', '100.1000');

    $this->getJson('/v1/reports/trial-balance?asOf=2026-07-31', ['X-Entity-Id' => $entity->id])
        ->assertOk()
        ->assertJsonPath('totals.balanced', true)
        ->assertJsonPath('totals.debit', '100.1000')
        ->assertJsonPath('totals.credit', '100.1000');

    expect(AuditLog::query()->where('action', 'journal_posted')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'JournalPosted')->exists())->toBeTrue();
});

it('rejects unbalanced journals before posting', function (): void {
    [$user, $entity] = createLedgerActor(['ledger.journals.create']);
    [$cash, $revenue] = createLedgerAccounts($entity->id);
    Sanctum::actingAs($user);

    $this->postJson('/v1/journals', [
        'entry_date' => '2026-07-15',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => ['amount' => '100.0000', 'currency' => 'BDT'], 'credit' => null],
            ['account_id' => $revenue->id, 'debit' => null, 'credit' => ['amount' => '99.9999', 'currency' => 'BDT']],
        ],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => '10000000-0000-4000-8000-000000000002'])
        ->assertUnprocessable()
        ->assertJsonPath('error_code', 'invariant_violation')
        ->assertJsonPath('details.rule', 'balanced_journal');
});

it('enforces period status rules when posting a journal', function (): void {
    [$user, $entity] = createLedgerActor(['ledger.journals.create', 'ledger.journals.post']);
    [$cash, $revenue] = createLedgerAccounts($entity->id);
    AccountingPeriod::query()->where('entity_id', $entity->id)->update(['state' => 'hard_closed']);
    Sanctum::actingAs($user);

    $journalId = $this->postJson('/v1/journals', [
        'entry_date' => '2026-07-15',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => ['amount' => '100.0000', 'currency' => 'BDT'], 'credit' => null],
            ['account_id' => $revenue->id, 'debit' => null, 'credit' => ['amount' => '100.0000', 'currency' => 'BDT']],
        ],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => '10000000-0000-4000-8000-000000000003'])->json('journal.id');

    $this->postJson("/v1/journals/{$journalId}/post", [], [
        'X-Entity-Id' => $entity->id,
        'Idempotency-Key' => '20000000-0000-4000-8000-000000000003', 'If-Match' => '1',
    ])
        ->assertStatus(423)
        ->assertJsonPath('error_code', 'period_locked');
});

it('creates a linked posted reversal without mutating the original posted journal', function (): void {
    [$user, $entity] = createLedgerActor([
        'ledger.journals.create',
        'ledger.journals.post',
        'ledger.journals.reverse',
    ]);
    [$cash, $revenue] = createLedgerAccounts($entity->id);
    Sanctum::actingAs($user);

    $journalId = $this->postJson('/v1/journals', [
        'entry_date' => '2026-07-15',
        'lines' => [
            ['account_id' => $cash->id, 'debit' => ['amount' => '100.0000', 'currency' => 'BDT'], 'credit' => null],
            ['account_id' => $revenue->id, 'debit' => null, 'credit' => ['amount' => '100.0000', 'currency' => 'BDT']],
        ],
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => '10000000-0000-4000-8000-000000000004'])->json('journal.id');

    $this->postJson("/v1/journals/{$journalId}/post", [], [
        'X-Entity-Id' => $entity->id,
        'Idempotency-Key' => '20000000-0000-4000-8000-000000000004', 'If-Match' => '1',
    ])->assertOk();

    $reversalId = $this->postJson("/v1/journals/{$journalId}/reverse", [
        'entry_date' => '2026-07-20',
        'reason' => 'Correction by reversal',
    ], [
        'X-Entity-Id' => $entity->id,
        'Idempotency-Key' => '30000000-0000-4000-8000-000000000003',
    ])
        ->assertCreated()
        ->assertJsonPath('journal.state', 'posted')
        ->assertJsonPath('journal.reversal_of_entry_id', $journalId)
        ->json('journal.id');

    expect($reversalId)->not->toBe($journalId)
        ->and(JournalEntry::query()->find($journalId)->state)->toBe('posted')
        ->and(OutboxMessage::query()->where('event_type', 'JournalReversed')->exists())->toBeTrue()
        ->and(OutboxMessage::query()->where('event_type', 'JournalPosted')->where('aggregate_id', $reversalId)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'journal_reversed')->exists())->toBeTrue();

    $this->postJson("/v1/journals/{$journalId}/reverse", [
        'entry_date' => '2026-07-21', 'reason' => 'Duplicate reversal attempt',
    ], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => '30000000-0000-4000-8000-000000000004'])
        ->assertUnprocessable()->assertJsonPath('details.rule', 'journal_already_reversed');
});
