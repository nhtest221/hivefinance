<?php

namespace App\Identity\Application;

use App\Models\User;
use App\Support\Audit\AuditLogger;

final class EntityAccessService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly RoleAuthorizationService $authorization,
    ) {}

    /**
     * @return array<int, array<string, mixed>>
     */
    public function availableEntities(User $user): array
    {
        return $user->entities()
            ->wherePivot('status', 'active')
            ->orderBy('legal_name')
            ->get()
            ->map(fn ($entity): array => [
                'id' => $entity->id,
                'legal_name' => $entity->legal_name,
                'functional_currency' => $entity->functional_currency,
                'active' => $entity->id === $user->active_entity_id,
            ])
            ->all();
    }

    public function switch(User $user, string $entityId): IdentityActionResult
    {
        if (! $this->authorization->canAccessEntity($user, $entityId)) {
            $this->audit->record('identity', 'entity_switch_denied', 'entity', $entityId, $user->id, $user->active_entity_id);

            return IdentityActionResult::error('authorization', 'The requested entity is not available to this user.', 403);
        }

        $previousEntityId = $user->active_entity_id;

        $user->forceFill(['active_entity_id' => $entityId])->save();

        $this->audit->record('identity', 'entity_switched', 'entity', $entityId, $user->id, $entityId, before: [
            'active_entity_id' => $previousEntityId,
        ], after: [
            'active_entity_id' => $entityId,
        ]);

        return IdentityActionResult::ok([
            'active_entity_id' => $entityId,
        ]);
    }
}
