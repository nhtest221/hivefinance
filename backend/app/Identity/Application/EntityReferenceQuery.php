<?php

namespace App\Identity\Application;

interface EntityReferenceQuery
{
    public function functionalCurrency(string $entityId): ?string;
}
