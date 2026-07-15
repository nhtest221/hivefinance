<?php

namespace App\Identity\Application;

final readonly class IdentityActionResult
{
    public bool $ok;

    public int $status;

    /**
     * @var array<string, mixed>
     */
    public array $payload;

    /**
     * @param array<string, mixed> $payload
     */
    private function __construct(
        bool $ok,
        int $status,
        array $payload,
    ) {
        $this->ok = $ok;
        $this->status = $status;
        $this->payload = $payload;
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function ok(array $payload, int $status = 200): self
    {
        return new self(true, $status, $payload);
    }

    /**
     * @param array<string, mixed> $details
     */
    public static function error(string $errorCode, string $message, int $status, array $details = []): self
    {
        return new self(false, $status, [
            'error_code' => $errorCode,
            'message' => $message,
            'details' => $details,
        ]);
    }
}
