<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\CruiseControl\CruiseActionRunService;
use App\Services\Generation\Video\ReplicateFabricAdapter;
use App\Services\Media\StorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Talking-spokesperson scene: turn the scene's still image + its voice audio
 * into a lip-synced talking video (VEED Fabric 1.0 via fal.ai). Reuses the
 * animation slot (animation_video_asset_id + 'animation' progress stage) and
 * the reserve/refund credit pattern, so it behaves like an animation tier in
 * the editor. REQUIRES the scene to already have a generated image AND a
 * voiceover — lip-sync needs the audio.
 */
class GenerateTalkingVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 900; // Fabric renders are slow (a few s of audio → minutes)

    public function __construct(
        public readonly int $sceneId,
        public readonly int $projectId,
        public readonly string $generationToken,
    ) {
        $this->onQueue('visual');
    }

    /**
     * Fire the talking-video job for a one-shot spokesperson scene once BOTH
     * its image and voice are ready. Called from the image job (post-image)
     * and the TTS job (post-voice) — whichever finishes last triggers it. The
     * Cache::add is an atomic one-time guard so the two callers can't both
     * dispatch.
     */
    public static function maybeDispatchForScene(Scene $scene): void
    {
        $s = $scene->fresh();
        if (! $s) {
            return;
        }
        $img = $s->image_generation_settings_json ?? [];
        if (empty($img['planned_spokesperson'])) {
            return;
        }
        if (! $s->visual_asset_id) {
            return; // image not ready yet
        }
        if (! data_get($s->voice_settings_json, 'audio_asset_id')) {
            return; // voice not ready yet
        }
        // Atomic one-time guard — only the first caller (image OR voice) wins.
        if (! \Illuminate\Support\Facades\Cache::add('spokesperson-dispatch:'.$s->getKey(), 1, 3600)) {
            return;
        }

        $token = (string) Str::uuid();
        $s->forceFill([
            'image_generation_settings_json' => array_merge($img, [
                'animation_in_progress' => true,
                'animation_last_error'  => null,
                'animation_tier'        => 'spokesperson',
                'animation_cost'        => CreditService::VIDEO_SPOKESPERSON,
                'animation_started_at'  => now()->toIso8601String(),
                'generation_token'      => $token,
            ]),
        ])->save();

        self::dispatch($s->getKey(), (int) $s->project_id, $token);
    }

    public function handle(ReplicateFabricAdapter $adapter): void
    {
        $scene = Scene::query()->with(['project'])->find($this->sceneId);
        if (! $scene) {
            return;
        }

        GenerationProgressed::dispatch($this->projectId, 'animation', 'processing', null, ['scene_id' => $this->sceneId]);

        // Length-based: cost + COGS follow the voiceover length (Fabric bills
        // per second). Mirror SceneController::animate so charge == quote.
        $audioId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
        $audioAsset = $audioId ? Asset::query()->find($audioId) : null;
        $voiceoverSeconds = (float) ($audioAsset?->duration_seconds ?: $scene->duration_seconds ?: 8);
        $resolution = (string) config('services.fabric.resolution', '480p');

        $cost = CreditService::spokespersonCost($voiceoverSeconds);
        $this->stamp($scene, [
            'animation_in_progress' => true,
            'animation_last_error'  => null,
            'animation_started_at'  => now()->toIso8601String(),
            'animation_tier'        => 'spokesperson',
            'animation_cost'        => $cost,
        ]);

        $charged = app(CreditService::class)->deduct(
            (int) $scene->project->workspace_id,
            $cost,
            'animate:spokesperson',
            [
                'project_id'        => $this->projectId,
                'scene_id'          => $this->sceneId,
                'user_id'           => $scene->project->created_by_user_id,
                'upstream_cost_usd' => CreditService::spokespersonCogsUsd($voiceoverSeconds, $resolution),
                'metadata'          => ['tier' => 'spokesperson', 'seconds' => round($voiceoverSeconds, 1)],
            ],
        );
        if (! $charged) {
            $this->fail($scene, 'Not enough credits to make this scene a talking spokesperson.');
            return;
        }

        try {
            // On a RE-render the scene's visual is already the talking video, so
            // lip-sync from the preserved original still instead.
            $origStillId = (int) (data_get($scene->image_generation_settings_json, 'animation_original_image_asset_id') ?? 0);
            $imageAsset = $scene->visual_asset_id ? Asset::query()->find($scene->visual_asset_id) : null;
            if (! $imageAsset || $imageAsset->asset_type !== 'image') {
                $imageAsset = $origStillId ? Asset::query()->find($origStillId) : $imageAsset;
            }
            if (! $imageAsset || ! $imageAsset->storage_url) {
                throw new RuntimeException('Generate the scene image first, then lip-sync it.');
            }
            if ($imageAsset->asset_type !== 'image') {
                throw new RuntimeException('This scene is already a video — lip-sync needs a still image.');
            }
            $audioAssetId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
            $audioAsset = $audioAssetId > 0 ? Asset::query()->find($audioAssetId) : null;
            if (! $audioAsset || ! $audioAsset->storage_url) {
                throw new RuntimeException('Generate the voiceover first — lip-sync needs the audio.');
            }

            // Submit, stash the prediction id for visibility/resume, then poll.
            $predictionId = $adapter->start(
                $this->publicUrl($imageAsset),
                $this->publicUrl($audioAsset),
            );
            $this->stamp($scene, ['animation_prediction_id' => $predictionId]);

            $videoUrl = $adapter->pollUntilDone($predictionId);
            if ($videoUrl === null) {
                // Still rendering at Replicate after the poll window — leave it
                // in-progress (prediction id stashed) and let a retry/reaper
                // pick it up. Do NOT refund yet; the clip may still land.
                return;
            }

            // Respect a cancel requested mid-render.
            if (data_get($scene->fresh()->image_generation_settings_json, 'animation_cancel_requested')) {
                app(CreditService::class)->refund((int) $scene->project->workspace_id, $cost, 'animate:spokesperson');
                return;
            }

            $bytes = Http::timeout(120)->get($videoUrl)->body();
            if ($bytes === '') {
                throw new RuntimeException('Fabric returned a video URL but the file was empty.');
            }
            $path = sprintf('workspaces/%s/assets/spokesperson/%s.mp4', $scene->project->workspace_id, Str::uuid());
            $storageUrl = app(StorageService::class)->put($path, $bytes);

            $asset = Asset::query()->create([
                'workspace_id'       => $scene->project->workspace_id,
                'channel_id'         => $scene->project->channel_id,
                'asset_type'         => 'video',
                'title'              => "Talking Spokesperson — Scene {$scene->scene_order}",
                'description'        => 'Lip-synced talking video (Fabric 1.0)',
                'storage_url'        => $storageUrl,
                'thumbnail_url'      => $imageAsset->storage_url,
                'mime_type'          => 'video/mp4',
                'tags'               => ['ai_animated', $adapter->providerKey(), 'spokesperson'],
                'source'             => 'ai_generated',
                'usage_count'        => 1,
                'status'             => 'active',
                'created_by_user_id' => $scene->project->created_by_user_id,
            ]);

            // Preserve the first original still for revert; swap the scene's
            // visual to the talking video (same slot as i2v animation).
            $existingOriginal = data_get($scene->image_generation_settings_json, 'animation_original_image_asset_id');
            $scene->forceFill(['visual_asset_id' => $asset->getKey()])->save();
            $this->stamp($scene->fresh(), [
                'animation_in_progress'             => false,
                'animation_last_error'              => null,
                'animation_completed_at'            => now()->toIso8601String(),
                'animation_video_asset_id'          => $asset->getKey(),
                'animation_original_image_asset_id' => $existingOriginal ?: $imageAsset->getKey(),
                'animation_prediction_id'           => null,
                // Record the voice this clip was lip-synced to. If the scene's
                // voice later changes (new TTS), the lips no longer match —
                // flagged via animation_outdated (see GenerateTTSJob).
                'animation_source_audio_asset_id'   => $audioAssetId,
                'animation_outdated'                => false,
            ]);

            $aniTotal = Scene::query()->where('project_id', $this->projectId)->count();
            $aniDone  = Scene::query()->where('project_id', $this->projectId)
                ->whereRaw("(image_generation_settings_json->>'animation_video_asset_id') IS NOT NULL")->count();
            GenerationProgressed::dispatch($this->projectId, 'animation', $aniDone >= $aniTotal ? 'completed' : 'processing', null, [
                'scene_id' => $this->sceneId, 'done' => $aniDone, 'total' => $aniTotal,
            ]);
            app(CruiseActionRunService::class)->markStageCompleted($this->projectId, 'animation', $this->sceneId);
            rescue(fn () => app(\App\Services\Generation\PipelineStatusService::class)->maybeMarkReady($this->projectId));
        } catch (\Throwable $e) {
            if ($charged) {
                app(CreditService::class)->refund((int) $scene->project->workspace_id, $cost, 'animate:spokesperson');
            }
            $this->fail($scene, mb_substr($e->getMessage(), 0, 300));
        }
    }

    private function publicUrl(Asset $asset): string
    {
        $storage = app(StorageService::class);
        $raw = (string) $asset->storage_url;

        return $storage->extractPath($raw) !== null ? $storage->url($raw) : $raw;
    }

    private function stamp(Scene $scene, array $delta): void
    {
        $scene->forceFill([
            'image_generation_settings_json' => array_merge($scene->image_generation_settings_json ?? [], $delta),
        ])->save();
    }

    private function fail(Scene $scene, string $message): void
    {
        $this->stamp($scene, ['animation_in_progress' => false, 'animation_last_error' => $message]);
        GenerationProgressed::dispatch($this->projectId, 'animation', 'failed', $message, ['scene_id' => $this->sceneId]);
        rescue(fn () => app(CruiseActionRunService::class)->markStageFailed($this->projectId, 'animation', $message, $this->sceneId));
    }
}
