<?php

namespace App\Identity\Application;

use App\Models\User;

final class AuthSessionPresenter
{
    public function __construct(private readonly RoleAuthorizationService $authorization) {}

    /**
     * @return array<string, mixed>
     */
    public function present(User $user): array
    {
        $user->loadMissing(['activeEntity', 'entities', 'roles']);
        $activeEntityId = $user->active_entity_id;

        return [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
                'mfa_required' => $this->authorization->requiresMfa($user),
                'mfa_enabled' => $user->mfa_enabled,
            ],
            'active_entity' => $user->activeEntity === null ? null : [
                'id' => $user->activeEntity->id,
                'legal_name' => $user->activeEntity->legal_name,
                'functional_currency' => $user->activeEntity->functional_currency,
            ],
            'roles' => $this->authorization->roleSlugs($user, $activeEntityId)->all(),
            'permissions' => $this->authorization->permissions($user, $activeEntityId),
        ];
    }
}
