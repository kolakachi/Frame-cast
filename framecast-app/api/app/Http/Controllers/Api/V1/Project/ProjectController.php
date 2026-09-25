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
use App\Services\Projects\ProjectCreationException;
use App\Services\Projects\ProjectCreationService;
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
        private readonly ProjectCreationService $creation,
    ) {
    }

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
            // UGC takes live in their own listing on the UGC page — mixed in
            // here they read as duplicates and open the wrong surfaces.
            ->whereNull('visual_brief->ugc_format')
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
                // When the project last finished an export. Drives the
                // "Exported" pill on the dashboard + video cards, so a user can
                // tell at a glance which videos actually produced a file —
                // project.status alone can't: it reads "ready_for_review" both
                // before and after exporting.
                'exported_at' => DB::table('export_jobs')
                    ->whereColumn('export_jobs.project_id', 'projects.id')
                    ->where('export_jobs.status', 'completed')
                    ->selectRaw('max(completed_at)'),
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
                // When the project last finished an export. Drives the
                // "Exported" pill on the dashboard + video cards, so a user can
                // tell at a glance which videos actually produced a file —
                // project.status alone can't: it reads "ready_for_review" both
                // before and after exporting.
                'exported_at' => DB::table('export_jobs')
                    ->whereColumn('export_jobs.project_id', 'projects.id')
                    ->where('export_jobs.status', 'completed')
                    ->selectRaw('max(completed_at)'),
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

        $validated = $request->validate([
            'source_type' => ['required', Rule::in(ProjectCreationService::SOURCE_TYPES)],
            // Consent to pay for reading this PDF's scanned pages (see
            // /projects/analyze-pdf for the estimate the user was shown).
            'pdf_read_scanned' => ['sometimes', 'boolean'],
            'source_content_raw' => ['nullable', 'string'],
            'allow_script_edit' => ['nullable', 'boolean'],
            'source_image_asset_ids' => ['nullable', 'array', 'max:15'],
            'source_image_asset_ids.*' => ['integer'],
            'visual_type' => ['nullable', Rule::in(['stock_clip', 'stock_image', 'ai_image', 'waveform'])],
            'visual_generation_mode' => ['nullable', Rule::in(['stock', 'ai_images', 'stock_images', 'waveform', 'ai_video'])],
            // ai_video only: which i2v model animates each scene's still.
            'animate_tier' => ['nullable', Rule::in(['quick', 'balanced', 'premium', 'seedance_lite', 'seedance_pro', 'veo_fast', 'seedance_25'])],
            'animate_quality' => ['nullable', 'string', 'max:16'],
            'animation_pacing' => ['nullable', Rule::in(['short', 'long'])],
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

        try {
            ['project' => $project, 'channel' => $channel, 'brand_kit_id' => $brandKitId, 'template' => $template]
                = $this->creation->create($user, $validated);
        } catch (ProjectCreationException $e) {
            return $this->creationError($e);
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

        // Let the retry carry a CORRECTED source. Retrying the exact input
        // that just failed re-fails identically — watched happen in production:
        // a user pasted an Instagram URL (login-walled, unreadable), got a
        // failed project, retried the same URL, and got a second failed
        // project. The fix they needed was to change the input, and this is
        // the only place they can.
        $retryValidated = $request->validate([
            'source_content_raw' => ['nullable', 'string'],
            // Rescue for scanned PDFs: the failure message tells the user the
            // document is image-only; this lets the retry opt in to vision
            // without recreating the project.
            'pdf_read_scanned'   => ['sometimes', 'boolean'],
        ]);

        if (($retryValidated['pdf_read_scanned'] ?? false) && $project->source_type === 'pdf_upload') {
            $project->forceFill(['pdf_read_scanned' => true])->save();
        }

        if (isset($retryValidated['source_content_raw']) && trim($retryValidated['source_content_raw']) !== '') {
            $newSource   = $retryValidated['source_content_raw'];
            $sourceError = $this->validateSourceContent($project->source_type, $newSource);
            if ($sourceError) {
                return $this->error('invalid_source_content', $sourceError, 422);
            }
            $project->forceFill([
                'source_content_raw'        => $newSource,
                'source_content_normalized' => $this->normalizeSource($newSource),
            ])->save();
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
            'prompt'         => ['required', 'string', 'min:3', 'max:8000'],
            'aspect_ratio'   => ['nullable', 'string', 'in:9:16,1:1,16:9,4:5'],
            'animate'        => ['nullable', 'boolean'],
            // Must match storeOneShot's tiers — spokesperson included, else the
            // plan/estimate call 422s the moment the user picks Spokesperson.
            'animation_tier' => ['nullable', 'string', 'in:quick,balanced,premium,seedance_lite,seedance_pro,veo_fast,seedance_25,spokesperson'],
            'scenes_count'   => ['nullable', 'integer', 'min:1', 'max:8'],
            // The plan is written in the language the video will be in,
            // so what the user approves is what they get.
            'language'       => ['nullable', 'string', 'in:en,es,fr,de,pt,it,hi,ja,ar,zh'],
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
        $promptText .= "\n\n".app(\App\Services\Agency\ClientContext::class)->prompt((int) $user->workspace_id);

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
            ->parseMultiScene($promptText, $sceneCount, $refUrls, (string) ($validated['language'] ?? 'en'));
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
            ? ($hasReferences
                ? CreditService::AI_CHARACTER
                : app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor(null))
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
            'spokesperson'  => CreditService::VIDEO_SPOKESPERSON,
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
            'prompt'        => ['required', 'string', 'min:3', 'max:8000'],
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
            'animation_tier'           => ['nullable', 'string', 'in:quick,balanced,premium,seedance_lite,seedance_pro,veo_fast,seedance_25,spokesperson'],
            // Likeness consent — required when the spokesperson tier is picked,
            // since every scene of the run will make a face appear to speak.
            'consent'                  => ['nullable', 'boolean'],
            // Every other source type carried a language; the one-shot
            // path never did, so a prompt always produced an English
            // script and an English voiceover whatever the user picked.
            'language'                 => ['nullable', 'string', 'in:en,es,fr,de,pt,it,hi,ja,ar,zh'],
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
            'plan.scenes.*.voice_gender' => ['nullable', 'string', 'max:16'],
            'plan.style_explicit'      => ['nullable', 'boolean'],
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
        $isSpokesperson = $animationTier === 'spokesperson';

        // Every scene of a spokesperson run makes a face appear to speak, so
        // the attestation is taken once, up front, before anything is charged.
        if ($isSpokesperson && empty($validated['consent'])) {
            return response()->json([
                'error' => [
                    'code'    => 'consent_required',
                    'message' => 'Please confirm you have the rights and consent to make this person appear to speak.',
                    'context' => ['field' => 'consent'],
                ],
            ], 422);
        }

        $animationCost  = $this->animationTierCost($animationTier);
        $perSceneImageCost = $isAiVisuals
            ? ($referenceAssets->isNotEmpty()
                ? CreditService::AI_CHARACTER
                : app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor(null))
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
        $promptText .= "\n\n".app(\App\Services\Agency\ClientContext::class)->prompt((int) $user->workspace_id);
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
                    'voice_gender' => trim((string) ($s['voice_gender'] ?? 'neutral')),
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
                'style_explicit' => (bool) ($validated['plan']['style_explicit'] ?? false),
                'music_mood' => trim((string) ($validated['plan']['music_mood'] ?? '')) ?: 'calm cinematic ambient',
                'character_sheet' => trim((string) ($validated['plan']['character_sheet'] ?? '')) ?: null,
                'cast'       => $this->sanitizePlanCast($validated['plan']['cast'] ?? null),
            ];
        } else {
            // No approved plan sent — re-parse, letting the planner SEE the
            // reference images (already resolved + workspace-scoped above).
        // Language: explicit choice first, then the chosen channel's default,
        // then English. Scoped to the workspace so a channel id from elsewhere
        // cannot influence it.
        $oneShotLanguage = 'en';
        if (! empty($validated['channel_id'])) {
            $oneShotLanguage = \App\Models\Channel::query()
                ->where('workspace_id', $user->workspace_id)
                ->whereKey((int) $validated['channel_id'])
                ->value('default_language') ?: 'en';
        }

            $parsed = app(\App\Services\Generation\OneShotPromptParser::class)
                ->parseMultiScene(
                    $promptText,
                    $sceneCount,
                    $referenceAssets->map(fn ($a) => $this->plannerImageUrl($a))->filter()->values()->all(),
                    $oneShotLanguage,
                );
        }

        // Inherit a referenced character's dominant style (e.g. a 3D character
        // → 3D video) when the user didn't explicitly name a style — so the
        // generated video matches the character instead of defaulting realistic.
        if (! empty($characterIds) && empty($parsed['style_explicit'])) {
            $charStyle = \App\Models\Character::query()
                ->whereIn('id', $characterIds)
                ->where('workspace_id', $user->workspace_id)
                ->whereNotNull('style')
                ->value('style');
            if ($charStyle) {
                $parsed['style'] = $charStyle;
            }
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
            // `name` is not a column and not fillable, so this was silently
            // discarded and every one-shot project was created untitled.
            'title'               => $title,
            'aspect_ratio'        => $aspectRatio,
            // Likewise: the projects column is duration_target_seconds.
            'duration_target_seconds' => 8 * $sceneCount,
            'visual_type'         => $sceneVisualType,
            'visual_generation_mode' => $generationMode,
            'ai_broll_style'      => $parsed['style'],
            'status'              => 'generating',
            'source_type'         => 'prompt',
            // Falls back to the chosen channel's default before English, so a
            // German channel produces German videos from a bare prompt without
            // the user restating it every time.
            'primary_language'    => $validated['language'] ?? $oneShotLanguage,
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
                // Match the voice gender to the on-screen speaker the parser
                // inferred, so a male character doesn't get a female voice
                // (which makes lip-sync look wrong).
                'voice_settings_json' => [
                    'voice_id'  => \App\Services\Generation\TTS\GeminiVoices::defaultForGender($sceneDef['voice_gender'] ?? null),
                    'provider'  => 'google',
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
                    // Spokesperson lip-sync needs BOTH the image and the voice,
                    // so it can't chain off the image like i2v. Flag it; the
                    // image + TTS jobs fire GenerateTalkingVideoJob once both
                    // are ready (see GenerateTalkingVideoJob::maybeDispatchForScene).
                    'planned_spokesperson'    => ($needsAnimation && $isSpokesperson) ? true : null,
                    // Carries the up-front attestation onto every scene the run
                    // creates, so the editor doesn't re-ask and the record sits
                    // with the scene that used it.
                    'spokesperson_consent'    => ($needsAnimation && $isSpokesperson) ? [
                        'at'      => now()->toIso8601String(),
                        'user_id' => (int) $user->getKey(),
                    ] : null,
                    'include_music'           => $includeMusic,
                    'visual_source'           => $visualSource,
                    // Audiogram scenes render the waveform at preview/export —
                    // seed the default look so the editor panel is populated.
                    'audiogram_style'         => $visualSource === 'waveform' ? 'bars' : null,
                    'audiogram_color'         => $visualSource === 'waveform' ? '#ff6b35' : null,
                ], fn ($v) => $v !== null),
            ])->save();

            if ($isAiVisuals) {
                // Chain i2v animation onto the image — but NOT for spokesperson
                // (that path waits for the voice and fires separately).
                $chainAnim = $needsAnimation && ! $isSpokesperson;
                \App\Jobs\GenerateAIImageJob::dispatch(
                    $scene->getKey(),
                    $project->getKey(),
                    $parsed['style'],
                    null,
                    $parsed['style'],
                    $imageToken,
                    $chainAnim ? $animateDuration  : null,
                    $chainAnim ? $sceneDef['motion'] : null,
                    $chainAnim ? $animationTier      : null,
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
     * Duplicate a project within the same workspace. Reuses the workspace's
     * existing assets/characters (no file copy, no credit charge) — clones the
     * project row + its scenes only. A series episode's copy stays in the
     * series with the next episode number; its episode summary is cleared (a
     * fresh copy hasn't earned any memory yet).
     */
    public function duplicate(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $source = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $source) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $scenes = Scene::query()
            ->where('project_id', $source->getKey())
            ->orderBy('scene_order')
            ->get();

        $clone = DB::transaction(function () use ($source, $scenes, $user): Project {
            $project = $source->replicate([
                'current_revision_id', 'family_id', 'share_token', 'is_shared',
            ]);
            $project->created_by_user_id = $user->getKey();
            $project->current_revision_id = null;
            $project->family_id = null;
            $project->share_token = null;
            $project->is_shared = false;
            $project->series_episode_summary = null;
            $project->status = 'ready_for_review';
            $project->title = mb_substr(trim(((string) ($source->title ?: 'Untitled')).' (copy)'), 0, 255);

            // Series episodes keep their series but take the next episode number.
            if ($source->series_id) {
                $project->series_episode_number = (int) (Project::query()
                    ->where('series_id', $source->series_id)
                    ->max('series_episode_number') ?? 0) + 1;
            }
            $project->save();

            foreach ($scenes as $scene) {
                $copy = $scene->replicate();
                $copy->project_id = $project->getKey();
                $copy->save();
            }

            return $project;
        });

        return response()->json([
            'data' => ['project' => $this->serializeProject($clone)],
            'meta' => [],
        ], 201);
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
        $estimatedCost = count($needsImage) * app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor(null)
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
            'initial' => ['sometimes', 'boolean'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '4:5', '16:9'])],
            'aspect_ratios' => ['nullable', 'array', 'max:4'],
            'aspect_ratios.*' => [Rule::in(['9:16', '1:1', '4:5', '16:9'])],
            'language' => ['nullable', 'string', 'max:16'],
            'watermark_enabled' => ['nullable', 'boolean'],
        ]);

        if (! empty($validated['initial'])) {
            if (! $project->usesAutomaticFinish()) {
                return $this->error('automatic_finish_unavailable', 'This project uses the editor and manual export flow.', 422);
            }
            $job = app(\App\Services\Export\ProjectExportService::class)->finishInitial($project);
            return response()->json(['data' => ['export_job' => $job ? $this->serializeExportJob($job, Asset::query()->whereKey($job->output_asset_id)->get()->keyBy('id')) : null], 'meta' => []], $job ? 200 : 202);
        }

        try {
            [$jobs, $skipped] = DB::transaction(function () use ($project, $validated, $user) {
                \App\Models\Workspace::whereKey($project->workspace_id)->lockForUpdate()->firstOrFail();
                $requested = array_values(array_unique($validated['aspect_ratios'] ?? [$validated['aspect_ratio'] ?? $project->aspect_ratio ?? '9:16']));
                if (! $requested) $requested = [$project->aspect_ratio ?: '9:16'];
                $remaining = $this->usageService->exportsRemaining($user);
                $inFlight = ExportJob::where('workspace_id', $project->workspace_id)->whereIn('status', ['queued', 'processing'])->count();
                $available = $remaining === null ? count($requested) : max(0, $remaining - $inFlight);
                $skipped = array_slice($requested, $available);
                $requested = array_slice($requested, 0, $available);
                if (! $requested) throw new \RuntimeException('Your export allowance is used or reserved by exports already in progress.', 402);
                $jobs = [];
                foreach ($requested as $ratio) {
                    $jobs[] = app(\App\Services\Export\ProjectExportService::class)->queue($project, [
                        'aspect_ratio' => $ratio,
                        'language' => $validated['language'] ?? $project->primary_language ?? 'en',
                        'watermark_enabled' => $validated['watermark_enabled'] ?? false,
                    ]);
                }
                return [$jobs, $skipped];
            });
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 402) return $this->limitError('export_limit_reached', $e->getMessage(), $this->usageService->exportLimitContext($user));
            return $this->error('export_blocked', $e->getMessage(), 422);
        }
        $payloads = array_map(fn ($job) => $this->exportJobPayload($job), $jobs);
        return response()->json(['data' => ['export_job' => $payloads[0], 'export_jobs' => $payloads,
            'skipped_aspect_ratios' => $skipped], 'meta' => []], 201);
    }

    /** @return array<string,mixed> */
    private function exportJobPayload(ExportJob $exportJob): array
    {
        return [
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
        ];
    }

    /**
     * On-demand hook generation (C9). Re-rolls the project's ranked hook options
     * from its script. GenerateHooksJob replaces the existing options and chains
     * ScoreHooksJob to rank them — LLM text (included tier), no credit charge.
     */
    public function generateHooks(Request $request, int $projectId): JsonResponse
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

        if (trim((string) $project->script_text) === '') {
            return $this->error('script_required', 'Generate or write a script before generating hook options.', 422);
        }

        \App\Jobs\GenerateHooksJob::dispatch((int) $project->getKey());

        return response()->json([
            'data' => ['status' => 'queued'],
            'meta' => [],
        ], 202);
    }

    public function exportFreshness(Request $request, int $projectId, int $exportId): JsonResponse
    {
        $project = Project::query()->where('workspace_id', $request->user()->workspace_id)->find($projectId);
        $export = $project ? ExportJob::query()->where('project_id', $projectId)
            ->where('workspace_id', $request->user()->workspace_id)->find($exportId) : null;
        if (! $project || ! $export) return $this->error('not_found', 'Export not found.', 404);
        if ($export->status !== 'completed' || ! $export->output_asset_id) {
            return $this->error('export_unavailable', 'This exported video is not available. Update the video first.', 422);
        }
        return response()->json(['data' => app(\App\Services\Export\ExportFreshnessService::class)->check($project, $export), 'meta' => []]);
    }

    public function editorOpened(Request $request, int $projectId): JsonResponse
    {
        return DB::transaction(function () use ($request, $projectId): JsonResponse {
            $project = Project::query()->whereKey($projectId)
                ->where('workspace_id', $request->user()->workspace_id)->lockForUpdate()->first();
            if (! $project) return $this->error('not_found', 'Project not found.', 404);
            $brief = $project->visual_brief ?? [];
            if ($project->usesAutomaticFinish() && empty($brief['editor_opened_at'])) {
                $project->forceFill(['visual_brief' => [
                    ...$brief, 'editor_opened_at' => now()->toIso8601String(),
                ]])->save();
            }
            return response()->json(['data' => ['editor_opened' => true], 'meta' => []]);
        });
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


    private function validateSourceContent(string $sourceType, ?string $source): ?string
    {
        return $this->creation->validateSourceContent($sourceType, $source);
    }


    private function normalizeSource(string $source): string
    {
        return $this->creation->normalizeSource($source);
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
                now()->addMinutes((int) config('media.signed_url_ttl_minutes', 720)),
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
            'download_url' => $outputAsset ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                'media.assets.content', now()->addMinutes((int) config('media.signed_url_ttl_minutes', 720)),
                ['assetId' => $outputAsset->getKey(), 'download' => 1],
            ) : null,
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
    /**
     * When this project last completed an export, or null if it never has.
     *
     * List endpoints select this as a subquery (no N+1). Single-project
     * endpoints don't, so fall back to one direct lookup rather than reporting
     * "never exported" just because the column wasn't selected.
     */
    private function projectExportedAt(Project $project): ?string
    {
        $value = array_key_exists('exported_at', $project->getAttributes())
            ? $project->getAttributes()['exported_at']
            : \App\Models\ExportJob::query()
                ->where('project_id', $project->getKey())
                ->where('status', 'completed')
                ->max('completed_at');

        if (! $value) {
            return null;
        }

        return \Illuminate\Support\Carbon::parse($value)->toIso8601String();
    }

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
            // The UGC surfaces key off this (whole-video takes hide the
            // editor; generic progress redirects to the run screen).
            'visual_brief' => $project->visual_brief,
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
            'exported_at' => $this->projectExportedAt($project),
            'has_export'  => $this->projectExportedAt($project) !== null,
            'created_by_user_id' => $project->created_by_user_id,
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
    }


    private function projectVisualTypeFromGenerationMode(?string $visualGenerationMode): ?string
    {
        return match ($visualGenerationMode) {
            'ai_images', 'ai_video' => 'ai_image',
            'stock_images' => 'stock_image',
            'waveform' => 'waveform',
            'stock', null => 'stock_clip',
            default => 'stock_clip',
        };
    }

    private function creationError(ProjectCreationException $e): JsonResponse
    {
        if ($e->isLimit) {
            return $this->limitError($e->errorCode, $e->getMessage(), $e->context, $e->status);
        }
        if ($e->context !== []) {
            return response()->json(['error' => ['code' => $e->errorCode, 'message' => $e->getMessage(), 'context' => $e->context]], $e->status);
        }

        return $this->error($e->errorCode, $e->getMessage(), $e->status);
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
