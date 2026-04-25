<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\ApiUsageEvent;
use App\Models\JobFailureTrace;
use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Auth\JwtService;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(private readonly WorkspaceUsageService $usageService) {}

    public function overview(Request $request): JsonResponse
    {
        $monthStart = now()->startOfMonth();

        return response()->json([
            'data' => [
                'summary' => [
                    'total_users' => User::query()->count(),
                    'active_users' => User::query()->where('status', 'active')->count(),
                    'total_workspaces' => Workspace::query()->count(),
                    'total_projects' => Project::query()->count(),
                    'exports_today' => ExportJob::query()->where('status', 'completed')->whereDate('completed_at', today())->count(),
                    'exports_month' => ExportJob::query()->where('status', 'completed')->where('completed_at', '>=', $monthStart)->count(),
                    'api_spend_today_usd' => $this->money(ApiUsageEvent::query()->whereDate('occurred_at', today())->sum('estimated_cost_usd')),
                    'api_spend_month_usd' => $this->money(ApiUsageEvent::query()->where('occurred_at', '>=', $monthStart)->sum('estimated_cost_usd')),
                    'api_spend_total_usd' => $this->money(ApiUsageEvent::query()->sum('estimated_cost_usd')),
                    'failed_jobs_today' => ExportJob::query()->where('status', 'failed')->whereDate('queued_at', today())->count(),
                ],
                'plan_distribution' => $this->planDistribution(),
                'spend_by_day' => $this->spendByDay(14),
                'top_spenders' => $this->topSpenders(5),
            ],
            'meta' => [],
        ]);
    }

    public function users(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'suspended'])],
            'plan' => ['nullable', 'string', Rule::in(array_keys(WorkspaceUsageService::plans()))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $page = (int) ($validated['page'] ?? 1);

        $monthStart = now()->startOfMonth();

        $spendByWorkspace = ApiUsageEvent::query()
            ->select('workspace_id', DB::raw('SUM(estimated_cost_usd) as spend'))
            ->whereNotNull('workspace_id')
            ->where('occurred_at', '>=', $monthStart)
            ->groupBy('workspace_id')
            ->pluck('spend', 'workspace_id');

        $projectsByWorkspace = Project::query()
            ->select('workspace_id', DB::raw('COUNT(*) as cnt'))
            ->groupBy('workspace_id')
            ->pluck('cnt', 'workspace_id');

        $exportsByWorkspace = ExportJob::query()
            ->select(DB::raw('projects.workspace_id'), DB::raw('COUNT(*) as cnt'))
            ->join('projects', 'projects.id', '=', 'export_jobs.project_id')
            ->where('export_jobs.status', 'completed')
            ->groupBy('projects.workspace_id')
            ->pluck('cnt', 'projects.workspace_id');

        $query = User::query()
            ->with('workspace:id,name,plan_tier,status')
            ->when($validated['search'] ?? null, function ($q, $s): void {
                $q->where(function ($q) use ($s): void {
                    $q->where('name', 'like', "%{$s}%")
                      ->orWhere('email', 'like', "%{$s}%");
                });
            })
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['plan'] ?? null, fn ($q, $p) => $q->whereHas('workspace', fn ($q) => $q->where('plan_tier', $p)))
            ->orderByDesc('id');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => [
                'users' => $paginator->getCollection()->map(function (User $user) use ($spendByWorkspace, $projectsByWorkspace, $exportsByWorkspace): array {
                    $wsId = (int) $user->workspace_id;
                    return [
                        'id' => $user->getKey(),
                        'name' => $user->name,
                        'email' => $user->email,
                        'role' => $user->role,
                        'status' => $user->status,
                        'workspace_id' => $wsId,
                        'workspace_name' => $user->workspace?->name,
                        'plan_tier' => $user->workspace?->plan_tier,
                        'workspace_status' => $user->workspace?->status,
                        'projects' => (int) ($projectsByWorkspace[$wsId] ?? 0),
                        'exports' => (int) ($exportsByWorkspace[$wsId] ?? 0),
                        'spend_month_usd' => $this->money($spendByWorkspace[$wsId] ?? 0),
                        'created_at' => $user->created_at?->toIso8601String(),
                        'last_login_at' => $user->updated_at?->toIso8601String(),
                    ];
                })->all(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function userDetail(Request $request, int $userId): JsonResponse
    {
        $user = User::query()->with('workspace')->find($userId);

        if (! $user) {
            return $this->error('not_found', 'User not found.', 404);
        }

        $wsId = (int) $user->workspace_id;
        $monthStart = now()->startOfMonth();

        $storageBytes = (int) Asset::query()->where('workspace_id', $wsId)->where('status', 'active')->sum('file_size_bytes');
        $storageCount = (int) Asset::query()->where('workspace_id', $wsId)->where('status', 'active')->count();

        $spendMonth = $this->money(ApiUsageEvent::query()->where('workspace_id', $wsId)->where('occurred_at', '>=', $monthStart)->sum('estimated_cost_usd'));
        $spendTotal = $this->money(ApiUsageEvent::query()->where('workspace_id', $wsId)->sum('estimated_cost_usd'));

        $providerBreakdown = ApiUsageEvent::query()
            ->select('provider', 'service', DB::raw('COUNT(*) as calls'), DB::raw('SUM(estimated_cost_usd) as cost'))
            ->where('workspace_id', $wsId)
            ->where('occurred_at', '>=', $monthStart)
            ->groupBy('provider', 'service')
            ->get()
            ->map(fn ($r) => [
                'provider' => $r->provider,
                'service' => $r->service,
                'calls' => (int) $r->calls,
                'cost_usd' => $this->money($r->cost),
            ])->values()->all();

        $recentProjects = Project::query()
            ->where('workspace_id', $wsId)
            ->withCount('scenes')
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->map(fn (Project $p) => [
                'id' => $p->getKey(),
                'title' => $p->title,
                'status' => $p->status,
                'scenes_count' => (int) ($p->scenes_count ?? 0),
                'created_at' => $p->created_at?->toIso8601String(),
            ])->all();

        $recentExports = ExportJob::query()
            ->join('projects', 'projects.id', '=', 'export_jobs.project_id')
            ->where('projects.workspace_id', $wsId)
            ->select('export_jobs.*', 'projects.title as project_title')
            ->orderByDesc('export_jobs.id')
            ->limit(10)
            ->get()
            ->map(fn ($j) => [
                'id' => (int) $j->id,
                'project_title' => $j->project_title,
                'status' => $j->status,
                'aspect_ratio' => $j->aspect_ratio,
                'failure_reason' => $j->failure_reason,
                'completed_at' => $j->completed_at,
            ])->all();

        $spendByDay = ApiUsageEvent::query()
            ->select(DB::raw('DATE(occurred_at) as day'), DB::raw('SUM(estimated_cost_usd) as spend'))
            ->where('workspace_id', $wsId)
            ->where('occurred_at', '>=', now()->subDays(30))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => ['day' => $r->day, 'spend' => $this->money($r->spend)])
            ->all();

        return response()->json([
            'data' => [
                'user' => [
                    'id' => $user->getKey(),
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'status' => $user->status,
                    'created_at' => $user->created_at?->toIso8601String(),
                    'last_login_at' => $user->updated_at?->toIso8601String(),
                ],
                'workspace' => $user->workspace ? $this->serializeWorkspace($user->workspace) : null,
                'usage' => $user->workspace ? $this->usageService->summaryForWorkspace($user->workspace) : null,
                'storage' => [
                    'total_bytes' => $storageBytes,
                    'total_human' => $this->humanBytes($storageBytes),
                    'asset_count' => $storageCount,
                ],
                'spend_month_usd' => $spendMonth,
                'spend_total_usd' => $spendTotal,
                'spend_by_day' => $spendByDay,
                'provider_breakdown' => $providerBreakdown,
                'recent_projects' => $recentProjects,
                'recent_exports' => $recentExports,
            ],
            'meta' => [],
        ]);
    }

    public function impersonate(Request $request, int $userId, JwtService $jwt): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $target = User::query()->with('workspace')->find($userId);

        if (! $target) {
            return $this->error('not_found', 'User not found.', 404);
        }

        $ttl = (int) config('admin.impersonation_ttl_minutes', 15);

        $token = $jwt->issue($target, $ttl, [
            'impersonated_by' => $admin->getKey(),
            'impersonation' => true,
        ]);

        AdminAuditLog::record(
            adminUserId: $admin->getKey(),
            action: 'impersonate',
            targetType: 'user',
            targetId: $target->getKey(),
            payload: ['target_email' => $target->email, 'ttl_minutes' => $ttl],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json([
            'data' => [
                'token' => $token,
                'expires_in_minutes' => $ttl,
                'user' => ['id' => $target->getKey(), 'name' => $target->name, 'email' => $target->email],
            ],
            'meta' => [],
        ]);
    }

    public function workspaces(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['active', 'inactive', 'suspended'])],
            'plan' => ['nullable', 'string', Rule::in(array_keys(WorkspaceUsageService::plans()))],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $page = (int) ($validated['page'] ?? 1);

        $monthStart = now()->startOfMonth();

        $spendByWorkspace = ApiUsageEvent::query()
            ->select('workspace_id', DB::raw('SUM(estimated_cost_usd) as spend'))
            ->whereNotNull('workspace_id')
            ->where('occurred_at', '>=', $monthStart)
            ->groupBy('workspace_id')
            ->pluck('spend', 'workspace_id');

        $paginator = Workspace::query()
            ->withCount(['users', 'projects'])
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where('name', 'like', "%{$s}%"))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['plan'] ?? null, fn ($q, $p) => $q->where('plan_tier', $p))
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => [
                'workspaces' => $paginator->getCollection()->map(function (Workspace $ws) use ($spendByWorkspace): array {
                    $usage = $this->usageService->summaryForWorkspace($ws);
                    $monthSpend = $this->money($spendByWorkspace[$ws->getKey()] ?? 0);
                    $budget = (float) $usage['api_budget_usd'];
                    return [
                        ...$this->serializeWorkspace($ws),
                        'users_count' => (int) ($ws->users_count ?? 0),
                        'projects_count' => (int) ($ws->projects_count ?? 0),
                        'exports_count' => (int) $usage['renders_used'],
                        'spend_month_usd' => $monthSpend,
                        'budget_usd' => $budget,
                        'budget_pct' => $budget > 0 ? round($monthSpend / $budget * 100, 1) : 0,
                        'channels' => (int) $usage['active_channels'],
                    ];
                })->all(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function updateWorkspacePlan(Request $request, int $workspaceId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $validated = $request->validate([
            'plan_tier' => ['required', 'string', Rule::in(array_keys(WorkspaceUsageService::plans()))],
        ]);

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace) {
            return $this->error('not_found', 'Workspace not found.', 404);
        }

        $old = $workspace->plan_tier;
        $workspace->fill($validated)->save();

        AdminAuditLog::record(
            adminUserId: $admin->getKey(),
            action: 'update_plan',
            targetType: 'workspace',
            targetId: $workspace->getKey(),
            payload: ['from' => $old, 'to' => $validated['plan_tier']],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['data' => ['workspace' => $this->serializeWorkspace($workspace->fresh())], 'meta' => []]);
    }

    public function updateWorkspaceStatus(Request $request, int $workspaceId): JsonResponse
    {
        /** @var User $admin */
        $admin = $request->user();

        $validated = $request->validate([
            'status' => ['required', 'string', Rule::in(['active', 'inactive', 'suspended'])],
        ]);

        $workspace = Workspace::query()->find($workspaceId);

        if (! $workspace) {
            return $this->error('not_found', 'Workspace not found.', 404);
        }

        $old = $workspace->status;
        $workspace->fill($validated)->save();

        AdminAuditLog::record(
            adminUserId: $admin->getKey(),
            action: 'update_workspace_status',
            targetType: 'workspace',
            targetId: $workspace->getKey(),
            payload: ['from' => $old, 'to' => $validated['status']],
            ip: $request->ip(),
            userAgent: $request->userAgent(),
        );

        return response()->json(['data' => ['workspace' => $this->serializeWorkspace($workspace->fresh())], 'meta' => []]);
    }

    public function videos(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'generating', 'ready_for_review', 'published', 'failed'])],
            'workspace_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page = (int) ($validated['page'] ?? 1);
        $monthStart = now()->startOfMonth();

        $spendByProject = ApiUsageEvent::query()
            ->select('project_id', DB::raw('SUM(estimated_cost_usd) as spend'))
            ->whereNotNull('project_id')
            ->where('occurred_at', '>=', $monthStart)
            ->groupBy('project_id')
            ->pluck('spend', 'project_id');

        $paginator = Project::query()
            ->with(['workspace:id,name,plan_tier'])
            ->withCount('scenes')
            ->when($validated['search'] ?? null, fn ($q, $s) => $q->where('title', 'like', "%{$s}%"))
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($validated['workspace_id'] ?? null, fn ($q, $id) => $q->where('workspace_id', $id))
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        return response()->json([
            'data' => [
                'videos' => $paginator->getCollection()->map(fn (Project $p) => [
                    'id' => $p->getKey(),
                    'title' => $p->title ?? 'Untitled',
                    'status' => $p->status,
                    'source_type' => $p->source_type,
                    'aspect_ratio' => $p->aspect_ratio,
                    'primary_language' => $p->primary_language,
                    'platform_target' => $p->platform_target,
                    'scenes_count' => (int) ($p->scenes_count ?? 0),
                    'workspace_id' => $p->workspace_id,
                    'workspace_name' => $p->workspace?->name,
                    'plan_tier' => $p->workspace?->plan_tier,
                    'channel_id' => $p->channel_id,
                    'cost_usd' => $this->money($spendByProject[$p->getKey()] ?? 0),
                    'created_at' => $p->created_at?->toIso8601String(),
                ])->all(),
                'status_counts' => [
                    'all' => Project::query()->count(),
                    'draft' => Project::query()->where('status', 'draft')->count(),
                    'generating' => Project::query()->where('status', 'generating')->count(),
                    'ready_for_review' => Project::query()->where('status', 'ready_for_review')->count(),
                    'published' => Project::query()->where('status', 'published')->count(),
                    'failed' => Project::query()->where('status', 'failed')->count(),
                ],
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function jobs(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', Rule::in(['queued', 'processing', 'completed', 'failed'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 50);
        $page = (int) ($validated['page'] ?? 1);

        $paginator = ExportJob::query()
            ->with('project:id,title,workspace_id')
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        // Resolve workspace names in one query.
        $workspaceIds = $paginator->getCollection()
            ->pluck('workspace_id')
            ->filter()
            ->unique()
            ->values();

        $workspaceNames = Workspace::query()
            ->whereIn('id', $workspaceIds)
            ->pluck('name', 'id');

        // Resolve render cost from ApiUsageEvent (recorded by ProcessExportJob on success).
        $exportJobIds = $paginator->getCollection()->pluck('id')->values()->all();

        $costByExportJob = DB::table('api_usage_events')
            ->selectRaw("(metadata_json->>'export_job_id')::integer as ej_id, SUM(estimated_cost_usd) as cost")
            ->whereRaw("metadata_json->>'export_job_id' IS NOT NULL")
            ->where('service', 'export')
            ->whereIn(DB::raw("(metadata_json->>'export_job_id')::integer"), $exportJobIds)
            ->groupByRaw("(metadata_json->>'export_job_id')::integer")
            ->pluck('cost', 'ej_id')
            ->mapWithKeys(fn ($cost, $id) => [(int) $id => $this->money($cost)]);

        return response()->json([
            'data' => [
                'jobs' => $paginator->getCollection()->map(fn (ExportJob $j) => [
                    'id' => $j->getKey(),
                    'project_id' => $j->project_id,
                    'project_title' => $j->project?->title,
                    'workspace_id' => $j->workspace_id,
                    'workspace_name' => $workspaceNames[$j->workspace_id] ?? null,
                    'status' => $j->status,
                    'progress_percent' => $j->progress_percent,
                    'failure_reason' => $j->failure_reason,
                    'aspect_ratio' => $j->aspect_ratio,
                    'render_seconds' => ($j->started_at && $j->completed_at)
                        ? $j->completed_at->diffInSeconds($j->started_at)
                        : null,
                    'render_cost_usd' => $costByExportJob[$j->getKey()] ?? null,
                    'queue' => 'exports',
                    'queued_at' => $j->queued_at?->toIso8601String(),
                    'started_at' => $j->started_at?->toIso8601String(),
                    'completed_at' => $j->completed_at?->toIso8601String(),
                ])->all(),
                'counts' => [
                    'queued' => ExportJob::query()->where('status', 'queued')->count(),
                    'processing' => ExportJob::query()->where('status', 'processing')->count(),
                    'completed_today' => ExportJob::query()->where('status', 'completed')->whereDate('completed_at', today())->count(),
                    'failed_today' => ExportJob::query()->where('status', 'failed')->whereDate('queued_at', today())->count(),
                ],
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function spendChart(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'days' => ['nullable', 'integer', Rule::in([7, 14, 30, 90])],
            'workspace_id' => ['nullable', 'integer'],
        ]);

        $days = (int) ($validated['days'] ?? 14);

        $rows = ApiUsageEvent::query()
            ->select(DB::raw('DATE(occurred_at) as day'), DB::raw('SUM(estimated_cost_usd) as spend'))
            ->when($validated['workspace_id'] ?? null, fn ($q, $id) => $q->where('workspace_id', $id))
            ->where('occurred_at', '>=', now()->subDays($days))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => ['day' => $r->day, 'spend' => $this->money($r->spend)])
            ->all();

        return response()->json(['data' => ['chart' => $rows], 'meta' => []]);
    }

    public function auditLog(Request $request): JsonResponse
    {
        $perPage = (int) in_array((int) $request->query('per_page'), [10, 20, 50, 100])
            ? $request->query('per_page')
            : 20;

        $paginator = \App\Models\AdminAuditLog::query()
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', (int) ($request->query('page', 1)));

        return response()->json([
            'data' => ['logs' => $paginator->items()],
            'meta' => ['pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'from' => $paginator->firstItem(), 'to' => $paginator->lastItem()]],
        ]);
    }

    private function planDistribution(): array
    {
        $total = Workspace::query()->count();

        $counts = Workspace::query()
            ->select('plan_tier', DB::raw('COUNT(*) as cnt'))
            ->groupBy('plan_tier')
            ->pluck('cnt', 'plan_tier');

        return collect(array_keys(WorkspaceUsageService::plans()))
            ->map(fn (string $plan) => [
                'plan' => $plan,
                'count' => (int) ($counts[$plan] ?? 0),
                'pct' => $total > 0 ? round(($counts[$plan] ?? 0) / $total * 100, 1) : 0,
            ])->all();
    }

    private function spendByDay(int $days): array
    {
        return ApiUsageEvent::query()
            ->select(DB::raw('DATE(occurred_at) as day'), DB::raw('SUM(estimated_cost_usd) as spend'))
            ->where('occurred_at', '>=', now()->subDays($days))
            ->groupBy('day')
            ->orderBy('day')
            ->get()
            ->map(fn ($r) => ['day' => $r->day, 'spend' => $this->money($r->spend)])
            ->all();
    }

    private function topSpenders(int $limit): array
    {
        $monthStart = now()->startOfMonth();

        $spendByWs = ApiUsageEvent::query()
            ->select('workspace_id', DB::raw('SUM(estimated_cost_usd) as spend'))
            ->whereNotNull('workspace_id')
            ->where('occurred_at', '>=', $monthStart)
            ->groupBy('workspace_id')
            ->orderByDesc('spend')
            ->limit($limit)
            ->pluck('spend', 'workspace_id');

        return Workspace::query()
            ->whereIn('id', $spendByWs->keys())
            ->with('users:id,workspace_id,name,email')
            ->get()
            ->map(fn (Workspace $ws) => [
                ...$this->serializeWorkspace($ws),
                'spend_month_usd' => $this->money($spendByWs[$ws->getKey()] ?? 0),
                'owner_email' => $ws->users->first()?->email,
            ])
            ->sortByDesc('spend_month_usd')
            ->values()
            ->all();
    }

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

    public function failureTraces(Request $request): JsonResponse
    {
        $perPage = in_array((int) $request->query('per_page'), [20, 50, 100]) ? (int) $request->query('per_page') : 50;

        $query = JobFailureTrace::query()->orderByDesc('failed_at');

        if ($request->filled('entity_type')) {
            $query->where('entity_type', $request->query('entity_type'));
        }

        if ($request->filled('job_class')) {
            $query->where('job_class', 'like', '%'.$request->query('job_class').'%');
        }

        $paginator = $query->paginate($perPage, ['*'], 'page', (int) ($request->query('page', 1)));

        $traces = collect($paginator->items())->map(fn (JobFailureTrace $t) => [
            'id'                => $t->getKey(),
            'job_class'         => $t->job_class,
            'job_label'         => $t->jobLabel(),
            'entity_type'       => $t->entity_type,
            'entity_id'         => $t->entity_id,
            'workspace_id'      => $t->workspace_id,
            'project_id'        => $t->project_id,
            'exception_class'   => $t->exception_class,
            'exception_message' => $t->exception_message,
            'exception_trace'   => $t->exception_trace,
            'failed_at'         => $t->failed_at?->toIso8601String(),
        ])->all();

        return response()->json([
            'data' => ['traces' => $traces],
            'meta' => ['pagination' => ['current_page' => $paginator->currentPage(), 'last_page' => $paginator->lastPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total()]],
        ]);
    }

    public function storage(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search'   => ['nullable', 'string', 'max:100'],
            'plan'     => ['nullable', 'string', Rule::in(array_keys(WorkspaceUsageService::plans()))],
            'page'     => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([10, 20, 50, 100])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 20);
        $page    = (int) ($validated['page'] ?? 1);
        $search  = $validated['search'] ?? null;
        $plan    = $validated['plan'] ?? null;

        // System-wide totals (always unfiltered).
        $totalBytes        = (int) Asset::query()->where('status', 'active')->sum('file_size_bytes');
        $totalCount        = (int) Asset::query()->where('status', 'active')->count();
        $workspaceCount    = (int) Asset::query()->where('status', 'active')->whereNotNull('workspace_id')->distinct()->count('workspace_id');
        $videoCount        = (int) Asset::query()->where('status', 'active')->where('asset_type', 'video')->count();
        $audioCount        = (int) Asset::query()->where('status', 'active')->where('asset_type', 'audio')->count();
        $imageCount        = (int) Asset::query()->where('status', 'active')->where('asset_type', 'image')->count();

        // Filtered count for pagination.
        $countQuery = DB::table('assets')
            ->join('workspaces', 'workspaces.id', '=', 'assets.workspace_id')
            ->where('assets.status', 'active')
            ->when($search, fn ($q) => $q->where('workspaces.name', 'ilike', "%{$search}%"))
            ->when($plan, fn ($q) => $q->where('workspaces.plan_tier', $plan))
            ->distinct()
            ->count('workspaces.id');

        // Per-workspace breakdown with filter + pagination.
        $byWorkspace = DB::table('assets')
            ->join('workspaces', 'workspaces.id', '=', 'assets.workspace_id')
            ->where('assets.status', 'active')
            ->when($search, fn ($q) => $q->where('workspaces.name', 'ilike', "%{$search}%"))
            ->when($plan, fn ($q) => $q->where('workspaces.plan_tier', $plan))
            ->select(
                'workspaces.id as workspace_id',
                'workspaces.name as workspace_name',
                'workspaces.plan_tier',
                DB::raw('COALESCE(SUM(assets.file_size_bytes), 0)::bigint AS total_bytes'),
                DB::raw('COUNT(*) AS asset_count'),
                DB::raw("COUNT(*) FILTER (WHERE assets.asset_type = 'video') AS video_count"),
                DB::raw("COUNT(*) FILTER (WHERE assets.asset_type = 'audio') AS audio_count"),
                DB::raw("COUNT(*) FILTER (WHERE assets.asset_type = 'image') AS image_count"),
            )
            ->groupBy('workspaces.id', 'workspaces.name', 'workspaces.plan_tier')
            ->orderByDesc('total_bytes')
            ->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        $lastPage = max(1, (int) ceil($countQuery / $perPage));
        $from     = $countQuery > 0 ? (($page - 1) * $perPage) + 1 : 0;
        $to       = min($page * $perPage, $countQuery);

        return response()->json([
            'data' => [
                'summary' => [
                    'total_bytes'     => $totalBytes,
                    'total_assets'    => $totalCount,
                    'total_human'     => $this->humanBytes($totalBytes),
                    'workspace_count' => $workspaceCount,
                    'video_count'     => $videoCount,
                    'audio_count'     => $audioCount,
                    'image_count'     => $imageCount,
                ],
                'by_workspace' => $byWorkspace->map(fn ($row): array => [
                    'workspace_id'   => $row->workspace_id,
                    'workspace_name' => $row->workspace_name,
                    'plan_tier'      => $row->plan_tier,
                    'total_bytes'    => (int) $row->total_bytes,
                    'total_human'    => $this->humanBytes((int) $row->total_bytes),
                    'asset_count'    => (int) $row->asset_count,
                    'video_count'    => (int) $row->video_count,
                    'audio_count'    => (int) $row->audio_count,
                    'image_count'    => (int) $row->image_count,
                ])->values()->all(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $page,
                    'last_page'    => $lastPage,
                    'per_page'     => $perPage,
                    'total'        => $countQuery,
                    'from'         => $from,
                    'to'           => $to,
                ],
            ],
        ]);
    }

    private function humanBytes(int $bytes): string
    {
        if ($bytes >= 1073741824) {
            return round($bytes / 1073741824, 2).' GB';
        }

        if ($bytes >= 1048576) {
            return round($bytes / 1048576, 2).' MB';
        }

        if ($bytes >= 1024) {
            return round($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }

    private function money(mixed $value): float
    {
        return round((float) $value, 4);
    }
}
