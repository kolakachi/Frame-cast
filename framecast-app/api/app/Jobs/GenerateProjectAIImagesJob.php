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

    public int $tries = 1;

    public int $timeout = 900;

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
            } catch (\Throwable $exception) {
                Log::error('Project AI B-roll scene generation failed', [
                    'project_id' => $project->getKey(),
                    'scene_id' => $scene->getKey(),
                    'error' => $exception->getMessage(),
                ]);

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
            $result = $adapter->generate($prompt, $style, $project->aspect_ratio ?? '9:16', $options);
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
        $imageCost = $ranCharacterPath ? CreditService::AI_CHARACTER : CreditService::AI_MEDIUM;

        rescue(fn () => app(CreditService::class)->deduct(
            (int) $project->workspace_id,
            $imageCost,
            $ranCharacterPath ? 'ai_image:character' : 'ai_image:initial',
            [
                'project_id' => $project->getKey(),
                'scene_id'   => $scene->getKey(),
                'user_id'    => $project->created_by_user_id,
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
        $prefix = $referenceStyle !== '' ? "{$referenceStyle} " : ($consistencyCard !== '' ? "{$consistencyCard} " : '');

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

        return trim("{$prefix}{$characterChunk}{$label} for a faceless {$tone} video. B-roll style: {$style}. Scene narration: {$sceneText}. Context: {$context}. Make it vertical-video friendly, visually specific, no text overlays.");
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
