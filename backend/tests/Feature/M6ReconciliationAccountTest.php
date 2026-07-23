<?php

use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\LedgerAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

/** @return array{Entity,User} */
function m6AccountActors(): array
{
    $entity = Entity::query()->create(['legal_name' => 'M6 Account '.Str::uuid(), 'functional_currency' => 'BDT']);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $user = User::query()->create(['name' => 'Owner', 'email' => 'owner-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $user->entities()->attach($entity->id, ['status' => 'active']);
    $user->roles()->attach($role->id, ['entity_id' => $entity->id]);

    return [$entity, $user];
}

it('configures a reconciliation account referencing an existing asset-type LedgerAccount', function (): void {
    [$entity, $user] = m6AccountActors();
    $ledgerAccount = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    Sanctum::actingAs($user);

    $this->postJson('/v1/reconciliation-accounts', ['ledger_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'display_name' => 'NRB Current', 'masked_bank_identifier' => '****4821'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertCreated()
        ->assertJsonPath('reconciliation_account.ledger_account_id', $ledgerAccount->id)
        ->assertJsonPath('reconciliation_account.reconciliation_enabled', true)
        ->assertJsonPath('reconciliation_account.version', 1);
});

it('rejects a ledger account that is not asset-type or does not exist', function (): void {
    [$entity, $user] = m6AccountActors();
    $expenseAccount = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '6010', 'name' => 'Rent', 'type' => 'expense', 'normal_balance' => 'debit', 'status' => 'active']);
    Sanctum::actingAs($user);

    $this->postJson('/v1/reconciliation-accounts', ['ledger_account_id' => $expenseAccount->id, 'currency' => 'BDT', 'display_name' => 'Wrong Type'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'invalid_ledger_account');

    $this->postJson('/v1/reconciliation-accounts', ['ledger_account_id' => (string) Str::uuid(), 'currency' => 'BDT', 'display_name' => 'Nonexistent'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'invalid_ledger_account');
});

it('rejects a second reconciliation account for the same ledger account', function (): void {
    [$entity, $user] = m6AccountActors();
    $ledgerAccount = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    Sanctum::actingAs($user);
    $this->postJson('/v1/reconciliation-accounts', ['ledger_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'display_name' => 'NRB'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->assertCreated();

    $this->postJson('/v1/reconciliation-accounts', ['ledger_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'display_name' => 'NRB Dup'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])
        ->assertStatus(422)
        ->assertJsonPath('error_code', 'duplicate_reconciliation_account');
});

it('updates a reconciliation account under optimistic concurrency, never allowing ledger_account_id or currency to change', function (): void {
    [$entity, $user] = m6AccountActors();
    $ledgerAccount = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    Sanctum::actingAs($user);
    $account = $this->postJson('/v1/reconciliation-accounts', ['ledger_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'display_name' => 'NRB'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('reconciliation_account');

    $this->patchJson('/v1/reconciliation-accounts/'.$account['id'], ['reconciliation_enabled' => false], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '99'])
        ->assertStatus(409)
        ->assertJsonPath('error_code', 'concurrency_conflict');

    $this->patchJson('/v1/reconciliation-accounts/'.$account['id'], ['reconciliation_enabled' => false], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid(), 'If-Match' => '1'])
        ->assertOk()
        ->assertJsonPath('reconciliation_account.reconciliation_enabled', false)
        ->assertJsonPath('reconciliation_account.ledger_account_id', $ledgerAccount->id)
        ->assertJsonPath('reconciliation_account.version', 2);
});

it('lists and shows reconciliation accounts scoped to the active entity', function (): void {
    [$entityA, $userA] = m6AccountActors();
    $entityB = Entity::query()->create(['legal_name' => 'Other '.Str::uuid(), 'functional_currency' => 'BDT']);
    $roleB = Role::query()->create(['entity_id' => $entityB->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $userA->entities()->attach($entityB->id, ['status' => 'active']);
    $userA->roles()->attach($roleB->id, ['entity_id' => $entityB->id]);
    $ledgerAccount = LedgerAccount::query()->create(['entity_id' => $entityA->id, 'code' => '1010', 'name' => 'Cash', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    Sanctum::actingAs($userA);
    $account = $this->postJson('/v1/reconciliation-accounts', ['ledger_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'display_name' => 'NRB'], ['X-Entity-Id' => $entityA->id, 'Idempotency-Key' => (string) Str::uuid()])->json('reconciliation_account');

    $this->getJson('/v1/reconciliation-accounts', ['X-Entity-Id' => $entityA->id])
        ->assertOk()
        ->assertJsonPath('reconciliation_accounts.0.id', $account['id']);

    // Same actor, but the resource belongs to a different entity than the active one.
    $this->getJson('/v1/reconciliation-accounts/'.$account['id'], ['X-Entity-Id' => $entityB->id])
        ->assertStatus(404);
});
