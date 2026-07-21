<?php

namespace App\Receivables\Application;

interface OpenReceivableService
{
    /** @return array<string,mixed>|null */
    public function getCustomer(string $entityId, string $customerId): ?array;

    /** @return array<string,mixed>|null */
    public function getOpenReceivable(string $entityId, string $documentId): ?array;

    /** @return array<string,mixed> */
    public function applySettlement(string $entityId, string $documentId, string $amount, int $expectedVersion): array;

    /** @return array<string,mixed> */
    public function reverseSettlement(string $entityId, string $documentId, string $amount, int $expectedVersion): array;
}
