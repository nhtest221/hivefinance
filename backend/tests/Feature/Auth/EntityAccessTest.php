<?php

use App\Models\AuditLog;
use App\Models\Identity\Entity;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

it('lists only entities granted to the authenticated user', function (): void {
    $primary = Entity::query()->create([
        'legal_name' => 'NotionHive Bangladesh',
        'functional_currency' => 'BDT',
    ]);
    Entity::query()->create([
        'legal_name' => 'NotionHive Canada',
        'functional_currency' => 'CAD',
    ]);
    $user = User::query()->create([
        'name' => 'Finance User',
        'email' => 'finance@example.com',
        'password' => 'correct-horse-battery',
        'status' => 'active',
        'active_entity_id' => $primary->id,
    ]);
    $user->entities()->attach($primary->id, ['status' => 'active']);

    Sanctum::actingAs($user);

    $this->getJson('/v1/entities')
        ->assertOk()
        ->assertJsonCount(1, 'entities')
        ->assertJsonPath('entities.0.id', $primary->id);
});

it('switches only to an accessible entity and audits denied attempts', function (): void {
    $primary = Entity::query()->create([
        'legal_name' => 'NotionHive Bangladesh',
        'functional_currency' => 'BDT',
    ]);
    $secondary = Entity::query()->create([
        'legal_name' => 'NotionHive Canada',
        'functional_currency' => 'CAD',
    ]);
    $denied = Entity::query()->create([
        'legal_name' => 'External Entity',
        'functional_currency' => 'USD',
    ]);
    $user = User::query()->create([
        'name' => 'Finance User',
        'email' => 'finance@example.com',
        'password' => 'correct-horse-battery',
        'status' => 'active',
        'active_entity_id' => $primary->id,
    ]);
    $user->entities()->attach($primary->id, ['status' => 'active']);
    $user->entities()->attach($secondary->id, ['status' => 'active']);

    Sanctum::actingAs($user);

    $this->postJson('/v1/entities/switch', [
        'entity_id' => $secondary->id,
    ])->assertOk()
        ->assertJsonPath('active_entity_id', $secondary->id);

    $this->postJson('/v1/entities/switch', [
        'entity_id' => $denied->id,
    ])->assertForbidden()
        ->assertJsonPath('error_code', 'authorization');

    expect($user->refresh()->active_entity_id)->toBe($secondary->id)
        ->and(AuditLog::query()->where('action', 'entity_switched')->where('record_id', $secondary->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'entity_switch_denied')->where('record_id', $denied->id)->exists())->toBeTrue();
});
