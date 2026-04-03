<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\System\HealthCheckController;
use App\Http\Controllers\Api\V1\System\VerificationController;
use App\Http\Controllers\Api\V1\Workspace\WorkspaceController;
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

    Route::middleware('auth.jwt')->group(function (): void {
        Route::get('/me', [VerificationController::class, 'me']);
        Route::post('/verification/storage-smoke', [VerificationController::class, 'storageSmoke']);

        Route::prefix('/workspaces')->group(function (): void {
            Route::get('/', [WorkspaceController::class, 'index']);
            Route::post('/', [WorkspaceController::class, 'store']);
            Route::get('/{workspaceId}', [WorkspaceController::class, 'show'])->whereNumber('workspaceId');
            Route::patch('/{workspaceId}', [WorkspaceController::class, 'update'])->whereNumber('workspaceId');
            Route::delete('/{workspaceId}', [WorkspaceController::class, 'destroy'])->whereNumber('workspaceId');
        });
    });
});
