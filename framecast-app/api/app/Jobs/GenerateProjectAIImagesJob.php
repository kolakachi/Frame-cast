<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Services\CreditService;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Generation\Image\ImageGenerationAdapter;
use App\Services\Media\StorageService;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateProjectAIImagesJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    // The batch is RESUMABLE by construction — it only touches scenes that
    // still lack a visual — so retries continue where the last attempt died
    // instead of redoing work. tries=3 + a bigger timeout turns a mid-batch
    // death (deploy restart, provider slowness) into a pause, not a loss.
    // 900s was exceeded by a real 11-image project at ~93s/image; the job
    // died at image 10 and the user sat on "generating" forever.
    public int $tries = 3;

    public int $timeout = 2700;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('visual');
    }

    public function handle(ImageGenerationAdapter $adapter): void
    {
        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereNull('visual_asset_id')
            ->orderBy('scene_order')
            ->get();

        $total = $scenes->count();
        $done  = 0;

        GenerationProgressed::dispatch($this->projectId, 'ai_image', 'processing', null, [
            'done' => 0, 'total' => $total,
        ]);

        // Art-director pass: the premium model writes ONE distinct visual
        // concept per scene before anything renders. Until now image prompts
        // were mechanical string assembly (script text + style card), which is
        // how eleven near-identical frames shipped — assembly can't imagine
        // variety, a model can. Fail-open: on any error the mechanical
        // composition below still runs.
        $this->artDirectScenes($project, $scenes);
        $scenes = $scenes->map(fn ($s) => $s->refresh());

        // Monotony guard — the assert this pipeline never had. A real
        // customer's 11 scenes rendered as one portrait eleven times because a
        // subject-description was smuggled in as "style" and dominated every
        // prompt. That bug completes successfully — no exception, no failed
        // job — so nothing mechanical could catch it. This can: if the
        // scene-specific openings of the prompts are near-identical, something
        // upstream is broken. Alert loudly (T&S inbox + digest); don't block —
        // a false positive that halts a customer's generation is worse than a
        // true positive that pages us.
        rescue(function () use ($project, $scenes): void {
            if ($scenes->count() < 3) {
                return;
            }
            $heads = $scenes->map(fn ($s) => mb_substr($this->buildPrompt($project, $s, $project->ai_broll_style ?: 'cinematic'), 0, 140))->values();
            $pairs = 0; $simSum = 0.0;
            for ($i = 0; $i < min(6, $heads->count()); $i++) {
                for ($j = $i + 1; $j < min(6, $heads->count()); $j++) {
                    similar_text($heads[$i], $heads[$j], $pct);
                    $simSum += $pct; $pairs++;
                }
            }
            $avg = $pairs ? $simSum / $pairs : 0;
            if ($avg > 82) {
                app(\App\Services\Moderation\ModerationService::class)->recordPatternAlert(
                    'prompt_monotony',
                    sprintf('Project %d: scene image prompts are %.0f%% identical — the video will render as near-duplicate frames. Check visual_brief.reference_style for smuggled subject descriptions.', $project->getKey(), $avg),
                    [
                        'workspace_id' => $project->workspace_id,
                        'user_id'      => $project->created_by_user_id,
                        'severity'     => \App\Models\ModerationEvent::SEVERITY_MEDIUM,
                        'project_id'   => $project->getKey(),
                        'metadata'     => ['avg_similarity' => round($avg, 1), 'sample' => $heads->first()],
                    ],
                );
            }
        }, report: false);

        foreach ($scenes as $scene) {
            // Lock the scene so the editor's manual generate-image endpoint is rejected
            // while the pipeline is actively generating for it.
            $scene->forceFill([
                'image_generation_settings_json' => array_merge(
                    $scene->image_generation_settings_json ?? [],
                    ['in_progress' => true]
                ),
            ])->save();

            try {
                $this->generateSceneImage($adapter, $project, $scene);
                $this->chainAnimationIfConfigured($project, $scene);
            } catch (\Throwable $exception) {
                Log::error('Project AI B-roll scene generation failed', [
                    'project_id' => $project->getKey(),
                    'scene_id' => $scene->getKey(),
                    'error' => $exception->getMessage(),
                ]);

                // Log moderation rejection for bulk-pipeline failures too. Same
                // shape as GenerateAIImageJob: only when the message looks like
                // a content-policy refusal, not a transport / timeout error.
                if (str_contains(strtolower($exception->getMessage()), 'policy') || str_contains(strtolower($exception->getMessage()), 'safety')) {
                    $scene->loadMissing('character');
                    $usedCharacter = $scene->character_id && $scene->character?->reference_asset_id;
                    rescue(fn () => app(\App\Services\Moderation\ModerationService::class)->recordRejection(
                        $exception->getMessage(),
                        [
                            'workspace_id' => $project->workspace_id,
                            'user_id'      => $project->created_by_user_id,
                            'project_id'   => $project->getKey(),
                            'scene_id'     => $scene->getKey(),
                            'operation'    => $usedCharacter ? 'ai_image:character' : 'ai_image:project',
                            'prompt'       => $scene->visual_prompt,
                            'reference_asset_id' => $usedCharacter ? $scene->character->reference_asset_id : null,
                            'metadata'     => ['style' => $project->ai_broll_style ?? 'cinematic', 'phase' => 'project_bulk'],
                        ],
                    ));
                }

                $scene->forceFill([
                    'image_generation_settings_json' => array_merge(
                        $scene->image_generation_settings_json ?? [],
                        ['in_progress' => false, 'needs_visual' => true, 'last_error' => $exception->getMessage()]
                    ),
                ])->save();
            }

            $done++;
            GenerationProgressed::dispatch($this->projectId, 'ai_image', 'processing', null, [
                'done' => $done, 'total' => $total,
            ]);
        }

        GenerationProgressed::dispatch($this->projectId, 'ai_image', 'completed');
        GenerateTTSJob::dispatch($project->getKey());
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'project', $this->projectId, null, $this->projectId);

        // Terminal failure must not strand the user on a forever-"generating"
        // screen. The scenes that DID get images are real work worth keeping,
        // so continue the pipeline (TTS -> ready) rather than junking the
        // project: missing visuals stay fillable per-scene in the editor.
        rescue(function (): void {
            $project = Project::query()->find($this->projectId);
            if (! $project || $project->status !== 'generating') {
                return;
            }
            GenerationProgressed::dispatch($this->projectId, 'ai_image', 'failed',
                'Some scene images could not be generated — you can create them per scene in the editor.');
            GenerateTTSJob::dispatch($this->projectId);
        }, report: false);
    }

    /**
     * AI Video mode: animate the still this scene just got.
     *
     * Chained per scene rather than batched at the end, so an image failure
     * only costs THAT scene its animation — the rest still move. A scene whose
     * animation later fails keeps its still (segments render images fine), so
     * AI Video is best-effort per scene: a mixed still/video result beats a
     * stuck project. AnimateSceneJob owns the charge and refund-on-failure,
     * same as every other animation path.
     */
    private function chainAnimationIfConfigured(Project $project, Scene $scene): void
    {
        if ($project->visual_generation_mode !== 'ai_video') {
            return;
        }

        $brief   = is_array($project->visual_brief) ? $project->visual_brief : [];
        $tier    = (string) ($brief['animate_tier'] ?? 'quick');
        $quality = CreditService::videoQuality($tier, $brief['animate_quality'] ?? null);

        $fresh = $scene->fresh();
        if (! $fresh || ! $fresh->visual_asset_id) {
            return; // image did not land — nothing to animate
        }

        $fresh->forceFill([
            'image_generation_settings_json' => array_merge($fresh->image_generation_settings_json ?? [], [
                'animation_in_progress' => true,
                'animation_last_error'  => null,
                'animation_tier'        => $tier,
                'animation_duration'    => 5,
                'animation_quality'     => $quality,
                'animation_started_at'  => now()->toIso8601String(),
            ]),
        ])->save();

        AnimateSceneJob::dispatch(
            $fresh->getKey(),
            $project->getKey(),
            $tier,
            5,
            null,
            quality: $quality,
            sourceAssetId: (int) $fresh->visual_asset_id,
        );
    }

    private function generateSceneImage(ImageGenerationAdapter $adapter, Project $project, Scene $scene): void
    {
        $style = $project->ai_broll_style ?: 'cinematic';

        // Character path: route to CharacterImageAdapter (gpt-image-2 /edits) when the
        // scene has a character with a reference image; otherwise the injected default adapter.
        $scene->loadMissing('character.referenceAsset');
        $useCharacterRef = $scene->character_id
            && $scene->character?->reference_asset_id
            && $scene->character?->referenceAsset;

        $prompt = $this->buildPrompt($project, $scene, $style, ! $useCharacterRef);

        $options = [
            'usage_context' => [
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                'user_id' => $project->created_by_user_id,
                'scene_id' => $scene->getKey(),
                'style' => $style,
            ],
            // Custom style descriptor — scene per-scene override beats the
            // project default. Reaches the adapter via $options['custom_style'].
            'custom_style' => $scene->custom_visual_style
                ?: $project->custom_visual_style
                ?: null,
        ];

        $result = null;
        if ($useCharacterRef) {
            $referenceUrl = $this->signedReferenceUrl($scene->character->referenceAsset);
            if ($referenceUrl) {
                $options['reference_image_url'] = $referenceUrl;
                // Mirror GenerateAIImageJob: identity_strength → gpt-image-2 quality knob.
                $strength = $scene->character->identity_strength ?? 'balanced';
                $options['quality'] = match ($strength) {
                    'subtle' => 'medium',
                    'locked' => 'high',
                    default  => 'high',
                };
                try {
                    $result = app(\App\Services\Generation\Image\CharacterImageAdapter::class)
                        ->generate($prompt, $style, $project->aspect_ratio ?? '9:16', $options);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::warning('GenerateProjectAIImagesJob: character adapter failed, falling back to DALL-E', [
                        'scene_id' => $scene->getKey(),
                        'error'    => $e->getMessage(),
                    ]);
                    // Re-build the prompt with the character description embedded for the fallback.
                    $prompt = $this->buildPrompt($project, $scene, $style, true);
                    unset($options['reference_image_url']);
                }
            }
        }

        if (! $result) {
            // Auto-generated scene visuals render on DEFAULT_MODEL. Pin it here
            // rather than relying on the globally bound adapter's own fallback —
            // this is the path that produced the "images are poor quality"
            // reports, and it must match what costFor(null) charges below.
            $defaultFactory = app(\App\Services\Generation\Image\ImageAdapterFactory::class);
            $defaultKey = \App\Services\Generation\Image\ImageAdapterFactory::DEFAULT_MODEL;
            if ($override = $defaultFactory->openaiModelOverride($defaultKey)) {
                $options['openai_model_override'] = $override;
            }
            $result = $defaultFactory->resolve($defaultKey)
                ->generate($prompt, $style, $project->aspect_ratio ?? '9:16', $options);
        }
        $storagePath = $this->storeImage($result['image_url'] ?? null, $project, $result['image_b64'] ?? null);

        $asset = Asset::query()->create([
            'workspace_id' => $project->workspace_id,
            'channel_id' => $project->channel_id,
            'asset_type' => 'image',
            'title' => "AI B-roll — {$style} — Scene {$scene->scene_order}",
            'description' => $prompt,
            'storage_url' => $storagePath,
            'thumbnail_url' => $storagePath,
            'duration_seconds' => null,
            'dimensions_json' => [
                'width' => $result['width'],
                'height' => $result['height'],
            ],
            'mime_type' => 'image/png',
            'tags' => ['ai_broll', $result['provider_key'], $style],
            'usage_count' => 1,
            'status' => 'active',
            'created_by_user_id' => $project->created_by_user_id,
        ]);

        $scene->forceFill([
            'visual_type' => 'ai_image',
            'visual_asset_id' => $asset->getKey(),
            'visual_prompt' => $prompt,
            'visual_style' => $style,
            'image_generation_settings_json' => [
                'in_progress' => false,
                'style' => $style,
                'provider_key' => $result['provider_key'],
                'revised_prompt' => $result['revised_prompt'],
                'seed' => $result['seed'],
                'asset_id' => $asset->getKey(),
                'source' => 'project_ai_broll',
            ],
        ])->save();
        // Same charge-by-actual-path rule as GenerateAIImageJob (see comment
        // there). Pre-fix this hardcoded AI_MEDIUM for every scene, including
        // ones that ran through gpt-image-2 /edits at ~\$0.30 upstream.
        $providerKey = (string) ($result['provider_key'] ?? 'dalle');
        $ranCharacterPath = $providerKey === 'openai:gpt-image-2';
        // Non-character scenes render on ImageAdapterFactory::DEFAULT_MODEL, so
        // price them from the factory rather than a hardcoded AI_MEDIUM — that
        // constant is gpt-image-1's 16cr and would undercharge the default.
        $imageFactory = app(\App\Services\Generation\Image\ImageAdapterFactory::class);
        $imageCost = $ranCharacterPath
            ? CreditService::AI_CHARACTER
            : $imageFactory->costFor(null);

        rescue(fn () => app(CreditService::class)->deduct(
            (int) $project->workspace_id,
            $imageCost,
            $ranCharacterPath ? 'ai_image:character' : 'ai_image:initial',
            [
                'project_id' => $project->getKey(),
                'scene_id'   => $scene->getKey(),
                'user_id'    => $project->created_by_user_id,
                'upstream_cost_usd' => CreditService::cogsUsd($imageFactory->cogsKey(null, $ranCharacterPath)),
                'metadata'   => [
                    'provider_key' => $providerKey,
                    'style'        => $style,
                ],
            ],
        ));
    }

    /**
     * Build a public, signed URL to a character's reference asset so Replicate can fetch it.
     */
    private function signedReferenceUrl(?\App\Models\Asset $asset): ?string
    {
        if (! $asset || ! $asset->storage_url) {
            return null;
        }
        $storage = app(\App\Services\Media\StorageService::class);
        $isStoredPath = $storage->extractPath((string) $asset->storage_url) !== null;
        if (! $isStoredPath) {
            return (string) $asset->storage_url;
        }
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'media.assets.content',
            now()->addMinutes(30),
            ['assetId' => $asset->getKey()],
        );
    }

    /**
     * One premium-model call writes a distinct visual concept for every scene
     * that doesn't already have one; concepts land in scene.visual_prompt,
     * which buildPrompt() then prefers over mechanical assembly.
     */
    private function artDirectScenes(Project $project, $scenes): void
    {
        $pending = $scenes->filter(fn ($s) => trim((string) $s->visual_prompt) === '');
        if ($pending->count() < 2) {
            return; // singles are fine mechanically; nothing to vary against
        }

        rescue(function () use ($project, $pending): void {
            $brief = is_array($project->visual_brief) ? $project->visual_brief : [];
            $list = $pending->map(fn ($s) => $s->scene_order.'. '.mb_substr(trim((string) $s->script_text), 0, 200))->implode("\n");

            $result = app(\App\Services\Generation\AI\AIGenerationAdapter::class)->generate('scene_visual_concepts', [
                'style'      => $project->ai_broll_style ?: ($project->default_visual_style ?: 'cinematic'),
                'palette'    => (string) ($brief['palette'] ?? 'natural'),
                'setting'    => (string) ($brief['setting'] ?? 'varied, fitting each scene'),
                'subject'    => (string) ($brief['subject'] ?? ($brief['recurring_subject'] ?? 'none')),
                'tone'       => $project->tone ?: 'neutral',
                'scene_list' => $list,
            ], 2500, 0.7, [
                'usage_context' => [
                    'workspace_id' => $project->workspace_id,
                    'project_id'   => $project->getKey(),
                    'user_id'      => $project->created_by_user_id,
                    'template'     => 'scene_visual_concepts',
                ],
            ]);

            $decoded = json_decode((string) $result['content'], true);
            $byOrder = collect($decoded['scenes'] ?? [])->keyBy('order');

            foreach ($pending as $scene) {
                $visual = trim((string) ($byOrder[$scene->scene_order]['visual'] ?? ''));
                if ($visual !== '') {
                    $scene->forceFill(['visual_prompt' => mb_substr($visual, 0, 900)])->save();
                }
            }
        }, report: false);
    }

    private function buildPrompt(Project $project, Scene $scene, string $style, bool $includeCharacterDescription = true): string
    {
        $sceneText = mb_substr(trim((string) $scene->script_text), 0, 260);
        $label = $scene->label ?: 'Scene '.$scene->scene_order;
        $tone = $project->tone ?: 'neutral';
        $context = mb_substr(trim((string) $project->source_content_raw), 0, 500);

        $brief = is_array($project->visual_brief) ? $project->visual_brief : [];

        // Consistency card locks character appearance, lighting, and color grade.
        $consistencyCard = trim((string) ($brief['consistency_card'] ?? ''));
        $referenceStyle  = trim((string) ($brief['reference_style'] ?? ''));

        // The brief's per-project creative direction — palette, setting,
        // keywords — was generated for exactly this purpose and then never
        // read; meanwhile the reference card was PREFIXED whole, so every
        // scene rendered as the same picture with a different caption (a real
        // customer's 11 scenes were one portrait, eleven times). The SCENE
        // leads now; style material follows as modifiers.
        $palette = trim((string) ($brief['palette'] ?? ''));
        $setting = trim((string) ($brief['setting'] ?? ''));
        $styleBits = array_filter([
            $referenceStyle !== '' ? "Visual style: {$referenceStyle}" : ($consistencyCard !== '' ? $consistencyCard : ''),
            $palette !== '' ? "Palette: {$palette}" : '',
            $setting !== '' ? "Typical setting: {$setting}" : '',
        ]);
        $styleClause = $styleBits ? ' '.implode('. ', $styleBits).'.' : '';

        // Inject character into the prompt only on the description-only (DALL-E) path.
        // When PuLID is the adapter, the reference image carries identity and over-describing
        // the face in the prompt actually fights the reference.
        $characterChunk = '';
        if ($includeCharacterDescription && $scene->character_id) {
            $character = $scene->relationLoaded('character') ? $scene->character : $scene->character()->first();
            if ($character) {
                $desc = trim((string) $character->description);
                $characterChunk = "Character: {$character->name}".($desc !== '' ? " — {$desc}" : '').'. ';
            }
        }

        // An art-directed concept (or an editor-established prompt) wins over
        // mechanical assembly — it was written to be distinct.
        $established = trim((string) $scene->visual_prompt);
        if ($established !== '') {
            return trim("{$characterChunk}{$established}{$styleClause} Vertical-video friendly, no text overlays.");
        }

        return trim("{$characterChunk}A distinct {$style} scene depicting: {$sceneText} ({$label}, {$tone} tone).{$styleClause} Context: {$context}. Vertical-video friendly, visually specific, different composition from other scenes in this video, no text overlays.");
    }

    private function storeImage(string|null $url, Project $project, string|null $b64 = null): string
    {
        $contents = $b64 !== null
            ? (base64_decode($b64, true) ?: '')
            : Http::timeout(30)->get((string) $url)->body();
        $path = sprintf(
            'workspaces/%s/assets/ai-broll/%s.png',
            $project->workspace_id,
            Str::uuid(),
        );

        return app(StorageService::class)->put($path, $contents);
    }
}
