<?php

use App\Http\Controllers\Api\V1\System\HealthCheckController;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthCheckController::class);
    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate']);
});
