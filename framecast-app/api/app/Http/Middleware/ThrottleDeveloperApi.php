<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Per-minute request limits for the developer namespace.
 *
 * Runs after auth.jwt, so the caller is known. Reads and writes have
 * separate buckets — polling status must never be starved by a create
 * limit, and a create limit must not be loosened to make polling work.
 * Every call counts against the caller (key, or user for a session) and
 * against the workspace, whichever is hit first refuses.
 */
class ThrottleDeveloperApi
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $bucket = in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true) ? 'reads' : 'writes';
        $keyId = $request->attributes->get('api_key_id');
        $caller = $keyId ? "key:{$keyId}" : "user:{$user->getKey()}";
        $limits = (array) config('developer.limits');

        $checks = [
            ["developer:{$bucket}:{$caller}", (int) $limits["{$bucket}_per_minute"]],
            ["developer:{$bucket}:workspace:{$user->workspace_id}", (int) $limits["workspace_{$bucket}_per_minute"]],
        ];

        foreach ($checks as [$key, $max]) {
            if ($max > 0 && RateLimiter::tooManyAttempts($key, $max)) {
                $retryAfter = max(1, RateLimiter::availableIn($key));

                return response()->json(['error' => [
                    'code' => 'rate_limited',
                    'message' => "Too many {$bucket} in the last minute. Retry after {$retryAfter} seconds.",
                    'context' => ['bucket' => $bucket, 'retry_after_seconds' => $retryAfter],
                ]], 429)->withHeaders([
                    'Retry-After' => $retryAfter,
                    'X-RateLimit-Limit' => $max,
                    'X-RateLimit-Remaining' => 0,
                ]);
            }
        }

        foreach ($checks as [$key]) {
            RateLimiter::hit($key, 60);
        }

        [$callerKey, $callerMax] = $checks[0];
        $response = $next($request);
        if ($callerMax > 0) {
            $response->headers->set('X-RateLimit-Limit', (string) $callerMax);
            $response->headers->set('X-RateLimit-Remaining', (string) max(0, RateLimiter::remaining($callerKey, $callerMax)));
        }

        return $response;
    }
}
