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

        $this->seedFromAgency($home, $client);

        return response()->json(['data' => ['client' => $this->shape($client->fresh(), false)], 'meta' => []], 201);
    }

    /**
     * Give a new client workspace the agency's look.
     *
     * An agency that has set up its brand once should not have to set it up
     * again for every client, and a brand-new client workspace with nothing in
     * it reads as broken rather than new.
     *
     * Only the presentational fields travel. `logo_asset_id` and
     * `default_voice_profile_id` point into assets and voice profiles that are
     * themselves workspace-scoped, so copying the ids across would leave the
     * client holding references to rows it is not allowed to read — a dangling
     * pointer that looks like a logo until someone tries to render it.
     */
    private function seedFromAgency(Workspace $agency, Workspace $client): void
    {
        rescue(function () use ($agency, $client): void {
            $kit = \App\Models\BrandKit::query()
                ->where('workspace_id', $agency->getKey())
                ->orderByDesc('id')
                ->first();

            if (! $kit) {
                return;
            }

            \App\Models\BrandKit::query()->create([
                'workspace_id'          => $client->getKey(),
                'name'                  => mb_substr((string) ($client->client_label ?: $client->name), 0, 120),
                'primary_color'         => $kit->primary_color,
                'secondary_color'       => $kit->secondary_color,
                'accent_color'          => $kit->accent_color,
                'font_primary'          => $kit->font_primary,
                'font_secondary'        => $kit->font_secondary,
                'default_caption_style' => $kit->default_caption_style,
            ]);
        }, null, false);
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
            // Null clears it. A ceiling of zero would mean "this client may do
            // nothing", which is what archiving is for.
            'monthly_credit_cap' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:10000000'],
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
    /**
     * What each client cost the agency.
     *
     * The pool is the point of this feature and also its blind spot: every
     * client's spend lands on the agency's ledger, so "how many credits are
     * left" is answerable and "who used them" was not. Deductions carry the
     * spending workspace in metadata; this reads it back.
     *
     * Only deductions are grouped. Grants are written with a `grant:` prefix
     * and a negative amount, and they belong to the agency however a client
     * later spends them — folding them in would net a client's usage against
     * a top-up that had nothing to do with them.
     */
    public function usage(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $home = $this->homeWorkspace($user);
        if (! $home) {
            return $this->error('workspace_not_found', 'Workspace not found.', 404);
        }

        $days = (int) $request->query('days', 30);
        $days = max(1, min(365, $days));
        $since = now()->subDays($days);

        // Now a real indexed column rather than a json field, so this is one
        // grouped query that every database can run — the earlier version had
        // to aggregate in PHP because the spender lived inside jsonb and only
        // Postgres could reach it in SQL.
        $rows = [];
        foreach (
            DB::table('credit_ledger')
                ->selectRaw('COALESCE(spent_by_workspace_id, workspace_id) AS ws')
                ->selectRaw('SUM(credits) AS credits')
                ->selectRaw('COUNT(*) AS operations')
                ->selectRaw('COUNT(DISTINCT project_id) AS projects')
                ->selectRaw('MAX(created_at) AS last_at')
                ->where('workspace_id', $home->getKey())
                ->where('operation', 'not like', 'grant:%')
                ->where('created_at', '>=', $since)
                ->groupBy('ws')
                ->get() as $row
        ) {
            $rows[(int) $row->ws] = [
                'credits'    => (int) $row->credits,
                'operations' => (int) $row->operations,
                'projects'   => (int) $row->projects,
                'last_at'    => $row->last_at ? (string) $row->last_at : null,
            ];
        }

        $ids = [(int) $home->getKey()];
        foreach (Workspace::query()->where('parent_workspace_id', $home->getKey())
            ->orderBy('client_label')->orderBy('id')->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        $total = 0;
        $breakdown = [];
        foreach ($ids as $id) {
            $r = $rows[$id] ?? null;
            $credits = (int) ($r['credits'] ?? 0);
            $total += $credits;
            $breakdown[$id] = [
                'workspace_id' => $id,
                'credits'      => $credits,
                'operations'   => (int) ($r['operations'] ?? 0),
                'projects'     => (int) ($r['projects'] ?? 0),
                'last_at'      => $r['last_at'] ?? null,
            ];
        }

        // A client deleted since it spent still shows, or the numbers would not
        // add up to the pool's own total and the report would look wrong.
        foreach ($rows as $id => $r) {
            if (! isset($breakdown[$id])) {
                $credits = (int) $r['credits'];
                $total += $credits;
                $breakdown[$id] = [
                    'workspace_id' => (int) $id,
                    'credits'      => $credits,
                    'operations'   => (int) $r['operations'],
                    'projects'     => (int) $r['projects'],
                    'last_at'      => $r['last_at'],
                    'archived'     => true,
                ];
            }
        }

        foreach ($breakdown as $id => $row) {
            $breakdown[$id]['share_percent'] = $total > 0
                ? round($row['credits'] / $total * 100, 1)
                : 0.0;
        }

        return response()->json(['data' => [
            'days'       => $days,
            'since'      => $since->toIso8601String(),
            'total'      => $total,
            'agency_id'  => (int) $home->getKey(),
            'usage'      => array_values($breakdown),
        ], 'meta' => []]);
    }

    /**
     * Invite a client to watch their own workspace.
     *
     * The invited person becomes a real user with the `client` role, pinned to
     * this one workspace. They can see it and approve what is put in front of
     * them; they cannot spend the agency's credits. That boundary is enforced
     * in AuthenticateWithJwt rather than here, because a permission checked at
     * the point of invitation is a permission that stops being checked.
     */
    public function inviteViewer(Request $request, int $id): JsonResponse
    {
        $client = $this->ownedClient($request, $id);
        if (! $client) {
            return $this->error('not_found', 'Client workspace not found.', 404);
        }

        $v = $request->validate([
            'email' => ['required', 'email:rfc', 'max:255'],
            'name'  => ['sometimes', 'nullable', 'string', 'max:160'],
            // Defaults to the most restrictive seat. An invite that silently
            // granted more than the agency meant to give is the one mistake
            // here that spends their money.
            'role'  => ['sometimes', \Illuminate\Validation\Rule::in(array_keys(User::CLIENT_SEATS))],
        ]);
        $seat = $v['role'] ?? User::ROLE_CLIENT_VIEWER;

        $email = mb_strtolower(trim($v['email']));
        $existing = User::query()->where('email', $email)->first();

        // An address that already has a WyvStudio account of its own must not
        // be moved into someone else's workspace. Silently re-pointing it would
        // take that person's own work away from them.
        if ($existing && ! ($existing->isClientSeat() && (int) $existing->workspace_id === (int) $client->getKey())) {
            return $this->error(
                'email_in_use',
                'That address already has a WyvStudio account. Ask them to use a different one for client access.',
                422,
            );
        }

        $user = $existing ?: User::query()->create([
            'workspace_id' => $client->getKey(),
            'name'         => $v['name'] ?? \Illuminate\Support\Str::of($email)->before('@')->headline()->value(),
            'email'        => $email,
            'timezone'     => 'UTC',
            'role'         => $seat,
            'status'       => 'active',
        ]);

        // Re-inviting somebody who already holds a seat is how an agency
        // changes their level, so the new one has to stick.
        if ($existing && $existing->role !== $seat) {
            $user->forceFill(['role' => $seat])->save();
        }

        $agency = $this->homeWorkspace($request->user());
        $link = $this->issueInviteLink($user);

        rescue(fn () => \Illuminate\Support\Facades\Mail::to($user->email)->send(
            new \App\Mail\Workspace\ClientViewerInvite(
                $user,
                $client,
                (string) ($agency?->name ?: 'Your agency'),
                $link,
            ),
        ), function (\Throwable $e) use ($user) {
            \Illuminate\Support\Facades\Log::error('Client viewer invite mail failed', [
                'user_id' => $user->getKey(),
                'error'   => $e->getMessage(),
            ]);
        });

        return response()->json(['data' => ['viewer' => $this->shapeViewer($user)], 'meta' => []], 201);
    }

    /** Take a client's access away. The user row goes; their workspace does not. */
    public function removeViewer(Request $request, int $id, int $userId): JsonResponse
    {
        $client = $this->ownedClient($request, $id);
        if (! $client) {
            return $this->error('not_found', 'Client workspace not found.', 404);
        }

        $user = User::query()
            ->whereKey($userId)
            ->where('workspace_id', $client->getKey())
            ->whereIn('role', array_keys(User::CLIENT_SEATS))
            ->first();

        if (! $user) {
            return $this->error('not_found', 'That person does not have access to this workspace.', 404);
        }

        // Kill live sessions first: deleting the user alone would leave an
        // already-issued access token working until it expired.
        rescue(fn () => AuthSession::query()->where('user_id', $user->getKey())->delete(), null, false);
        rescue(fn () => \App\Models\MagicLinkToken::query()->where('user_id', $user->getKey())->delete(), null, false);
        $user->delete();

        return response()->json(['data' => ['removed' => true], 'meta' => []]);
    }

    /**
     * A sign-in link for an invited client.
     *
     * Seven days rather than the fifteen minutes a login link gets: this is an
     * invitation that may sit in an inbox over a weekend, and a client who has
     * to ask the agency to resend it will simply not come.
     */
    private function issueInviteLink(User $user): string
    {
        $plain = \Illuminate\Support\Str::random(96);

        \App\Models\MagicLinkToken::query()
            ->where('user_id', $user->getKey())
            ->where('expires_at', '>', now())
            ->update(['expires_at' => now()]);

        \App\Models\MagicLinkToken::query()->create([
            'user_id'    => $user->getKey(),
            'email'      => $user->email,
            'token_hash' => hash('sha256', $plain),
            'expires_at' => now()->addDays(7),
            'created_at' => now(),
        ]);

        return sprintf('%s/auth/magic?token=%s', rtrim((string) config('app.frontend_url'), '/'), $plain);
    }

    /** @return array<string, mixed> */
    private function shapeViewer(User $user): array
    {
        return [
            'id'            => (int) $user->getKey(),
            'name'          => $user->name,
            'email'         => $user->email,
            'role'          => $user->role,
            'last_seen_at'  => $user->last_seen_at?->toIso8601String(),
            'invited_at'    => $user->created_at?->toDateString(),
        ];
    }

    /**
     * Fund a client, or take credits back.
     *
     * Positive adds, negative reclaims. Funding a pooled client is what turns
     * it funded — the agency does not choose a mode and then move money, it
     * moves money and the mode follows, because a funded client with nothing
     * in it is only a client that cannot work.
     */
    public function fund(Request $request, int $id): JsonResponse
    {
        $client = $this->ownedClient($request, $id);
        if (! $client) {
            return $this->error('not_found', 'Client workspace not found.', 404);
        }

        $v = $request->validate([
            'amount' => ['required', 'integer', 'not_in:0', 'min:-10000000', 'max:10000000'],
        ]);

        $agency = $this->homeWorkspace($request->user());
        if (! $agency) {
            return $this->error('workspace_not_found', 'Workspace not found.', 404);
        }

        [$moved, $why] = app(CreditService::class)
            ->transferToClient($agency, $client, (int) $v['amount']);

        if (! $moved) {
            return $this->error($why, match ($why) {
                'agency_short' => 'Your balance does not cover that. Top up, or move a smaller amount.',
                'client_empty' => 'That client has no credits left to take back.',
                'not_your_client' => 'That client workspace is not yours.',
                default => 'Those credits could not be moved.',
            }, 422);
        }

        $client = $client->fresh();

        // First money in makes it funded; emptying it by hand does not make it
        // pooled again, because "spend the agency's balance instead" is a
        // decision the agency should have to state rather than fall into.
        if ((int) $v['amount'] > 0 && ! $client->isFunded()) {
            $client->forceFill(['funding_mode' => Workspace::FUNDING_FUNDED])->save();
        }

        return response()->json(['data' => ['client' => $this->shape($client->fresh(), false)], 'meta' => []]);
    }

    /** Put a client back on the shared pool, returning whatever it still holds. */
    public function unfund(Request $request, int $id): JsonResponse
    {
        $client = $this->ownedClient($request, $id);
        if (! $client) {
            return $this->error('not_found', 'Client workspace not found.', 404);
        }

        $agency = $this->homeWorkspace($request->user());
        $remaining = (int) $client->credits_topup;
        if ($remaining > 0 && $agency) {
            app(CreditService::class)->transferToClient($agency, $client, -$remaining);
        }

        $client->forceFill(['funding_mode' => Workspace::FUNDING_POOLED])->save();

        return response()->json(['data' => ['client' => $this->shape($client->fresh(), false)], 'meta' => []]);
    }

    /** Change what somebody may do, without making them accept a new invite. */
    public function updateViewer(Request $request, int $id, int $userId): JsonResponse
    {
        $client = $this->ownedClient($request, $id);
        if (! $client) {
            return $this->error('not_found', 'Client workspace not found.', 404);
        }

        $v = $request->validate([
            'role' => ['required', \Illuminate\Validation\Rule::in(array_keys(User::CLIENT_SEATS))],
        ]);

        $user = User::query()->whereKey($userId)
            ->where('workspace_id', $client->getKey())
            ->whereIn('role', array_keys(User::CLIENT_SEATS))
            ->first();

        if (! $user) {
            return $this->error('not_found', 'That person does not have access to this workspace.', 404);
        }

        $user->forceFill(['role' => $v['role']])->save();

        return response()->json(['data' => ['viewer' => $this->shapeViewer($user->fresh())], 'meta' => []]);
    }

    /**
     * The people on one client workspace, paged.
     *
     * Separate from the client list because a client with a hundred members
     * cannot be rendered inside a row of another list, and because searching a
     * hundred addresses is not something the browser should be doing.
     */
    public function viewers(Request $request, int $id): JsonResponse
    {
        $client = $this->ownedClient($request, $id);
        if (! $client) {
            return $this->error('not_found', 'Client workspace not found.', 404);
        }

        $q = trim((string) $request->query('q', ''));
        $perPage = min(100, max(10, (int) $request->query('per_page', 25)));

        $paginator = User::query()
            ->where('workspace_id', $client->getKey())
            ->whereIn('role', array_keys(User::CLIENT_SEATS))
            ->when($q !== '', fn ($query) => $query->where(function ($w) use ($q): void {
                $w->where('email', 'like', "%{$q}%")->orWhere('name', 'like', "%{$q}%");
            }))
            ->orderBy('email')
            ->paginate($perPage, ['*'], 'page', max(1, (int) $request->query('page', 1)));

        return response()->json(['data' => [
            'viewers' => collect($paginator->items())->map(fn (User $u) => $this->shapeViewer($u))->all(),
        ], 'meta' => ['pagination' => [
            'current_page' => $paginator->currentPage(),
            'last_page' => $paginator->lastPage(),
            'per_page' => $paginator->perPage(),
            'total' => $paginator->total(),
        ]]]);
    }

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
            // The ceiling and how close this client is to it. Null cap means
            // no ceiling — the agency's balance is the only limit.
            'monthly_credit_cap' => $w->monthly_credit_cap ? (int) $w->monthly_credit_cap : null,
            'spent_this_month' => $isAgency
                ? 0
                : app(CreditService::class)->spentThisMonth((int) $w->getKey(), (int) ($w->parent_workspace_id ?: $w->getKey())),
            // A count, not the list. A client with a hundred members would
            // otherwise put a hundred rows inside one row of this list.
            'members' => $isAgency ? 0 : User::query()
                ->where('workspace_id', $w->getKey())
                ->whereIn('role', array_keys(User::CLIENT_SEATS))
                ->count(),
            'funding_mode' => $isAgency ? null : (string) ($w->funding_mode ?: Workspace::FUNDING_POOLED),
            // Only a funded client has a balance of its own; a pooled one
            // reports null rather than zero, which would read as "spent out".
            'credits' => $w->isFunded() ? (int) $w->creditsBalance() : null,
        ];
    }

}
