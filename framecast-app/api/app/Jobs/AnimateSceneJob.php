<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Scene;
use App\Services\Generation\Video\I2VAdapter;
use App\Services\Media\StorageService;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Image-to-video animation job (rung 4).
 *
 * Takes a scene's current still image, sends it to the chosen i2v model on Replicate,
 * polls until the clip is ready, downloads it into B2, and swaps the scene's visual
 * asset to the new video. Regenerating the image (existing flow) reverts.
 */
class AnimateSceneJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public int $tries = 2;
    public int $timeout = 600; // i2v polls can run up to 6 min on premium

    public function __construct(
        public readonly int $sceneId,
        public readonly int $projectId,
        public readonly string $tier = 'quick',          // quick | balanced | premium
        public readonly int $durationSeconds = 6,        // 3–10
        public readonly ?string $motionPrompt = null,
    ) {
        $this->onQueue('visual');
    }

    public function handle(I2VAdapter $adapter): void
    {
        $scene = Scene::query()->with(['project'])->find($this->sceneId);
        if (! $scene) {
            return;
        }

        GenerationProgressed::dispatch($this->projectId, 'animation', 'processing');

        // Mark animation as in-progress so the editor can show distinct loading state.
        $this->stampAnimationState($scene, [
            'animation_in_progress'   => true,
            'animation_last_error'    => null,
            'animation_started_at'    => now()->toIso8601String(),
            'animation_tier'          => $this->tier,
        ]);

        try {
            $sourceAsset = $scene->visual_asset_id
                ? Asset::query()->find($scene->visual_asset_id)
                : null;
            if (! $sourceAsset || ! $sourceAsset->storage_url) {
                throw new RuntimeException('Scene has no source image to animate.');
            }

            $imageUrl = $this->publicUrlFor($sourceAsset);

            $result = $adapter->animate(
                $imageUrl,
                (string) $this->motionPrompt,
                $this->tier,
                $this->durationSeconds,
                ['aspect_ratio' => $scene->project->aspect_ratio ?? '9:16'],
            );

            // Download the produced video from Replicate's CDN into our storage.
            $videoBytes = Http::timeout(120)->get($result['video_url'])->body();
            if ($videoBytes === '') {
                throw new RuntimeException('Replicate returned a video URL but the file was empty.');
            }

            $path = sprintf(
                'workspaces/%s/assets/i2v/%s.mp4',
                $scene->project->workspace_id,
                Str::uuid(),
            );
            $storageUrl = app(StorageService::class)->put($path, $videoBytes);

            $asset = Asset::query()->create([
                'workspace_id'       => $scene->project->workspace_id,
                'channel_id'         => $scene->project->channel_id,
                'asset_type'         => 'video',
                'title'              => "AI Animated — {$this->tier} — Scene {$scene->scene_order}",
                'description'        => $this->motionPrompt ?: 'i2v animation',
                'storage_url'        => $storageUrl,
                'thumbnail_url'      => $sourceAsset->storage_url, // reuse source still as thumb
                'duration_seconds'   => $result['duration_seconds'],
                'mime_type'          => 'video/mp4',
                'tags'               => ['ai_animated', $result['provider_key'], $this->tier],
                'source'             => 'ai_generated',
                'usage_count'        => 1,
                'status'             => 'active',
                'created_by_user_id' => $scene->project->created_by_user_id,
            ]);

            // Swap the scene's visual to the new video. visual_type stays 'ai_image' so the
            // user can regenerate the still later via the existing flow — the asset_type
            // ('video') is what drives renderer + preview behaviour.
            $scene->forceFill(['visual_asset_id' => $asset->getKey()])->save();

            $this->stampAnimationState($scene->fresh(), [
                'animation_in_progress'   => false,
                'animation_last_error'    => null,
                'animation_completed_at'  => now()->toIso8601String(),
                'animation_video_asset_id' => $asset->getKey(),
            ]);

            GenerationProgressed::dispatch($this->projectId, 'animation', 'completed');
        } catch (\Throwable $e) {
            $this->stampAnimationState($scene->fresh() ?: $scene, [
                'animation_in_progress' => false,
                'animation_last_error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);
            GenerationProgressed::dispatch($this->projectId, 'animation', 'failed');
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'scene', $this->sceneId, null, $this->projectId);
    }

    /**
     * Build a public, signed URL Replicate can fetch from.
     */
    private function publicUrlFor(Asset $asset): string
    {
        $storage = app(StorageService::class);
        $isStoredPath = $storage->extractPath((string) $asset->storage_url) !== null;
        if (! $isStoredPath) {
            return (string) $asset->storage_url;
        }
        return URL::temporarySignedRoute(
            'media.assets.content',
            now()->addHours(2),
            ['assetId' => $asset->getKey()],
        );
    }

    /**
     * Merge animation-related keys into the scene's image_generation_settings_json
     * without trampling unrelated keys.
     *
     * @param array<string,mixed> $delta
     */
    private function stampAnimationState(Scene $scene, array $delta): void
    {
        $settings = is_array($scene->image_generation_settings_json)
            ? $scene->image_generation_settings_json
            : [];
        foreach ($delta as $k => $v) {
            $settings[$k] = $v;
        }
        $scene->forceFill(['image_generation_settings_json' => $settings])->save();
    }
}
