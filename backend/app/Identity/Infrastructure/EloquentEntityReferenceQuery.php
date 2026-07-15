<?php

namespace App\Identity\Infrastructure;

use App\Identity\Application\EntityReferenceQuery;
use App\Models\Identity\Entity;

final class EloquentEntityReferenceQuery implements EntityReferenceQuery
{
    public function functionalCurrency(string $entityId): ?string
    {
        $currency = Entity::query()->whereKey($entityId)->value('functional_currency');

        return is_string($currency) ? $currency : null;
    }
}
