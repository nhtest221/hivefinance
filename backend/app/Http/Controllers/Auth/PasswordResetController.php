<?php

namespace App\Http\Controllers\Auth;

use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Identity\Application\PasswordResetService;
use Illuminate\Http\JsonResponse;

final class PasswordResetController
{
    public function forgot(ForgotPasswordRequest $request, PasswordResetService $service): JsonResponse
    {
        $result = $service->sendResetLink((string) $request->validated('email'));

        return response()->json($result->payload, $result->status);
    }

    public function reset(ResetPasswordRequest $request, PasswordResetService $service): JsonResponse
    {
        $result = $service->reset(
            (string) $request->validated('email'),
            (string) $request->validated('password'),
            (string) $request->validated('token'),
        );

        return response()->json($result->payload, $result->status);
    }
}
