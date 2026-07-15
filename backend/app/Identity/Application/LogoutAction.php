<?php

namespace App\Identity\Application;

use App\Models\User;
use App\Support\Audit\AuditLogger;

final class LogoutAction
{
    public function __construct(private readonly AuditLogger $audit) {}

    public function execute(User $user): void
    {
        $token = $user->currentAccessToken();

        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        $this->audit->record('identity', 'logout', 'user', $user->id, $user->id, $user->active_entity_id);
    }
}
