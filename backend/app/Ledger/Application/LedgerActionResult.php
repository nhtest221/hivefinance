<?php

namespace App\Ledger\Application;

final readonly class LedgerActionResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public array $payload,
        public int $status = 200,
        public array $headers = [],
    ) {}
}
