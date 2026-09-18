<?php

namespace App\Services\Agency;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class WorkspaceAccess
{
    /** Always start from persisted identity, never a request's borrowed workspace/role. */
    public function role(User $user, Workspace $workspace): ?string
    {
        $identity = User::find($user->id);
        if (! $identity || $identity->status !== 'active') {
            return null;
        }
        if ((int) $identity->workspace_id === (int) $workspace->id && ! $identity->isClientSeat()) {
            return $identity->role;
        }
        if ($workspace->parent_workspace_id && (int) $workspace->parent_workspace_id === (int) $identity->workspace_id && ! $identity->isClientSeat()) {
            $parent = $workspace->parent;
            if ($parent?->status === 'active' && ($identity->role === 'owner' || (int) $parent->owner_user_id === (int) $identity->id)) {
                return 'owner';
            }
        }

        return DB::table('workspace_memberships')->where('workspace_id', $workspace->id)->where('user_id', $identity->id)->whereNull('revoked_at')->value('role');
    }

    public function activate(User $user, Workspace $workspace): bool
    {
        $role = $this->role($user, $workspace);
        if (! $role || $workspace->status !== 'active' || ($workspace->parent_workspace_id && $workspace->parent?->status !== 'active')) {
            return false;
        }
        $user->setRawAttributes(array_merge($user->getAttributes(), ['workspace_id' => $workspace->id, 'role' => $role]), true);
        $user->setRelation('workspace', $workspace);

        return true;
    }

    public function agency(User $user): ?Workspace
    {
        $identity = User::find($user->id);
        $home = $identity?->workspace;

        return $home && ! $home->parent_workspace_id && ! $identity->isClientSeat()
            && ($identity->role === 'owner' || (int) $home->owner_user_id === (int) $identity->id) ? $home : null;
    }

    public function memberIds(int $workspaceId): array
    {
        return DB::table('workspace_memberships')->where('workspace_id', $workspaceId)->whereNull('revoked_at')->pluck('user_id')->all();
    }
}
