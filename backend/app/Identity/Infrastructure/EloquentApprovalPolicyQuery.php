<?php

namespace App\Identity\Infrastructure;

use App\Identity\Application\ApprovalPolicyQuery;
use App\Models\Identity\Entity;

final class EloquentApprovalPolicyQuery implements ApprovalPolicyQuery
{
    public function isConfigured(string $entityId): bool
    {
        $policy = Entity::query()->whereKey($entityId)->value('approval_policy');
        if (is_string($policy)) {
            $policy = json_decode($policy, true);
        }

        return is_array($policy) && $policy !== [];
    }
}
