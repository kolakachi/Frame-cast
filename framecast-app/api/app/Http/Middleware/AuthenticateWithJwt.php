<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
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

        $user = User::query()->with('workspace')->whereKey($claims['user_id'])->first();

        if (! $user) {
            return $this->unauthorized('User session is no longer valid.');
        }

        // The token names the workspace being acted in, which is the user's own
        // or — for an agency — one of its client workspaces. This equality used
        // to be the whole tenant boundary, so widening it is the one place a
        // mistake becomes a cross-tenant leak: a client workspace is accepted
        // ONLY when its parent is this user's own workspace.
        $active = (int) ($claims['workspace_id'] ?? 0);
        if ($active !== (int) $user->workspace_id && ! $this->ownsClient($user, $active)) {
            return $this->unauthorized('User session is no longer valid.');
        }

        // Every controller reads $user->workspace_id to scope its queries. Point
        // it at the active workspace so all of them follow the switch without
        // being touched — and sync it as original so a later save() of this
        // model cannot write the borrowed id over the user's real home.
        if ($active !== (int) $user->workspace_id) {
            $user->setRawAttributes(
                array_merge($user->getAttributes(), ['workspace_id' => $active]),
                true,
            );
            $user->setRelation('workspace', Workspace::find($active));
        }

        if (
            ! WorkspaceUsageService::isAdmin($user)
            // Impersonation tokens pass: suspension gates the CUSTOMER, and a
            // suspended account is exactly the one an admin needs to inspect.
            && empty($claims['impersonated'])
            // The feedback box is the ONE thing a suspended (usually refunded)
            // user may still submit — the suspension modal asks "what was
            // missing?", and blocking the answer would be self-defeating.
            // Write-only, rate-limited, creates nothing but a report.
            && ! ($request->is('api/v1/feedback') && $request->isMethod('POST'))
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

    /**
     * Whether this workspace is a client of the user's own.
     *
     * One level only, by design: the parent must be exactly the user's
     * workspace. No recursion, no "descendant of", nothing that could widen
     * quietly if the schema grows a level later.
     */
    private function ownsClient(User $user, int $workspaceId): bool
    {
        if ($workspaceId <= 0 || ! $user->workspace_id) {
            return false;
        }

        return Workspace::query()
            ->whereKey($workspaceId)
            ->where('parent_workspace_id', $user->workspace_id)
            ->exists();
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
