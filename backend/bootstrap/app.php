<?php

use App\Support\Correlation\CorrelationIdMiddleware;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        apiPrefix: 'v1',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->append(CorrelationIdMiddleware::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->render(function (ValidationException $error, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['error_code' => 'validation', 'message' => 'The request is invalid.', 'details' => ['fields' => $error->errors()]], 400);
            }
        });
        $exceptions->render(function (AuthenticationException $error, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['error_code' => 'authentication_required', 'message' => 'Authentication is required.', 'details' => []], 401);
            }
        });
        $exceptions->render(function (Throwable $error, Request $request) {
            if ($error instanceof HttpExceptionInterface) {
                return null;
            }
            if ($request->expectsJson()) {
                return response()->json(['error_code' => 'internal_error', 'message' => 'An unexpected error occurred.', 'details' => []], 500);
            }
        });
    })
    ->create();
