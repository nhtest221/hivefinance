<?php

namespace App\Ledger\Application;

final readonly class RecognitionPostingResult
{
    public function __construct(public ?string $journalId, public ?string $errorCode = null) {}
}
