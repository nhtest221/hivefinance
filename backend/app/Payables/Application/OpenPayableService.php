<?php

namespace App\Payables\Application;

interface OpenPayableService
{
    /** @return array<string,mixed>|null */
    public function getVendor(string $entityId, string $vendorId): ?array;

    /** @return array<string,mixed>|null */
    public function getOpenPayable(string $entityId, string $documentId): ?array;

    /** @return array<string,mixed> */
    public function applySettlement(string $entityId, string $documentId, string $amount, int $expectedVersion): array;

    /** @return array<string,mixed> */
    public function reverseSettlement(string $entityId, string $documentId, string $amount, int $expectedVersion): array;
}
