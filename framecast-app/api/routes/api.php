<?php

use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Controllers\Api\V1\Niche\NicheController;
use App\Http\Controllers\Api\V1\Asset\AssetController;
use App\Http\Controllers\Api\V1\Asset\CollectionController;
use App\Http\Controllers\Api\V1\Asset\ImageStyleController;
use App\Http\Controllers\Api\V1\BrandKit\BrandKitController;
use App\Http\Controllers\Api\V1\Channel\ChannelController;
use App\Http\Controllers\Api\V1\Series\SeriesController;
use App\Http\Controllers\Api\V1\Localization\LocalizationController;
use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Http\Controllers\Api\V1\Scene\SceneController;
use App\Http\Controllers\Api\V1\System\FontController;
use App\Http\Controllers\Api\V1\System\HealthCheckController;
use App\Http\Controllers\Api\V1\System\NotificationController;
use App\Http\Controllers\Api\V1\System\VerificationController;
use App\Http\Controllers\Api\V1\Variant\VariantController;
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
        Route::patch('/me', [VerificationController::class, 'updateMe']);
        Route::post('/verification/storage-smoke', [VerificationController::class, 'storageSmoke']);
        Route::get('/notifications', [NotificationController::class, 'index']);
        Route::post('/notifications/{notificationId}/read', [NotificationController::class, 'markRead'])->whereNumber('notificationId');
        Route::get('/voice-profiles', [VoiceProfileController::class, 'index']);
        Route::get('/niches', [NicheController::class, 'index']);
        Route::get('/fonts', [FontController::class, 'index']);
        Route::get('/visual-styles', [ImageStyleController::class, 'index']);
        Route::get('/image-generation/styles', [ImageStyleController::class, 'index']);
        Route::prefix('/admin')->middleware(['admin', 'admin.ip'])->group(function (): void {
            Route::get('/overview', [AdminController::class, 'overview']);
            Route::get('/users', [AdminController::class, 'users']);
            Route::get('/users/{userId}', [AdminController::class, 'userDetail'])->whereNumber('userId');
            Route::post('/users/{userId}/impersonate', [AdminController::class, 'impersonate'])->whereNumber('userId');
            Route::get('/workspaces', [AdminController::class, 'workspaces']);
            Route::patch('/workspaces/{workspaceId}/plan', [AdminController::class, 'updateWorkspacePlan'])->whereNumber('workspaceId');
            Route::patch('/workspaces/{workspaceId}/status', [AdminController::class, 'updateWorkspaceStatus'])->whereNumber('workspaceId');
            Route::get('/videos', [AdminController::class, 'videos']);
            Route::get('/jobs', [AdminController::class, 'jobs']);
            Route::get('/spend-chart', [AdminController::class, 'spendChart']);
            Route::get('/audit-log', [AdminController::class, 'auditLog']);
        });
        Route::prefix('/assets')->group(function (): void {
            Route::get('/', [AssetController::class, 'index']);
            Route::post('/', [AssetController::class, 'store']);
            Route::get('/{assetId}', [AssetController::class, 'show'])->whereNumber('assetId');
            Route::patch('/{assetId}', [AssetController::class, 'update'])->whereNumber('assetId');
            Route::delete('/{assetId}', [AssetController::class, 'destroy'])->whereNumber('assetId');
        });
        Route::prefix('/collections')->group(function (): void {
            Route::get('/', [CollectionController::class, 'index']);
            Route::post('/', [CollectionController::class, 'store']);
            Route::patch('/{collectionId}', [CollectionController::class, 'update'])->whereNumber('collectionId');
            Route::delete('/{collectionId}', [CollectionController::class, 'destroy'])->whereNumber('collectionId');
        });
        Route::prefix('/workspaces')->group(function (): void {
            Route::get('/', [WorkspaceController::class, 'index']);
            Route::post('/', [WorkspaceController::class, 'store']);
            Route::get('/{workspaceId}', [WorkspaceController::class, 'show'])->whereNumber('workspaceId');
            Route::patch('/{workspaceId}', [WorkspaceController::class, 'update'])->whereNumber('workspaceId');
            Route::delete('/{workspaceId}', [WorkspaceController::class, 'destroy'])->whereNumber('workspaceId');
        });

        Route::prefix('/series')->group(function (): void {
            Route::get('/', [SeriesController::class, 'index']);
            Route::post('/', [SeriesController::class, 'store']);
            Route::get('/{seriesId}', [SeriesController::class, 'show'])->whereNumber('seriesId');
            Route::patch('/{seriesId}', [SeriesController::class, 'update'])->whereNumber('seriesId');
            Route::delete('/{seriesId}', [SeriesController::class, 'destroy'])->whereNumber('seriesId');
            Route::get('/{seriesId}/episodes', [SeriesController::class, 'episodes'])->whereNumber('seriesId');
            Route::get('/{seriesId}/characters', [SeriesController::class, 'characters'])->whereNumber('seriesId');
            Route::post('/{seriesId}/characters', [SeriesController::class, 'storeCharacter'])->whereNumber('seriesId');
            Route::patch('/{seriesId}/characters/{characterId}', [SeriesController::class, 'updateCharacter'])->whereNumber('seriesId')->whereNumber('characterId');
            Route::delete('/{seriesId}/characters/{characterId}', [SeriesController::class, 'destroyCharacter'])->whereNumber('seriesId')->whereNumber('characterId');
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
            Route::get('/queue', [ProjectController::class, 'queue']);
            Route::post('/', [ProjectController::class, 'store']);
            Route::get('/{projectId}', [ProjectController::class, 'show'])->whereNumber('projectId');
            Route::patch('/{projectId}', [ProjectController::class, 'update'])->whereNumber('projectId');
            Route::get('/{projectId}/exports', [ProjectController::class, 'exports'])->whereNumber('projectId');
            Route::get('/{projectId}/variants', [VariantController::class, 'index'])->whereNumber('projectId');
            Route::post('/{projectId}/variants', [VariantController::class, 'store'])->whereNumber('projectId');
            Route::get('/{projectId}/localizations', [LocalizationController::class, 'index'])->whereNumber('projectId');
            Route::post('/{projectId}/localizations', [LocalizationController::class, 'store'])->whereNumber('projectId');
            Route::post('/{projectId}/export', [ProjectController::class, 'export'])->whereNumber('projectId');
            Route::post('/{projectId}/retry-generation', [ProjectController::class, 'retryGeneration'])->whereNumber('projectId');
            Route::delete('/{projectId}', [ProjectController::class, 'destroy'])->whereNumber('projectId');
        });

        Route::prefix('/variant-sets')->group(function (): void {
            Route::post('/{variantSetId}/export', [VariantController::class, 'export'])->whereNumber('variantSetId');
            Route::post('/{variantSetId}/retry-failed', [VariantController::class, 'retryFailed'])->whereNumber('variantSetId');
        });

        Route::delete('/variants/{variantId}', [VariantController::class, 'destroy'])->whereNumber('variantId');
        Route::post('/localization-links/{localizationLinkId}/retry', [LocalizationController::class, 'retry'])->whereNumber('localizationLinkId');

        Route::prefix('/scenes')->group(function (): void {
            Route::post('/', [SceneController::class, 'store']);
            Route::post('/generate-draft', [SceneController::class, 'generateDraft']);
            Route::patch('/reorder', [SceneController::class, 'reorder']);
            Route::patch('/{sceneId}', [SceneController::class, 'update'])->whereNumber('sceneId');
            Route::get('/{sceneId}/preview', [SceneController::class, 'preview'])->whereNumber('sceneId');
            Route::post('/{sceneId}/regenerate-voice', [SceneController::class, 'regenerateVoice'])->whereNumber('sceneId');
            Route::post('/{sceneId}/swap-visual', [SceneController::class, 'swapVisual'])->whereNumber('sceneId');
            Route::post('/{sceneId}/generate-image', [SceneController::class, 'generateImage'])->whereNumber('sceneId');
            Route::post('/{sceneId}/rewrite', [SceneController::class, 'rewrite'])->whereNumber('sceneId');
            Route::post('/{sceneId}/duplicate', [SceneController::class, 'duplicate'])->whereNumber('sceneId');
            Route::delete('/{sceneId}', [SceneController::class, 'destroy'])->whereNumber('sceneId');
        });
    });
});
