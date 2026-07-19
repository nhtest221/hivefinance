<?php

namespace App\Tax\Application;

final readonly class TaxActionResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function __construct(public array $payload, public int $status = 200, public array $headers = []) {}
}
