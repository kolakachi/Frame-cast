<?php

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Http\Controllers\Controller;
use App\Models\{MagicLinkToken, User, Workspace};
use App\Services\Agency\{Allowance, WorkspaceAccess};
use Illuminate\Http\{JsonResponse, Request};
use Illuminate\Support\Facades\{DB, Mail};
use Illuminate\Support\Str;

/**
 * An agency's team (phase 3, 2026-10-09; owner: collaborators are agency team members with a monthly allowance).
 * The agency owner invites collaborators, gives each a monthly credit allowance and the clients they may work in,
 * and pauses or removes them. Only an agency (a tier that can own clients) has a team.
 */
class TeamController extends Controller
{
    public const MAX = 25;

    public function __construct(private WorkspaceAccess $access) {}

    public function index(Request $r): JsonResponse
    {
        $agency = $this->agency($r);
        $people = User::query()->where('workspace_id', $agency->id)->where('role', User::ROLE_COLLABORATOR)->whereIn('status', ['active', 'paused'])->orderBy('name')->get();

        return response()->json(['data' => ['collaborators' => $people->map(fn ($u) => $this->shape($u, $agency))->values(), 'max' => self::MAX,
            'clients' => Workspace::where('parent_workspace_id', $agency->id)->where('status', '!=', 'archived')->orderBy('name')->get(['id', 'name', 'client_label'])
                ->map(fn ($w) => ['id' => $w->id, 'name' => $w->client_label ?: $w->name])->values()]]);
    }

    public function store(Request $r): JsonResponse
    {
        $agency = $this->agency($r);
        $v = $r->validate(['email' => 'required|email:rfc|max:255', 'name' => 'sometimes|nullable|string|max:160',
            'allowance' => 'present|nullable|integer|min:0|max:10000000', 'client_ids' => 'sometimes|array|max:50', 'client_ids.*' => 'integer']);
        $email = mb_strtolower(trim($v['email']));
        abort_if(User::query()->where('workspace_id', $agency->id)->where('role', User::ROLE_COLLABORATOR)->whereIn('status', ['active', 'paused'])->count() >= self::MAX, 422, 'Your team is full ('.self::MAX.' collaborators).');
        // A collaborator signs in to the agency as home, so they need an address not already used elsewhere.
        abort_if(User::query()->where('email', $email)->exists(), 422, 'That email already has a WyvStudio account. Invite them with another address for now.');
        $user = User::query()->create(['workspace_id' => $agency->id, 'email' => $email, 'timezone' => 'UTC', 'status' => 'active', 'role' => User::ROLE_COLLABORATOR,
            'name' => $v['name'] ?? Str::of($email)->before('@')->headline()->value(), 'monthly_credit_allowance' => $v['allowance']]);
        DB::table('workspace_memberships')->insert(['workspace_id' => $agency->id, 'user_id' => $user->id, 'role' => User::ROLE_COLLABORATOR,
            'delivery_status' => 'pending', 'invited_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->syncClients($agency, $user, $v['client_ids'] ?? []);
        $sent = $this->sendInvite($agency, $user);

        return response()->json(['data' => ['collaborator' => $this->shape($user->fresh(), $agency), 'invite_sent' => $sent]], 201);
    }

    public function update(Request $r, int $userId): JsonResponse
    {
        $agency = $this->agency($r);
        $user = $this->member($agency, $userId);
        $v = $r->validate(['allowance' => 'sometimes|nullable|integer|min:0|max:10000000', 'client_ids' => 'sometimes|array|max:50', 'client_ids.*' => 'integer',
            'status' => 'sometimes|in:active,paused', 'name' => 'sometimes|string|max:160']);
        if (array_key_exists('allowance', $v)) $user->monthly_credit_allowance = $v['allowance'];
        if (isset($v['status'])) $user->status = $v['status'];
        if (isset($v['name'])) $user->name = $v['name'];
        $user->save();
        if (array_key_exists('client_ids', $v)) $this->syncClients($agency, $user, $v['client_ids']);

        return response()->json(['data' => ['collaborator' => $this->shape($user->fresh(), $agency)]]);
    }

    public function resend(Request $r, int $userId): JsonResponse
    {
        $agency = $this->agency($r);

        return response()->json(['data' => ['invite_sent' => $this->sendInvite($agency, $this->member($agency, $userId))]]);
    }

    /** Removed, not deleted: their videos and spend stay on the agency's record. */
    public function destroy(Request $r, int $userId): JsonResponse
    {
        $agency = $this->agency($r);
        $user = $this->member($agency, $userId);
        $user->forceFill(['status' => 'removed'])->save();
        DB::table('workspace_memberships')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        DB::table('auth_sessions')->where('user_id', $user->id)->whereNull('revoked_at')->update(['revoked_at' => now()]);

        return response()->json(['data' => ['removed' => true]]);
    }

    private function agency(Request $r): Workspace
    {
        $agency = $this->access->agency($r->user());
        abort_unless($agency && $agency->canOwnClients(), 403, 'A team is part of the Agency plan.');

        return $agency;
    }

    private function member(Workspace $agency, int $userId): User
    {
        return User::query()->where('workspace_id', $agency->id)->where('role', User::ROLE_COLLABORATOR)->where('status', '!=', 'removed')->findOrFail($userId);
    }

    /** The clients this collaborator may work in: exactly the ones given, each one of this agency's. */
    private function syncClients(Workspace $agency, User $user, array $ids): void
    {
        $ids = Workspace::where('parent_workspace_id', $agency->id)->whereIn('id', $ids)->pluck('id')->all();
        $children = Workspace::where('parent_workspace_id', $agency->id)->pluck('id')->all();
        DB::table('workspace_memberships')->where('user_id', $user->id)->whereIn('workspace_id', array_diff($children, $ids))->whereNull('revoked_at')->update(['revoked_at' => now(), 'updated_at' => now()]);
        foreach ($ids as $id) {
            DB::table('workspace_memberships')->updateOrInsert(['workspace_id' => $id, 'user_id' => $user->id],
                ['role' => User::ROLE_COLLABORATOR, 'revoked_at' => null, 'delivery_status' => 'sent', 'invited_at' => now(), 'updated_at' => now(), 'created_at' => now()]);
        }
    }

    private function sendInvite(Workspace $agency, User $user): bool
    {
        $plain = Str::random(96);
        MagicLinkToken::query()->where('user_id', $user->id)->where('expires_at', '>', now())->update(['expires_at' => now()]);
        MagicLinkToken::query()->create(['user_id' => $user->id, 'email' => $user->email, 'token_hash' => hash('sha256', $plain), 'expires_at' => now()->addDays(7), 'created_at' => now()]);
        $link = sprintf('%s/auth/magic?token=%s', rtrim((string) config('app.frontend_url'), '/'), $plain);
        try {
            Mail::to($user->email)->send(new \App\Mail\Workspace\CollaboratorInvite($user, (string) $agency->name, $user->monthly_credit_allowance, $link));
            DB::table('workspace_memberships')->where('workspace_id', $agency->id)->where('user_id', $user->id)->update(['delivery_status' => 'sent', 'updated_at' => now()]);

            return true;
        } catch (\Throwable $e) {
            report($e);
            DB::table('workspace_memberships')->where('workspace_id', $agency->id)->where('user_id', $user->id)->update(['delivery_status' => 'failed', 'updated_at' => now()]);

            return false;
        }
    }

    private function shape(User $u, Workspace $agency): array
    {
        $m = DB::table('workspace_memberships')->where('workspace_id', $agency->id)->where('user_id', $u->id)->first();

        return ['id' => $u->id, 'name' => $u->name, 'email' => $u->email, 'status' => $u->status,
            'allowance' => $u->monthly_credit_allowance !== null ? (int) $u->monthly_credit_allowance : null,
            'spent_this_month' => Allowance::spentThisMonth($u->id), 'reserved' => Allowance::reserved($u->id),
            'client_ids' => DB::table('workspace_memberships')->join('workspaces', 'workspaces.id', '=', 'workspace_memberships.workspace_id')
                ->where('workspaces.parent_workspace_id', $agency->id)->where('workspace_memberships.user_id', $u->id)->whereNull('workspace_memberships.revoked_at')->pluck('workspaces.id')->all(),
            'invite' => $m?->accepted_at ? 'accepted' : ($m?->delivery_status ?? 'pending'), 'last_seen_at' => $u->last_seen_at?->toIso8601String()];
    }
}
