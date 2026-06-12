<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Services\CreditService;
use App\Models\Asset;
use App\Models\Scene;
use App\Services\CruiseControl\CruiseActionRunService;
use App\Services\Generation\Image\ImageGenerationAdapter;
use App\Services\Media\StorageService;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateAIImageJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public int $tries = 2;
    // Per-scene gpt-image-2 /edits (character path) takes 30-90s for the
    // OpenAI call alone, plus storage + DB ops. The previous 120s ceiling was
    // hitting jobs at 117-130s range and the worker process was being killed
    // mid-run with exitCode=1, orphaning the scene's in_progress flag and
    // restarting the whole worker container. 300s gives gpt-image-2 a healthy
    // ceiling without letting truly hung jobs sit forever.
    public int $timeout = 300;

    public function __construct(
        public readonly int $sceneId,
        public readonly int $projectId,
        public readonly string $style = 'cinematic',
        public readonly ?string $promptOverride = null,
        public readonly ?string $visualStyle = null,
        public readonly ?string $generationToken = null,
        // One-shot chain: when set, dispatches AnimateSceneJob on success so
        // the user's "Animate the image" toggle actually animates. Was a
        // gap before — storeOneShot only dispatched image+tts+music and the
        // animation never ran (no AnimateSceneJob anywhere in the pipeline).
        public readonly ?int $chainAnimateAfterSeconds = null,
        public readonly ?string $chainAnimateMotionPrompt = null,
        public readonly ?string $chainAnimateTier = null,
        // Model picker key (see ImageAdapterFactory::AVAILABLE). When null,
        // the DI-injected default (DalleImageAdapter / gpt-image-1) wins so
        // existing callers without the picker keep working.
        public readonly ?string $modelKey = null,
        // One-shot multi-reference: when set, GenerateAIImageJob routes
        // through CharacterImageAdapter with these references passed as
        // image[] parts on /v1/images/edits. Up to 4 (OpenAI cap). Wins
        // over the scene.character_id auto-route.
        public readonly array $referenceAssetIds = [],
    ) {
        $this->onQueue('visual');
    }

    public function handle(ImageGenerationAdapter $adapter): void
    {
        $scene = Scene::query()->with(['project', 'character.referenceAsset'])->find($this->sceneId);

        if (! $scene) {
            return;
        }

        GenerationProgressed::dispatch($this->projectId, 'ai_image', 'processing', null, [
            'scene_id' => $this->sceneId,
        ]);

        try {
            // Character path: when the scene is bound to a character with a reference image,
            // route to CharacterImageAdapter (gpt-image-2 /edits) so the generated face
            // matches the reference. Otherwise use the injected default adapter (DALL-E today).
            $useCharacterRef = $scene->character_id
                && $scene->character?->reference_asset_id
                && $scene->character?->referenceAsset;

            // Reserve credits UP-FRONT (atomic) so an image can't be generated
            // for free when the balance has run out. Reserve the character rate
            // when a character/reference path will be attempted; if it falls
            // back to DALL-E (cheaper) we refund the difference after success.
            // A failure refunds the whole reservation (see catch()).
            $expectsCharacter = $useCharacterRef || ! empty($this->referenceAssetIds);
            // Single cost source of truth. Reference work defaults to nano-banana
            // (its cheaper rate) unless the user explicitly picked gpt-image-2.
            $factory = app(\App\Services\Generation\Image\ImageAdapterFactory::class);
            $reserved = $expectsCharacter
                ? $factory->referenceGenerationCost($this->modelKey)
                : $factory->generationCost($this->modelKey, false);
            $cogsKey  = $expectsCharacter
                ? $factory->referenceCogsKey($this->modelKey)
                : $factory->cogsKey($this->modelKey, false);
            $charged = app(CreditService::class)->deduct(
                (int) $scene->project->workspace_id,
                $reserved,
                $expectsCharacter ? 'ai_image:character' : 'ai_image:manual',
                [
                    'project_id' => $this->projectId,
                    'scene_id'   => $this->sceneId,
                    'user_id'    => $scene->project->created_by_user_id,
                    'upstream_cost_usd' => CreditService::cogsUsd($cogsKey),
                    'metadata'   => ['style' => $this->style, 'reserved' => true, 'model_key' => $this->modelKey],
                ],
            );
            if (! $charged) {
                $scene->forceFill([
                    'image_generation_settings_json' => array_merge(
                        $scene->image_generation_settings_json ?? [],
                        [
                            'in_progress'      => false,
                            'needs_visual'     => true,
                            'last_error'       => 'Not enough credits to generate this image.',
                            'generation_token' => $this->generationToken,
                        ],
                    ),
                ])->save();
                GenerationProgressed::dispatch($this->projectId, 'ai_image', 'failed', 'Not enough credits to generate this image.', ['scene_id' => $this->sceneId]);
                app(CruiseActionRunService::class)->markStageFailed($this->projectId, 'ai_image', 'Not enough credits to generate this image.', $this->sceneId);

                return;
            }

            $prompt = $this->buildPrompt($scene, ! $useCharacterRef);

            // Content-safety catch-all: screen the final prompt before we call
            // any generator. Covers every path (Cruise / one-shot / project /
            // chained) — not just the editor's pre-flight check. On a block we
            // refund the reservation and fail the scene cleanly (no spend).
            $safetyBlock = app(\App\Services\Moderation\ContentSafetyService::class)->screenText($prompt, [
                'workspace_id' => (int) $scene->project->workspace_id,
                'project_id'   => $this->projectId,
                'scene_id'     => $this->sceneId,
                'operation'    => 'ai_image',
            ]);
            if ($safetyBlock) {
                app(CreditService::class)->refund((int) $scene->project->workspace_id, $reserved, 'ai_image:blocked');
                $scene->forceFill([
                    'image_generation_settings_json' => array_merge($scene->image_generation_settings_json ?? [], [
                        'in_progress'      => false,
                        'needs_visual'     => true,
                        'last_error'       => $safetyBlock,
                        'generation_token' => $this->generationToken,
                    ]),
                ])->save();
                GenerationProgressed::dispatch($this->projectId, 'ai_image', 'failed', $safetyBlock, ['scene_id' => $this->sceneId]);
                app(CruiseActionRunService::class)->markStageFailed($this->projectId, 'ai_image', $safetyBlock, $this->sceneId);

                return;
            }

            $aspectRatio = $scene->project->aspect_ratio ?? '9:16';

            $scene->loadMissing('project');
            $project = $scene->project;

            $options = [
                'usage_context' => [
                    'workspace_id' => $project?->workspace_id,
                    'project_id' => $this->projectId,
                    'user_id' => $project?->created_by_user_id,
                    'scene_id' => $this->sceneId,
                    'style' => $this->style,
                ],
                // When the user picked Custom in the editor, the descriptor
                // lives on the scene (per-scene override) or the project
                // (project default). Scene wins if both are present.
                'custom_style' => $scene->custom_visual_style
                    ?: $project?->custom_visual_style
                    ?: null,
            ];

            // Multi-reference path: explicit referenceAssetIds (one-shot wizard)
            // OR, for a multi-character scene, every cast member's reference
            // image — so two named people in one shot each bring their face.
            $effectiveRefIds = $this->referenceAssetIds;
            if (empty($effectiveRefIds)) {
                $castIds = is_array($scene->character_ids) ? array_values(array_filter($scene->character_ids)) : [];
                if (count($castIds) > 1) {
                    $effectiveRefIds = \App\Models\Character::query()
                        ->whereIn('id', $castIds)
                        ->whereNotNull('reference_asset_id')
                        ->pluck('reference_asset_id')
                        ->map(fn ($i) => (int) $i)->filter()->values()->all();
                }
            }
            if (! empty($effectiveRefIds)) {
                $refAssets = \App\Models\Asset::query()
                    ->whereIn('id', $effectiveRefIds)
                    ->where('asset_type', 'image')
                    ->get();
                $urls = [];
                foreach ($refAssets as $a) {
                    $signed = $this->signedReferenceUrl($a);
                    if ($signed) $urls[] = $signed;
                }
                if (! empty($urls)) {
                    $options['reference_image_urls'] = array_slice($urls, 0, 4);
                    $options['quality'] = 'high';
                    try {
                        $result = $this->referenceAdapter()
                            ->generate($prompt, $this->style, $aspectRatio, $options);
                    } catch (\Throwable $e) {
                        \Illuminate\Support\Facades\Log::warning('GenerateAIImageJob: multi-reference adapter failed, falling back to text-to-image', [
                            'scene_id'      => $this->sceneId,
                            'reference_ct'  => count($urls),
                            'error'         => $e->getMessage(),
                        ]);
                        unset($options['reference_image_urls']);
                    }
                }
            }

            if ($useCharacterRef && ! isset($result)) {
                $referenceUrl = $this->signedReferenceUrl($scene->character->referenceAsset);
                if ($referenceUrl) {
                    $options['reference_image_url'] = $referenceUrl;
                    // Identity strength → gpt-image-2 quality knob. Stronger identity
                    // preservation correlates with higher render quality (more compute
                    // spent on the reference). Subtle keeps it cheap and lets the model
                    // drift toward the prompt; Locked spends more to nail the face.
                    $strength = $scene->character->identity_strength ?? 'balanced';
                    $options['quality'] = match ($strength) {
                        'subtle' => 'medium',
                        'locked' => 'high',
                        default  => 'high', // balanced + strong → high
                    };
                    try {
                        $result = $this->referenceAdapter()
                            ->generate($prompt, $this->style, $aspectRatio, $options);
                    } catch (\Throwable $e) {
                        // Character adapter failed — log and fall through to DALL-E with the
                        // character description baked into the prompt so the user still gets
                        // *an* image. Common cause: reference URL expired, content-policy reject.
                        \Illuminate\Support\Facades\Log::warning('GenerateAIImageJob: character adapter failed, falling back to DALL-E', [
                            'scene_id' => $this->sceneId,
                            'error'    => $e->getMessage(),
                        ]);
                        $prompt = $this->buildPrompt($scene, true); // include character description for the fallback
                        unset($options['reference_image_url']);
                    }
                }
                // else: no reference asset — DALL-E with description-only path is fine.
            }

            if (! isset($result)) {
                // If the caller picked a specific model, override the DI default
                // and resolve via the factory. Lets users hit nano-banana /
                // flux-schnell / sdxl-lightning / gpt-image-2 without changing
                // the global bind. For OpenAI entries, pass the specific model
                // name through options so DalleImageAdapter routes to the right
                // /generations endpoint.
                if ($this->modelKey) {
                    $factory = app(\App\Services\Generation\Image\ImageAdapterFactory::class);
                    $resolved = $factory->resolve($this->modelKey);
                    $override = $factory->openaiModelOverride($this->modelKey);
                    if ($override) {
                        $options['openai_model_override'] = $override;
                    }
                } else {
                    $resolved = $adapter;
                }
                $result = $resolved->generate($prompt, $this->style, $aspectRatio, $options);
            }

            if (! $this->sceneStillMatchesGeneration($scene)) {
                return;
            }

            // Download the image and store in B2 so it persists beyond provider URL TTL
            $storagePath = $this->storeImage($result['image_url'] ?? null, $scene, $result['image_b64'] ?? null);

            $asset = Asset::query()->create([
                'workspace_id'      => $scene->project->workspace_id,
                'channel_id'        => $scene->project->channel_id,
                'asset_type'        => 'image',
                'title'             => "AI Image — {$this->style} — Scene {$scene->scene_order}",
                'description'       => $prompt,
                'storage_url'       => $storagePath,
                'thumbnail_url'     => $storagePath,
                'duration_seconds'  => null,
                'dimensions_json'   => [
                    'width'  => $result['width'],
                    'height' => $result['height'],
                ],
                'mime_type'         => 'image/png',
                'tags'              => ['ai_generated', $result['provider_key'], $this->style],
                'source'            => 'ai_generated',
                'usage_count'       => 1,
                'status'            => 'active',
                'created_by_user_id' => $scene->project->created_by_user_id,
            ]);

            if (! $this->sceneStillMatchesGeneration($scene)) {
                Log::warning('GenerateAIImageJob: post-asset guard aborted; asset created but scene not linked', [
                    'scene_id' => $this->sceneId,
                    'asset_id' => $asset->getKey(),
                    'job_token' => $this->generationToken,
                ]);
                return;
            }

            $saved = $scene->forceFill([
                'visual_type'                    => 'ai_image',
                'visual_asset_id'                => $asset->getKey(),
                'visual_prompt'                  => $prompt,
                'visual_style'                   => $this->style,
                'image_generation_settings_json' => [
                    'in_progress'    => false,
                    'needs_visual'   => false,
                    'last_error'     => null,
                    'style'          => $this->style,
                    'provider_key'   => $result['provider_key'],
                    // Remember the model used so a retry / regenerate reuses it
                    // instead of resetting to the default picker value.
                    'model_key'      => $this->modelKey,
                    'revised_prompt' => $result['revised_prompt'],
                    'seed'           => $result['seed'],
                    'asset_id'       => $asset->getKey(),
                    'generation_token' => $this->generationToken,
                ],
            ])->save();

            // Defensive: if Eloquent silently no-ops the save (observed once
            // on prod 2026-06-02, scene 187), fall back to a raw UPDATE so the
            // asset doesn't end up orphaned. Loud-log either way for forensics.
            if (! $saved) {
                Log::error('GenerateAIImageJob: forceFill+save returned falsy; falling back to raw UPDATE', [
                    'scene_id' => $this->sceneId,
                    'asset_id' => $asset->getKey(),
                ]);
            }
            $rowsAffected = \DB::table('scenes')->where('id', $this->sceneId)->whereNull('visual_asset_id')->update([
                'visual_asset_id' => $asset->getKey(),
                'visual_type'     => 'ai_image',
                'updated_at'      => now(),
            ]);
            // $rowsAffected = 0 is the expected case (Eloquent save already won).
            // $rowsAffected = 1 means the save() lied and the raw UPDATE rescued it.
            if ($rowsAffected > 0) {
                Log::error('GenerateAIImageJob: raw UPDATE rescued an orphan', [
                    'scene_id' => $this->sceneId,
                    'asset_id' => $asset->getKey(),
                ]);
            }

            // BUGFIX 2026-05-31: charge based on which adapter actually ran.
            // Pre-ledger, this hardcoded AI_MEDIUM (15) regardless of path, so
            // character-bound regens through gpt-image-2 /edits — which cost
            // ~\$0.30 upstream — were only charging users 15 credits (\$0.15).
            // Negative-margin every time a character regen ran. Now: charge
            // AI_CHARACTER when the gpt-image-2 path produced the image, fall
            // back to AI_MEDIUM otherwise. The SceneController guard already
            // requires AI_CHARACTER credits up-front so this aligns guard
            // and deduction.
            // Reconcile the up-front reservation against what actually ran.
            // We already charged $reserved; if the character path fell back to
            // DALL-E (cheaper), refund the difference. Actual can never exceed
            // the reservation, so this only ever refunds.
            $providerKey = (string) ($result['provider_key'] ?? 'dalle');
            // "Character path" only counts when we actually EXPECTED it. A
            // deliberate gpt-image-2 *model pick* (non-character) must bill at
            // its per-model rate (43), not AI_CHARACTER (50). Without the
            // $expectsCharacter gate, every direct gpt-image-2 pick mis-charged.
            $ranCharacterPath = $expectsCharacter && $providerKey === 'openai:gpt-image-2';
            $actualCost = $ranCharacterPath
                ? CreditService::AI_CHARACTER
                : $factory->costFor($this->modelKey);
            if ($reserved > $actualCost) {
                app(CreditService::class)->refund(
                    (int) $scene->project->workspace_id,
                    $reserved - $actualCost,
                    'ai_image:reconcile',
                );
            }
            // Multi-scene aware progress: count scenes in this project that
            // already have a visual_asset_id. If we're not the last one to
            // finish, emit 'processing' with done/total so the progress view
            // can render "Generating image · X / N" — keeps the user on the
            // page through the full multi-scene wait instead of marking
            // complete after the first scene's success.
            $total = Scene::query()->where('project_id', $this->projectId)->count();
            $done  = Scene::query()->where('project_id', $this->projectId)
                ->whereNotNull('visual_asset_id')->count();
            $stageStatus = ($done >= $total) ? 'completed' : 'processing';
            GenerationProgressed::dispatch($this->projectId, 'ai_image', $stageStatus, null, [
                'scene_id'  => $this->sceneId,
                'asset_id'  => $asset->getKey(),
                'image_url' => app(StorageService::class)->url($storagePath),
                'done'      => $done,
                'total'     => $total,
            ]);
            app(CruiseActionRunService::class)->markStageCompleted($this->projectId, 'ai_image', $this->sceneId);

            // One-shot spokesperson: image is ready — fire the talking-video job
            // if the voice is also ready (no-op + idempotent otherwise).
            rescue(fn () => \App\Jobs\GenerateTalkingVideoJob::maybeDispatchForScene($scene));

            // One-shot animate chain: image is ready, kick AnimateSceneJob so
            // the user actually gets the animated clip they toggled on. Quick
            // tier defaults to 5s (Wan 2.5 supports 5 or 10). Music + TTS were
            // already dispatched in parallel by storeOneShot.
            if ($this->chainAnimateAfterSeconds !== null) {
                AnimateSceneJob::dispatch(
                    $this->sceneId,
                    $this->projectId,
                    $this->chainAnimateTier ?? 'quick',
                    $this->chainAnimateAfterSeconds,
                    $this->chainAnimateMotionPrompt,
                );
            }
        } catch (\Throwable $e) {
            // Generation failed — refund the up-front reservation so a failed
            // image costs nothing. This job doesn't retry (the catch handles
            // the failure), so it's a single refund.
            if (! empty($charged)) {
                app(CreditService::class)->refund(
                    (int) $scene->project->workspace_id,
                    $reserved,
                    'ai_image:manual',
                );
            }

            $isPolicyViolation = $this->isPolicyError($e->getMessage());

            // Record every provider rejection in moderation_events so the
            // admin Trust & Safety tab can surface repeat offenders + the
            // daily pattern job has data to scan.
            if ($isPolicyViolation) {
                rescue(fn () => app(\App\Services\Moderation\ModerationService::class)->recordRejection(
                    $e->getMessage(),
                    [
                        'workspace_id' => $scene->project->workspace_id ?? null,
                        'user_id'      => $scene->project->created_by_user_id ?? null,
                        'project_id'   => $this->projectId,
                        'scene_id'     => $this->sceneId,
                        'operation'    => $useCharacterRef ? 'ai_image:character' : 'ai_image:manual',
                        'prompt'       => $prompt ?? null,
                        'reference_asset_id' => $useCharacterRef ? ($scene->character->reference_asset_id ?? null) : null,
                        'metadata'     => ['style' => $this->style, 'phase' => 'initial'],
                    ],
                ));

                Log::warning('GenerateAIImageJob: policy violation — attempting prompt rewrite', [
                    'scene_id' => $this->sceneId,
                ]);

                try {
                    $safePrompt = $this->rewritePromptForPolicy($scene);
                    $result = $adapter->generate($safePrompt, $this->style, $scene->project->aspect_ratio ?? '9:16', [
                        'usage_context' => [
                            'workspace_id' => $scene->project->workspace_id,
                            'project_id'   => $this->projectId,
                            'user_id'      => $scene->project->created_by_user_id,
                            'scene_id'     => $this->sceneId,
                            'style'        => $this->style,
                        ],
                    ]);

                    if (! $this->sceneStillMatchesGeneration($scene)) {
                        return;
                    }

                    $storagePath = $this->storeImage($result['image_url'] ?? null, $scene, $result['image_b64'] ?? null);

                    $asset = Asset::query()->create([
                        'workspace_id'       => $scene->project->workspace_id,
                        'channel_id'         => $scene->project->channel_id,
                        'asset_type'         => 'image',
                        'title'              => "AI Image — {$this->style} — Scene {$scene->scene_order}",
                        'description'        => $safePrompt,
                        'storage_url'        => $storagePath,
                        'thumbnail_url'      => $storagePath,
                        'duration_seconds'   => null,
                        'dimensions_json'    => ['width' => $result['width'], 'height' => $result['height']],
                        'mime_type'          => 'image/png',
                        'tags'               => ['ai_generated', $result['provider_key'], $this->style, 'policy_rewritten'],
                        'source'             => 'ai_generated',
                        'usage_count'        => 1,
                        'status'             => 'active',
                        'created_by_user_id' => $scene->project->created_by_user_id,
                    ]);

                    if (! $this->sceneStillMatchesGeneration($scene)) {
                        return;
                    }

                    $scene->forceFill([
                        'visual_type'                    => 'ai_image',
                        'visual_asset_id'                => $asset->getKey(),
                        'visual_prompt'                  => $safePrompt,
                        'visual_style'                   => $this->style,
                        'image_generation_settings_json' => [
                            'in_progress'    => false,
                            'needs_visual'   => false,
                            'last_error'     => null,
                            'style'          => $this->style,
                            'provider_key'   => $result['provider_key'],
                            'revised_prompt' => $result['revised_prompt'],
                            'seed'           => $result['seed'],
                            'asset_id'       => $asset->getKey(),
                            'policy_rewritten' => true,
                            'generation_token' => $this->generationToken,
                        ],
                    ])->save();

                    GenerationProgressed::dispatch($this->projectId, 'ai_image', 'completed', null, [
                        'scene_id'  => $this->sceneId,
                        'asset_id'  => $asset->getKey(),
                        'image_url' => app(StorageService::class)->url($storagePath),
                    ]);
                    app(CruiseActionRunService::class)->markStageCompleted($this->projectId, 'ai_image', $this->sceneId);

                    // One-shot spokesperson: fire the talking-video job if the
                    // voice is also ready (idempotent guard inside).
                    rescue(fn () => \App\Jobs\GenerateTalkingVideoJob::maybeDispatchForScene($scene));

                    // Resume safety net (see PipelineStatusService) — only
                    // relevant when no animation is chained behind this image.
                    if (! $this->chainAnimateTier) {
                        rescue(fn () => app(\App\Services\Generation\PipelineStatusService::class)->maybeMarkReady($this->projectId));
                    }

                    return;
                } catch (\Throwable $retryE) {
                    Log::error('GenerateAIImageJob: policy rewrite retry also failed', [
                        'scene_id' => $this->sceneId,
                        'error'    => $retryE->getMessage(),
                    ]);
                    $e = $retryE;
                }
            }

            Log::error('GenerateAIImageJob failed', [
                'scene_id' => $this->sceneId,
                'error'    => $e->getMessage(),
            ]);

            if (! $this->sceneStillMatchesGeneration($scene)) {
                return;
            }

            // Flag scene so the editor can surface the failure without blocking the project
            $scene->forceFill([
                'image_generation_settings_json' => array_merge(
                    $scene->image_generation_settings_json ?? [],
                    [
                        'in_progress' => false,
                        'needs_visual' => true,
                        'last_error' => mb_substr(preg_replace('/data:[^,]+,\S+/', '[binary data omitted]', $e->getMessage()) ?? $e->getMessage(), 0, 500),
                        'generation_token' => $this->generationToken,
                    ]
                ),
            ])->save();

            GenerationProgressed::dispatch($this->projectId, 'ai_image', 'failed', $e->getMessage(), [
                'scene_id' => $this->sceneId,
            ]);
            app(CruiseActionRunService::class)->markStageFailed($this->projectId, 'ai_image', $e->getMessage(), $this->sceneId);
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'scene', $this->sceneId, null, $this->projectId);

        // Clear the in_progress lock so the scene isn't permanently stuck after a crash/timeout.
        $scene = Scene::query()->find($this->sceneId);
        if (! $scene) {
            return;
        }
        $settings = $scene->image_generation_settings_json ?? [];
        if (! empty($settings['in_progress']) && ($settings['generation_token'] ?? null) === $this->generationToken) {
            $scene->forceFill([
                'image_generation_settings_json' => array_merge($settings, [
                    'in_progress'  => false,
                    'needs_visual' => true,
                    'last_error'   => mb_substr($exception->getMessage(), 0, 500),
                ]),
            ])->save();
        }
    }

    private function isPolicyError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($lower, 'policy') || str_contains($lower, 'safety');
    }

    private function rewritePromptForPolicy(Scene $scene): string
    {
        $originalPrompt = $this->buildPrompt($scene);
        $apiKey = config('services.openai.api_key');

        if (empty($apiKey)) {
            throw new RuntimeException('OpenAI API key not configured for policy rewrite.');
        }

        $response = Http::withToken($apiKey)
            ->timeout(30)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a prompt rewriter. Rewrite image generation prompts to be safe, neutral, and suitable for DALL-E. Remove any violent, graphic, sexual, or politically sensitive elements while preserving the original visual intent and setting. Return only the rewritten prompt, no explanations.',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Rewrite this image generation prompt to avoid content policy violations:\n\n{$originalPrompt}",
                    ],
                ],
                'max_tokens' => 300,
                'temperature' => 0.3,
            ])
            ->throw()
            ->json();

        $rewritten = trim((string) data_get($response, 'choices.0.message.content', ''));

        if ($rewritten === '') {
            throw new RuntimeException('Policy rewrite returned empty prompt.');
        }

        return $rewritten;
    }

    /**
     * Build a public, signed URL to a character's reference asset so Replicate can fetch it.
     * Returns null when the asset is missing or the storage path can't be resolved.
     */
    private function signedReferenceUrl(?\App\Models\Asset $asset): ?string
    {
        if (! $asset || ! $asset->storage_url) {
            return null;
        }
        $storage = app(\App\Services\Media\StorageService::class);
        $isStoredPath = $storage->extractPath((string) $asset->storage_url) !== null;
        if (! $isStoredPath) {
            // External URL already — pass through.
            return (string) $asset->storage_url;
        }
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'media.assets.content',
            now()->addMinutes(30),
            ['assetId' => $asset->getKey()],
        );
    }

    private function buildPrompt(Scene $scene, bool $includeCharacterDescription = true): string
    {
        if ($this->promptOverride) {
            return trim($this->promptOverride).$this->characterBoardSuffix($scene);
        }

        // No explicit override: reuse the scene's ESTABLISHED prompt — the rich
        // prompt that one-shot / Cruise / the last successful gen used (stored
        // in visual_prompt). This is what makes a retry or a plain "Regenerate"
        // (blank prompt box) reproduce the SAME plan, instead of a thin
        // script-derived prompt that wasn't what the user set up.
        $established = trim((string) ($scene->visual_prompt ?? ''));
        if ($established !== '') {
            return $established.$this->characterBoardSuffix($scene);
        }

        $script = mb_substr(trim((string) $scene->script_text), 0, 200);
        $label  = $scene->label ?: 'scene';
        $tone   = $scene->project->tone ?? 'neutral';

        // visual_style on the scene takes precedence over the job-level style.
        $styleModifier = $this->visualStyle ?? $scene->visual_style ?? null;

        // First-time, no-override build: make the image FIT the rest of the
        // video. Pull the project's creative brief (theme + recurring subject
        // + style) so a fresh scene is consistent with its siblings instead of
        // being generated in isolation.
        $assistantBrief = is_array($scene->project->assistant_brief_json) ? $scene->project->assistant_brief_json : [];
        if (! $styleModifier && ! empty($assistantBrief['visual_style'])) {
            $styleModifier = (string) $assistantBrief['visual_style'];
        }
        $briefBits = [];
        if (! empty($assistantBrief['recurring_subject'])) $briefBits[] = (string) $assistantBrief['recurring_subject'];
        if (! empty($assistantBrief['theme']))             $briefBits[] = (string) $assistantBrief['theme'];
        $briefContext = $briefBits ? (implode(', ', $briefBits) . '. ') : '';

        $stylePart = $styleModifier ? ", {$styleModifier} visual style" : '';

        $brief = is_array($scene->project->visual_brief) ? $scene->project->visual_brief : [];

        // Consistency card locks character appearance, lighting, and color grade
        // across every scene so AI-generated images look like one cohesive video.
        $consistencyCard = trim((string) ($brief['consistency_card'] ?? ''));

        // Reference style from uploaded reference images takes precedence.
        $referenceStyle = trim((string) ($brief['reference_style'] ?? ''));

        $prefix = $referenceStyle !== '' ? "{$referenceStyle} " : ($consistencyCard !== '' ? "{$consistencyCard} " : '');

        // Inject the scene's character into the prompt when we're going through DALL-E
        // (no visual reference, identity is description-only). When PuLID is the adapter,
        // identity comes from the reference image — over-describing the face in the prompt
        // actually fights the reference, so we keep the prompt action-focused.
        $characterChunk = '';
        if ($includeCharacterDescription && $scene->character_id) {
            $character = $scene->relationLoaded('character') ? $scene->character : $scene->character()->first();
            if ($character) {
                $desc = trim((string) $character->description);
                $characterChunk = "Character: {$character->name}".($desc !== '' ? " — {$desc}" : '').'. ';
            }
        }

        return trim("{$prefix}{$briefContext}{$characterChunk}{$label} for a {$tone} video{$stylePart}: {$script}")
            .$this->characterBoardSuffix($scene);
    }

    /**
     * Per-project character board (projects.character_board_json) — the
     * canonical appearance sheet for the recurring subject. Appended to EVERY
     * image prompt so costume/hair stop drifting between scenes and across
     * regenerations. Phrased conditionally ("if a person appears…") so
     * person-less b-roll scenes don't grow a person.
     */
    /**
     * Adapter for a reference/character generation: nano-banana by default
     * (better identity + skin-tone fidelity under style changes), gpt-image-2
     * only when the user explicitly picked it. Both read the references from
     * $options (reference_image_urls / reference_image_url), so they swap
     * transparently.
     */
    private function referenceAdapter(): object
    {
        return app(\App\Services\Generation\Image\ImageAdapterFactory::class)->referenceUsesGptImage2($this->modelKey)
            ? app(\App\Services\Generation\Image\CharacterImageAdapter::class)
            : app(\App\Services\Generation\Image\NanoBananaImageAdapter::class);
    }

    private function characterBoardSuffix(Scene $scene): string
    {
        // Multi-character scene: describe each named character so every person
        // stays consistent. Only triggers when a scene has >1 character — a
        // single-character scene keeps the existing project-board behaviour.
        $castIds = is_array($scene->character_ids) ? array_values(array_filter($scene->character_ids)) : [];
        if (count($castIds) > 1) {
            $lines = [];
            foreach (\App\Models\Character::query()->whereIn('id', $castIds)->get() as $c) {
                $desc = trim((string) $c->description);
                $lines[] = trim((string) $c->name).($desc !== '' ? ': '.$desc : '');
            }
            $lines = array_filter($lines);
            if (! empty($lines)) {
                return ' This scene features multiple named characters; each must look'
                    .' EXACTLY as described, no variation in face, outfit or hair: '
                    .implode('; ', $lines).'.';
            }
        }

        $board = $scene->project?->character_board_json;
        $sheet = is_array($board) ? trim((string) ($board['sheet'] ?? '')) : '';
        if ($sheet === '') {
            return '';
        }

        return ' If a person appears in this scene, they must look EXACTLY like this'
            .' — same outfit, hair and accessories, no variations: '.$sheet;
    }

    private function storeImage(string|null $url, Scene $scene, string|null $b64 = null): string
    {
        if ($b64 !== null) {
            $contents = base64_decode($b64, true) ?: '';
        } else {
            $contents = Http::timeout(30)->get((string) $url)->body();
        }

        $path = sprintf(
            'workspaces/%s/assets/ai-images/%s.png',
            $scene->project->workspace_id,
            Str::uuid()
        );

        return app(StorageService::class)->put($path, $contents);
    }

    private function sceneStillMatchesGeneration(Scene $scene): bool
    {
        if (! $this->generationToken) {
            return true;
        }

        $scene->refresh();
        $settings = is_array($scene->image_generation_settings_json) ? $scene->image_generation_settings_json : [];

        return (string) ($settings['generation_token'] ?? '') === $this->generationToken;
    }
}
