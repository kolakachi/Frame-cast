<?php

use App\Http\Controllers\Api\V1\Asset\AssetController;
use Illuminate\Support\Facades\Route;

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
