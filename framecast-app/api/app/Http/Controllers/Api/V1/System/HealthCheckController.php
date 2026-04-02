<?php

namespace App\Http\Controllers\Api\V1\System;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

class HealthCheckController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json([
            'data' => [
                'name' => config('app.name'),
                'environment' => app()->environment(),
                'status' => 'ok',
                'timestamp' => now()->toIso8601String(),
            ],
            'meta' => [],
        ]);
    }
}
