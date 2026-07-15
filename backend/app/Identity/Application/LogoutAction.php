<?php

namespace App\Identity\Application;

use App\Models\User;
use App\Support\Audit\AuditLogger;

final readonly class LogoutAction
{
    public function __construct(private AuditLogger $audit) {}

    public function execute(User $user): void
    {
        $token = $user->currentAccessToken();

        /** @phpstan-ignore-next-line Transient Sanctum test tokens do not expose delete(). */
        if ($token !== null && method_exists($token, 'delete')) {
            $token->delete();
        }

        $this->audit->record('identity', 'logout', 'user', $user->id, $user->id, $user->active_entity_id);
    }
}
