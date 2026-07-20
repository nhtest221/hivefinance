<?php

namespace App\Ledger\Application;

interface RecognitionPostingService
{
    /** @param list<array<string, mixed>> $lines */
    public function post(string $entityId, string $sourceDocumentId, string $date, string $entryType, string $actorId, array $lines): RecognitionPostingResult;
}
