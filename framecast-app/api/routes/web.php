<?php

use App\Http\Controllers\Api\V1\Asset\AssetController;
use App\Http\Controllers\Api\V1\Sfx\SfxController;
use Illuminate\Support\Facades\Route;

// Server-rendered public share page — real OG/VideoObject markup for
// scrapers and search, hover-to-play for humans. nginx routes /sample/
// here instead of the SPA.
Route::get('/sample/{token}', [\App\Http\Controllers\Web\SharePageController::class, 'show']);

Route::get('/', function () {
    return response()->json([
        'data' => [
            'message' => 'Framecast API',
        ],
        'meta' => [],
    ]);
});

Route::get('/media/assets/{assetId}', [AssetController::class, 'content'])
    ->whereNumber('assetId')
    ->middleware('signed')
    ->name('media.assets.content');

Route::get('/media/sfx/{soundId}', [SfxController::class, 'stream'])
    ->whereNumber('soundId')
    ->middleware('signed')
    ->name('media.sfx.stream');
