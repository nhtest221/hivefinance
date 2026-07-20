<?php

namespace App\Support\Documents;

final readonly class DocumentActionResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(public array $payload, public int $status = 200, public array $headers = []) {}
}
