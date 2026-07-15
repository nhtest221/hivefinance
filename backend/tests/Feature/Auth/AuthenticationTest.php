<?php

use App\Models\AuditLog;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

function createIdentityUser(string $roleSlug = 'accountant'): array
{
    $entity = Entity::query()->create([
        'legal_name' => 'NotionHive Bangladesh',
        'functional_currency' => 'BDT',
    ]);

    $user = User::query()->create([
        'name' => 'Finance User',
        'email' => 'finance@example.com',
        'password' => 'correct-horse-battery',
        'status' => 'active',
        'active_entity_id' => $entity->id,
    ]);

    $role = Role::query()->create([
        'entity_id' => $entity->id,
        'name' => str($roleSlug)->replace('-', ' ')->title()->toString(),
        'slug' => $roleSlug,
        'is_system' => true,
    ]);

    $role->permissions()->create(['permission' => 'identity.session.read']);
    $user->entities()->attach($entity->id, ['status' => 'active']);
    $user->roles()->attach($role->id, ['entity_id' => $entity->id]);

    return [$user->refresh(), $entity, $role];
}

it('logs in an active user, creates a session token, and audits the action', function (): void {
    [$user] = createIdentityUser();

    $response = $this->postJson('/v1/auth/login', [
        'email' => 'finance@example.com',
        'password' => 'correct-horse-battery',
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('token_type', 'Bearer')
        ->assertJsonPath('session.user.id', $user->id)
        ->assertJsonPath('session.active_entity.id', $user->active_entity_id);

    expect($response->json('token'))->toBeString()->not->toBeEmpty()
        ->and(AuditLog::query()->where('action', 'login_succeeded')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('logs out the current Sanctum session and audits the action', function (): void {
    [$user] = createIdentityUser();
    Sanctum::actingAs($user);

    $this->postJson('/v1/auth/logout')
        ->assertOk()
        ->assertJsonPath('status', 'logged_out');

    expect(AuditLog::query()->where('action', 'logout')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('locks an account after repeated failed login attempts', function (): void {
    [$user] = createIdentityUser();

    for ($attempt = 1; $attempt <= 4; $attempt++) {
        $this->postJson('/v1/auth/login', [
            'email' => 'finance@example.com',
            'password' => 'wrong-password',
        ])->assertUnauthorized();
    }

    $this->postJson('/v1/auth/login', [
        'email' => 'finance@example.com',
        'password' => 'wrong-password',
    ])->assertStatus(423)
        ->assertJsonPath('error_code', 'account_locked');

    expect($user->refresh()->locked_until)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'account_locked')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('requires MFA for Owner and completes the local testing challenge', function (): void {
    [$user] = createIdentityUser('owner');

    $challenge = $this->postJson('/v1/auth/login', [
        'email' => 'finance@example.com',
        'password' => 'correct-horse-battery',
    ])->assertAccepted()
        ->assertJsonPath('mfa_required', true)
        ->json('mfa_challenge_id');

    $this->postJson('/v1/auth/mfa', [
        'mfa_challenge_id' => $challenge,
        'code' => '000000',
    ])->assertOk()
        ->assertJsonPath('session.user.id', $user->id);

    expect(AuditLog::query()->where('action', 'mfa_challenge_issued')->where('actor_id', $user->id)->exists())->toBeTrue();
});

it('returns active-entity RBAC roles and permissions for the authenticated user', function (): void {
    [$user] = createIdentityUser('finance-manager');
    Sanctum::actingAs($user);

    $this->getJson('/v1/roles')
        ->assertOk()
        ->assertJsonPath('roles.0', 'finance-manager')
        ->assertJsonPath('permissions.0', 'identity.session.read');
});

it('sends and completes password reset through the password broker', function (): void {
    Notification::fake();
    [$user] = createIdentityUser();

    $this->postJson('/v1/auth/password/forgot', [
        'email' => 'finance@example.com',
    ])->assertOk();

    $token = Password::createToken($user);

    $this->postJson('/v1/auth/password/reset', [
        'email' => 'finance@example.com',
        'token' => $token,
        'password' => 'new-correct-horse-battery',
    ])->assertOk();

    expect(AuditLog::query()->where('action', 'password_reset_requested')->where('record_id', $user->id)->exists())->toBeTrue()
        ->and(AuditLog::query()->where('action', 'password_reset_completed')->where('record_id', $user->id)->exists())->toBeTrue();
});
