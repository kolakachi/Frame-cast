<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Events\ExportProgressed;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateAIImageJob;
use App\Jobs\GenerateScriptJob;
use App\Jobs\GenerateTTSJob;
use App\Jobs\ProcessExportJob;
use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Channel;
use App\Models\Series;
use App\Models\Niche;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\ProjectHookOption;
use App\Models\Scene;
use App\Models\Template;
use App\Models\User;
use App\Services\Media\StorageService;
use App\Services\WorkspaceUsageService;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(
        private readonly WorkspaceUsageService $usageService,
        private readonly CreditService $credits,
    ) {}

    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([4, 8, 12, 16, 24])],
            'channel_id' => ['nullable', 'integer'],
            'status' => ['nullable', 'string', Rule::in(['draft', 'generating', 'ready', 'published', 'failed'])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 8);
        $page = (int) ($validated['page'] ?? 1);

        $paginator = Project::query()
            ->where('workspace_id', $user->workspace_id)
            ->when(! empty($validated['channel_id']), fn ($q) => $q->where('channel_id', (int) $validated['channel_id']))
            ->when(! empty($validated['status']), fn ($q) => $q->where('status', $validated['status']))
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('variants')
                    ->whereColumn('variants.derived_project_id', 'projects.id');
            })
            ->withCount('scenes')
            ->addSelect([
                'variants_count' => DB::table('variants')
                    ->join('variant_sets', 'variant_sets.id', '=', 'variants.variant_set_id')
                    ->whereColumn('variant_sets.base_project_id', 'projects.id')
                    ->selectRaw('count(*)'),
            ])
            ->orderByDesc('id')
            ->paginate($perPage, ['*'], 'page', $page);

        $projects = $paginator->getCollection();

        // Per-scene generation can outlive project.status: GenerateTTSJob flips
        // the project to ready_for_review when voice lands, while AI image /
        // animation jobs are still running. Surface that as generation_pending
        // so the frontend routes a click to the progress view, not the editor.
        $pendingProjectIds = $projects->isEmpty() ? [] : DB::table('scenes')
            ->whereIn('project_id', $projects->pluck('id'))
            ->where(function ($q): void {
                $q->whereRaw("image_generation_settings_json::jsonb->>'in_progress' = 'true'")
                    ->orWhereRaw("image_generation_settings_json::jsonb->>'animation_in_progress' = 'true'");
            })
            ->distinct()
            ->pluck('project_id')
            ->all();

        return response()->json([
            'data' => [
                'projects' => $projects->map(fn (Project $project): array => [
                    ...$this->serializeProject($project),
                    'scenes_count' => (int) ($project->scenes_count ?? 0),
                    'variants_count' => (int) ($project->variants_count ?? 0),
                    'generation_pending' => $project->status === 'generating'
                        || in_array($project->getKey(), $pendingProjectIds, true),
                ])->all(),
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

    public function show(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        $hookOptions = ProjectHookOption::query()
            ->where('project_id', $project->getKey())
            ->orderBy('sort_order')
            ->get();

        $assetIds = $scenes
            ->flatMap(function (Scene $scene): array {
                $ids = [];

                if ($scene->visual_asset_id) {
                    $ids[] = (int) $scene->visual_asset_id;
                }

                $audioAssetId = data_get($scene->voice_settings_json, 'audio_asset_id');

                if ($audioAssetId) {
                    $ids[] = (int) $audioAssetId;
                }

                if ($scene->sound_asset_id) {
                    $ids[] = (int) $scene->sound_asset_id;
                }

                return $ids;
            })
            ->unique()
            ->values();

        /** @var Collection<int, Asset> $assetMap */
        $assetMap = Asset::query()
            ->whereIn('id', $assetIds)
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => [
                'project' => $this->serializeProject($project),
                'scenes' => $scenes->map(fn (Scene $scene): array => $this->serializeScene($scene, $assetMap))->all(),
                'hook_options' => $hookOptions->map(fn (ProjectHookOption $option): array => $this->serializeHookOption($option))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function queue(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([5, 10, 20])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 10);
        $page = (int) ($validated['page'] ?? 1);

        $paginator = Project::query()
            ->where('workspace_id', $user->workspace_id)
            ->whereIn('status', ['generating', 'ready_for_review', 'failed'])
            ->withCount('scenes')
            ->addSelect([
                'variants_count' => DB::table('variants')
                    ->join('variant_sets', 'variant_sets.id', '=', 'variants.variant_set_id')
                    ->whereColumn('variant_sets.base_project_id', 'projects.id')
                    ->selectRaw('count(*)'),
            ])
            ->orderByDesc('updated_at')
            ->paginate($perPage, ['*'], 'page', $page);

        $projects = $paginator->getCollection();

        // Recent export jobs: active ones + completed/failed within the last 7 days.
        $exportRows = ExportJob::query()
            ->where('workspace_id', $user->workspace_id)
            ->where(function ($q): void {
                $q->whereIn('status', ['queued', 'processing'])
                  ->orWhere('queued_at', '>=', now()->subDays(7));
            })
            ->with('project:id,title')
            ->orderByDesc('queued_at')
            ->limit(50)
            ->get()
            ->map(fn (ExportJob $j): array => [
                'job_type'        => 'export',
                'id'              => $j->getKey(),
                'project_id'      => $j->project_id,
                'title'           => $j->project?->title ?? "Project #{$j->project_id}",
                'status'          => $j->status,
                'progress_percent' => (int) $j->progress_percent,
                'aspect_ratio'    => $j->aspect_ratio,
                'language'        => $j->language,
                'failure_reason'  => $j->failure_reason,
                'queued_at'       => $j->queued_at?->toIso8601String(),
                'completed_at'    => $j->completed_at?->toIso8601String(),
                'created_at'      => $j->queued_at?->toIso8601String(),
            ])->all();

        return response()->json([
            'data' => [
                'queue_rows' => $projects->map(fn (Project $project): array => [
                    ...$this->serializeProject($project),
                    'job_type'      => 'generation',
                    'scenes_count'  => (int) ($project->scenes_count ?? 0),
                    'variants_count' => (int) ($project->variants_count ?? 0),
                ])->all(),
                'export_rows' => $exportRows,
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page'    => $paginator->lastPage(),
                    'per_page'     => $paginator->perPage(),
                    'total'        => $paginator->total(),
                    'from'         => $paginator->firstItem(),
                    'to'           => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        if (! $user->workspace_id) {
            return $this->error('workspace_required', 'User is not assigned to a workspace.', 422);
        }

        if ($this->usageService->hasExceededApiBudget($user)) {
            $ctx = $this->usageService->apiBudgetContext($user);
            return $this->limitError(
                'api_budget_exceeded',
                "Your workspace has reached its \${$ctx['budget_usd']} AI budget for the {$ctx['plan']} plan this month.",
                $ctx,
            );
        }

        $validated = $request->validate([
            'source_type' => ['required', Rule::in($this->allowedSourceTypes())],
            'source_content_raw' => ['nullable', 'string'],
            'source_image_asset_ids' => ['nullable', 'array', 'max:15'],
            'source_image_asset_ids.*' => ['integer'],
            'visual_type' => ['nullable', Rule::in(['stock_clip', 'stock_image', 'ai_image', 'waveform'])],
            'visual_generation_mode' => ['nullable', Rule::in(['stock', 'ai_images', 'stock_images', 'waveform'])],
            'ai_broll_style' => ['nullable', 'string', 'max:64'],
            'visual_style' => ['nullable', 'string', 'max:64'],
            'custom_visual_style' => ['nullable', 'string', 'max:500'],
            'voice_settings' => ['nullable', 'array'],
            'voice_settings_json' => ['nullable', 'array'],
            'voice_settings_json.voice_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'voice_settings_json.speed' => ['sometimes', 'numeric', 'min:0.25', 'max:4'],
            'voice_settings_json.stability' => ['sometimes', 'nullable', 'string', 'max:32'],
            'voice_settings.voice_id' => ['sometimes', 'nullable', 'string', 'max:255'],
            'voice_settings.speed' => ['sometimes', 'numeric', 'min:0.25', 'max:4'],
            'voice_settings.stability' => ['sometimes', 'nullable', 'string', 'max:32'],
            'image_generation_settings_json' => ['nullable', 'array'],
            'image_generation_settings_json.audiogram_style' => ['sometimes', 'nullable', 'string', 'max:64'],
            'image_generation_settings_json.audiogram_color' => ['sometimes', 'nullable', 'string', 'max:16'],
            'image_generation_settings_json.audiogram_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'waveform_settings_json' => ['nullable', 'array'],
            'waveform_settings_json.audiogram_style' => ['sometimes', 'nullable', 'string', 'max:64'],
            'waveform_settings_json.audiogram_color' => ['sometimes', 'nullable', 'string', 'max:16'],
            'waveform_settings_json.audiogram_bg' => ['sometimes', 'nullable', 'string', 'max:32'],
            'languages' => ['nullable', 'array', 'min:1'],
            'languages.*' => ['required', 'string', 'max:16'],
            'platform_target' => ['nullable', 'string', 'max:64'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'channel_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'integer'],
            'brand_kit_id' => ['nullable', 'integer'],
            'niche_id' => ['nullable', 'integer'],
            'character_id' => ['nullable', 'integer'],
            'content_goal' => ['nullable', 'string', 'max:255'],
            'duration_target_seconds' => ['nullable', 'integer', 'min:5', 'max:600'],
            'tone' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'series_id' => ['nullable', 'integer'],
            'series_episode_number' => ['nullable', 'integer', 'min:1'],
        ]);

        if (isset($validated['visual_type'])) {
            $validated['visual_generation_mode'] = $this->visualGenerationModeFromVisualType($validated['visual_type']);
        }

        $sourceError = $this->validateSourceContent($validated['source_type'], $validated['source_content_raw'] ?? null);

        if ($sourceError) {
            return $this->error('invalid_source_content', $sourceError, 422);
        }

        // ── Plan duration guard ──────────────────────────────────────────
        // Free tier caps at 60s; paid tiers go higher. Reject up front rather
        // than letting the user wait through generation.
        $maxDuration = $this->credits->maxDurationSeconds((int) $user->workspace_id);
        $requestedDuration = (int) ($validated['duration_target_seconds'] ?? 0);
        if ($maxDuration !== null && $requestedDuration > 0 && $requestedDuration > $maxDuration) {
            $planTier = $this->credits->planTier((int) $user->workspace_id);
            return response()->json([
                'error' => [
                    'code'    => 'plan_duration_exceeded',
                    'message' => "Your {$planTier} plan caps video length at {$maxDuration}s. This project asks for {$requestedDuration}s — shorten it or upgrade for longer videos.",
                    'context' => ['plan' => $planTier, 'max_duration_seconds' => $maxDuration, 'requested' => $requestedDuration],
                ],
            ], 422);
        }

        // ── Credit guard ─────────────────────────────────────────────────
        $estimate = $this->credits->estimateProject(
            sourceType:    $validated['source_type'],
            sourceContent: $validated['source_content_raw'] ?? null,
            visualMode:    $validated['visual_generation_mode'] ?? 'stock',
            aiQuality:     $validated['ai_image_quality'] ?? 'medium',
        );
        $balance = $this->credits->balance((int) $user->workspace_id);
        if ($balance < $estimate['credits_min']) {
            return response()->json([
                'error' => [
                    'code'    => 'insufficient_credits',
                    'message' => "You need at least {$estimate['credits_min']} credits to generate this video. Your balance is {$balance}.",
                    'context' => [
                        'balance'      => $balance,
                        'estimate_min' => $estimate['credits_min'],
                        'estimate_max' => $estimate['credits_max'],
                        'shortage'     => $estimate['credits_min'] - $balance,
                    ],
                ],
            ], 402);
        }

        $sourceImageAssetIds = $this->resolveSourceImageAssetIds(
            $validated['source_type'],
            $validated['source_image_asset_ids'] ?? [],
            $user,
            $validated['visual_generation_mode'] ?? null,
        );

        if ($sourceImageAssetIds === null) {
            return $this->error('invalid_source_images', 'Upload Images requires 1-15 image assets from this workspace.', 422);
        }

        $channel = null;

        if (! empty($validated['channel_id'])) {
            $channel = Channel::query()
                ->whereKey($validated['channel_id'])
                ->where('workspace_id', $user->workspace_id)
                ->first();

            if (! $channel) {
                return $this->error('invalid_channel', 'Selected channel does not exist in this workspace.', 422);
            }
        }

        if (! empty($validated['brand_kit_id'])) {
            $brandKitExists = BrandKit::query()
                ->whereKey($validated['brand_kit_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();

            if (! $brandKitExists) {
                return $this->error('invalid_brand_kit', 'Selected brand kit does not exist in this workspace.', 422);
            }
        }

        $template = null;

        if (! empty($validated['template_id'])) {
            $template = Template::query()
                ->whereKey($validated['template_id'])
                ->where(function ($query) use ($user): void {
                    $query->whereNull('workspace_id')
                        ->orWhere('workspace_id', $user->workspace_id);
                })
                ->first();

            if (! $template) {
                return $this->error('invalid_template', 'Selected template is not available in this workspace.', 422);
            }
        }

        $allowedTemplateIds = $channel?->allowed_template_ids;

        if ($channel && is_array($allowedTemplateIds) && $allowedTemplateIds !== []) {
            $allowedTemplateIds = array_map(static fn (mixed $id): int => (int) $id, $allowedTemplateIds);

            if ($template && ! in_array((int) $template->getKey(), $allowedTemplateIds, true)) {
                return $this->error('template_channel_conflict', 'Selected template is not allowed for this channel.', 422);
            }

            if (! $template) {
                $template = Template::query()
                    ->whereIn('id', $allowedTemplateIds)
                    ->where(function ($query) use ($user): void {
                        $query->whereNull('workspace_id')
                            ->orWhere('workspace_id', $user->workspace_id);
                    })
                    ->first();
            }
        }

        $brandKitId = $validated['brand_kit_id'] ?? $channel?->brand_kit_id;

        // Resolve niche and apply its defaults where not explicitly overridden.
        $niche = null;
        $nicheMusicAssetId = null;
        $nicheTone = null;
        $nicheVisualStyle = null;

        // Resolve character — validates workspace ownership; nullable.
        $defaultCharacterId = null;
        if (! empty($validated['character_id'])) {
            $character = \App\Models\Character::query()
                ->whereKey($validated['character_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('status', 'active')
                ->first();
            if (! $character) {
                return $this->error('invalid_character', 'Character not found in this workspace.', 422);
            }
            $defaultCharacterId = $character->getKey();
        }

        if (! empty($validated['niche_id'])) {
            $niche = Niche::query()->find($validated['niche_id']);

            if ($niche) {
                // Inherit tone from niche if not explicitly set.
                if (empty($validated['tone'])) {
                    $nicheTone = $niche->default_voice_tone;
                }

                if (empty($validated['visual_style'])) {
                    $nicheVisualStyle = $niche->default_visual_style;
                }

                // Pick a music asset matching the niche's mood if no template already sets one.
                if ($niche->default_music_mood) {
                    $nicheMusicAssetId = Asset::query()
                        ->where('workspace_id', $user->workspace_id)
                        ->where('asset_type', 'music')
                        ->whereJsonContains('tags', $niche->default_music_mood)
                        ->value('id');
                }

                // Use niche's template type to pick template if one wasn't explicitly chosen.
                if (! $template && $niche->default_template_type) {
                    $template = Template::query()
                        ->where('template_type', $niche->default_template_type)
                        ->where(function ($query) use ($user): void {
                            $query->whereNull('workspace_id')
                                ->orWhere('workspace_id', $user->workspace_id);
                        })
                        ->first();
                }
            }
        }

        // Resolve series and apply its defaults.
        $series = null;
        $seriesEpisodeNumber = null;

        if (! empty($validated['series_id'])) {
            $series = Series::query()
                ->whereKey($validated['series_id'])
                ->where('workspace_id', $user->workspace_id)
                ->first();

            if ($series) {
                $seriesEpisodeNumber = $validated['series_episode_number']
                    ?? ((int) Project::query()->where('series_id', $series->getKey())->max('series_episode_number') + 1);

                // Inherit series defaults where the request doesn't override.
                if (empty($validated['aspect_ratio']) && $series->aspect_ratio) {
                    $validated['aspect_ratio'] = $series->aspect_ratio;
                }
                if (empty($validated['duration_target_seconds']) && $series->duration_target_seconds) {
                    $validated['duration_target_seconds'] = $series->duration_target_seconds;
                }
                if (empty($validated['tone']) && $series->tone) {
                    $validated['tone'] = $series->tone;
                }
                if (empty($validated['platform_target']) && ! empty($series->platform_targets)) {
                    $validated['platform_target'] = $series->platform_targets[0];
                }
                if (empty($validated['languages']) && $series->default_language) {
                    $validated['languages'] = [$series->default_language];
                }
            }
        }

        // Apply final defaults for required fields still missing after all inheritance.
        if (empty($validated['aspect_ratio'])) {
            $validated['aspect_ratio'] = '9:16';
        }
        if (empty($validated['platform_target'])) {
            $validated['platform_target'] = 'tiktok';
        }
        if (empty($validated['languages'])) {
            $validated['languages'] = ['en'];
        }

        if (! isset($validated['voice_settings_json']) && isset($validated['voice_settings'])) {
            $validated['voice_settings_json'] = $validated['voice_settings'];
        }

        if (
            isset($validated['image_generation_settings_json']) &&
            ($validated['visual_generation_mode'] ?? null) === 'waveform'
        ) {
            $validated['waveform_settings_json'] = $validated['image_generation_settings_json'];
        }

        $defaultVisualStyle = $validated['visual_style']
            ?? $validated['ai_broll_style']
            ?? $nicheVisualStyle;

        if (
            empty($validated['ai_broll_style'])
            && ($validated['visual_generation_mode'] ?? null) === 'ai_images'
            && $defaultVisualStyle
        ) {
            $validated['ai_broll_style'] = $defaultVisualStyle;
        }

        $defaultVoiceSettings = is_array($validated['voice_settings_json'] ?? null)
            ? array_filter(
                $validated['voice_settings_json'],
                static fn (mixed $value): bool => $value !== null && $value !== ''
            )
            : null;

        $waveformSettings = is_array($validated['waveform_settings_json'] ?? null)
            ? array_filter(
                $validated['waveform_settings_json'],
                static fn (mixed $value): bool => $value !== null && $value !== ''
            )
            : null;

        $project = Project::query()->create([
            'workspace_id' => $user->workspace_id,
            'channel_id' => $channel?->getKey() ?? $series?->channel_id,
            'brand_kit_id' => $brandKitId,
            'template_id' => $template?->getKey(),
            'niche_id' => $niche?->getKey(),
            'default_character_id' => $defaultCharacterId,
            'music_asset_id' => $nicheMusicAssetId,
            'music_settings_json' => $nicheMusicAssetId ? ['volume' => 30, 'duck_volume' => 8, 'fade_in_ms' => 500, 'loop' => true, 'duck_during_voice' => true] : null,
            'source_type' => $validated['source_type'],
            'source_content_raw' => $validated['source_content_raw'] ?? null,
            'source_content_normalized' => $this->normalizeSource($validated['source_content_raw'] ?? ''),
            'source_image_asset_ids' => $sourceImageAssetIds,
            'visual_generation_mode' => $validated['visual_generation_mode'] ?? null,
            'ai_broll_style' => $validated['ai_broll_style'] ?? null,
            'waveform_settings_json' => $waveformSettings,
            'default_visual_style' => $defaultVisualStyle,
            'custom_visual_style' => $validated['custom_visual_style'] ?? null,
            'content_goal' => $validated['content_goal'] ?? null,
            'platform_target' => $validated['platform_target'],
            'duration_target_seconds' => $validated['duration_target_seconds'] ?? null,
            'aspect_ratio' => $validated['aspect_ratio'],
            'tone' => $validated['tone'] ?? $nicheTone,
            'default_voice_settings_json' => $defaultVoiceSettings,
            'primary_language' => $validated['languages'][0],
            'title' => $validated['title'] ?? null,
            'status' => $validated['source_type'] === 'blank' ? 'ready_for_review' : 'generating',
            'created_by_user_id' => $user->getKey(),
            'series_id' => $series?->getKey(),
            'series_episode_number' => $seriesEpisodeNumber,
        ]);

        // Blank projects skip AI generation — user builds all scenes manually in the editor.
        if ($validated['source_type'] !== 'blank') {
            GenerateScriptJob::dispatch($project->getKey());
        }

        return response()->json([
            'data' => [
                'project' => $this->serializeProject($project),
                'defaults' => [
                    'channel_id' => $channel?->getKey(),
                    'brand_kit_id' => $brandKitId,
                    'default_voice_profile_id' => $channel?->default_voice_profile_id,
                    'default_caption_preset_id' => $channel?->default_caption_preset_id,
                    'template_id' => $template?->getKey(),
                ],
            ],
            'meta' => [],
        ], 201);
    }

    public function retryGeneration(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        if ($project->status !== 'failed') {
            return $this->error('invalid_state', 'Only failed projects can be retried.', 422);
        }

        if ($project->source_type === 'blank') {
            return $this->error('invalid_state', 'Blank projects have no generation to retry.', 422);
        }

        $hasScenes = Scene::query()->where('project_id', $project->getKey())->exists();

        if (! $hasScenes) {
            // Script generation failed — check budget before re-dispatching.
            if ($this->usageService->hasExceededApiBudget($user)) {
                $ctx = $this->usageService->apiBudgetContext($user);
                return $this->limitError(
                    'api_budget_exceeded',
                    "Your workspace has reached its \${$ctx['budget_usd']} AI budget for the {$ctx['plan']} plan this month.",
                    $ctx,
                );
            }

            $project->forceFill(['status' => 'generating'])->save();
            GenerateScriptJob::dispatch($project->getKey());

            return response()->json(['data' => ['project' => $this->serializeProject($project->fresh())], 'meta' => []]);
        }

        // Scenes exist — script completed. Check what still needs generating.
        $missingAudio = Scene::query()
            ->where('project_id', $project->getKey())
            ->where(function ($q): void {
                $q->whereNull('voice_settings_json->audio_asset_id')
                    ->orWhere('voice_settings_json->is_outdated', true);
            })
            ->exists();

        $scenesNeedingVisuals = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereNull('visual_asset_id')
            ->where('visual_type', 'ai_image')
            ->get();

        $missingVisual = $scenesNeedingVisuals->isNotEmpty();

        if (! $missingAudio && ! $missingVisual) {
            // Everything generated — just unblock the project.
            $project->forceFill(['status' => 'ready_for_review'])->save();

            return response()->json(['data' => ['project' => $this->serializeProject($project->fresh())], 'meta' => []]);
        }

        if ($missingAudio && $this->usageService->hasReachedVoiceLimit($user)) {
            $ctx = $this->usageService->voiceLimitContext($user);
            return $this->limitError(
                'voice_limit_reached',
                "Your workspace has used {$ctx['used']} of {$ctx['limit']} voice minutes on the {$ctx['plan']} plan.",
                $ctx,
            );
        }

        if ($this->usageService->hasExceededApiBudget($user)) {
            $ctx = $this->usageService->apiBudgetContext($user);
            return $this->limitError(
                'api_budget_exceeded',
                "Your workspace has reached its \${$ctx['budget_usd']} AI budget for the {$ctx['plan']} plan this month.",
                $ctx,
            );
        }

        $project->forceFill(['status' => 'generating'])->save();

        if ($missingAudio) {
            GenerateTTSJob::dispatch($project->getKey());
        }

        foreach ($scenesNeedingVisuals as $scene) {
            // Mirror SceneController::regenerateAIImage — set the in-progress
            // lock + generation_token BEFORE dispatching so the editor's
            // computed `activeSceneAIImagePending` reliably reflects state,
            // and so the job's `sceneStillMatchesGeneration()` guard has a
            // consistent token to compare against. Without this, jobs ran
            // with a null token and scenes occasionally ended up with an
            // orphaned asset + null settings_json + stuck "AI Generating…"
            // spinner (observed prod 2026-06-02 project 20 scene 187).
            $token = (string) Str::uuid();
            $scene->forceFill([
                'image_generation_settings_json' => array_merge(
                    $scene->image_generation_settings_json ?? [],
                    [
                        'in_progress'           => true,
                        'last_error'            => null,
                        'needs_visual'          => false,
                        'generation_token'      => $token,
                        'generation_started_at' => now()->toIso8601String(),
                    ],
                ),
            ])->save();

            GenerateAIImageJob::dispatch(
                $scene->getKey(),
                $scene->project_id,
                (string) ($scene->visual_style ?: 'cinematic'),
                null,
                $scene->visual_style ?: null,
                $token,
            );
        }

        return response()->json(['data' => ['project' => $this->serializeProject($project->fresh())], 'meta' => []]);
    }

    /**
     * One-shot prompt -> single-scene project with image + (optional)
     * animation + voice-over + AI music. Activation lever: a free-tier
     * user with 200 credits can fire ~4 of these and feel the whole
     * WyvStudio pipeline in ~90 seconds.
     *
     * Different from store() in two ways:
     *   1. Skips niche / source-content validation — just a prompt.
     *   2. Auto-dispatches the full pipeline (image -> [animate ->] tts
     *      -> music) instead of leaving the user to wire it up scene by
     *      scene.
     */
    /**
     * Phase 1 of the assisted one-shot: parse the prompt into a scene plan
     * and return it WITHOUT creating a project or spending anything. The
     * wizard shows this for approval/tweaks, then calls storeOneShot with
     * the (possibly edited) plan + the captions/sounds toggles. Cheap — one
     * parser call, no jobs.
     */
    public function planOneShot(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->workspace_id) {
            return $this->error('workspace_required', 'User is not assigned to a workspace.', 422);
        }

        $validated = $request->validate([
            'prompt'         => ['required', 'string', 'min:3', 'max:1000'],
            'aspect_ratio'   => ['nullable', 'string', 'in:9:16,1:1,16:9,4:5'],
            'animate'        => ['nullable', 'boolean'],
            'animation_tier' => ['nullable', 'string', 'in:quick,balanced,premium,seedance_lite,seedance_pro'],
            'scenes_count'   => ['nullable', 'integer', 'min:1', 'max:8'],
            // Visual source: AI images (default), stock footage, or audiogram.
            'visual_source'  => ['nullable', 'string', 'in:ai_images,stock_video,stock_images,waveform'],
            // References only affect the cost estimate at this stage.
            'source_image_asset_ids'   => ['nullable', 'array', 'max:4'],
            'source_image_asset_ids.*' => ['integer'],
            'character_ids'            => ['nullable', 'array', 'max:4'],
            'character_ids.*'          => ['integer'],
        ]);

        $sceneCount     = max(1, min(8, (int) ($validated['scenes_count'] ?? 1)));
        $promptText     = trim($validated['prompt']);

        // Resolve reference images (workspace-scoped) to signed URLs so the
        // PLANNER can SEE them — scene visuals then describe what the images
        // actually show (UI layout, the person's appearance) instead of
        // planning blind and only using them at generation time.
        $refUrls = [];
        $refAssetIds = array_filter($validated['source_image_asset_ids'] ?? []);
        $refCharIds  = array_filter($validated['character_ids'] ?? []);
        if (! empty($refAssetIds) || ! empty($refCharIds)) {
            $refAssets = collect();
            if (! empty($refAssetIds)) {
                $refAssets = $refAssets->merge(Asset::query()
                    ->whereIn('id', $refAssetIds)
                    ->where('workspace_id', $user->workspace_id)
                    ->where('asset_type', 'image')
                    ->get());
            }
            if (! empty($refCharIds)) {
                $chars = \App\Models\Character::query()
                    ->whereIn('id', $refCharIds)
                    ->where('workspace_id', $user->workspace_id)
                    ->with('referenceAsset')
                    ->get();
                foreach ($chars as $c) {
                    if ($c->referenceAsset) {
                        $refAssets->push($c->referenceAsset);
                    }
                }
            }
            $refUrls = $refAssets->unique('id')->take(4)
                ->map(fn ($a) => $this->plannerImageUrl($a))
                ->filter()->values()->all();
        }

        $parsed = app(\App\Services\Generation\OneShotPromptParser::class)
            ->parseMultiScene($promptText, $sceneCount, $refUrls);
        $hints = $parsed['hints'] ?? ['visual_source' => null, 'animate' => null];

        // Prompt-led inference with explicit overrides: a pill the user
        // touched (key present in the request) always wins; otherwise the
        // prompt's own cues decide ("make an audiogram…", "use stock
        // footage…", "no animation"), falling back to the defaults.
        $sourceProvided  = array_key_exists('visual_source', $validated) && $validated['visual_source'] !== null;
        $animateProvided = array_key_exists('animate', $validated) && $validated['animate'] !== null;
        $visualSource = $sourceProvided
            ? $validated['visual_source']
            : ($hints['visual_source'] ?? 'ai_images');
        $isAiVisuals    = $visualSource === 'ai_images';
        $hasReferences  = $isAiVisuals && (! empty($validated['source_image_asset_ids']) || ! empty($validated['character_ids']));
        // Animation only applies to AI-image scenes (it animates the still).
        $needsAnimation = $isAiVisuals && ($animateProvided
            ? (bool) $validated['animate']
            : ($hints['animate'] ?? true));
        $animationTier  = $validated['animation_tier'] ?? 'quick';

        // Stock matching + audiogram waveforms are included (0 cr) — only AI
        // image generation is billed per scene.
        $perSceneImageCost = $isAiVisuals
            ? ($hasReferences ? CreditService::AI_CHARACTER : CreditService::AI_MEDIUM)
            : 0;
        $perScene = $perSceneImageCost + CreditService::TTS
            + ($needsAnimation ? $this->animationTierCost($animationTier) : 0);

        return response()->json([
            'data' => [
                'plan' => [
                    'scenes'       => $parsed['scenes'],
                    'style'        => $parsed['style'],
                    'music_mood'   => $parsed['music_mood'],
                    'scenes_count' => $sceneCount,
                    'character_sheet' => $parsed['character_sheet'] ?? null,
                    'cast'         => $parsed['cast'] ?? [],
                ],
                'defaults' => [
                    'include_music'    => true,
                    'include_captions' => true,
                    'animate'          => $needsAnimation,
                ],
                // What the prompt implied — the wizard applies these to any
                // pill the user hasn't touched, so "make an audiogram of…"
                // just works without clicking the source picker.
                'resolved' => [
                    'visual_source'   => $visualSource,
                    'animate'         => $needsAnimation,
                    'source_inferred' => ! $sourceProvided && $hints['visual_source'] !== null,
                    'animate_inferred'=> ! $animateProvided && $hints['animate'] !== null,
                ],
                'estimate' => [
                    'with_music'    => ($perScene * $sceneCount) + CreditService::AI_MUSIC,
                    'without_music' => $perScene * $sceneCount,
                    'balance'       => (new CreditService())->balance((int) $user->workspace_id),
                ],
            ],
            'meta' => [],
        ]);
    }

    private function animationTierCost(string $tier): int
    {
        return match ($tier) {
            'premium'       => CreditService::VIDEO_PREMIUM,
            'balanced'      => CreditService::VIDEO_BALANCED,
            'seedance_pro'  => CreditService::VIDEO_SEEDANCE_PRO,
            'seedance_lite' => CreditService::VIDEO_SEEDANCE_LITE,
            default         => CreditService::VIDEO_QUICK,
        };
    }

    public function storeOneShot(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        if (! $user->workspace_id) {
            return $this->error('workspace_required', 'User is not assigned to a workspace.', 422);
        }

        $validated = $request->validate([
            'prompt'        => ['required', 'string', 'min:3', 'max:1000'],
            'title'         => ['nullable', 'string', 'max:200'],
            'aspect_ratio'  => ['nullable', 'string', 'in:9:16,1:1,16:9,4:5'],
            'animate'       => ['nullable', 'boolean'],
            'channel_id'    => ['nullable', 'integer', 'exists:channels,id'],
            // References (optional, multiple). Both arrays go through gpt-image-2
            // /edits when any are present — model handles up to 4 reference
            // images and uses them to anchor identity/composition. When BOTH
            // arrays are empty, we fall back to text-to-image (gpt-image-1).
            'source_image_asset_ids'   => ['nullable', 'array', 'max:4'],
            'source_image_asset_ids.*' => ['integer', 'exists:assets,id'],
            'character_ids'            => ['nullable', 'array', 'max:4'],
            'character_ids.*'          => ['integer', 'exists:characters,id'],
            // Legacy singles — keep accepting them for any callers that
            // haven't moved to the array form yet (older wizard build).
            'source_image_asset_id'    => ['nullable', 'integer', 'exists:assets,id'],
            'character_id'             => ['nullable', 'integer', 'exists:characters,id'],
            'animation_tier'           => ['nullable', 'string', 'in:quick,balanced,premium,seedance_lite,seedance_pro'],
            // Visual source: AI images (default), stock footage, or audiogram.
            'visual_source'            => ['nullable', 'string', 'in:ai_images,stock_video,stock_images,waveform'],
            // 1-8 scenes. 1 = instant demo, 3 = DTC ad shape, 8 = full Reel.
            'scenes_count'             => ['nullable', 'integer', 'min:1', 'max:8'],
            // Assistant toggles from the plan-approval step. Default ON to
            // preserve the legacy one-shot behaviour for callers that don't
            // send them.
            'include_music'            => ['nullable', 'boolean'],
            'include_captions'         => ['nullable', 'boolean'],
            // Approved (possibly user-edited) plan from planOneShot. When
            // present we skip the parser and build scenes from it directly,
            // so the user's tweaks aren't thrown away.
            'plan'                     => ['nullable', 'array'],
            'plan.scenes'              => ['nullable', 'array', 'min:1', 'max:8'],
            'plan.scenes.*.script'     => ['required_with:plan.scenes', 'string', 'max:1000'],
            'plan.scenes.*.visual'     => ['required_with:plan.scenes', 'string', 'max:2000'],
            'plan.scenes.*.motion'     => ['nullable', 'string', 'max:300'],
            'plan.scenes.*.characters'   => ['nullable', 'array', 'max:6'],
            'plan.scenes.*.characters.*' => ['string', 'max:60'],
            'plan.style'               => ['nullable', 'string', 'max:40'],
            'plan.music_mood'          => ['nullable', 'string', 'max:80'],
            'plan.character_sheet'     => ['nullable', 'string', 'max:500'],
            'plan.cast'                => ['nullable', 'array', 'max:6'],
            'plan.cast.*.name'         => ['required_with:plan.cast', 'string', 'max:60'],
            'plan.cast.*.appearance'   => ['nullable', 'string', 'max:500'],
        ]);

        $includeMusic    = (bool) ($validated['include_music'] ?? true);
        $includeCaptions = (bool) ($validated['include_captions'] ?? true);
        $providedScenes  = $validated['plan']['scenes'] ?? null;

        // Resolve references — flatten arrays + legacy singles into one
        // list of Asset rows scoped to this workspace. The list (possibly
        // empty) drives the image-gen path: empty -> text-to-image, any -> /edits.
        $assetIds = array_filter(array_merge(
            $validated['source_image_asset_ids'] ?? [],
            ! empty($validated['source_image_asset_id']) ? [$validated['source_image_asset_id']] : [],
        ));
        $characterIds = array_filter(array_merge(
            $validated['character_ids'] ?? [],
            ! empty($validated['character_id']) ? [$validated['character_id']] : [],
        ));

        $referenceAssets = collect();
        if (! empty($assetIds)) {
            $referenceAssets = $referenceAssets->merge(
                \App\Models\Asset::query()
                    ->whereIn('id', $assetIds)
                    ->where('workspace_id', $user->workspace_id)
                    ->where('asset_type', 'image')
                    ->get()
            );
        }
        if (! empty($characterIds)) {
            $characters = \App\Models\Character::query()
                ->whereIn('id', $characterIds)
                ->where('workspace_id', $user->workspace_id)
                ->with('referenceAsset')
                ->get();
            foreach ($characters as $c) {
                if ($c->referenceAsset) {
                    $referenceAssets->push($c->referenceAsset);
                }
            }
        }
        // De-duplicate (same character + uploaded photo could resolve to same
        // asset) and cap at 4 (OpenAI's hard limit per /edits call).
        $referenceAssets = $referenceAssets->unique('id')->take(4);
        $primaryCharacterId = $characterIds[0] ?? null;

        // Credit check up-front. Per-scene: image + TTS + (animate); plus
        // ONE music bed for the whole video (shared across scenes). Image
        // cost depends on path: AI_CHARACTER when refs present (gpt-image-2
        // /edits), AI_MEDIUM for text-to-image fallback.
        $visualSource = $validated['visual_source'] ?? 'ai_images';
        $isAiVisuals  = $visualSource === 'ai_images';
        // Animation animates a generated still — AI-image mode only. Same for
        // references (they steer image generation).
        $needsAnimation = $isAiVisuals && (bool) ($validated['animate'] ?? true);
        if (! $isAiVisuals) {
            $referenceAssets = collect();
            $primaryCharacterId = null;
        }
        $animationTier  = $validated['animation_tier'] ?? 'quick';
        $animationCost  = $this->animationTierCost($animationTier);
        $perSceneImageCost = $isAiVisuals
            ? ($referenceAssets->isNotEmpty() ? CreditService::AI_CHARACTER : CreditService::AI_MEDIUM)
            : 0; // stock matching + waveforms are included
        // Scene count follows the approved plan when one was sent, so an
        // edited plan (user added/removed a scene) costs the right amount.
        $sceneCount = is_array($providedScenes) && count($providedScenes) > 0
            ? count($providedScenes)
            : (int) ($validated['scenes_count'] ?? 1);
        $sceneCount = max(1, min(8, $sceneCount));
        $perScene = $perSceneImageCost + CreditService::TTS + ($needsAnimation ? $animationCost : 0);
        // Music is a shared, optional bed — only billed when the user kept it.
        $estimatedCost = ($perScene * $sceneCount) + ($includeMusic ? CreditService::AI_MUSIC : 0);

        $balance = (new CreditService())->balance((int) $user->workspace_id);
        if ($balance < $estimatedCost) {
            return $this->error(
                'insufficient_credits',
                "This one-shot needs about {$estimatedCost} credits. You have {$balance}.",
                402,
            );
        }

        $promptText  = trim($validated['prompt']);
        $aspectRatio = $validated['aspect_ratio'] ?? '9:16';
        $title       = $validated['title']
            ?? \Illuminate\Support\Str::limit($promptText, 60, '');

        // Use the approved plan verbatim when the wizard sent one (so the
        // user's edits survive); otherwise split the prompt via the parser.
        // For N=1 the parser short-circuits to the single-scene path.
        if (is_array($providedScenes) && count($providedScenes) > 0) {
            $planScenes = [];
            foreach (array_slice($providedScenes, 0, $sceneCount) as $s) {
                $planScenes[] = [
                    'script' => trim((string) ($s['script'] ?? '')),
                    'visual' => trim((string) ($s['visual'] ?? '')),
                    'motion' => trim((string) ($s['motion'] ?? '')),
                    'characters' => array_values(array_filter(array_map(
                        fn ($n) => trim((string) $n),
                        (array) ($s['characters'] ?? []),
                    ))),
                ];
            }
            $planStyle = trim((string) ($validated['plan']['style'] ?? ''));
            $parsed = [
                'scenes'     => $planScenes,
                'style'      => $planStyle !== '' ? $planStyle : 'photorealistic',
                'music_mood' => trim((string) ($validated['plan']['music_mood'] ?? '')) ?: 'calm cinematic ambient',
                'character_sheet' => trim((string) ($validated['plan']['character_sheet'] ?? '')) ?: null,
                'cast'       => $this->sanitizePlanCast($validated['plan']['cast'] ?? null),
            ];
        } else {
            // No approved plan sent — re-parse, letting the planner SEE the
            // reference images (already resolved + workspace-scoped above).
            $parsed = app(\App\Services\Generation\OneShotPromptParser::class)
                ->parseMultiScene(
                    $promptText,
                    $sceneCount,
                    $referenceAssets->map(fn ($a) => $this->plannerImageUrl($a))->filter()->values()->all(),
                );
        }

        // Scene/project typing per visual source. MatchVisualsJob reads
        // visual_generation_mode ('stock_images' => image montage, else clips).
        [$sceneVisualType, $generationMode] = match ($visualSource) {
            'stock_video'  => ['stock_clip', 'stock'],
            'stock_images' => ['stock_image', 'stock_images'],
            'waveform'     => ['waveform', 'waveform'],
            default        => ['ai_image', 'ai_images'],
        };

        $project = Project::query()->create([
            'workspace_id'        => $user->workspace_id,
            'created_by_user_id'  => $user->getKey(),
            'channel_id'          => $validated['channel_id'] ?? null,
            'name'                => $title,
            'aspect_ratio'        => $aspectRatio,
            'duration_seconds'    => 8 * $sceneCount,
            'visual_type'         => $sceneVisualType,
            'visual_generation_mode' => $generationMode,
            'ai_broll_style'      => $parsed['style'],
            'status'              => 'generating',
            'source_type'         => 'prompt',
            'source_content_raw'  => $promptText,
            // Cheap, no-LLM seed so the assistant knows the theme/style from
            // turn one. A refresh later can enrich it from the actual scenes.
            'assistant_brief_json' => app(\App\Services\CruiseControl\ProjectBriefService::class)
                ->seed($promptText, $parsed['style'], null),
            // Character board: canonical appearance for the recurring subject
            // (outfit, hair, accessories) — injected into every image prompt
            // so costume doesn't drift between scenes. Assistant/admin only.
            'character_board_json' => ! empty($parsed['character_sheet'])
                ? ['sheet' => $parsed['character_sheet'], 'source' => 'planner', 'updated_at' => now()->toIso8601String()]
                : null,
        ]);

        // Animation parameters — Balanced (Hailuo) needs 6 or 10; the rest
        // use 5 or 10.
        $animateDuration = $animationTier === 'balanced' ? 6 : 5;
        $referenceIdsArr = $referenceAssets->pluck('id')->all();
        $firstSceneId    = null;

        // Planner-detected cast (2+ distinct named people). Create/reuse one
        // auto-character per member (text appearance only — no reference face,
        // so consistency comes from the appended per-character description;
        // face-lock improves if a reference image is added later). Only for AI
        // visuals — stock/waveform scenes never get characters. When the
        // planner found no cast (b-roll, product, single subject) this is a
        // no-op and scenes keep their existing single-character behaviour.
        $castMap = []; // lowercase name => character_id
        if ($isAiVisuals && ! empty($parsed['cast'])) {
            foreach ($parsed['cast'] as $member) {
                $name = trim((string) ($member['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $appearance = trim((string) ($member['appearance'] ?? ''));
                $character = \App\Models\Character::query()
                    ->where('workspace_id', $user->workspace_id)
                    ->where('is_auto', true)
                    ->whereRaw('LOWER(name) = ?', [mb_strtolower($name)])
                    ->first();
                if (! $character) {
                    $character = \App\Models\Character::query()->create([
                        'workspace_id'       => $user->workspace_id,
                        'name'               => $name,
                        'description'        => $appearance,
                        'consistency_method' => 'reference_image',
                        'identity_strength'  => 'balanced',
                        'status'             => 'active',
                        'is_auto'            => true,
                        'created_by_user_id' => $user->getKey(),
                    ]);
                } elseif ($appearance !== '' && trim((string) $character->description) === '') {
                    $character->forceFill(['description' => $appearance])->save();
                }
                $castMap[mb_strtolower($name)] = (int) $character->getKey();
            }
        }

        // Create N scenes + dispatch image/animate per scene. Music dispatches
        // ONCE for the whole project (one bed across all scenes). TTS dispatches
        // once per project — GenerateTTSJob walks every scene with script_text.
        //
        // Staggered dispatch (delay = $i * 3s) spaces upstream calls so we
        // don't burst N parallel image-gen requests at OpenAI / Replicate.
        // Keeps multi-scene under OpenAI gpt-image-1 Tier-1 (~5 RPM) safely
        // at N≤8 and avoids Replicate prediction queueing under load.
        foreach ($parsed['scenes'] as $idx => $sceneDef) {
            $sceneOrder = $idx + 1;
            $imageToken = (string) \Illuminate\Support\Str::uuid();

            // Resolve this scene's named characters → character ids (cast map).
            $sceneCastIds = [];
            foreach ((array) ($sceneDef['characters'] ?? []) as $cn) {
                $cid = $castMap[mb_strtolower((string) $cn)] ?? null;
                if ($cid) {
                    $sceneCastIds[] = $cid;
                }
            }
            $sceneCastIds = array_values(array_unique($sceneCastIds));

            $scene = Scene::query()->create([
                'project_id'        => $project->getKey(),
                'scene_order'       => $sceneOrder,
                'scene_type'        => 'narration',
                'label'             => "Scene {$sceneOrder}",
                'script_text'       => $sceneDef['script'],
                'duration_seconds'  => 8,
                'voice_settings_json' => [
                    'voice_id'  => 'alloy',
                    'speed'     => 1.0,
                    'stability' => 'medium',
                ],
                // Captions are a render-time overlay, not a generation stage —
                // honouring the toggle is just flipping 'enabled'. The user
                // can still turn them on per-scene later in the editor.
                'caption_settings_json' => $includeCaptions ? [
                    'enabled'        => true,
                    'style_key'      => 'impact',
                    'highlight_mode' => 'keywords',
                    'position'       => 'bottom_third',
                    'font'           => 'Bebas Neue',
                    'highlight_color'=> '#ff6b35',
                ] : ['enabled' => false],
                'visual_type'   => $sceneVisualType,
                'visual_prompt' => $sceneDef['visual'],
                'visual_style'  => $parsed['style'],
                'status'        => 'draft',
                // Primary = first named character in the scene, else the
                // user-picked character. character_ids carries the full cast
                // (generation multi-paths only when >1).
                'character_id'  => $sceneCastIds[0] ?? $primaryCharacterId,
                'character_ids' => ! empty($sceneCastIds) ? $sceneCastIds : null,
            ]);

            $scene->forceFill([
                'image_generation_settings_json' => array_filter([
                    // in_progress only when an image JOB will actually run —
                    // a stale true on stock/waveform scenes would pin
                    // generation_pending (dashboard routing) forever.
                    'in_progress'             => $isAiVisuals,
                    'last_error'              => null,
                    'needs_visual'            => false,
                    'generation_token'        => $isAiVisuals ? $imageToken : null,
                    'generation_started_at'   => $isAiVisuals ? now()->toIso8601String() : null,
                    'reference_asset_ids'     => $isAiVisuals ? $referenceIdsArr : [],
                    'suggested_motion_prompt' => $sceneDef['motion'],
                    // Persist the generation plan: the progress view derives
                    // its stage list from these when reached WITHOUT the
                    // wizard's query params (dashboard re-entry).
                    'auto_animate'            => $needsAnimation,
                    'include_music'           => $includeMusic,
                    'visual_source'           => $visualSource,
                    // Audiogram scenes render the waveform at preview/export —
                    // seed the default look so the editor panel is populated.
                    'audiogram_style'         => $visualSource === 'waveform' ? 'bars' : null,
                    'audiogram_color'         => $visualSource === 'waveform' ? '#ff6b35' : null,
                ], fn ($v) => $v !== null),
            ])->save();

            if ($isAiVisuals) {
                \App\Jobs\GenerateAIImageJob::dispatch(
                    $scene->getKey(),
                    $project->getKey(),
                    $parsed['style'],
                    null,
                    $parsed['style'],
                    $imageToken,
                    $needsAnimation ? $animateDuration : null,
                    $needsAnimation ? $sceneDef['motion'] : null,
                    $needsAnimation ? $animationTier      : null,
                    null,
                    $referenceIdsArr,
                )->delay(now()->addSeconds($idx * 3));
            }

            $firstSceneId = $firstSceneId ?? $scene->getKey();
        }

        // Stock modes: one project-wide matcher fills every scene's visual
        // from Pexels (visual_generation_mode picks clips vs image montage).
        // Waveform needs no visual job at all — it renders from the voice.
        if (in_array($visualSource, ['stock_video', 'stock_images'], true)) {
            \App\Jobs\MatchVisualsJob::dispatch($project->getKey());
        }

        // TTS walks every scene with script_text (existing behavior).
        \App\Jobs\GenerateTTSJob::dispatch($project->getKey());

        // Music dispatches once with the first scene id as anchor — the
        // job attaches it to project.music_asset_id which is video-wide.
        // Skipped entirely when the user turned sounds off; the progress
        // view must also drop the ai_music stage (no_music flag) so it
        // doesn't wait on a stage that will never fire.
        if ($includeMusic) {
            \App\Jobs\GenerateAIMusicJob::dispatch(
                $firstSceneId,
                $project->getKey(),
                $parsed['music_mood'],
                $parsed['music_mood'],
                8,
            );
        }

        $project->refresh();
        return response()->json([
            'data' => [
                'project'    => $this->serializeProject($project),
                'one_shot'   => [
                    'estimated_cost'   => $estimatedCost,
                    'auto_animate'     => $needsAnimation,
                    'scene_id'         => $firstSceneId,
                    'scenes_count'     => $sceneCount,
                    'include_music'    => $includeMusic,
                    'include_captions' => $includeCaptions,
                ],
            ],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $project->delete();

        return response()->json([
            'data' => [
                'deleted' => true,
                'project_id' => $projectId,
            ],
            'meta' => [],
        ]);
    }

    /**
     * Re-dispatch jobs for scenes whose image-gen or animation failed
     * mid-pipeline. Common case: user submitted a multi-scene one-shot
     * just past their credit ceiling, X scenes succeeded, Y failed
     * because we under-estimated (or upstream costs hit a spike), they
     * topped up, and now they want to finish without clicking each
     * failed scene's Regenerate button manually.
     *
     * Idempotent: scenes that already succeeded are skipped. Pre-flight
     * estimates the cost of just the failed re-dispatch (not the whole
     * project) and 402s if the user still can't afford it.
     */
    public function resumeFailed(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        // Classify each scene's failure state. needs_image gets the full
        // image+animate chain back; needs_animate gets only the animate job
        // because image succeeded but i2v failed downstream.
        $needsImage = [];
        $needsAnimate = [];
        foreach ($scenes as $scene) {
            $cfg = $scene->image_generation_settings_json ?? [];
            $imageBroken =
                ! empty($cfg['needs_visual'])
                || (! empty($cfg['last_error']) && empty($scene->visual_asset_id));
            $animationBroken =
                ! empty($cfg['animation_last_error'])
                && empty($cfg['animation_video_asset_id']);
            if ($imageBroken) {
                $needsImage[] = $scene;
            } elseif ($animationBroken) {
                $needsAnimate[] = $scene;
            }
        }

        if (empty($needsImage) && empty($needsAnimate)) {
            return response()->json([
                'data' => [
                    'resumed' => 0,
                    'message' => 'No failed scenes — nothing to resume.',
                ],
                'meta' => [],
            ]);
        }

        // Cost estimate — same constants as storeOneShot uses up-front.
        // Animation on resume follows the PERSISTED per-scene plan
        // (auto_animate, stamped at creation): resuming an image-only project
        // used to hard-chain a quick animation onto every resumed scene —
        // unplanned video spend + "why is it animating?" Scenes without the
        // flag (older projects) resume WITHOUT animation; users can animate
        // manually from the editor. Tier defaults to quick (cheapest).
        $animationTier = 'quick';
        $animationCost = CreditService::VIDEO_QUICK;
        $chainCount = count(array_filter(
            $needsImage,
            fn ($scene) => ! empty(($scene->image_generation_settings_json ?? [])['auto_animate']),
        ));
        $estimatedCost = count($needsImage) * CreditService::AI_MEDIUM
            + $chainCount * $animationCost
            + count($needsAnimate) * $animationCost;

        $balance = (new CreditService())->balance((int) $user->workspace_id);
        if ($balance < $estimatedCost) {
            return $this->error(
                'insufficient_credits',
                "Resuming {$this->countWord(count($needsImage) + count($needsAnimate))} needs about {$estimatedCost} credits. You have {$balance}.",
                402,
            );
        }

        // Re-dispatch. Stagger 3s per scene so we don't burst upstream
        // (same pattern as storeOneShot's fan-out).
        $resumedCount = 0;
        $delaySec = 0;
        foreach ($needsImage as $scene) {
            $imageToken = (string) \Illuminate\Support\Str::uuid();
            $cfg = $scene->image_generation_settings_json ?? [];
            $referenceIds = $cfg['reference_asset_ids'] ?? [];
            $motionPrompt = $cfg['suggested_motion_prompt'] ?? null;
            // Chain animation ONLY when the original plan included it.
            $chainAnimate = ! empty($cfg['auto_animate']);
            $scene->forceFill([
                'image_generation_settings_json' => array_merge($cfg, [
                    'in_progress'           => true,
                    'last_error'            => null,
                    'needs_visual'          => false,
                    'generation_token'      => $imageToken,
                    'generation_started_at' => now()->toIso8601String(),
                ]),
            ])->save();
            \App\Jobs\GenerateAIImageJob::dispatch(
                $scene->getKey(),
                $project->getKey(),
                $scene->visual_style ?? $project->ai_broll_style ?? 'cinematic',
                null,
                $scene->visual_style ?? $project->ai_broll_style ?? 'cinematic',
                $imageToken,
                $chainAnimate ? 5 : null,            // animate duration (Wan quick = 5 or 10)
                $chainAnimate ? $motionPrompt : null,
                $chainAnimate ? $animationTier : null,
                null,
                $referenceIds,
            )->delay(now()->addSeconds($delaySec));
            $delaySec += 3;
            $resumedCount++;
        }
        foreach ($needsAnimate as $scene) {
            $cfg = $scene->image_generation_settings_json ?? [];
            $motionPrompt = $cfg['suggested_motion_prompt'] ?? null;
            \App\Jobs\AnimateSceneJob::dispatch(
                $scene->getKey(),
                $project->getKey(),
                $animationTier,
                5,
                $motionPrompt,
            )->delay(now()->addSeconds($delaySec));
            // Clear the prior error so the failure banner doesn't keep
            // showing the stale message while we retry.
            $scene->forceFill([
                'image_generation_settings_json' => array_merge($cfg, [
                    'animation_last_error' => null,
                ]),
            ])->save();
            $delaySec += 3;
            $resumedCount++;
        }

        // Reflect that the project is generating again so the editor's
        // status pills (and the dashboard's project card) update.
        $project->forceFill(['status' => 'generating'])->save();

        return response()->json([
            'data' => [
                'resumed'        => $resumedCount,
                'image_resumed'  => count($needsImage),
                'animate_resumed'=> count($needsAnimate),
                'estimated_cost' => $estimatedCost,
            ],
            'meta' => [],
        ]);
    }

    private function countWord(int $n): string
    {
        return $n . ' scene' . ($n === 1 ? '' : 's');
    }

    public function export(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $this->reconcileStaleExports((int) $project->getKey());

        $validated = $request->validate([
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'language' => ['nullable', 'string', 'max:16'],
            'watermark_enabled' => ['nullable', 'boolean'],
        ]);

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        if ($this->usageService->hasReachedExportLimit($user)) {
            $ctx = $this->usageService->exportLimitContext($user);
            return $this->limitError(
                'export_limit_reached',
                "You've used {$ctx['used']} of {$ctx['limit']} exports on the {$ctx['plan']} plan this month.",
                $ctx,
            );
        }

        if ($scenes->isEmpty()) {
            return $this->error('export_blocked', 'At least one scene is required before export.', 422);
        }

        $visualOptionalTypes = ['text_card', 'waveform'];

        foreach ($scenes as $scene) {
            if (trim((string) $scene->script_text) === '') {
                return $this->error('export_blocked', 'All scenes must have script content before export.', 422);
            }

            if (! $scene->visual_asset_id && ! in_array((string) $scene->visual_type, $visualOptionalTypes, true)) {
                return $this->error('export_blocked', 'Missing visual blocks export.', 422);
            }

            if (! data_get($scene->voice_settings_json, 'audio_asset_id')) {
                return $this->error('export_blocked', 'Missing voice blocks export.', 422);
            }
        }

        $aspectRatio = (string) ($validated['aspect_ratio'] ?? $project->aspect_ratio ?? '9:16');
        $language = (string) ($validated['language'] ?? $project->primary_language ?? 'en');
        $titleSlug = Str::slug((string) ($project->title ?: 'framecast-project'));

        $exportJob = ExportJob::query()->create([
            'workspace_id' => $project->workspace_id,
            'project_id' => $project->getKey(),
            'variant_id' => null,
            'aspect_ratio' => $aspectRatio,
            'language' => $language,
            'file_name' => "{$titleSlug}-{$aspectRatio}-{$language}.mp4",
            'watermark_enabled' => $this->shouldWatermark($project->workspace_id, (bool) ($validated['watermark_enabled'] ?? false)),
            'status' => 'queued',
            'progress_percent' => 0,
            'priority' => 0,
            'queued_at' => now(),
        ]);

        rescue(static function () use ($project, $exportJob): void {
            ExportProgressed::dispatch(
                (int) $project->getKey(),
                (int) $exportJob->getKey(),
                'queued',
                0,
                'Export queued.',
                (string) $exportJob->file_name,
                $exportJob->failure_reason
            );
        }, false);

        ProcessExportJob::dispatch((int) $exportJob->getKey());

        return response()->json([
            'data' => [
                'export_job' => [
                    'id' => $exportJob->getKey(),
                    'workspace_id' => $exportJob->workspace_id,
                    'project_id' => $exportJob->project_id,
                    'variant_id' => $exportJob->variant_id,
                    'aspect_ratio' => $exportJob->aspect_ratio,
                    'language' => $exportJob->language,
                    'file_name' => $exportJob->file_name,
                    'watermark_enabled' => $exportJob->watermark_enabled,
                    'status' => $exportJob->status,
                    'progress_percent' => $exportJob->progress_percent,
                    'failure_reason' => $exportJob->failure_reason,
                    'output_asset_id' => $exportJob->output_asset_id,
                    'priority' => $exportJob->priority,
                    'queued_at' => $exportJob->queued_at?->toIso8601String(),
                    'started_at' => $exportJob->started_at?->toIso8601String(),
                    'completed_at' => $exportJob->completed_at?->toIso8601String(),
                ],
            ],
            'meta' => [],
        ], 201);
    }

    public function update(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
            'aspect_ratio' => ['sometimes', 'nullable', 'string', 'in:9:16,1:1,16:9'],
            'channel_id' => ['sometimes', 'nullable', 'integer'],
            'brand_kit_id' => ['sometimes', 'nullable', 'integer'],
            'music_asset_id' => ['sometimes', 'nullable', 'integer'],
            'music_settings_json' => ['sometimes', 'nullable', 'array'],
            'music_settings_json.volume' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'music_settings_json.duck_volume' => ['sometimes', 'integer', 'min:0', 'max:100'],
            'music_settings_json.fade_in_ms' => ['sometimes', 'integer', 'min:0', 'max:5000'],
            'music_settings_json.loop' => ['sometimes', 'boolean'],
            'music_settings_json.duck_during_voice' => ['sometimes', 'boolean'],
        ]);

        if (array_key_exists('aspect_ratio', $validated)) {
            $existingAspectRatio = $project->aspect_ratio ? (string) $project->aspect_ratio : null;
            $requestedAspectRatio = $validated['aspect_ratio'] ? (string) $validated['aspect_ratio'] : null;

            if ($existingAspectRatio !== null && $requestedAspectRatio !== $existingAspectRatio) {
                return $this->error(
                    'aspect_ratio_locked',
                    'Aspect ratio is locked after project creation. Create a new project if you need a different format.',
                    422
                );
            }
        }

        if (array_key_exists('channel_id', $validated) && $validated['channel_id']) {
            $channelExists = Channel::query()
                ->whereKey($validated['channel_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();

            if (! $channelExists) {
                return $this->error('invalid_channel', 'Selected channel does not exist in this workspace.', 422);
            }
        }

        if (array_key_exists('brand_kit_id', $validated) && $validated['brand_kit_id']) {
            $brandKitExists = BrandKit::query()
                ->whereKey($validated['brand_kit_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();

            if (! $brandKitExists) {
                return $this->error('invalid_brand_kit', 'Selected brand kit does not exist in this workspace.', 422);
            }
        }

        if (array_key_exists('music_asset_id', $validated) && $validated['music_asset_id']) {
            $musicAssetExists = Asset::query()
                ->whereKey($validated['music_asset_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('asset_type', 'music')
                ->exists();

            if (! $musicAssetExists) {
                return $this->error('invalid_music_asset', 'Selected music track does not exist in this workspace.', 422);
            }
        }

        $project->fill($validated)->save();

        return response()->json([
            'data' => [
                'project' => $this->serializeProject($project->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function exports(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $this->reconcileStaleExports((int) $project->getKey());

        $exportJobs = ExportJob::query()
            ->where('project_id', $project->getKey())
            ->orderByDesc('id')
            ->limit(10)
            ->get();

        $outputAssetIds = $exportJobs
            ->pluck('output_asset_id')
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->values();

        /** @var Collection<int, Asset> $assetMap */
        $assetMap = Asset::query()
            ->whereIn('id', $outputAssetIds)
            ->get()
            ->keyBy('id');

        return response()->json([
            'data' => [
                'export_jobs' => $exportJobs
                    ->map(fn (ExportJob $exportJob): array => $this->serializeExportJob($exportJob, $assetMap))
                    ->all(),
            ],
            'meta' => [],
        ]);
    }

    /**
     * @return list<string>
     */
    private function allowedSourceTypes(): array
    {
        return [
            'blank',
            'prompt',
            'script',
            'url',
            'images',
            'product_description',
            'csv_topic',
            'audio_upload',
            'video_upload',
        ];
    }

    private function validateSourceContent(string $sourceType, ?string $source): ?string
    {
        // Blank projects have no source content — user builds scenes in the editor.
        if ($sourceType === 'blank') {
            return null;
        }

        $trimmed = trim((string) $source);

        if ($trimmed === '') {
            return 'Source content is required for the selected source type.';
        }

        if ($sourceType === 'url' && ! filter_var($trimmed, FILTER_VALIDATE_URL) && mb_strlen($trimmed) < 50) {
            return 'URL/article source requires a valid URL or at least 50 characters of article text.';
        }

        if (in_array($sourceType, ['prompt', 'script', 'product_description'], true) && mb_strlen($trimmed) < 10) {
            return 'Text source content must be at least 10 characters.';
        }

        return null;
    }

    /**
     * @param  mixed  $assetIds
     * @return list<int>|null
     */
    private function resolveSourceImageAssetIds(string $sourceType, mixed $assetIds, User $user, ?string $visualGenerationMode): ?array
    {
        if ($sourceType !== 'images') {
            return [];
        }

        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            is_array($assetIds) ? $assetIds : [],
        )));

        if ($ids === [] && $visualGenerationMode === 'ai_images') {
            return [];
        }

        if ($ids === [] || count($ids) > 15) {
            return null;
        }

        $foundIds = Asset::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('asset_type', 'image')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (count($foundIds) !== count($ids)) {
            return null;
        }

        return $ids;
    }

    private function normalizeSource(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', $source) ?? '');
    }

    /**
     * @param  Collection<int, Asset>  $assetMap
     * @return array<string, mixed>
     */
    private function serializeScene(Scene $scene, Collection $assetMap): array
    {
        $visualAsset = $scene->visual_asset_id ? $assetMap->get((int) $scene->visual_asset_id) : null;
        $audioAssetId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
        $audioAsset = $audioAssetId > 0 ? $assetMap->get($audioAssetId) : null;
        $soundAsset = $scene->sound_asset_id ? $assetMap->get((int) $scene->sound_asset_id) : null;

        return [
            'id' => $scene->getKey(),
            'project_id' => $scene->project_id,
            'scene_order' => $scene->scene_order,
            'scene_type' => $scene->scene_type,
            'label' => $scene->label,
            'script_text' => $scene->script_text,
            'duration_seconds' => $scene->duration_seconds,
            'voice_profile_id' => $scene->voice_profile_id,
            'voice_settings' => $scene->voice_settings_json,
            'voice_settings_json' => $scene->voice_settings_json,
            'caption_settings' => $scene->caption_settings_json,
            'caption_settings_json' => $scene->caption_settings_json,
            'visual_type' => $scene->visual_type,
            'visual_asset_id' => $scene->visual_asset_id,
            'character_id' => $scene->character_id,
            'visual_prompt' => $scene->visual_prompt,
            'visual_style' => $scene->visual_style,
            'image_generation_settings' => $this->normalizeImageGenerationSettings($scene),
            'image_generation_settings_json' => $this->normalizeImageGenerationSettings($scene),
            'motion_settings' => $scene->motion_settings_json,
            'motion_settings_json' => $scene->motion_settings_json,
            'transition_rule' => $scene->transition_rule,
            'status' => $scene->status,
            'locked_fields' => $scene->locked_fields_json,
            'locked_fields_json' => $scene->locked_fields_json,
            'sound_asset_id' => $scene->sound_asset_id,
            'visual_asset' => $visualAsset ? $this->serializeAsset($visualAsset) : null,
            'audio_asset' => $audioAsset ? $this->serializeAsset($audioAsset) : null,
            'sound_asset' => $soundAsset ? $this->serializeAsset($soundAsset) : null,
            'created_at' => $scene->created_at?->toIso8601String(),
            'updated_at' => $scene->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAsset(Asset $asset): array
    {
        $thumbnailUrl = trim((string) $asset->thumbnail_url);
        return [
            'id' => $asset->getKey(),
            'asset_type' => $asset->asset_type,
            'title' => $asset->title,
            'storage_url' => $this->assetUrl($asset),
            'thumbnail_url' => ($thumbnailUrl !== '' && (str_starts_with($thumbnailUrl, 'data:') || ! $this->isB2Url($thumbnailUrl))) ? $thumbnailUrl : null,
            'duration_seconds' => $asset->duration_seconds,
            'mime_type' => $asset->mime_type,
            'tags' => $asset->tags ?? [],
            'transcript_text' => $asset->transcript_text,
            'transcription_status' => $asset->transcription_status,
            'metadata_json' => $asset->metadata_json,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeImageGenerationSettings(Scene $scene): ?array
    {
        $settings = is_array($scene->image_generation_settings_json) ? $scene->image_generation_settings_json : null;

        if (! $settings) {
            return $settings;
        }

        if (
            $scene->visual_type === 'ai_image' &&
            $scene->visual_asset_id !== null &&
            (int) ($settings['asset_id'] ?? 0) === (int) $scene->visual_asset_id &&
            empty($settings['in_progress'])
        ) {
            $settings['needs_visual'] = false;
            $settings['last_error'] = null;
        }

        return $settings;
    }

    /**
     * URL an EXTERNAL fetcher (OpenAI vision) can download directly —
     * presigned B2, never the app's /media/assets proxy route. The api
     * container is single-threaded (artisan serve): while our request is
     * blocked waiting on OpenAI, OpenAI fetching the image back through the
     * app deadlocks and times out ("invalid_image_url").
     */
    private function plannerImageUrl(Asset $asset): ?string
    {
        $storageUrl = trim((string) $asset->storage_url);
        if ($storageUrl === '') {
            return null;
        }
        $storage = app(\App\Services\Media\StorageService::class);

        return $storage->extractPath($storageUrl) !== null
            ? $storage->url($storageUrl)
            : $storageUrl;
    }

    /**
     * Normalize a plan's cast (from the wizard) → list of {name, appearance}.
     * Only a 2+ named cast is meaningful; fewer collapses to [] so the
     * single-character / no-character paths stay unchanged.
     *
     * @return list<array{name: string, appearance: string}>
     */
    private function sanitizePlanCast(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $cast = [];
        $seen = [];
        foreach ($raw as $member) {
            if (! is_array($member)) {
                continue;
            }
            $name = trim((string) ($member['name'] ?? ''));
            $key = mb_strtolower($name);
            if ($name === '' || isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $cast[] = [
                'name'       => mb_substr($name, 0, 60),
                'appearance' => mb_substr(trim((string) ($member['appearance'] ?? '')), 0, 500),
            ];
        }

        return count($cast) >= 2 ? $cast : [];
    }

    private function assetUrl(Asset $asset): ?string
    {
        $storageUrl = trim((string) $asset->storage_url);

        if ($storageUrl === '') {
            return null;
        }

        if ($this->isB2Url($storageUrl) || $this->shouldProxyAudio($asset)) {
            return URL::temporarySignedRoute(
                'media.assets.content',
                now()->addHours(6),
                ['assetId' => $asset->getKey()],
            );
        }

        return $storageUrl;
    }

    private function isB2Url(string $url): bool
    {
        return app(StorageService::class)->isManagedUrl($url);
    }

    private function shouldProxyAudio(Asset $asset): bool
    {
        return (string) $asset->asset_type === 'audio'
            || str_starts_with((string) ($asset->mime_type ?? ''), 'audio/');
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeHookOption(ProjectHookOption $option): array
    {
        return [
            'id' => $option->getKey(),
            'project_id' => $option->project_id,
            'sort_order' => $option->sort_order,
            'hook_text' => $option->hook_text,
            'hook_score' => $option->hook_score,
            'hook_score_reason' => $option->hook_score_reason,
            'created_at' => $option->created_at?->toIso8601String(),
        ];
    }

    /**
     * @param Collection<int, Asset> $assetMap
     */
    private function serializeExportJob(ExportJob $exportJob, Collection $assetMap): array
    {
        $outputAsset = $exportJob->output_asset_id
            ? $assetMap->get((int) $exportJob->output_asset_id)
            : null;
        $status = $exportJob->status;
        $failureReason = $exportJob->failure_reason;

        if ($status === 'completed' && $outputAsset && $this->managedAssetMissing($outputAsset)) {
            $failureReason = 'Export output is missing from storage. Please export again.';
            $status = 'failed';
            $outputAsset = null;
            $this->markExportOutputMissing($exportJob, $failureReason);
        }

        return [
            'id' => $exportJob->getKey(),
            'workspace_id' => $exportJob->workspace_id,
            'project_id' => $exportJob->project_id,
            'variant_id' => $exportJob->variant_id,
            'aspect_ratio' => $exportJob->aspect_ratio,
            'language' => $exportJob->language,
            'file_name' => $exportJob->file_name,
            'watermark_enabled' => (bool) $exportJob->watermark_enabled,
            'status' => $status,
            'progress_percent' => (int) $exportJob->progress_percent,
            'failure_reason' => $failureReason,
            'priority' => (int) $exportJob->priority,
            'queued_at' => $exportJob->queued_at?->toIso8601String(),
            'started_at' => $exportJob->started_at?->toIso8601String(),
            'completed_at' => $exportJob->completed_at?->toIso8601String(),
            'output_asset' => $outputAsset ? $this->serializeAsset($outputAsset) : null,
        ];
    }

    private function shouldWatermark(int $workspaceId, bool $requested): bool
    {
        $workspace = \App\Models\Workspace::find($workspaceId);
        $planTier  = $workspace?->plan_tier ?? 'free';
        // Free plan always watermarks regardless of what the frontend sends
        if ($planTier === 'free') {
            return true;
        }
        return $requested;
    }

    private function reconcileStaleExports(int $projectId): void
    {
        ExportJob::query()
            ->where('project_id', $projectId)
            ->where('status', 'processing')
            ->whereNotNull('started_at')
            ->where('started_at', '<', now()->subMinutes(70))
            ->update([
                'status' => 'failed',
                'failure_reason' => 'Export worker stopped before completing this render.',
                'completed_at' => now(),
            ]);
    }

    private function managedAssetMissing(Asset $asset): bool
    {
        $storageUrl = trim((string) $asset->storage_url);

        if ($storageUrl === '') {
            return true;
        }

        $storage = app(StorageService::class);

        if (! $storage->isManagedUrl($storageUrl)) {
            return false;
        }

        return ! $storage->exists($storageUrl);
    }

    private function markExportOutputMissing(ExportJob $exportJob, string $failureReason): void
    {
        ExportJob::query()
            ->whereKey($exportJob->getKey())
            ->where('status', 'completed')
            ->update([
                'status' => 'failed',
                'failure_reason' => $failureReason,
                'output_asset_id' => null,
                'completed_at' => now(),
            ]);
    }

    /**
     * @return array{
     *     id:int,
     *     workspace_id:int,
     *     channel_id:?int,
     *     brand_kit_id:?int,
     *     template_id:?int,
     *     source_type:string,
     *     source_content_raw:?string,
     *     source_content_normalized:?string,
     *     content_goal:?string,
     *     platform_target:?string,
     *     duration_target_seconds:?int,
     *     aspect_ratio:?string,
     *     tone:?string,
     *     primary_language:?string,
     *     title:?string,
     *     script_text:?string,
     *     status:string,
     *     variants_count?:int,
     *     created_by_user_id:?int,
     *     created_at:?string,
     *     updated_at:?string
     * }
     */
    private function serializeProject(Project $project): array
    {
        return [
            'id' => $project->getKey(),
            'workspace_id' => $project->workspace_id,
            'channel_id' => $project->channel_id,
            'brand_kit_id' => $project->brand_kit_id,
            'template_id' => $project->template_id,
            'niche_id' => $project->niche_id,
            'source_type' => $project->source_type,
            'source_content_raw' => $project->source_content_raw,
            'source_content_normalized' => $project->source_content_normalized,
            'source_image_asset_ids' => $project->source_image_asset_ids,
            'visual_type' => $this->projectVisualTypeFromGenerationMode($project->visual_generation_mode),
            'visual_generation_mode' => $project->visual_generation_mode,
            'ai_broll_style' => $project->ai_broll_style,
            'image_generation_settings_json' => $project->waveform_settings_json,
            'waveform_settings_json' => $project->waveform_settings_json,
            'visual_style' => $project->default_visual_style,
            'default_visual_style' => $project->default_visual_style,
            'custom_visual_style' => $project->custom_visual_style,
            'voice_settings_json' => $project->default_voice_settings_json,
            'content_goal' => $project->content_goal,
            'platform_target' => $project->platform_target,
            'duration_target_seconds' => $project->duration_target_seconds,
            'aspect_ratio' => $project->aspect_ratio,
            'tone' => $project->tone,
            'default_voice_settings_json' => $project->default_voice_settings_json,
            'primary_language' => $project->primary_language,
            'title' => $project->title,
            'script_text' => $project->script_text,
            'status' => $project->status,
            'share_token' => $project->share_token,
            'is_shared'   => (bool) $project->is_shared,
            'share_url'   => $project->share_token
                ? rtrim((string) config('app.frontend_url'), '/') . '/sample/' . $project->share_token
                : null,
            'generation_status_json' => \App\Events\GenerationProgressed::getProgress($project->getKey()),
            'music_asset_id' => $project->music_asset_id,
            'music_settings_json' => $project->music_settings_json,
            'variants_count' => isset($project->variants_count) ? (int) $project->variants_count : 0,
            'created_by_user_id' => $project->created_by_user_id,
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }

    private function visualGenerationModeFromVisualType(?string $visualType): ?string
    {
        return match ($visualType) {
            'ai_image' => 'ai_images',
            'stock_image' => 'stock_images',
            'waveform' => 'waveform',
            'stock_clip' => 'stock',
            default => null,
        };
    }

    private function projectVisualTypeFromGenerationMode(?string $visualGenerationMode): ?string
    {
        return match ($visualGenerationMode) {
            'ai_images' => 'ai_image',
            'stock_images' => 'stock_image',
            'waveform' => 'waveform',
            'stock', null => 'stock_clip',
            default => 'stock_clip',
        };
    }

    protected function error(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
