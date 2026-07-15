<?php

namespace App\Support\Correlation;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class CorrelationIdMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $value = $request->header('X-Correlation-Id');
        if ($value !== null && ! Str::isUuid($value)) {
            return response()->json(['error_code' => 'validation', 'message' => 'X-Correlation-Id must be a UUID.', 'details' => ['header' => 'X-Correlation-Id']], 400);
        }
        $correlationId = $value ?? (string) Str::uuid();
        $request->attributes->set('correlation_id', $correlationId);
        $response = $next($request);
        $response->headers->set('X-Correlation-Id', $correlationId);

        return $response;
    }
}
