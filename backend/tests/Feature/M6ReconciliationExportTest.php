<?php

use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\Ledger\LedgerAccount;
use App\Models\Reconciliation\ReconciliationAccount;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('exports a reconciliation statement as CSV and PDF, and rejects xlsx', function (): void {
    $entity = Entity::query()->create(['legal_name' => 'M6 Export '.Str::uuid(), 'functional_currency' => 'BDT']);
    $role = Role::query()->create(['entity_id' => $entity->id, 'name' => 'Owner', 'slug' => 'owner', 'is_system' => true]);
    $user = User::query()->create(['name' => 'Owner', 'email' => 'o-'.Str::uuid().'@test.local', 'password' => 'secret-password', 'status' => 'active', 'active_entity_id' => $entity->id]);
    $user->entities()->attach($entity->id, ['status' => 'active']);
    $user->roles()->attach($role->id, ['entity_id' => $entity->id]);
    $ledgerAccount = LedgerAccount::query()->create(['entity_id' => $entity->id, 'code' => '1010', 'name' => 'NRB', 'type' => 'asset', 'normal_balance' => 'debit', 'status' => 'active']);
    $account = ReconciliationAccount::query()->create(['entity_id' => $entity->id, 'ledger_account_id' => $ledgerAccount->id, 'currency' => 'BDT', 'display_name' => 'NRB Current', 'reconciliation_enabled' => true, 'version' => 1]);
    Sanctum::actingAs($user);
    $reconciliation = $this->postJson('/v1/reconciliations', ['reconciliation_account_id' => $account->id, 'period_ref' => '2026-07', 'opening_balance' => '0.0000', 'closing_balance' => '0.0000'], ['X-Entity-Id' => $entity->id, 'Idempotency-Key' => (string) Str::uuid()])->json('reconciliation');

    $csv = $this->get('/v1/reconciliations/'.$reconciliation['id'].'/statement?format=csv', ['X-Entity-Id' => $entity->id])
        ->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
    expect($csv->content())->toContain('NRB Current')->toContain('Draft');

    $pdf = $this->get('/v1/reconciliations/'.$reconciliation['id'].'/statement?format=pdf', ['X-Entity-Id' => $entity->id])
        ->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect(substr((string) $pdf->content(), 0, 4))->toBe('%PDF');

    $this->get('/v1/reconciliations/'.$reconciliation['id'].'/statement?format=xlsx', ['X-Entity-Id' => $entity->id])
        ->assertStatus(400);
});
