<?php

namespace App\Identity\Infrastructure;

use App\Identity\Application\ApprovalPayloadProtector;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

final class LaravelApprovalPayloadProtector implements ApprovalPayloadProtector
{
    public function protect(array $payload, int $schemaVersion): array
    {
        $canonical = $this->canonical($payload, $schemaVersion);

        return [
            'ciphertext' => Crypt::encryptString($canonical),
            'hash' => hash('sha256', $canonical),
        ];
    }

    public function reveal(string $ciphertext, string $expectedHash, int $schemaVersion): ?array
    {
        try {
            $canonical = Crypt::decryptString($ciphertext);
        } catch (DecryptException) {
            return null;
        }
        if (! hash_equals($expectedHash, hash('sha256', $canonical))) {
            return null;
        }
        try {
            $decoded = json_decode($canonical, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (! is_array($decoded) || ($decoded['schema_version'] ?? null) !== $schemaVersion || ! is_array($decoded['payload'] ?? null)) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        $payload = $decoded['payload'];

        return $payload;
    }

    /** @param array<string, mixed> $payload */
    private function canonical(array $payload, int $schemaVersion): string
    {
        $value = ['payload' => $this->sort($payload), 'schema_version' => $schemaVersion];

        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private function sort(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map($this->sort(...), $value);
        }
        ksort($value, SORT_STRING);
        foreach ($value as $key => $item) {
            $value[$key] = $this->sort($item);
        }

        return $value;
    }
}
