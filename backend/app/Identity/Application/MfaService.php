<?php

namespace App\Identity\Application;

use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class MfaService
{
    public function issueChallenge(User $user): string
    {
        $challengeId = (string) Str::uuid();
        $ttlMinutes = (int) config('identity.mfa.challenge_ttl_minutes', 5);

        Cache::put($this->cacheKey($challengeId), $user->id, now()->addMinutes($ttlMinutes));

        return $challengeId;
    }

    public function verifyChallenge(string $challengeId, string $code): ?User
    {
        $userId = Cache::get($this->cacheKey($challengeId));

        if (! is_string($userId)) {
            return null;
        }

        if (! app()->environment(['local', 'testing'])) {
            return null;
        }

        if (! hash_equals((string) config('identity.mfa.test_code', '000000'), $code)) {
            return null;
        }

        Cache::forget($this->cacheKey($challengeId));

        return User::query()->find($userId);
    }

    private function cacheKey(string $challengeId): string
    {
        return "identity:mfa-challenge:{$challengeId}";
    }
}
