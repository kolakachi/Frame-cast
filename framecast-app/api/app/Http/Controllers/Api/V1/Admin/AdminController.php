<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\ApiUsageEvent;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(private readonly WorkspaceUsageService $usageService)
    {
    }

    public function overview(Request $request): JsonResponse
    {
        if (! $this->canUseGodMode($request->user())) {
            return $this->error('forbidden', 'God mode access is required.', 403);
        }

        $monthStart = now()->startOfMonth();

        return response()->json([
            'data' => [
                'summary' => [
                    'users' => User::query()->count(),
                    'workspaces' => Workspace::query()->count(),
                    'projects' => Project::query()->count(),
                    'api_spend_total_usd' => $this->money(ApiUsageEvent::query()->sum('estimated_cost_usd')),
                    'api_spend_month_usd' => $this->money(ApiUsageEvent::query()->where('occurred_at', '>=', $monthStart)->sum('estimated_cost_usd')),
                    'failed_api_calls_month' => ApiUsageEvent::query()
                        ->where('occurred_at', '>=', $monthStart)
                        ->where('status', 'failed')
                        ->count(),
                ],
                'plans' => WorkspaceUsageService::plans(),
                'workspaces' => $this->workspaceRows(),
                'users' => $this->userRows(),
                'recent_usage' => $this->recentUsageRows(),
            ],
            'meta' => [],
        ]);
    }

    public function updateWorkspacePlan(Request $request, int $workspaceId): JsonResponse
    {
        if (! $this->canUseGodMode($request->user())) {
            return $this->error('forbidden', 'God mode access is required.', 403);
        }

        $validated = $request->validate([
            'plan_tier' => ['required', 'string', Rule::in(array_keys(WorkspaceUsageService::plans()))],
            'status' => ['sometimes', 'string', Rule::in(['active', 'paused', 'cancelled'])],
        ]);

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace) {
            return $this->error('not_found', 'Workspace not found.', 404);
        }

        $workspace->fill($validated)->save();

        return response()->json([
            'data' => [
                'workspace' => $this->serializeWorkspace($workspace->fresh()),
            ],
            'meta' => [],
        ]);
    }

    private function canUseGodMode(?User $user): bool
    {
        return in_array($user?->role, ['super_admin', 'platform_admin'], true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function workspaceRows(): array
    {
        $spendByWorkspace = ApiUsageEvent::query()
            ->select('workspace_id', DB::raw('SUM(estimated_cost_usd) as spend'))
            ->groupBy('workspace_id')
            ->pluck('spend', 'workspace_id');

        $monthSpendByWorkspace = ApiUsageEvent::query()
            ->select('workspace_id', DB::raw('SUM(estimated_cost_usd) as spend'))
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->groupBy('workspace_id')
            ->pluck('spend', 'workspace_id');

        return Workspace::query()
            ->withCount(['users', 'projects'])
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(function (Workspace $workspace) use ($spendByWorkspace, $monthSpendByWorkspace): array {
                $usage = $this->usageService->summaryForWorkspace($workspace);
                $monthSpend = $this->money($monthSpendByWorkspace[$workspace->getKey()] ?? 0);
                $apiBudget = $this->money($usage['api_budget_usd'] ?? 0);

                return [
                    ...$this->serializeWorkspace($workspace),
                    'users_count' => (int) ($workspace->users_count ?? 0),
                    'projects_count' => (int) ($workspace->projects_count ?? 0),
                    'api_spend_usd' => $this->money($spendByWorkspace[$workspace->getKey()] ?? 0),
                    'api_spend_month_usd' => $monthSpend,
                    'api_budget_usd' => $apiBudget,
                    'api_budget_remaining_usd' => $this->money(max(0, $apiBudget - $monthSpend)),
                    'usage' => $usage,
                    'remaining' => [
                        'renders' => max(0, (int) $usage['render_limit'] - (int) $usage['renders_used']),
                        'voice_minutes' => max(0, (int) $usage['voice_minutes_limit'] - (int) $usage['voice_minutes_used']),
                        'dub_languages' => max(0, (int) $usage['dub_languages_limit'] - (int) $usage['dub_languages_used']),
                        'channels' => max(0, (int) $usage['channel_limit'] - (int) $usage['active_channels']),
                        'voice_clones' => max(0, (int) $usage['voice_cloning_limit'] - (int) $usage['voice_cloning_used']),
                    ],
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function userRows(): array
    {
        return User::query()
            ->with('workspace:id,name,plan_tier,status')
            ->orderByDesc('id')
            ->limit(100)
            ->get()
            ->map(fn (User $user): array => [
                'id' => $user->getKey(),
                'workspace_id' => $user->workspace_id,
                'workspace_name' => $user->workspace?->name,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'status' => $user->status,
                'created_at' => $user->created_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentUsageRows(): array
    {
        return ApiUsageEvent::query()
            ->with(['workspace:id,name', 'project:id,title'])
            ->orderByDesc('occurred_at')
            ->limit(50)
            ->get()
            ->map(fn (ApiUsageEvent $event): array => [
                'id' => $event->getKey(),
                'workspace_id' => $event->workspace_id,
                'workspace_name' => $event->workspace?->name,
                'project_id' => $event->project_id,
                'project_title' => $event->project?->title,
                'provider' => $event->provider,
                'service' => $event->service,
                'operation' => $event->operation,
                'model' => $event->model,
                'status' => $event->status,
                'total_tokens' => $event->total_tokens,
                'units' => $event->units,
                'estimated_cost_usd' => $this->money($event->estimated_cost_usd),
                'error_message' => $event->error_message,
                'occurred_at' => $event->occurred_at?->toIso8601String(),
            ])
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
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
        ];
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 6);
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
