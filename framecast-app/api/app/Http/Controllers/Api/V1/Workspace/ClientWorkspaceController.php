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

        // Aggregated in PHP rather than in SQL. The client id lives inside a
        // json column, and every database spells that extraction differently;
        // one grouped query here would be Postgres-only and would quietly
        // return nothing the first time it ran anywhere else. The scan is
        // bounded — one agency's deductions over at most a year — and chunked
        // so a busy pool cannot pull its whole ledger into memory.
        $rows = [];
        DB::table('credit_ledger')
            ->select(['workspace_id', 'credits', 'project_id', 'metadata', 'created_at'])
            ->where('workspace_id', $home->getKey())
            ->where('operation', 'not like', 'grant:%')
            ->where('created_at', '>=', $since)
            ->orderBy('id')
            ->chunk(1000, function ($chunk) use (&$rows, $home): void {
                foreach ($chunk as $row) {
                    $meta = is_string($row->metadata) ? json_decode($row->metadata, true) : (array) $row->metadata;
                    // Absent means the agency spent it on its own account.
                    $ws = (int) ($meta['spent_by_workspace_id'] ?? $row->workspace_id ?: $home->getKey());

                    if (! isset($rows[$ws])) {
                        $rows[$ws] = ['credits' => 0, 'operations' => 0, 'projects' => [], 'last_at' => null];
                    }
                    $rows[$ws]['credits'] += (int) $row->credits;
                    $rows[$ws]['operations']++;
                    if ($row->project_id !== null) {
                        $rows[$ws]['projects'][(int) $row->project_id] = true;
                    }
                    $at = (string) $row->created_at;
                    if ($rows[$ws]['last_at'] === null || $at > $rows[$ws]['last_at']) {
                        $rows[$ws]['last_at'] = $at;
                    }
                }
            });

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
                'projects'     => count($r['projects'] ?? []),
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
                    'projects'     => count($r['projects']),
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
            // Who the agency has let in to watch this one. Empty for the
            // agency's own row — it is not a client of itself.
            'viewers' => $isAgency ? [] : User::query()
                ->where('workspace_id', $w->getKey())
                ->whereIn('role', array_keys(User::CLIENT_SEATS))
                ->orderBy('email')
                ->get()
                ->map(fn (User $u) => $this->shapeViewer($u))
                ->all(),
        ];
    }

}
