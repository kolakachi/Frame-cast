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

        // An API key authenticates the same routes as a browser session, minus
        // the ones that could change what the account is or what it costs.
        if (str_starts_with($bearerToken, 'wyv_live_')) {
            return $this->handleApiKey($request, $next, $bearerToken);
        }

        try {
            $claims = $this->jwtService->parse($bearerToken);
        } catch (\Throwable) {
            return $this->unauthorized('Invalid access token.');
        }

        $session = \App\Models\AuthSession::whereKey($claims['session_id'])->where('user_id', $claims['user_id'])->first();
        if (! $session || $session->revoked_at || ($session->expires_at && $session->expires_at->isPast())) {
            return $this->unauthorized('User session is no longer valid.');
        }
        $request->attributes->set('auth_session_id', $claims['session_id']);

        $user = User::query()->with('workspace')->whereKey($claims['user_id'])->first();

        if (! $user) {
            return $this->unauthorized('User session is no longer valid.');
        }

        $active = (int) ($claims['workspace_id'] ?? 0);
        $target = Workspace::find($active);
        $access = app(\App\Services\Agency\WorkspaceAccess::class);
        if (! $target || ! $access->role($user, $target)) {
            return $this->unauthorized('Workspace access is no longer valid.');
        }

        // A revoked membership takes effect on the next request, including old JWTs.
        $role = $access->role($user, $target);
        $user->setRawAttributes(array_merge($user->getAttributes(), ['workspace_id' => $active, 'role' => $role]), true);
        $user->setRelation('workspace', $target);

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
            && ($user->workspace->status !== 'active' || ($user->workspace->parent_workspace_id && $user->workspace->parent?->status !== 'active'))
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

        \Illuminate\Support\Facades\DB::table('workspace_memberships')->where('workspace_id', $active)->where('user_id', $user->id)->whereNull('accepted_at')->whereNull('revoked_at')->update(['accepted_at' => now()]);

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

        if ($request->is('api/v1/workspace-access/switch/*') || ($request->isMethod('POST') && ($request->is('api/v1/client-work/requests') || $request->is('api/v1/client-work/attachments') || $request->is('api/v1/client-work/draft-text')))) {
            return null;
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

        // Workspace lifecycle belongs to the agency; editors cannot change settings.
        if ($request->is('api/v1/workspaces/*') && ($request->isMethod('DELETE') || $user->role !== User::ROLE_CLIENT_ADMIN)) {
            return $this->forbiddenForClient($user);
        }
        // Editor and above: content operations.
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

    /**
     * Paths an API key may never touch, whatever the plan.
     *
     * A key is a long-lived credential that often ends up pasted into a
     * third-party tool, so it must not be able to change the billing plan,
     * mint more credentials, act as an admin, or move the account's identity.
     * Everything else — projects, scenes, generation, exports, assets — is
     * exactly what the key exists for.
     */
    private const FORBIDDEN = ['api/v1/billing', 'api/v1/admin', 'api/v1/auth', 'api/v1/api-keys', 'api/v1/workspaces'];

    private function handleApiKey(Request $request, Closure $next, string $token): Response
    {
        $key = \App\Models\ApiKey::resolve($token);

        if (! $key) {
            return $this->unauthorized('Invalid or revoked API key.');
        }

        foreach (self::FORBIDDEN as $prefix) {
            if ($request->is($prefix, $prefix.'/*')) {
                return response()->json(['error' => [
                    'code'    => 'api_key_forbidden_path',
                    'message' => 'API keys cannot access billing, admin, auth or key management. Use the dashboard.',
                ]], 403);
            }
        }

        $workspace = Workspace::find($key->workspace_id);
        if (! $workspace || $workspace->status !== 'active') {
            return $this->unauthorized('This workspace is not active.');
        }

        // The plan can change after a key is issued; check on every request
        // rather than trusting what was true at creation.
        if (! app(\App\Services\CreditService::class)->limitFor((int) $workspace->getKey(), 'api_access')) {
            return response()->json(['error' => [
                'code'    => 'api_access_not_on_plan',
                'message' => 'API access is available on Creator and Agency plans.',
            ]], 403);
        }

        $user = User::query()->with('workspace')->whereKey($key->created_by_user_id)->first();
        if (! $user) {
            return $this->unauthorized('The user this key belongs to no longer exists.');
        }

        $user->setRawAttributes(array_merge($user->getAttributes(), [
            'workspace_id' => $workspace->getKey(),
        ]), true);
        $user->setRelation('workspace', $workspace);

        $request->setUserResolver(fn () => $user);
        $request->attributes->set('api_key_id', $key->getKey());

        // Touch at most once a minute — this is for "is it still in use?",
        // not an audit log, and a write on every call would be wasteful.
        if (! $key->last_used_at || $key->last_used_at->diffInSeconds(now()) > 60) {
            $key->forceFill(['last_used_at' => now()])->saveQuietly();
        }

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
