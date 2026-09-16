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

        if ($user->isClientSeat() && ($deny = $this->denyClientSeat($request, $user, $active))) {
            return $deny;
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
     * What a client seat may do, stated as what it may NOT.
     *
     * An agency hands out three seats on a client workspace: viewer reads and
     * approves, editor also makes and changes videos, admin also runs the
     * workspace. Every one of them spends the agency's credits rather than
     * their own, so the boundary that never moves is the agency itself — no
     * seat sees the roster, the switcher, billing or admin, whatever its
     * level.
     *
     * Inside that boundary the model inverts by seat. A viewer is fail-closed:
     * safe methods, plus a named few. An editor is the opposite — everything
     * except the agency surfaces — because enumerating every endpoint that
     * makes a video would be a list nobody could keep correct, and the first
     * one forgotten would be a feature the customer paid for and cannot use.
     *
     * @return JsonResponse|null  a refusal, or null to allow
     */
    private function denyClientSeat(Request $request, User $user, int $active): ?JsonResponse
    {
        // Pinned to the workspace they were invited to. A client is never an
        // agency, so the switching path that exists for agencies is not merely
        // unnecessary here — it is the one thing that would let a client read
        // a different client's work.
        if ($active !== (int) $user->workspace_id) {
            return $this->unauthorized('User session is no longer valid.');
        }

        // Agency-only surfaces, refused at every seat and whatever the method.
        // `homeWorkspace()` resolves a child to its parent, so without this a
        // client listing clients would be handed the agency's whole roster.
        foreach (['api/v1/workspaces/clients*', 'api/v1/workspaces/switch/*', 'api/v1/admin/*', 'api/v1/billing/*'] as $pattern) {
            if ($request->is($pattern)) {
                return $this->forbiddenForClient($user);
            }
        }

        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return null;
        }

        // A person's own account is always their own, whatever they may do in
        // the workspace. PATCH /me carries name, timezone and preferences and
        // touches nothing the agency owns — and it is what "skip" on the
        // onboarding screen calls, so refusing it trapped every invited client
        // on that page behind a 403 with no way forward.
        foreach (['api/v1/me', 'api/v1/auth/logout', 'api/v1/auth/refresh', 'api/v1/feedback'] as $pattern) {
            if ($request->is($pattern)) {
                return null;
            }
        }

        // Editor and above: anything that is not the agency's own business.
        if ($user->clientSeatLevel() >= User::CLIENT_SEATS[User::ROLE_CLIENT_EDITOR]) {
            return null;
        }

        // Viewer: deciding on a video put in front of them, and nothing else.
        if ($request->is('api/v1/approvals/*/decide')) {
            return null;
        }

        return $this->forbiddenForClient($user);
    }

    private function forbiddenForClient(User $user): JsonResponse
    {
        // Two different refusals wearing one status code: a viewer is being
        // told their seat is read-only, an editor that the thing they reached
        // for belongs to the agency and not to them.
        $readOnly = $user->clientSeatLevel() < User::CLIENT_SEATS[User::ROLE_CLIENT_EDITOR];

        return response()->json([
            'error' => [
                'code' => $readOnly ? 'client_seat_read_only' : 'client_seat_forbidden',
                'message' => $readOnly
                    ? 'Your access to this workspace is view-and-approve only. Ask the agency that invited you to make this change.'
                    : 'That belongs to the agency that invited you, not to this workspace.',
            ],
        ], 403);
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
