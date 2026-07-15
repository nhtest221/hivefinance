<?php

namespace App\Ledger\Application;

use App\Identity\Application\RoleAuthorizationService;
use App\Models\User;

final readonly class LedgerAuthorizationService
{
    public function __construct(private RoleAuthorizationService $roles) {}

    public function can(User $user, string $entityId, string $permission): bool
    {
        if (! $this->roles->canAccessEntity($user, $entityId)) {
            return false;
        }

        $roleSlugs = $this->roles->roleSlugs($user, $entityId);
        if ($roleSlugs->intersect(['owner', 'admin'])->isNotEmpty()) {
            return true;
        }

        return in_array($permission, $this->roles->permissions($user, $entityId), true);
    }

    public function denyResponse(string $permission): LedgerActionResult
    {
        return new LedgerActionResult([
            'error_code' => 'authorization',
            'message' => 'The requested capability is not permitted for the active entity.',
            'details' => ['permission' => $permission],
        ], 403);
    }
}
