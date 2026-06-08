<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Scene;
use App\Services\CruiseControl\CruiseActionRunService;
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
        public readonly string $tier = 'quick',          // quick | balanced | premium | seedance_lite | seedance_pro
        public readonly int $durationSeconds = 6,        // 3–10
        public readonly ?string $motionPrompt = null,
        // Set by ReapStuckGenerationsJob to RESUME a prediction whose original
        // worker died mid-poll: skip creating a new prediction, re-attach to
        // this one, and run the normal download/finalize. No re-charge.
        public readonly ?string $resumePredictionId = null,
    ) {
        $this->onQueue('visual');
    }

    public function handle(I2VAdapter $adapter): void
    {
        $scene = Scene::query()->with(['project'])->find($this->sceneId);
        if (! $scene) {
            return;
        }

        GenerationProgressed::dispatch($this->projectId, 'animation', 'processing', null, ['scene_id' => $this->sceneId]);

        // Mark animation as in-progress so the editor can show distinct loading state.
        // animation_cost is what we'd refund if the user cancels.
        $cost = match ($this->tier) {
            'premium'       => \App\Services\CreditService::VIDEO_PREMIUM,
            'balanced'      => \App\Services\CreditService::VIDEO_BALANCED,
            'seedance_pro'  => \App\Services\CreditService::VIDEO_SEEDANCE_PRO,
            'seedance_lite' => \App\Services\CreditService::VIDEO_SEEDANCE_LITE,
            default         => \App\Services\CreditService::VIDEO_QUICK,
        } * ($this->durationSeconds >= 10 ? 2 : 1);
        $this->stampAnimationState($scene, [
            'animation_in_progress'   => true,
            'animation_last_error'    => null,
            'animation_started_at'    => now()->toIso8601String(),
            'animation_tier'          => $this->tier,
            'animation_duration'      => $this->durationSeconds,
            'animation_cost'          => $cost,
        ]);

        // Charge up-front (reserve) here — the SINGLE billing point for EVERY
        // animation path (manual editor, Cruise, one-shot, chained). Animation
        // used to be charged only on the manual path, so Cruise/one-shot clips
        // — the priciest op, ~75% of spend — were generated for free. Resume
        // re-dispatches were already paid on the first attempt, so skip them.
        // Refunded in catch() on failure, so a failed clip is free and a retry
        // (tries=2) nets to exactly one charge.
        $charged = false;
        if (! $this->resumePredictionId) {
            $charged = app(\App\Services\CreditService::class)->deduct(
                (int) $scene->project->workspace_id,
                $cost,
                "animate:{$this->tier}",
                [
                    'project_id' => $this->projectId,
                    'scene_id'   => $this->sceneId,
                    'user_id'    => $scene->project->created_by_user_id,
                    'metadata'   => ['tier' => $this->tier, 'duration_seconds' => $this->durationSeconds],
                ],
            );
            if (! $charged) {
                $this->stampAnimationState($scene, [
                    'animation_in_progress' => false,
                    'animation_last_error'  => 'Not enough credits to animate this scene.',
                ]);
                GenerationProgressed::dispatch($this->projectId, 'animation', 'failed', 'Not enough credits to animate this scene.', ['scene_id' => $this->sceneId]);
                app(CruiseActionRunService::class)->markStageFailed($this->projectId, 'animation', 'Not enough credits to animate this scene.', $this->sceneId);

                return;
            }
        }

        try {
            $sourceAsset = $scene->visual_asset_id
                ? Asset::query()->find($scene->visual_asset_id)
                : null;
            if (! $sourceAsset || ! $sourceAsset->storage_url) {
                throw new RuntimeException('Scene has no source image to animate.');
            }

            if ($this->resumePredictionId) {
                // Resume: re-attach to the prediction the dead worker started.
                $videoUrl = $adapter->pollExisting($this->resumePredictionId);
                if ($videoUrl === null) {
                    // Still cooking at Replicate — leave it in_progress; the
                    // next reaper sweep resumes again. Don't clear or fail.
                    return;
                }
                $result = [
                    'provider_key'     => $adapter->providerKey(),
                    'model_slug'       => 'resumed',
                    'video_url'        => $videoUrl,
                    'duration_seconds' => $this->durationSeconds,
                    'width'            => null,
                    'height'           => null,
                ];
            } else {
                $imageUrl = $this->publicUrlFor($sourceAsset);

                $result = $adapter->animate(
                    $imageUrl,
                    (string) $this->motionPrompt,
                    $this->tier,
                    $this->durationSeconds,
                    [
                        'aspect_ratio' => $scene->project->aspect_ratio ?? '9:16',
                        // Persist the prediction id the instant Replicate hands
                        // it over, so a mid-poll worker death is resumable.
                        'on_prediction_created' => function (string $pid) use ($scene): void {
                            $this->stampAnimationState($scene, ['animation_prediction_id' => $pid]);
                        },
                    ],
                );
            }

            // The user may have cancelled while Replicate was running. Bail before
            // downloading or swapping the asset — credits were already refunded by
            // the cancel endpoint.
            $freshSettings = $scene->fresh()->image_generation_settings_json ?? [];
            if (! empty($freshSettings['animation_cancel_requested'])) {
                return;
            }

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

            // Track the previous still so the user can revert. Only set if the current
            // asset is an image — re-animation should preserve the *first* original still,
            // not whatever video the last animation produced.
            $previousImageId = ($sourceAsset->asset_type === 'image') ? $sourceAsset->getKey() : null;
            $existingOriginal = data_get($scene->image_generation_settings_json, 'animation_original_image_asset_id');
            $originalToStore = $existingOriginal ?: $previousImageId;

            // Swap the scene's visual to the new video. visual_type stays 'ai_image' so the
            // user can regenerate the still later via the existing flow — the asset_type
            // ('video') is what drives renderer + preview behaviour.
            $scene->forceFill(['visual_asset_id' => $asset->getKey()])->save();

            // Append to a capped history so users can compare past animations.
            $fresh = $scene->fresh();
            $history = data_get($fresh->image_generation_settings_json, 'animation_history', []);
            if (! is_array($history)) $history = [];
            array_unshift($history, [
                'asset_id'      => $asset->getKey(),
                'tier'          => $this->tier,
                'duration'      => $this->durationSeconds,
                'motion_prompt' => $this->motionPrompt,
                'completed_at'  => now()->toIso8601String(),
            ]);
            $history = array_slice($history, 0, 3); // keep last 3

            $this->stampAnimationState($fresh, [
                'animation_in_progress'             => false,
                'animation_last_error'              => null,
                'animation_completed_at'            => now()->toIso8601String(),
                'animation_video_asset_id'          => $asset->getKey(),
                'animation_original_image_asset_id' => $originalToStore,
                'animation_history'                 => $history,
                'animation_prediction_id'           => null, // done — nothing to resume
            ]);

            // Multi-scene aware: count scenes with completed animation. Emit
            // 'processing' with done/total until all scenes finish; then
            // 'completed'. Keeps the progress page's animation stage honest
            // for one-shot multi-scene (otherwise it'd tick complete after
            // the first scene finishes animating).
            $aniTotal = Scene::query()->where('project_id', $this->projectId)->count();
            $aniDone  = Scene::query()->where('project_id', $this->projectId)
                ->whereRaw("(image_generation_settings_json->>'animation_video_asset_id') IS NOT NULL")
                ->count();
            $aniStatus = ($aniDone >= $aniTotal) ? 'completed' : 'processing';
            GenerationProgressed::dispatch($this->projectId, 'animation', $aniStatus, null, [
                'scene_id' => $this->sceneId,
                'done'     => $aniDone,
                'total'    => $aniTotal,
            ]);
            app(CruiseActionRunService::class)->markStageCompleted($this->projectId, 'animation', $this->sceneId);
        } catch (\Throwable $e) {
            // The clip failed — refund what we charged up-front so a failed
            // animation costs nothing. (On a retry the next attempt re-charges;
            // deduct + refund-on-fail nets to one charge on eventual success.)
            if ($charged) {
                app(\App\Services\CreditService::class)->refund(
                    (int) $scene->project->workspace_id,
                    $cost,
                    "animate:{$this->tier}",
                );
            }

            // Animation safety rejections from Replicate (Kling/Hailuo/Wan
            // each have their own content filters) get logged to
            // moderation_events the same way image gen rejections do.
            $msg = strtolower($e->getMessage());
            if (str_contains($msg, 'policy') || str_contains($msg, 'safety') || str_contains($msg, 'content') || str_contains($msg, 'nsfw')) {
                rescue(fn () => app(\App\Services\Moderation\ModerationService::class)->recordRejection(
                    $e->getMessage(),
                    [
                        'workspace_id' => $scene->project->workspace_id ?? null,
                        'user_id'      => $scene->project->created_by_user_id ?? null,
                        'project_id'   => $this->projectId,
                        'scene_id'     => $this->sceneId,
                        'operation'    => 'animate:' . ($this->tier ?? 'unknown'),
                        'prompt'       => $this->motionPrompt ?? null,
                        'reference_asset_id' => $scene->visual_asset_id,
                        'metadata'     => ['tier' => $this->tier ?? null],
                    ],
                ));
            }

            $this->stampAnimationState($scene->fresh() ?: $scene, [
                'animation_in_progress' => false,
                'animation_last_error'  => mb_substr($e->getMessage(), 0, 1000),
            ]);
            GenerationProgressed::dispatch($this->projectId, 'animation', 'failed', $e->getMessage(), ['scene_id' => $this->sceneId]);
            app(CruiseActionRunService::class)->markStageFailed($this->projectId, 'animation', $e->getMessage(), $this->sceneId);
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'scene', $this->sceneId, null, $this->projectId);
    }

    /**
     * Build a public, signed URL Replicate can fetch from.
     *
     * Replicate's i2v input validator sniffs the image format from the URL
     * path (extension), NOT from the response Content-Type header. So
     * `/media/assets/651` returns `Invalid image format ''` even though the
     * file is a valid PNG with the right MIME. We prefer the direct B2
     * public URL (which keeps the `.png` in the path) and only fall back to
     * the signed Laravel route when the asset is stored somewhere we can't
     * expose publicly.
     */
    private function publicUrlFor(Asset $asset): string
    {
        $storage = app(StorageService::class);
        $rawStorageUrl = (string) $asset->storage_url;

        // Already a plain HTTP URL → pass through.
        if ($storage->extractPath($rawStorageUrl) === null) {
            return $rawStorageUrl;
        }

        // Resolve to a public B2/MinIO URL with the original extension intact.
        try {
            $publicUrl = $storage->url($rawStorageUrl);
            if (filter_var($publicUrl, FILTER_VALIDATE_URL)
                && preg_match('/\.(png|jpe?g|webp)(\?|$)/i', $publicUrl)) {
                return $publicUrl;
            }
        } catch (\Throwable) {
            // Fall through to the signed-route fallback.
        }

        // Last resort: signed Laravel route. Replicate may reject if it
        // can't sniff the extension; that's the bug this function avoids
        // when the public URL path is available.
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
