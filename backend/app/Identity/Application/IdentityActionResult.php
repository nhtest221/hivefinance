<?php

namespace App\Identity\Application;

final readonly class IdentityActionResult
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    private function __construct(public bool $ok, public int $status, public array $payload, public array $headers = []) {}

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public static function ok(array $payload, int $status = 200, array $headers = []): self
    {
        return new self(true, $status, $payload, $headers);
    }

    /**
     * @param  array<string, mixed>  $details
     * @param  array<string, mixed>  $additional
     */
    public static function error(string $errorCode, string $message, int $status, array $details = [], array $additional = []): self
    {
        return new self(false, $status, array_merge([
            'error_code' => $errorCode,
            'message' => $message,
            'details' => $details,
        ], $additional));
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public static function replay(int $status, array $payload, array $headers): self
    {
        return new self($status < 400, $status, $payload, $headers);
    }
}
