<?php

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Http\Controllers\Controller;
use App\Models\AuthSession;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\JwtService;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Client workspaces belonging to an agency.
 *
 * Each client gets a real workspace — its own projects, characters and brand —
 * and none of them has a balance. Credits belong to the agency, so every child
 * resolves to that one pool (see Workspace::creditRoot).
 *
 * Switching is a new access token naming the client workspace. The middleware
 * accepts it only when that workspace's parent is the caller's own, which is
 * the entire tenant boundary for this feature and the reason switching is an
 * endpoint rather than a query parameter.
 */
class ClientWorkspaceController extends Controller
{
    public function __construct(private readonly JwtService $jwt)
    {
    }

    /** The agency and everything under it, with the shared balance stated once. */
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $home = $this->homeWorkspace($user);
        if (! $home) {
            return $this->error('workspace_not_found', 'Workspace not found.', 404);
        }

        $children = Workspace::query()->where('parent_workspace_id', $home->getKey())
            ->orderBy('client_label')->orderBy('id')->get();

        return response()->json(['data' => [
            'can_own_clients' => $home->canOwnClients(),
            'max_clients' => (int) config('workspaces.max_clients', 50),
            // One balance, named once. A per-client figure would be a fiction.
            'shared_credits' => app(CreditService::class)->balance((int) $home->getKey()),
            'agency' => $this->shape($home, true),
            'clients' => $children->map(fn (Workspace $w) => $this->shape($w, false))->all(),
            'active_workspace_id' => (int) $user->workspace_id,
        ], 'meta' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $home = $this->homeWorkspace($user);

        if (! $home || ! $home->canOwnClients()) {
            return $this->error('not_available',
                'Client workspaces are part of the Agency plan.', 403);
        }

        $v = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'client_label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);

        $count = Workspace::query()->where('parent_workspace_id', $home->getKey())->count();
        if ($count >= (int) config('workspaces.max_clients', 50)) {
            return $this->error('limit_reached',
                'You have reached the maximum number of client workspaces.', 422);
        }

        // No credits of its own — every lookup resolves to the agency. Setting a
        // balance here would create a second, wrong answer to "how many left?".
        $client = Workspace::query()->create(['name' => $v['name'], 'status' => 'active']);
        $client->forceFill([
            'parent_workspace_id' => $home->getKey(),
            'client_label' => $v['client_label'] ?? $v['name'],
            'owner_user_id' => $user->getKey(),
            'plan_tier' => $home->plan_tier,
            'plan_source' => $home->plan_source,
            'plan_status' => 'active',
            'credits_monthly' => 0,
            'credits_topup' => 0,
        ])->save();

        return response()->json(['data' => ['client' => $this->shape($client->fresh(), false)], 'meta' => []], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $client = $this->ownedClient($request, $id);
        if (! $client) {
            return $this->error('not_found', 'No such client workspace.', 404);
        }

        $v = $request->validate([
            'name' => ['sometimes', 'string', 'max:120'],
            'client_label' => ['sometimes', 'nullable', 'string', 'max:120'],
        ]);
        $client->forceFill($v)->save();

        return response()->json(['data' => ['client' => $this->shape($client->fresh(), false)], 'meta' => []]);
    }

    /**
     * A token for one of the caller's client workspaces.
     *
     * Reuses the caller's current session so switching does not multiply
     * sessions, and signing out still ends all of them at once.
     */
    public function switch(Request $request, int $id): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $home = $this->homeWorkspace($user);
        if (! $home) {
            return $this->error('workspace_not_found', 'Workspace not found.', 404);
        }

        $target = (int) $id === (int) $home->getKey()
            ? $home
            : Workspace::query()->whereKey($id)->where('parent_workspace_id', $home->getKey())->first();

        if (! $target) {
            return $this->error('not_found', 'No such client workspace.', 404);
        }

        $session = AuthSession::query()
            ->where('user_id', $user->getKey())
            ->whereNull('revoked_at')
            ->latest('id')->first();

        if (! $session) {
            return $this->error('session_expired', 'Sign in again to switch workspace.', 401);
        }

        return response()->json(['data' => [
            'access_token' => $this->jwt->issue($user, $target, $session),
            'workspace' => $this->shape($target, (int) $target->getKey() === (int) $home->getKey()),
        ], 'meta' => []]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        $client = $this->ownedClient($request, $id);
        if (! $client) {
            return $this->error('not_found', 'No such client workspace.', 404);
        }

        // Archived, not deleted: the work inside belongs to the agency's client
        // and a mis-click must not be the end of it. Nothing addresses an
        // archived workspace, so it disappears from the switcher either way.
        $client->forceFill(['status' => 'archived'])->save();

        return response()->json(['data' => ['archived' => (int) $client->getKey()], 'meta' => []]);
    }

    // ── helpers ──────────────────────────────────────────────────────

    /**
     * The user's own workspace, never the one they are currently acting in.
     *
     * The middleware points workspace_id at the active workspace, so asking the
     * user would give the client while an agency is switched into one — and an
     * agency could then create clients under its own client.
     */
    private function homeWorkspace(User $user): ?Workspace
    {
        $active = Workspace::find($user->workspace_id);
        if (! $active) {
            return null;
        }

        return $active->parent_workspace_id ? $active->parent : $active;
    }

    private function ownedClient(Request $request, int $id): ?Workspace
    {
        $home = $this->homeWorkspace($request->user());

        return $home
            ? Workspace::query()->whereKey($id)->where('parent_workspace_id', $home->getKey())->first()
            : null;
    }

    /** @return array<string, mixed> */
    private function shape(Workspace $w, bool $isAgency): array
    {
        return [
            'id' => (int) $w->getKey(),
            'name' => $w->name,
            'client_label' => $w->client_label,
            'is_agency' => $isAgency,
            'status' => $w->status,
            'projects' => DB::table('projects')->where('workspace_id', $w->getKey())->count(),
            'created_at' => $w->created_at?->toDateString(),
        ];
    }

}
