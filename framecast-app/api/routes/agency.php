<?php

use App\Http\Controllers\Api\V1\Workspace\ClientHubController as Hub;
use Illuminate\Support\Facades\Route;

Route::get('/delivery/{token}', [Hub::class, 'delivery'])->middleware('throttle:60,1');
Route::match(['GET', 'POST'], '/approve/{token}/comments', [Hub::class, 'discussion'])->middleware('throttle:30,1');
Route::middleware('auth.jwt')->group(function () {
    Route::get('/agency-overview', [Hub::class, 'overview']);
    Route::post('/client-work/attachments', [Hub::class, 'attachment'])->middleware('throttle:20,1');
    Route::get('/workspace-access', [Hub::class, 'workspaces']);
    Route::post('/workspace-access/switch/{id}', [Hub::class, 'switch'])->whereNumber('id');
    Route::get('/client-work', [Hub::class, 'show']);
    Route::post('/client-work/draft-text', [Hub::class, 'draftText'])->middleware('throttle:10,1');
    Route::put('/client-work/profile', [Hub::class, 'profile']);
    Route::post('/client-work/requests', [Hub::class, 'requestVideo']);
    Route::prefix('/workspaces/clients/{id}')->whereNumber('id')->group(function () {
        Route::post('/attachments', [Hub::class, 'attachment'])->middleware('throttle:20,1');
        Route::post('/offboard', [Hub::class, 'offboard']);
        Route::get('/hub', [Hub::class, 'show']);
        Route::post('/draft-text', [Hub::class, 'draftText'])->middleware('throttle:10,1');
        Route::put('/profile', [Hub::class, 'profile']);
        Route::post('/requests', [Hub::class, 'requestVideo']);
        Route::post('/requests/{requestId}/start', [Hub::class, 'startRequest'])->whereNumber('requestId');
        Route::patch('/requests/{requestId}', [Hub::class, 'updateRequest'])->whereNumber('requestId');
        Route::post('/deliveries', [Hub::class, 'createDelivery']);
        Route::delete('/deliveries/{deliveryId}', [Hub::class, 'revokeDelivery'])->whereNumber('deliveryId');
    });
});
