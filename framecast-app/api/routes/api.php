<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\BrandKit\BrandKitController;
use App\Http\Controllers\Api\V1\Channel\ChannelController;
use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Http\Controllers\Api\V1\Scene\SceneController;
use App\Http\Controllers\Api\V1\System\HealthCheckController;
use App\Http\Controllers\Api\V1\System\NotificationController;
use App\Http\Controllers\Api\V1\System\VerificationController;
use App\Http\Controllers\Api\V1\VoiceProfile\VoiceProfileController;
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
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->whereNumber('notificationId');
        Route::get('/voice-profiles', [VoiceProfileController::class, 'index']);
        Route::prefix('/workspaces')->group(function (): void {
            Route::get('/', [WorkspaceController::class, 'index']);
            Route::post('/', [WorkspaceController::class, 'store']);
            Route::get('/{workspaceId}', [WorkspaceController::class, 'show'])->whereNumber('workspaceId');
            Route::patch('/{workspaceId}', [WorkspaceController::class, 'update'])->whereNumber('workspaceId');
            Route::delete('/{workspaceId}', [WorkspaceController::class, 'destroy'])->whereNumber('workspaceId');
        });

        Route::prefix('/channels')->group(function (): void {
            Route::get('/', [ChannelController::class, 'index']);
            Route::post('/', [ChannelController::class, 'store']);
            Route::get('/{channelId}', [ChannelController::class, 'show'])->whereNumber('channelId');
            Route::patch('/{channelId}', [ChannelController::class, 'update'])->whereNumber('channelId');
            Route::delete('/{channelId}', [ChannelController::class, 'destroy'])->whereNumber('channelId');
        });

        Route::prefix('/brand-kits')->group(function (): void {
            Route::get('/', [BrandKitController::class, 'index']);
            Route::post('/', [BrandKitController::class, 'store']);
            Route::get('/{brandKitId}', [BrandKitController::class, 'show'])->whereNumber('brandKitId');
            Route::patch('/{brandKitId}', [BrandKitController::class, 'update'])->whereNumber('brandKitId');
            Route::delete('/{brandKitId}', [BrandKitController::class, 'destroy'])->whereNumber('brandKitId');
        });

        Route::prefix('/projects')->group(function (): void {
            Route::get('/', [ProjectController::class, 'index']);
            Route::post('/', [ProjectController::class, 'store']);
            Route::get('/{projectId}', [ProjectController::class, 'show'])->whereNumber('projectId');
            Route::post('/{projectId}/export', [ProjectController::class, 'export'])->whereNumber('projectId');
            Route::delete('/{projectId}', [ProjectController::class, 'destroy'])->whereNumber('projectId');
        });

        Route::prefix('/scenes')->group(function (): void {
            Route::patch('/reorder', [SceneController::class, 'reorder']);
            Route::patch('/{sceneId}', [SceneController::class, 'update'])->whereNumber('sceneId');
            Route::get('/{sceneId}/preview', [SceneController::class, 'preview'])->whereNumber('sceneId');
            Route::post('/{sceneId}/regenerate-voice', [SceneController::class, 'regenerateVoice'])->whereNumber('sceneId');
            Route::post('/{sceneId}/swap-visual', [SceneController::class, 'swapVisual'])->whereNumber('sceneId');
            Route::post('/{sceneId}/rewrite', [SceneController::class, 'rewrite'])->whereNumber('sceneId');
            Route::post('/{sceneId}/duplicate', [SceneController::class, 'duplicate'])->whereNumber('sceneId');
            Route::delete('/{sceneId}', [SceneController::class, 'destroy'])->whereNumber('sceneId');
        });
    });
});
