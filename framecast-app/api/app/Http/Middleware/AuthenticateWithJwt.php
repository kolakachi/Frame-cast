<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Services\Auth\JwtService;
use App\Services\WorkspaceUsageService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateWithJwt
{
    public function __construct(
        private readonly JwtService $jwtService,
    ) {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $bearerToken = $request->bearerToken();

        if (! $bearerToken) {
            return $this->unauthorized('Missing bearer token.');
        }

        try {
            $claims = $this->jwtService->parse($bearerToken);
        } catch (\Throwable) {
            return $this->unauthorized('Invalid access token.');
        }

        $user = User::query()
            ->with('workspace')
            ->whereKey($claims['user_id'])
            ->where('workspace_id', $claims['workspace_id'])
            ->first();

        if (! $user) {
            return $this->unauthorized('User session is no longer valid.');
        }

        if (
            ! WorkspaceUsageService::isAdmin($user)
            && $user->workspace
            && $user->workspace->status !== 'active'
        ) {
            return response()->json([
                'error' => [
                    'code' => 'workspace_suspended',
                    'message' => 'This workspace has been suspended. Please contact support.',
                ],
            ], 403);
        }

        $request->setUserResolver(fn (): User => $user);

        // Bump last_seen_at, throttled to once per 5 minutes per user.
        // Powers the admin "Last active" column. Wrapped in rescue() so a
        // cache or DB hiccup never breaks an authenticated request.
        \rescue(function () use ($user): void {
            $key = "user:{$user->getKey()}:last_seen_bumped";
            if (Cache::add($key, 1, now()->addMinutes(5))) {
                $user->forceFill(['last_seen_at' => now()])->saveQuietly();
            }
        }, null, false);

        return $next($request);
    }

    private function unauthorized(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'unauthorized',
                'message' => $message,
            ],
        ], 401);
    }
}
