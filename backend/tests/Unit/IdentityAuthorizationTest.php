<?php

use App\Identity\Application\FailedLoginLockoutPolicy;
use App\Identity\Application\RoleAuthorizationService;
use App\Models\Identity\Entity;
use App\Models\Identity\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('detects privileged roles that require MFA', function (): void {
    $entity = Entity::query()->create([
        'legal_name' => 'NotionHive Bangladesh',
        'functional_currency' => 'BDT',
    ]);
    $user = User::query()->create([
        'name' => 'Owner',
        'email' => 'owner@example.com',
        'password' => 'correct-horse-battery',
        'status' => 'active',
    ]);
    $role = Role::query()->create([
        'entity_id' => $entity->id,
        'name' => 'Finance Manager',
        'slug' => 'finance-manager',
        'is_system' => true,
    ]);
    $user->roles()->attach($role->id, ['entity_id' => $entity->id]);

    expect(app(RoleAuthorizationService::class)->requiresMfa($user))->toBeTrue();
});

it('applies lockout after the configured failed login threshold', function (): void {
    config()->set('identity.lockout.max_attempts', 2);

    $user = User::query()->create([
        'name' => 'Finance User',
        'email' => 'finance@example.com',
        'password' => 'correct-horse-battery',
        'status' => 'active',
    ]);
    $policy = app(FailedLoginLockoutPolicy::class);

    expect($policy->recordFailure($user))->toBeFalse()
        ->and($policy->recordFailure($user->refresh()))->toBeTrue()
        ->and($policy->isLocked($user->refresh()))->toBeTrue();
});
