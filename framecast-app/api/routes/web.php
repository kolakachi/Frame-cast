<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'data' => [
            'message' => 'Framecast API',
        ],
        'meta' => [],
    ]);
});
