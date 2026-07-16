<?php

namespace App\CurrencyFx\Application;

interface RateReferenceService
{
    /** @param array<string, mixed> $reference */
    public function isExactReference(string $entityId, array $reference, string $baseCurrency, string $quoteCurrency, string $effectiveDate): bool;

    public function markReferenced(string $entityId, string $rateRecordId): void;
}
