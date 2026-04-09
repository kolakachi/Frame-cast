<?php

namespace App\Http\Controllers\Api\V1\Variant;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateVariantJob;
use App\Jobs\GenerateVariantSetJob;
use App\Jobs\ProcessExportJob;
use App\Models\Asset;
use App\Models\BatchJob;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\ProjectHookOption;
use App\Models\Scene;
use App\Models\User;
use App\Models\Variant;
use App\Models\VariantSet;
use App\Models\VoiceProfile;
use App\Services\VariantGeneration\VariantGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class VariantController extends Controller
{
    public function index(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = $this->resolveProject($projectId, $user);

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $variantSets = VariantSet::query()
            ->where('base_project_id', $project->getKey())
            ->with(['variants.derivedProject'])
            ->orderByDesc('id')
            ->get();

        $derivedProjectIds = $variantSets
            ->flatMap(fn (VariantSet $variantSet): Collection => $variantSet->variants->pluck('derived_project_id'))
            ->filter()
            ->map(static fn (mixed $id): int => (int) $id)
            ->values();

        $exportJobs = ExportJob::query()
            ->whereIn('project_id', $derivedProjectIds)
            ->orderByDesc('id')
            ->get()
            ->groupBy('variant_id');

        $outputAssetIds = $exportJobs
            ->flatten()
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
                'variant_sets' => $variantSets->map(
                    fn (VariantSet $variantSet): array => $this->serializeVariantSet($variantSet, $exportJobs, $assetMap)
                )->all(),
            ],
            'meta' => [],
        ]);
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = $this->resolveProject($projectId, $user);

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $validated = $request->validate([
            'generation_dimensions' => ['required', 'array'],
            'generation_dimensions.hook' => ['sometimes', 'array'],
            'generation_dimensions.hook.count' => ['sometimes', 'integer', 'min:2', 'max:5'],
            'generation_dimensions.voice' => ['sometimes', 'array'],
            'generation_dimensions.voice.voice_profile_ids' => ['sometimes', 'array', 'min:1'],
            'generation_dimensions.voice.voice_profile_ids.*' => ['integer'],
            'generation_dimensions.voice.provider_voice_keys' => ['sometimes', 'array', 'min:1'],
            'generation_dimensions.voice.provider_voice_keys.*' => ['string'],
            'generation_dimensions.visual' => ['sometimes', 'array'],
            'generation_dimensions.visual.enabled' => ['sometimes', 'boolean'],
            'generation_dimensions.format' => ['sometimes', 'array'],
            'generation_dimensions.format.aspect_ratios' => ['sometimes', 'array', 'min:1'],
            'generation_dimensions.format.aspect_ratios.*' => ['string', Rule::in(['9:16', '1:1', '16:9'])],
            'generation_dimensions.language' => ['sometimes', 'array'],
            'lock_rules_json' => ['sometimes', 'array'],
        ]);

        if (isset($validated['generation_dimensions']['language'])) {
            return $this->error('unsupported_dimension', 'Language variants ship in Phase 5. Use hook, voice, visual, or format for now.', 422);
        }

        $lockRules = array_merge([
            'brand_kit' => true,
            'template' => true,
            'scene_text' => false,
            'captions' => false,
        ], $validated['lock_rules_json'] ?? []);

        // Hook variants must be able to change scene text — silently override the lock.
        if (isset($validated['generation_dimensions']['hook'])) {
            $lockRules['scene_text'] = false;
        }

        $plan = $this->buildVariantPlan($project, $validated['generation_dimensions'], $user);

        if ($plan === null) {
            return $this->error('invalid_variant_dimensions', 'Select at least one supported variant dimension.', 422);
        }

        if (count($plan) > 20) {
            return $this->error('variant_limit_exceeded', 'Variant requests are limited to 20 per batch in the current build.', 422);
        }

        $variantSet = DB::transaction(function () use ($project, $validated, $lockRules, $user, $plan): VariantSet {
            $variantSet = VariantSet::query()->create([
                'base_project_id' => $project->getKey(),
                'generation_dimensions' => $validated['generation_dimensions'],
                'variant_count_requested' => count($plan),
                'lock_rules_json' => $lockRules,
                'status' => 'pending',
                'created_by_user_id' => $user->getKey(),
            ]);

            foreach ($plan as $variantConfig) {
                Variant::query()->create([
                    'variant_set_id' => $variantSet->getKey(),
                    'base_project_id' => $project->getKey(),
                    'derived_project_id' => null,
                    'variant_label' => $variantConfig['label'],
                    'changed_dimensions_json' => $variantConfig['changed_dimensions'],
                    'status' => 'pending',
                ]);
            }

            return $variantSet->fresh('variants');
        });

        GenerateVariantSetJob::dispatch((int) $variantSet->getKey());

        return response()->json([
            'data' => [
                'variant_set' => $this->serializeVariantSet($variantSet->load('variants'), collect(), collect()),
            ],
            'meta' => [],
        ], 201);
    }

    public function export(Request $request, int $variantSetId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $variantSet = VariantSet::query()
            ->whereKey($variantSetId)
            ->whereHas('baseProject', function ($query) use ($user): void {
                $query->where('workspace_id', $user->workspace_id);
            })
            ->with(['baseProject', 'variants'])
            ->first();

        if (! $variantSet || ! $variantSet->baseProject) {
            return $this->error('not_found', 'Variant set not found.', 404);
        }

        $validated = $request->validate([
            'variant_ids' => ['required', 'array', 'min:1'],
            'variant_ids.*' => ['required', 'integer', 'distinct'],
            'watermark_enabled' => ['sometimes', 'boolean'],
        ]);

        $variants = $variantSet->variants
            ->whereIn('id', $validated['variant_ids'])
            ->values();

        if ($variants->count() !== count($validated['variant_ids'])) {
            return $this->error('invalid_variant_scope', 'One or more variants do not belong to this variant set.', 422);
        }

        foreach ($variants as $variant) {
            if (! $variant->derived_project_id || $variant->status === 'failed') {
                return $this->error('variant_not_ready', 'Only ready variants can be exported.', 422);
            }
        }

        $batchJob = BatchJob::query()->create([
            'workspace_id' => $variantSet->baseProject->workspace_id,
            'job_type' => 'batch_export',
            'source_entity_type' => 'variant_set',
            'source_entity_id' => $variantSet->getKey(),
            'requested_count' => $variants->count(),
            'completed_count' => 0,
            'failed_count' => 0,
            'status' => 'queued',
            'failure_summary_json' => null,
            'created_by_user_id' => $user->getKey(),
        ]);

        $exportJobs = [];

        foreach ($variants as $variant) {
            $derivedProject = Project::query()->find($variant->derived_project_id);

            if (! $derivedProject) {
                continue;
            }

            $fileSlug = Str::slug((string) ($variantSet->baseProject->title ?: 'framecast-project'));
            $variantSlug = Str::slug((string) $variant->variant_label);

            $exportJob = ExportJob::query()->create([
                'workspace_id' => $derivedProject->workspace_id,
                'project_id' => $derivedProject->getKey(),
                'variant_id' => $variant->getKey(),
                'batch_job_id' => $batchJob->getKey(),
                'aspect_ratio' => (string) ($derivedProject->aspect_ratio ?: '9:16'),
                'language' => (string) ($derivedProject->primary_language ?: 'en'),
                'file_name' => "{$fileSlug}_{$variantSlug}_".date('Ymd').'.mp4',
                'watermark_enabled' => (bool) ($validated['watermark_enabled'] ?? false),
                'status' => 'queued',
                'progress_percent' => 0,
                'priority' => 0,
                'queued_at' => now(),
            ]);

            $variant->forceFill(['status' => 'queued'])->save();
            ProcessExportJob::dispatch((int) $exportJob->getKey());
            $exportJobs[] = $exportJob;
        }

        $batchJob->forceFill([
            'status' => $exportJobs === [] ? 'failed' : 'processing',
            'failed_count' => $exportJobs === [] ? $batchJob->requested_count : 0,
            'failure_summary_json' => $exportJobs === [] ? ['message' => 'No derived projects were available for export.'] : null,
        ])->save();

        return response()->json([
            'data' => [
                'batch_job' => $this->serializeBatchJob($batchJob->fresh()),
                'export_jobs' => array_map(fn (ExportJob $job): array => $this->serializeExportJob($job), $exportJobs),
            ],
            'meta' => [],
        ], 201);
    }

    private function resolveProject(int $projectId, User $user): ?Project
    {
        return Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
    }

    /**
     * @return list<array{label:string,changed_dimensions:array<string,mixed>}>|null
     */
    private function buildVariantPlan(Project $project, array $dimensions, User $user): ?array
    {
        $dimensionOptions = [];

        if (isset($dimensions['hook'])) {
            $count = (int) ($dimensions['hook']['count'] ?? 0);
            $hooks = ProjectHookOption::query()
                ->where('project_id', $project->getKey())
                ->orderBy('sort_order')
                ->limit(max(0, $count))
                ->get();

            if ($hooks->isEmpty()) {
                unset($dimensions['hook']);
            } else {
                $dimensionOptions['hook'] = $hooks->values()->map(
                    fn (ProjectHookOption $hook, int $index): array => [
                        'label' => 'Hook '.($index + 1),
                        'value' => [
                            'hook_text' => $hook->hook_text,
                            'sort_order' => $hook->sort_order,
                        ],
                    ]
                )->all();
            }
        }

        if (isset($dimensions['voice'])) {
            $providerKeys = collect($dimensions['voice']['provider_voice_keys'] ?? [])
                ->map(static fn (mixed $k): string => (string) $k)
                ->filter()
                ->unique()
                ->values();

            $voiceIds = collect($dimensions['voice']['voice_profile_ids'] ?? [])
                ->map(static fn (mixed $id): int => (int) $id)
                ->unique()
                ->values();

            $voices = VoiceProfile::query()
                ->where(function ($q) use ($voiceIds, $providerKeys): void {
                    if ($voiceIds->isNotEmpty()) {
                        $q->orWhereIn('id', $voiceIds);
                    }
                    if ($providerKeys->isNotEmpty()) {
                        $q->orWhereIn('provider_voice_key', $providerKeys);
                    }
                })
                ->where(function ($query) use ($user): void {
                    $query->whereNull('workspace_id')
                        ->orWhere('workspace_id', $user->workspace_id);
                })
                ->get();

            if ($voices->isEmpty() && $providerKeys->isNotEmpty()) {
                $dimensionOptions['voice'] = $providerKeys->values()->map(
                    static fn (string $providerKey): array => [
                        'label' => 'Voice '.ucfirst($providerKey),
                        'value' => [
                            'voice_profile_id' => null,
                            'provider_voice_key' => $providerKey,
                            'name' => ucfirst($providerKey),
                        ],
                    ]
                )->all();
            } elseif ($voices->isEmpty()) {
                return null;
            } else {
                $dimensionOptions['voice'] = $voices->values()->map(
                    fn (VoiceProfile $voice): array => [
                        'label' => 'Voice '.$voice->name,
                        'value' => [
                            'voice_profile_id' => $voice->getKey(),
                            'provider_voice_key' => $voice->provider_voice_key,
                            'name' => $voice->name,
                        ],
                    ]
                )->all();
            }
        }

        if ((bool) data_get($dimensions, 'visual.enabled', false)) {
            $dimensionOptions['visual'] = [[
                'label' => 'Visual Refresh',
                'value' => ['refresh' => true],
            ]];
        }

        if (isset($dimensions['format'])) {
            $aspectRatios = collect($dimensions['format']['aspect_ratios'] ?? [])
                ->map(static fn (mixed $ratio): string => (string) $ratio)
                ->unique()
                ->values();

            if ($aspectRatios->isEmpty()) {
                return null;
            }

            $dimensionOptions['format'] = $aspectRatios->map(
                fn (string $ratio): array => [
                    'label' => 'Format '.$ratio,
                    'value' => ['aspect_ratio' => $ratio],
                ]
            )->all();
        }

        if ($dimensionOptions === []) {
            return null;
        }

        return $this->crossProduct($dimensionOptions);
    }

    /**
     * @param  array<string, list<array{label:string,value:array<string,mixed>}>>  $dimensionOptions
     * @return list<array{label:string,changed_dimensions:array<string,mixed>}>
     */
    private function crossProduct(array $dimensionOptions): array
    {
        $results = [['label_parts' => [], 'changed_dimensions' => []]];

        foreach ($dimensionOptions as $dimension => $options) {
            $next = [];

            foreach ($results as $result) {
                foreach ($options as $option) {
                    $next[] = [
                        'label_parts' => [...$result['label_parts'], $option['label']],
                        'changed_dimensions' => [
                            ...$result['changed_dimensions'],
                            $dimension => $option['value'],
                        ],
                    ];
                }
            }

            $results = $next;
        }

        return array_map(
            static fn (array $result): array => [
                'label' => implode(' · ', $result['label_parts']),
                'changed_dimensions' => $result['changed_dimensions'],
            ],
            $results
        );
    }

    /**
     * @param Collection<int, Collection<int, ExportJob>> $exportJobsByVariant
     * @param Collection<int, Asset> $assetMap
     * @return array<string, mixed>
     */
    private function serializeVariantSet(VariantSet $variantSet, Collection $exportJobsByVariant, Collection $assetMap): array
    {
        return [
            'id' => $variantSet->getKey(),
            'base_project_id' => $variantSet->base_project_id,
            'generation_dimensions' => $variantSet->generation_dimensions,
            'variant_count_requested' => (int) $variantSet->variant_count_requested,
            'lock_rules' => $variantSet->lock_rules_json,
            'status' => $variantSet->status,
            'created_by_user_id' => $variantSet->created_by_user_id,
            'created_at' => $variantSet->created_at?->toIso8601String(),
            'updated_at' => $variantSet->updated_at?->toIso8601String(),
            'variants' => $variantSet->variants->map(function (Variant $variant) use ($exportJobsByVariant, $assetMap): array {
                $latestExport = $exportJobsByVariant->get($variant->getKey())?->first();

                return [
                    'id' => $variant->getKey(),
                    'variant_set_id' => $variant->variant_set_id,
                    'base_project_id' => $variant->base_project_id,
                    'derived_project_id' => $variant->derived_project_id,
                    'variant_label' => $variant->variant_label,
                    'changed_dimensions' => $variant->changed_dimensions_json,
                    'status' => $variant->status,
                    'derived_project' => $variant->derivedProject ? [
                        'id' => $variant->derivedProject->getKey(),
                        'title' => $variant->derivedProject->title,
                        'aspect_ratio' => $variant->derivedProject->aspect_ratio,
                        'primary_language' => $variant->derivedProject->primary_language,
                        'status' => $variant->derivedProject->status,
                    ] : null,
                    'latest_export_job' => $latestExport ? $this->serializeExportJob($latestExport, $assetMap) : null,
                    'created_at' => $variant->created_at?->toIso8601String(),
                    'updated_at' => $variant->updated_at?->toIso8601String(),
                ];
            })->values()->all(),
        ];
    }

    /**
     * @param Collection<int, Asset>|null $assetMap
     * @return array<string, mixed>
     */
    private function serializeExportJob(ExportJob $exportJob, ?Collection $assetMap = null): array
    {
        $outputAsset = $assetMap && $exportJob->output_asset_id
            ? $assetMap->get((int) $exportJob->output_asset_id)
            : null;

        return [
            'id' => $exportJob->getKey(),
            'workspace_id' => $exportJob->workspace_id,
            'project_id' => $exportJob->project_id,
            'variant_id' => $exportJob->variant_id,
            'batch_job_id' => $exportJob->batch_job_id,
            'aspect_ratio' => $exportJob->aspect_ratio,
            'language' => $exportJob->language,
            'file_name' => $exportJob->file_name,
            'status' => $exportJob->status,
            'progress_percent' => (int) $exportJob->progress_percent,
            'failure_reason' => $exportJob->failure_reason,
            'output_asset' => $outputAsset ? [
                'id' => $outputAsset->getKey(),
                'storage_url' => $outputAsset->storage_url,
                'mime_type' => $outputAsset->mime_type,
            ] : null,
            'queued_at' => $exportJob->queued_at?->toIso8601String(),
            'started_at' => $exportJob->started_at?->toIso8601String(),
            'completed_at' => $exportJob->completed_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeBatchJob(BatchJob $batchJob): array
    {
        return [
            'id' => $batchJob->getKey(),
            'workspace_id' => $batchJob->workspace_id,
            'job_type' => $batchJob->job_type,
            'source_entity_type' => $batchJob->source_entity_type,
            'source_entity_id' => $batchJob->source_entity_id,
            'requested_count' => (int) $batchJob->requested_count,
            'completed_count' => (int) $batchJob->completed_count,
            'failed_count' => (int) $batchJob->failed_count,
            'status' => $batchJob->status,
            'failure_summary' => $batchJob->failure_summary_json,
            'created_by_user_id' => $batchJob->created_by_user_id,
            'created_at' => $batchJob->created_at?->toIso8601String(),
            'updated_at' => $batchJob->updated_at?->toIso8601String(),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => [],
        ], $status);
    }

    public function retryFailed(Request $request, int $variantSetId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $variantSet = VariantSet::query()
            ->whereKey($variantSetId)
            ->whereHas('baseProject', function ($query) use ($user): void {
                $query->where('workspace_id', $user->workspace_id);
            })
            ->with(['variants', 'baseProject'])
            ->first();

        if (! $variantSet || ! $variantSet->baseProject) {
            return $this->error('not_found', 'Variant set not found.', 404);
        }

        $failedVariants = $variantSet->variants
            ->where('status', 'failed')
            ->values();

        if ($failedVariants->isEmpty()) {
            return $this->error('no_failed_variants', 'There are no failed variants to retry.', 422);
        }

        foreach ($failedVariants as $variant) {
            $variant->forceFill(['status' => 'pending'])->save();
            GenerateVariantJob::dispatch((int) $variant->getKey());
        }

        $variantSet->forceFill(['status' => 'generating'])->save();

        return response()->json([
            'data' => [
                'variant_set' => $this->serializeVariantSet($variantSet->fresh('variants'), collect(), collect()),
            ],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $variantId, VariantGenerationService $generationService): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $variant = Variant::query()
            ->whereKey($variantId)
            ->whereHas('baseProject', function ($query) use ($user): void {
                $query->where('workspace_id', $user->workspace_id);
            })
            ->with(['variantSet', 'derivedProject'])
            ->first();

        if (! $variant || ! $variant->variantSet) {
            return $this->error('not_found', 'Variant not found.', 404);
        }

        if (in_array($variant->status, ['pending', 'generating', 'queued'], true)) {
            return $this->error('variant_busy', 'Wait for this variant to finish before deleting it.', 422);
        }

        $variantSetId = (int) $variant->variant_set_id;

        DB::transaction(function () use ($variant): void {
            ExportJob::query()->where('variant_id', $variant->getKey())->delete();

            if ($variant->derived_project_id) {
                Scene::query()->where('project_id', $variant->derived_project_id)->delete();
                Project::query()->whereKey($variant->derived_project_id)->delete();
            }

            $variant->delete();
        });

        $remainingCount = Variant::query()->where('variant_set_id', $variantSetId)->count();

        if ($remainingCount === 0) {
            VariantSet::query()->whereKey($variantSetId)->delete();
        } else {
            $generationService->refreshVariantSetStatus($variantSetId);
        }

        return response()->json([
            'data' => [
                'deleted_variant_id' => $variantId,
                'variant_set_deleted' => $remainingCount === 0,
            ],
            'meta' => [],
        ]);
    }
}
