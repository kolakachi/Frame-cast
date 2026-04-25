<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Scene;
use App\Services\Generation\Image\ImageGenerationAdapter;
use App\Services\Media\StorageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;

class GenerateAIImageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(
        public readonly int $sceneId,
        public readonly int $projectId,
        public readonly string $style = 'cinematic',
        public readonly ?string $promptOverride = null,
        public readonly ?string $visualStyle = null,
    ) {
        $this->onQueue('visual');
    }

    public function handle(ImageGenerationAdapter $adapter): void
    {
        $scene = Scene::query()->with('project')->find($this->sceneId);

        if (! $scene) {
            return;
        }

        GenerationProgressed::dispatch($this->projectId, 'ai_image', 'processing');

        try {
            $prompt = $this->buildPrompt($scene);
            $aspectRatio = $scene->project->aspect_ratio ?? '9:16';

            $scene->loadMissing('project');
            $project = $scene->project;

            $result = $adapter->generate($prompt, $this->style, $aspectRatio, [
                'usage_context' => [
                    'workspace_id' => $project?->workspace_id,
                    'project_id' => $this->projectId,
                    'user_id' => $project?->created_by_user_id,
                    'scene_id' => $this->sceneId,
                    'style' => $this->style,
                ],
            ]);

            // Download the image and store in B2 so it persists beyond provider URL TTL
            $storagePath = $this->storeImage($result['image_url'], $scene);

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

            $scene->forceFill([
                'visual_type'                    => 'ai_image',
                'visual_asset_id'                => $asset->getKey(),
                'visual_prompt'                  => $prompt,
                'visual_style'                   => $this->style,
                'image_generation_settings_json' => [
                    'in_progress'    => false,
                    'style'          => $this->style,
                    'provider_key'   => $result['provider_key'],
                    'revised_prompt' => $result['revised_prompt'],
                    'seed'           => $result['seed'],
                    'asset_id'       => $asset->getKey(),
                ],
            ])->save();

            GenerationProgressed::dispatch($this->projectId, 'ai_image', 'completed', null, [
                'scene_id'  => $this->sceneId,
                'asset_id'  => $asset->getKey(),
                'image_url' => app(StorageService::class)->url($storagePath),
            ]);
        } catch (\Throwable $e) {
            $isPolicyViolation = $this->isPolicyError($e->getMessage());

            if ($isPolicyViolation) {
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

                    $storagePath = $this->storeImage($result['image_url'], $scene);

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

                    $scene->forceFill([
                        'visual_type'                    => 'ai_image',
                        'visual_asset_id'                => $asset->getKey(),
                        'visual_prompt'                  => $safePrompt,
                        'visual_style'                   => $this->style,
                        'image_generation_settings_json' => [
                            'in_progress'    => false,
                            'style'          => $this->style,
                            'provider_key'   => $result['provider_key'],
                            'revised_prompt' => $result['revised_prompt'],
                            'seed'           => $result['seed'],
                            'asset_id'       => $asset->getKey(),
                            'policy_rewritten' => true,
                        ],
                    ])->save();

                    GenerationProgressed::dispatch($this->projectId, 'ai_image', 'completed', null, [
                        'scene_id'  => $this->sceneId,
                        'asset_id'  => $asset->getKey(),
                        'image_url' => app(StorageService::class)->url($storagePath),
                    ]);

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

            // Flag scene so the editor can surface the failure without blocking the project
            $scene->forceFill([
                'image_generation_settings_json' => array_merge(
                    $scene->image_generation_settings_json ?? [],
                    ['in_progress' => false, 'needs_visual' => true, 'last_error' => $e->getMessage()]
                ),
            ])->save();

            GenerationProgressed::dispatch($this->projectId, 'ai_image', 'failed', $e->getMessage(), [
                'scene_id' => $this->sceneId,
            ]);
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

    private function buildPrompt(Scene $scene): string
    {
        if ($this->promptOverride) {
            return trim($this->promptOverride);
        }

        $script = mb_substr(trim((string) $scene->script_text), 0, 200);
        $label  = $scene->label ?: 'scene';
        $tone   = $scene->project->tone ?? 'neutral';

        // visual_style on the scene takes precedence over the job-level style.
        $styleModifier = $this->visualStyle ?? $scene->visual_style ?? null;
        $stylePart = $styleModifier ? ", {$styleModifier} visual style" : '';

        // Prepend reference style from uploaded reference images so regenerated
        // scenes stay visually consistent with the rest of the project.
        $brief = is_array($scene->project->visual_brief) ? $scene->project->visual_brief : [];
        $referenceStyle = trim((string) ($brief['reference_style'] ?? ''));
        $stylePrefix = $referenceStyle !== '' ? "{$referenceStyle} " : '';

        return trim("{$stylePrefix}{$label} for a {$tone} video{$stylePart}: {$script}");
    }

    private function storeImage(string $url, Scene $scene): string
    {
        $contents = Http::timeout(30)->get($url)->body();
        $path = sprintf(
            'workspaces/%s/assets/ai-images/%s.png',
            $scene->project->workspace_id,
            Str::uuid()
        );

        return app(StorageService::class)->put($path, $contents);
    }
}
