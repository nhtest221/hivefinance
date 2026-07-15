<?php

namespace App\Identity\Application;

use App\Enums\SystemRole;
use App\Models\Identity\Role;
use App\Models\User;
use Illuminate\Support\Collection;

final class RoleAuthorizationService
{
    public function requiresMfa(User $user): bool
    {
        if ($user->mfa_required) {
            return true;
        }

        return $this->roleSlugs($user)->contains(
            fn (string $slug): bool => SystemRole::tryFrom($slug)?->requiresMfa() ?? false
        );
    }

    public function canAccessEntity(User $user, string $entityId): bool
    {
        return $user->entities()
            ->where('identity_entities.id', $entityId)
            ->wherePivot('status', 'active')
            ->exists();
    }

    /**
     * @return array<int, string>
     */
    public function permissions(User $user, ?string $entityId = null): array
    {
        /** @var \Illuminate\Database\Eloquent\Collection<int, Role> $roles */
        $roles = $user->roles()
            ->with('permissions')
            ->when($entityId !== null, fn ($query) => $query->wherePivot('entity_id', $entityId))
            ->get();

        return $roles
            ->flatMap(fn (Role $role): Collection => $role->permissions->pluck('permission'))
            ->unique()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, string>
     */
    public function roleSlugs(User $user, ?string $entityId = null): Collection
    {
        return $user->roles()
            ->when($entityId !== null, fn ($query) => $query->wherePivot('entity_id', $entityId))
            ->pluck('slug')
            ->values();
    }
}
