<?php

namespace App\Tax\Application;

use App\Identity\Application\RoleAuthorizationService;
use App\Models\User;

final readonly class TaxAuthorizationService
{
    public function __construct(private RoleAuthorizationService $roles) {}

    public function can(User $user, string $entityId, string $permission): bool
    {
        return $this->roles->canAccessEntity($user, $entityId)
            && ($this->roles->roleSlugs($user, $entityId)->intersect(['owner', 'admin'])->isNotEmpty()
                || in_array($permission, $this->roles->permissions($user, $entityId), true));
    }

    public function denied(string $permission): TaxActionResult
    {
        return new TaxActionResult(['error_code' => 'authorization', 'message' => 'The requested capability is not permitted for the active entity.', 'details' => ['permission' => $permission]], 403);
    }
}
