<?php

namespace App\Identity\Application;

interface ApprovalPayloadProtector
{
    /**
     * @param  array<string, mixed>  $payload
     * @return array{ciphertext:string,hash:string}
     */
    public function protect(array $payload, int $schemaVersion): array;

    /**
     * @return array<string, mixed>|null Null means decryption, schema, or integrity verification failed.
     */
    public function reveal(string $ciphertext, string $expectedHash, int $schemaVersion): ?array;
}
