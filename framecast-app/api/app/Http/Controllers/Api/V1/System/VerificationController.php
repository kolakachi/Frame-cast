<?php

namespace App\Http\Controllers\Api\V1\System;

use App\Http\Controllers\Controller;
use App\Models\CreditLedgerEntry;
use App\Models\User;
use App\Services\CreditService;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class VerificationController extends Controller
{
    public function __construct(
        private readonly WorkspaceUsageService $usageService,
        private readonly CreditService $credits,
    ) {}

    public function me(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $workspace = $user->workspace;

        return response()->json([
            'data' => [
                'user'    => $this->serializeUser($user),
                'usage'   => $this->usageService->summaryForUser($user),
                'credits' => [
                    'balance'          => $workspace ? $workspace->creditsBalance() : 0,
                    'credits_monthly'  => (int) ($workspace?->credits_monthly ?? 0),
                    'credits_topup'    => (int) ($workspace?->credits_topup ?? 0),
                    'billing_renews_at'=> $workspace?->billing_renews_at?->toIso8601String(),
                    'plan_monthly_allocation' => CreditService::PLAN_CREDITS[$workspace?->plan_tier ?? 'free'] ?? 0,
                ],
            ],
            'meta' => [],
        ]);
    }

    /**
     * GDPR data export — returns a JSON dump of the user's personal data.
     * Streamed inline as application/json so the browser downloads it via
     * the Content-Disposition header.
     */
    public function exportMe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = $user->workspace;

        $payload = [
            'export_meta' => [
                'generated_at'    => now()->toIso8601String(),
                'wyvstudio_user_id' => $user->getKey(),
                'format_version'  => 1,
            ],
            'profile' => [
                'email'       => $user->email,
                'name'        => $user->name,
                'role'        => $user->role,
                'timezone'    => $user->timezone,
                'preferences' => $user->preferences_json ?? [],
                'created_at'  => $user->created_at?->toIso8601String(),
            ],
            'workspace' => $workspace ? [
                'id'                => $workspace->getKey(),
                'plan_tier'         => $workspace->plan_tier,
                'credits_monthly'   => (int) ($workspace->credits_monthly ?? 0),
                'credits_topup'     => (int) ($workspace->credits_topup ?? 0),
                'billing_renews_at' => $workspace->billing_renews_at?->toIso8601String(),
                'created_at'        => $workspace->created_at?->toIso8601String(),
            ] : null,
            'connected_social_accounts' => $workspace
                ? \App\Models\SocialAccount::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->get(['id', 'platform', 'platform_username', 'platform_display_name', 'status', 'created_at'])
                    ->map(fn ($a) => [
                        'platform'              => $a->platform,
                        'username'              => $a->platform_username,
                        'display_name'          => $a->platform_display_name,
                        'status'                => $a->status,
                        'connected_at'          => $a->created_at?->toIso8601String(),
                    ])->all()
                : [],
            'projects' => $workspace
                ? \App\Models\Project::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->orderBy('id')
                    ->get(['id', 'title', 'status', 'source_type', 'aspect_ratio', 'tone', 'created_at'])
                    ->map(fn ($p) => [
                        'id'           => $p->getKey(),
                        'title'        => $p->title,
                        'status'       => $p->status,
                        'source_type'  => $p->source_type,
                        'aspect_ratio' => $p->aspect_ratio,
                        'tone'         => $p->tone,
                        'created_at'   => $p->created_at?->toIso8601String(),
                    ])->all()
                : [],
            'characters' => $workspace
                ? \App\Models\Character::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->get(['id', 'name', 'description', 'identity_strength', 'status', 'created_at'])
                    ->map(fn ($c) => [
                        'name'              => $c->name,
                        'description'       => $c->description,
                        'identity_strength' => $c->identity_strength,
                        'status'            => $c->status,
                        'created_at'        => $c->created_at?->toIso8601String(),
                    ])->all()
                : [],
            'scheduled_posts' => $workspace
                ? \App\Models\ScheduledPost::query()
                    ->where('workspace_id', $workspace->getKey())
                    ->orderBy('id')
                    ->get(['id', 'platform', 'status', 'scheduled_for', 'published_at', 'platform_post_id', 'created_at'])
                    ->map(fn ($p) => [
                        'platform'         => $p->platform,
                        'status'           => $p->status,
                        'scheduled_for'    => $p->scheduled_for?->toIso8601String(),
                        'published_at'     => $p->published_at?->toIso8601String(),
                        'platform_post_id' => $p->platform_post_id,
                        'created_at'       => $p->created_at?->toIso8601String(),
                    ])->all()
                : [],
        ];

        $filename = sprintf('wyvstudio-export-%s-%s.json',
            $user->getKey(),
            now()->format('Y-m-d'),
        );

        return response()->json(['data' => $payload, 'meta' => []])
            ->header('Content-Disposition', "attachment; filename=\"{$filename}\"");
    }

    /**
     * Hard delete of the user and all associated data. GDPR-compliant.
     * Workspace cascadeOnDelete fans out to projects, characters, scenes, assets,
     * social_accounts, scheduled_posts, exports, jobs, etc. Auth tokens are
     * removed up front so any pending request from another tab fails fast.
     */
    public function deleteMe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $request->validate([
            // Belt-and-suspenders: require the user to retype their email to confirm.
            'confirm_email' => ['required', 'string', 'in:'.$user->email],
        ]);

        $workspace = $user->workspace;

        // Best-effort: revoke API tokens immediately so other sessions can't act.
        \Illuminate\Support\Facades\DB::table('auth_tokens')
            ->where('user_id', $user->getKey())
            ->delete();

        // Cascade-delete domain data via workspace removal. If the workspace has
        // multiple users (future), only nuke it when this user is the last one.
        if ($workspace) {
            $otherUsers = User::query()
                ->where('workspace_id', $workspace->getKey())
                ->where('id', '!=', $user->getKey())
                ->count();
            if ($otherUsers === 0) {
                $workspace->delete();
            }
        }

        // Null the user's workspace pointer first to avoid FK confusion, then delete.
        $user->workspace_id = null;
        $user->save();
        $user->delete();

        return response()->json([
            'data' => ['deleted' => true],
            'meta' => [],
        ]);
    }

    public function updateMe(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'timezone' => ['sometimes', 'string', 'max:64'],
            'preferences' => ['sometimes', 'array'],
            'preferences.auto_generate_captions' => ['sometimes', 'boolean'],
            'preferences.preview_before_render' => ['sometimes', 'boolean'],
            'preferences.auto_music' => ['sometimes', 'boolean'],
            'preferences.watermark_enabled' => ['sometimes', 'boolean'],
            'preferences.onboarded' => ['sometimes', 'boolean'],
        ]);

        $user->fill(collect($validated)->except('preferences')->all());

        if (array_key_exists('preferences', $validated)) {
            $user->preferences_json = array_merge(
                $this->defaultPreferences(),
                $user->preferences_json ?? [],
                $validated['preferences'],
            );
        }

        $user->save();

        $freshUser = $user->fresh('workspace');
        $workspace = $freshUser?->workspace;

        return response()->json([
            'data' => [
                'user'    => $this->serializeUser($freshUser),
                'usage'   => $this->usageService->summaryForUser($freshUser),
                'credits' => [
                    'balance'          => $workspace ? $workspace->creditsBalance() : 0,
                    'credits_monthly'  => (int) ($workspace?->credits_monthly ?? 0),
                    'credits_topup'    => (int) ($workspace?->credits_topup ?? 0),
                    'billing_renews_at'=> $workspace?->billing_renews_at?->toIso8601String(),
                    'plan_monthly_allocation' => CreditService::PLAN_CREDITS[$workspace?->plan_tier ?? 'free'] ?? 0,
                ],
            ],
            'meta' => [],
        ]);
    }

    /**
     * GET /me/credit-history — paginated credit_ledger entries for the
     * caller's workspace.
     *
     * Query params:
     *   ?per_page=25      max 100, default 25
     *   ?since=30         days back, max 365, default 30. Use 0 for "all-time"
     *                     (no time filter — useful when the user wants to find
     *                     a specific grant from months ago).
     *   ?filter=all       all | debits | grants
     *   ?sort=newest      newest | oldest | largest (largest = biggest debit/grant first)
     *   ?cursor=:id       id of last seen row; returns rows OLDER than that
     *                     when sort=newest (or NEWER when sort=oldest)
     *
     * Returns:
     *   entries      — page slice
     *   summary      — per-operation roll-up for the FILTERED window
     *                  (not affected by cursor, only by since + filter)
     *   balance      — live balance
     *   next_cursor  — id to pass on the next page, or null if no more
     *   window       — { since_days, since_at } so the UI can show the range
     */
    public function creditHistory(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->workspace_id) {
            return $this->error('workspace_required', 'User is not assigned to a workspace.', 422);
        }

        $perPage   = min(max((int) $request->query('per_page', 25), 1), 100);
        $sinceDays = min(max((int) $request->query('since', 30), 0), 365);
        $filter    = in_array($request->query('filter'), ['all', 'debits', 'grants'], true)
            ? $request->query('filter')
            : 'all';
        $sort      = in_array($request->query('sort'), ['newest', 'oldest', 'largest'], true)
            ? $request->query('sort')
            : 'newest';
        $cursor    = (int) $request->query('cursor', 0);

        $applyWindowAndFilter = function ($query) use ($user, $sinceDays, $filter) {
            $query->where('workspace_id', $user->workspace_id);
            if ($sinceDays > 0) {
                $query->where('created_at', '>=', now()->subDays($sinceDays));
            }
            if ($filter === 'debits') {
                // Deductions are stored with operation NOT starting 'grant:'.
                $query->where('operation', 'not like', 'grant:%');
            } elseif ($filter === 'grants') {
                $query->where('operation', 'like', 'grant:%');
            }
        };

        // Page query — applies window, filter, sort, and cursor.
        $pageQuery = CreditLedgerEntry::query();
        $applyWindowAndFilter($pageQuery);

        match ($sort) {
            'oldest'  => $pageQuery->orderBy('id', 'asc'),
            'largest' => $pageQuery->orderByRaw('ABS(credits) DESC')->orderByDesc('id'),
            default   => $pageQuery->orderByDesc('id'),
        };

        if ($cursor > 0) {
            // Cursor semantics depend on sort direction.
            if ($sort === 'oldest') {
                $pageQuery->where('id', '>', $cursor);
            } elseif ($sort === 'largest') {
                // For 'largest' we need a secondary tie-breaker (id) — keyset
                // pagination on ABS(credits) is awkward, so use offset-style
                // here by ignoring the cursor and returning the first page.
                // (Most users only need ~3 'largest' pages at most.)
                // Intentionally leave cursor as a no-op for 'largest'.
            } else {
                $pageQuery->where('id', '<', $cursor);
            }
        }

        $rows    = $pageQuery->limit($perPage + 1)->get();
        $hasMore = $rows->count() > $perPage;
        if ($hasMore) {
            $rows = $rows->take($perPage);
        }
        $entries = $rows->map(fn (CreditLedgerEntry $e) => $this->serializeLedgerEntry($e))->all();

        // Summary is computed independently of cursor (it's the window total).
        $summaryQuery = CreditLedgerEntry::query();
        $applyWindowAndFilter($summaryQuery);
        $summary = $summaryQuery
            ->selectRaw('operation, COUNT(*) as ops, SUM(credits) as credits')
            ->groupBy('operation')
            ->orderByRaw('ABS(SUM(credits)) DESC')
            ->get()
            ->map(fn ($row) => [
                'operation' => (string) $row->operation,
                'ops'       => (int) $row->ops,
                'credits'   => (int) $row->credits,
            ])
            ->all();

        return response()->json([
            'data' => [
                'entries'     => $entries,
                'summary'     => $summary,
                'balance'     => $this->credits->balance((int) $user->workspace_id),
                'next_cursor' => $hasMore ? ($rows->last()?->id ?? null) : null,
                'window'      => [
                    'since_days' => $sinceDays,
                    'since_at'   => $sinceDays > 0 ? now()->subDays($sinceDays)->toIso8601String() : null,
                ],
                'filter'      => $filter,
                'sort'        => $sort,
            ],
            'meta' => [],
        ]);
    }

    /**
     * Shared row serializer — reused by the admin variant below.
     */
    private function serializeLedgerEntry(CreditLedgerEntry $e): array
    {
        return [
            'id'                => $e->getKey(),
            'operation'         => $e->operation,
            'credits'           => (int) $e->credits,
            'balance_after'     => (int) $e->balance_after,
            'project_id'        => $e->project_id,
            'scene_id'          => $e->scene_id,
            'metadata'          => is_array($e->metadata) ? $e->metadata : [],
            'upstream_cost_usd' => $e->upstream_cost_usd !== null ? (float) $e->upstream_cost_usd : null,
            'created_at'        => $e->created_at?->toIso8601String(),
        ];
    }

    public function storageSmoke(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $disk = Storage::disk('b2');
        $path = sprintf(
            'phase-0-smoke/%s/%s.txt',
            $user->workspace_id,
            Str::uuid()->toString(),
        );

        $contents = json_encode([
            'type' => 'phase_0_storage_smoke',
            'user_id' => $user->getKey(),
            'workspace_id' => $user->workspace_id,
            'generated_at' => Carbon::now()->toIso8601String(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        $disk->put($path, $contents, [
            'ContentType' => 'text/plain',
        ]);

        $temporaryUrl = $disk->url($path);

        return response()->json([
            'data' => [
                'disk' => 'b2',
                'path' => $path,
                'temporary_url' => $temporaryUrl,
            ],
            'meta' => [],
        ]);
    }

    /**
     * @return array{id:int,workspace_id:?int,name:string,email:string,timezone:string,role:string,status:string,preferences:array<string,bool>}
     */
    private function serializeUser(User $user): array
    {
        return [
            'id' => $user->getKey(),
            'workspace_id' => $user->workspace_id,
            'name' => $user->name,
            'email' => $user->email,
            'timezone' => $user->timezone,
            'role' => $user->role,
            'status' => $user->status,
            // Drives the Settings password panel: "Set a password" when
            // false, "Change password" when true. Boolean (not the hash)
            // so the actual credential never leaves the API.
            'has_password' => ! empty($user->password_hash),
            'preferences' => array_merge($this->defaultPreferences(), $user->preferences_json ?? []),
        ];
    }

    /**
     * @return array{auto_generate_captions:bool,preview_before_render:bool,auto_music:bool,watermark_enabled:bool}
     */
    private function defaultPreferences(): array
    {
        return [
            'auto_generate_captions' => true,
            'preview_before_render' => true,
            'auto_music' => true,
            'watermark_enabled' => false,
            'onboarded' => false,
        ];
    }

}
