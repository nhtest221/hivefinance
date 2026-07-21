<?php

namespace App\Ledger\Application;

interface SettlementPostingService
{
    /** @param list<array<string,mixed>> $lines */
    public function post(string $entityId, string $allocationId, string $date, string $actorId, string $causationId, array $lines): RecognitionPostingResult;

    /** @param list<string> $journalIds */
    public function reverse(string $entityId, string $reversalAllocationId, string $date, string $actorId, string $causationId, array $journalIds): RecognitionPostingResult;
}
