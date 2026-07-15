<?php

namespace App\Identity\Application;

use App\Models\User;
use Illuminate\Support\Carbon;

final class FailedLoginLockoutPolicy
{
    public function isLocked(User $user): bool
    {
        return $user->locked_until !== null && $user->locked_until->isFuture();
    }

    public function recordFailure(User $user): bool
    {
        $maxAttempts = (int) config('identity.lockout.max_attempts', 5);
        $decayMinutes = (int) config('identity.lockout.decay_minutes', 15);
        $attempts = $user->failed_login_attempts + 1;

        $user->forceFill([
            'failed_login_attempts' => $attempts,
            'locked_until' => $attempts >= $maxAttempts ? Carbon::now('UTC')->addMinutes($decayMinutes) : $user->locked_until,
        ])->save();

        return $attempts >= $maxAttempts;
    }

    public function clear(User $user): void
    {
        $user->forceFill([
            'failed_login_attempts' => 0,
            'locked_until' => null,
        ])->save();
    }
}
