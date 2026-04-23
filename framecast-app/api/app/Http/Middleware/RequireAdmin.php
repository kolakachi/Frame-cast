<?php

namespace App\Http\Middleware;

use App\Services\WorkspaceUsageService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class RequireAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! WorkspaceUsageService::isAdmin($user)) {
            return response()->json([
                'error' => ['code' => 'forbidden', 'message' => 'Admin access required.'],
            ], 403);
        }

        return $next($request);
    }
}
