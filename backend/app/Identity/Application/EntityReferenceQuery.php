<?php

namespace App\Identity\Application;

interface EntityReferenceQuery
{
    public function functionalCurrency(string $entityId): ?string;

    public function fiscalYearForDate(string $entityId, string $date): ?string;
}
