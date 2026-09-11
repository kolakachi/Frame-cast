<?php

namespace App\Http\Middleware;

use App\Support\InternalAccess;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards features that are built but not released — internal dogfooding only.
 *
 * Answers 404 rather than 403 on purpose: a customer who stumbles onto the
 * route should find nothing there, not a locked door advertising a feature
 * they cannot buy yet.
 */
class RequireInternal
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! InternalAccess::allows($request->user())) {
            return response()->json([
                'error' => ['code' => 'not_found', 'message' => 'Not found.'],
            ], 404);
        }

        return $next($request);
    }
}
