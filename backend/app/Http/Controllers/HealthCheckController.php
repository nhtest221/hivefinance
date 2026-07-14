<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;

final class HealthCheckController
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'status' => 'ok',
            'service' => 'hivefinance-backend',
        ]);
    }
}
