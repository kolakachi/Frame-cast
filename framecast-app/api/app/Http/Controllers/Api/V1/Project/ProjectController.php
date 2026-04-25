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
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    public function __construct(private readonly WorkspaceUsageService $usageService) {}

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

        return response()->json([
            'data' => [
                'projects' => $projects->map(fn (Project $project): array => [
                    ...$this->serializeProject($project),
                    'scenes_count' => (int) ($project->scenes_count ?? 0),
                    'variants_count' => (int) ($project->variants_count ?? 0),
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
            'visual_generation_mode' => ['nullable', Rule::in(['stock', 'ai_images'])],
            'ai_broll_style' => ['nullable', 'string', 'max:64'],
            'languages' => ['nullable', 'array', 'min:1'],
            'languages.*' => ['required', 'string', 'max:16'],
            'platform_target' => ['nullable', 'string', 'max:64'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'channel_id' => ['nullable', 'integer'],
            'template_id' => ['nullable', 'integer'],
            'brand_kit_id' => ['nullable', 'integer'],
            'niche_id' => ['nullable', 'integer'],
            'content_goal' => ['nullable', 'string', 'max:255'],
            'duration_target_seconds' => ['nullable', 'integer', 'min:5', 'max:600'],
            'tone' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:255'],
            'series_id' => ['nullable', 'integer'],
            'series_episode_number' => ['nullable', 'integer', 'min:1'],
        ]);

        $sourceError = $this->validateSourceContent($validated['source_type'], $validated['source_content_raw'] ?? null);

        if ($sourceError) {
            return $this->error('invalid_source_content', $sourceError, 422);
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

        if (! empty($validated['niche_id'])) {
            $niche = Niche::query()->find($validated['niche_id']);

            if ($niche) {
                // Inherit tone from niche if not explicitly set.
                if (empty($validated['tone'])) {
                    $nicheTone = $niche->default_voice_tone;
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

        $project = Project::query()->create([
            'workspace_id' => $user->workspace_id,
            'channel_id' => $channel?->getKey() ?? $series?->channel_id,
            'brand_kit_id' => $brandKitId,
            'template_id' => $template?->getKey(),
            'niche_id' => $niche?->getKey(),
            'music_asset_id' => $nicheMusicAssetId,
            'music_settings_json' => $nicheMusicAssetId ? ['volume' => 30, 'duck_volume' => 8, 'fade_in_ms' => 500, 'loop' => true, 'duck_during_voice' => true] : null,
            'source_type' => $validated['source_type'],
            'source_content_raw' => $validated['source_content_raw'] ?? null,
            'source_content_normalized' => $this->normalizeSource($validated['source_content_raw'] ?? ''),
            'source_image_asset_ids' => $sourceImageAssetIds,
            'visual_generation_mode' => $validated['visual_generation_mode'] ?? null,
            'ai_broll_style' => $validated['ai_broll_style'] ?? null,
            'content_goal' => $validated['content_goal'] ?? null,
            'platform_target' => $validated['platform_target'],
            'duration_target_seconds' => $validated['duration_target_seconds'] ?? null,
            'aspect_ratio' => $validated['aspect_ratio'],
            'tone' => $validated['tone'] ?? $nicheTone,
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
            GenerateAIImageJob::dispatch(
                $scene->getKey(),
                $scene->project_id,
                (string) ($scene->visual_style ?: 'cinematic'),
            );
        }

        return response()->json(['data' => ['project' => $this->serializeProject($project->fresh())], 'meta' => []]);
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
            'watermark_enabled' => (bool) ($validated['watermark_enabled'] ?? false),
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
            'visual_prompt' => $scene->visual_prompt,
            'visual_style' => $scene->visual_style,
            'image_generation_settings' => $scene->image_generation_settings_json,
            'image_generation_settings_json' => $scene->image_generation_settings_json,
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
            'visual_generation_mode' => $project->visual_generation_mode,
            'ai_broll_style' => $project->ai_broll_style,
            'content_goal' => $project->content_goal,
            'platform_target' => $project->platform_target,
            'duration_target_seconds' => $project->duration_target_seconds,
            'aspect_ratio' => $project->aspect_ratio,
            'tone' => $project->tone,
            'primary_language' => $project->primary_language,
            'title' => $project->title,
            'script_text' => $project->script_text,
            'status' => $project->status,
            'generation_status_json' => $project->generation_status_json,
            'music_asset_id' => $project->music_asset_id,
            'music_settings_json' => $project->music_settings_json,
            'variants_count' => isset($project->variants_count) ? (int) $project->variants_count : 0,
            'created_by_user_id' => $project->created_by_user_id,
            'created_at' => $project->created_at?->toIso8601String(),
            'updated_at' => $project->updated_at?->toIso8601String(),
        ];
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
