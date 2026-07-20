<?php

namespace App\CurrencyFx\Application;

interface RateReferenceService
{
    /** @param array<string, mixed> $reference */
    public function isExactReference(string $entityId, array $reference, string $baseCurrency, string $quoteCurrency, string $effectiveDate): bool;

    public function markReferenced(string $entityId, string $rateRecordId): void;

    /** @param array<string, mixed> $reference */
    public function matchesFunctionalAmount(string $entityId, array $reference, string $foreignAmount, string $functionalAmount): bool;

    /** @return array<string,mixed>|null */
    public function exactById(string $entityId, string $rateRecordId, string $baseCurrency, string $quoteCurrency, string $effectiveDate): ?array;
}
