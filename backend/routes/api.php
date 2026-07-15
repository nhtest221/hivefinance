<?php

use App\Http\Controllers\Auth\AuthController;
use App\Http\Controllers\Auth\EntitySessionController;
use App\Http\Controllers\Auth\PasswordResetController;
use App\Http\Controllers\Auth\RoleController;
use App\Http\Controllers\HealthCheckController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthCheckController::class)->name('health');

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->name('auth.login');
    Route::post('/mfa', [AuthController::class, 'mfa'])->name('auth.mfa');
    Route::post('/password/forgot', [PasswordResetController::class, 'forgot'])->name('auth.password.forgot');
    Route::post('/password/reset', [PasswordResetController::class, 'reset'])->name('auth.password.reset');

    Route::middleware('auth:sanctum')->group(function (): void {
        Route::get('/session', [AuthController::class, 'session'])->name('auth.session');
        Route::post('/logout', [AuthController::class, 'logout'])->name('auth.logout');
    });
});

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/entities', [EntitySessionController::class, 'index'])->name('entities.index');
    Route::post('/entities/switch', [EntitySessionController::class, 'switch'])->name('entities.switch');
    Route::get('/roles', RoleController::class)->name('roles.index');
});
