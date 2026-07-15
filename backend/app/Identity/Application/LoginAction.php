<?php

namespace App\Identity\Application;

use App\Models\User;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

final readonly class LoginAction
{
    public function __construct(
        private AuditLogger $audit,
        private FailedLoginLockoutPolicy $lockout,
        private RoleAuthorizationService $authorization,
        private MfaService $mfa,
        private AuthSessionPresenter $presenter,
    ) {}

    public function execute(string $email, string $password, ?string $ipAddress, ?string $userAgent): IdentityActionResult
    {
        $user = User::query()->where('email', strtolower($email))->first();

        if (! $user instanceof User) {
            $this->auditLoginFailure((string) Str::uuid(), null, $email, $ipAddress, $userAgent);

            return IdentityActionResult::error('invalid_credentials', 'The supplied credentials are invalid.', 401);
        }

        if ($this->lockout->isLocked($user)) {
            $this->audit->record('identity', 'login_locked', 'user', $user->id, $user->id, $user->active_entity_id, metadata: [
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
                'locked_until' => $user->locked_until?->toIso8601String(),
            ]);

            return IdentityActionResult::error('account_locked', 'The account is temporarily locked.', 423, [
                'locked_until' => $user->locked_until?->toIso8601String(),
            ]);
        }

        if ($user->status !== 'active' || ! Hash::check($password, $user->password)) {
            $locked = $this->lockout->recordFailure($user);
            $this->auditLoginFailure($user->id, $user->active_entity_id, $email, $ipAddress, $userAgent);

            if ($locked) {
                $this->audit->record('identity', 'account_locked', 'user', $user->id, $user->id, $user->active_entity_id);

                return IdentityActionResult::error('account_locked', 'The account is temporarily locked.', 423, [
                    'locked_until' => $user->refresh()->locked_until?->toIso8601String(),
                ]);
            }

            return IdentityActionResult::error('invalid_credentials', 'The supplied credentials are invalid.', 401);
        }

        $this->lockout->clear($user);
        $this->ensureActiveEntity($user);

        if ($this->authorization->requiresMfa($user)) {
            $challengeId = $this->mfa->issueChallenge($user);
            $this->audit->record('identity', 'mfa_challenge_issued', 'user', $user->id, $user->id, $user->active_entity_id, metadata: [
                'ip_address' => $ipAddress,
                'user_agent' => $userAgent,
            ]);

            return IdentityActionResult::ok([
                'mfa_required' => true,
                'mfa_challenge_id' => $challengeId,
            ], 202);
        }

        return $this->createSession($user, $ipAddress, $userAgent);
    }

    public function createSession(User $user, ?string $ipAddress, ?string $userAgent): IdentityActionResult
    {
        $token = $user->createToken('api-session')->plainTextToken;

        $user->forceFill(['last_login_at' => Carbon::now('UTC')])->save();
        $this->audit->record('identity', 'login_succeeded', 'user', $user->id, $user->id, $user->active_entity_id, metadata: [
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);

        return IdentityActionResult::ok([
            'token_type' => 'Bearer',
            'token' => $token,
            'session' => $this->presenter->present($user->refresh()),
        ]);
    }

    private function ensureActiveEntity(User $user): void
    {
        if ($user->active_entity_id !== null && $this->authorization->canAccessEntity($user, $user->active_entity_id)) {
            return;
        }

        $entity = $user->entities()->wherePivot('status', 'active')->first();

        if ($entity !== null) {
            $user->forceFill(['active_entity_id' => $entity->id])->save();
        }
    }

    private function auditLoginFailure(string $recordId, ?string $entityId, string $email, ?string $ipAddress, ?string $userAgent): void
    {
        $this->audit->record('identity', 'login_failed', 'user', $recordId, null, $entityId, metadata: [
            'email' => strtolower($email),
            'ip_address' => $ipAddress,
            'user_agent' => $userAgent,
        ]);
    }
}
