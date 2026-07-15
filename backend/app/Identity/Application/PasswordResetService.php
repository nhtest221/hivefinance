<?php

namespace App\Identity\Application;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;

final readonly class PasswordResetService
{
    public function __construct(private AuditLogger $audit) {}

    public function sendResetLink(string $email): IdentityActionResult
    {
        $status = Password::sendResetLink(['email' => strtolower($email)]);
        $user = User::query()->where('email', strtolower($email))->first();
        $recordId = $user instanceof User ? $user->id : (string) Str::uuid();

        $this->audit->record('identity', 'password_reset_requested', 'user', $recordId, $user?->id, $user?->active_entity_id, metadata: [
            'email' => strtolower($email),
            'status' => $status,
        ]);

        return IdentityActionResult::ok([
            'status' => $status,
        ]);
    }

    public function reset(string $email, string $password, string $token): IdentityActionResult
    {
        $status = Password::reset([
            'email' => strtolower($email),
            'password' => $password,
            'password_confirmation' => $password,
            'token' => $token,
        ], function (User $user) use ($password): void {
            $user->forceFill([
                'password' => Hash::make($password),
                'remember_token' => Str::random(60),
                'failed_login_attempts' => 0,
                'locked_until' => null,
            ])->save();

            event(new PasswordReset($user));

            $this->audit->record('identity', 'password_reset_completed', 'user', $user->id, $user->id, $user->active_entity_id);
        });

        if ($status !== Password::PASSWORD_RESET) {
            return IdentityActionResult::error('invalid_password_reset', 'The password reset token is invalid or expired.', 422, [
                'status' => $status,
            ]);
        }

        return IdentityActionResult::ok(['status' => $status]);
    }
}
