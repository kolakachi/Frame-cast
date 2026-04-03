<?php

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class WorkspaceController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->currentWorkspace($user);

        return response()->json([
            'data' => [
                'workspaces' => $workspace ? [$this->serializeWorkspace($workspace)] : [],
            ],
            'meta' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if ($this->currentWorkspace($user)) {
            return $this->error('workspace_exists', 'User already belongs to a workspace.', 422);
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $workspace = Workspace::query()->create([
            'name' => $validated['name'],
            'plan_tier' => 'free',
            'status' => 'active',
            'owner_user_id' => $user->getKey(),
        ]);

        $user->forceFill([
            'workspace_id' => $workspace->getKey(),
        ])->save();

        return response()->json([
            'data' => [
                'workspace' => $this->serializeWorkspace($workspace),
            ],
            'meta' => [],
        ], 201);
    }

    public function show(Request $request, int $workspaceId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspaceForUser($user, $workspaceId);

        if (! $workspace) {
            return $this->error('not_found', 'Workspace not found.', 404);
        }

        return response()->json([
            'data' => [
                'workspace' => $this->serializeWorkspace($workspace),
            ],
            'meta' => [],
        ]);
    }

    public function update(Request $request, int $workspaceId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspaceForUser($user, $workspaceId);

        if (! $workspace) {
            return $this->error('not_found', 'Workspace not found.', 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'status' => ['sometimes', 'required', 'in:active,archived'],
        ]);

        $workspace->fill($validated)->save();

        return response()->json([
            'data' => [
                'workspace' => $this->serializeWorkspace($workspace->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $workspaceId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $this->workspaceForUser($user, $workspaceId);

        if (! $workspace) {
            return $this->error('not_found', 'Workspace not found.', 404);
        }

        $workspace->forceFill([
            'status' => 'archived',
        ])->save();

        return response()->json([
            'data' => [
                'archived' => true,
                'workspace' => $this->serializeWorkspace($workspace->fresh()),
            ],
            'meta' => [],
        ]);
    }

    private function currentWorkspace(User $user): ?Workspace
    {
        if (! $user->workspace_id) {
            return null;
        }

        return Workspace::query()->find($user->workspace_id);
    }

    private function workspaceForUser(User $user, int $workspaceId): ?Workspace
    {
        if ((int) $user->workspace_id !== $workspaceId) {
            return null;
        }

        return Workspace::query()->find($workspaceId);
    }

    /**
     * @return array{id:int,name:string,owner_user_id:int|null,plan_tier:string,status:string,created_at:?string,updated_at:?string}
     */
    private function serializeWorkspace(Workspace $workspace): array
    {
        return [
            'id' => $workspace->getKey(),
            'name' => $workspace->name,
            'owner_user_id' => $workspace->owner_user_id,
            'plan_tier' => $workspace->plan_tier,
            'status' => $workspace->status,
            'created_at' => $workspace->created_at?->toIso8601String(),
            'updated_at' => $workspace->updated_at?->toIso8601String(),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
