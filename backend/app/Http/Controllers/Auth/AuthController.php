<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\MfaRequest;
use App\Identity\Application\AuthSessionPresenter;
use App\Identity\Application\LoginAction;
use App\Identity\Application\LogoutAction;
use App\Identity\Application\MfaService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuthController
{
    public function login(LoginRequest $request, LoginAction $login): JsonResponse
    {
        $result = $login->execute(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            $request->ip(),
            $request->userAgent(),
        );

        return response()->json($result->payload, $result->status);
    }

    public function mfa(MfaRequest $request, MfaService $mfa, LoginAction $login): JsonResponse
    {
        $user = $mfa->verifyChallenge(
            (string) $request->validated('mfa_challenge_id'),
            (string) $request->validated('code'),
        );

        if ($user === null) {
            return response()->json([
                'error_code' => 'invalid_mfa_challenge',
                'message' => 'The MFA challenge is invalid or expired.',
                'details' => [],
            ], 401);
        }

        $result = $login->createSession($user, $request->ip(), $request->userAgent());

        return response()->json($result->payload, $result->status);
    }

    public function session(Request $request, AuthSessionPresenter $presenter): JsonResponse
    {
        return response()->json([
            'session' => $presenter->present($request->user()),
        ]);
    }

    public function logout(Request $request, LogoutAction $logout): JsonResponse
    {
        $logout->execute($request->user());

        return response()->json([
            'status' => 'logged_out',
        ]);
    }
}
