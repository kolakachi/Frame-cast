<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Scene;
use App\Services\Generation\Image\ImageGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
                'image_url' => Storage::disk('b2')->temporaryUrl($storagePath, now()->addHours(2)),
            ]);
        } catch (\Throwable $e) {
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

        Storage::disk('b2')->put($path, $contents);

        return $path;
    }
}
