<?php

namespace App\Support\Pagination;

use Illuminate\Pagination\Cursor;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

final class StableCursor
{
    /** @param array<string, mixed> $binding
     * @return array{Cursor|null,string}
     */
    public static function decode(?string $token, array $binding): array
    {
        if ($token === null) {
            return [null, Carbon::now('UTC')->toISOString()];
        }
        $decoded = base64_decode(strtr($token, '-_', '+/'), true);
        $data = is_string($decoded) ? json_decode($decoded, true) : null;
        if (! is_array($data) || ! is_string($data['cursor'] ?? null) || ! is_string($data['boundary'] ?? null) || ! is_string($data['signature'] ?? null)) {
            throw new InvalidArgumentException('Invalid cursor.');
        }
        $expected = self::signature($data['cursor'], $data['boundary'], $binding);
        if (! hash_equals($expected, $data['signature'])) {
            throw new InvalidArgumentException('Cursor does not match the requested entity or filters.');
        }

        return [Cursor::fromEncoded($data['cursor']), $data['boundary']];
    }

    /** @param array<string, mixed> $binding */
    public static function encode(?Cursor $cursor, string $boundary, array $binding): ?string
    {
        if ($cursor === null) {
            return null;
        }
        $native = $cursor->encode();
        $json = json_encode(['cursor' => $native, 'boundary' => $boundary, 'signature' => self::signature($native, $boundary, $binding)], JSON_THROW_ON_ERROR);

        return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
    }

    /** @param array<string, mixed> $binding */
    private static function signature(string $cursor, string $boundary, array $binding): string
    {
        return hash_hmac('sha256', json_encode([$cursor, $boundary, $binding], JSON_THROW_ON_ERROR), (string) config('app.key'));
    }
}
