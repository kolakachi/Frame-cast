<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\System\HealthCheckController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthCheckController::class);

    Route::prefix('/auth')->group(function (): void {
        Route::post('/login', [AuthController::class, 'login']);
        Route::post('/magic-link', [AuthController::class, 'magicLink']);
        Route::get('/magic-link/verify', [AuthController::class, 'verifyMagicLink']);
        Route::post('/refresh', [AuthController::class, 'refresh']);
        Route::post('/logout', [AuthController::class, 'logout']);
        Route::post('/logout-all', [AuthController::class, 'logoutAll'])->middleware('auth.jwt');
    });

    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])->middleware('auth.jwt');
});
