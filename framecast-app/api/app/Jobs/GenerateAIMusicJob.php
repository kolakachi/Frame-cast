<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\CruiseControl\CruiseActionRunService;
use App\Services\Generation\Music\ReplicateMusicAdapter;
use App\Services\Media\StorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Generate background music via MusicGen on Replicate, download the audio,
 * store it in the workspace's asset library, and set it as the project's
 * music bed (project.music_asset_id). Music is project-wide — it is NOT a
 * per-scene sound effect, so it never touches scene.sound_asset_id.
 *
 * Used by the new one-shot prompt flow (see ProjectController::storeOneShot)
 * to give the video a music bed without the user choosing one from the
 * stock library.
 */
class GenerateAIMusicJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 240;
    public int $tries   = 1;

    public function __construct(
        public readonly int $sceneId,
        public readonly int $projectId,
        public readonly string $prompt,
        public readonly ?string $genre = null,
        public readonly int $durationSeconds = 8,
    ) {
        $this->onQueue('generation');
    }

    public function handle(ReplicateMusicAdapter $adapter, StorageService $storage): void
    {
        $scene = Scene::query()->with('project')->find($this->sceneId);
        if (! $scene || ! $scene->project) {
            return;
        }

        GenerationProgressed::dispatch($this->projectId, 'ai_music', 'processing', null, ['scene_id' => $this->sceneId]);

        try {
            $result = $adapter->generate($this->prompt, $this->durationSeconds, $this->genre);

            // Download the audio from Replicate's CDN and persist it in B2
            // so the URL doesn't expire when Replicate prunes the prediction.
            $audioBytes = file_get_contents($result['audio_url']);
            if ($audioBytes === false) {
                throw new \RuntimeException('Could not download generated audio from Replicate.');
            }

            $storagePath = 'workspaces/' . $scene->project->workspace_id
                . '/assets/ai-music/' . \Illuminate\Support\Str::uuid() . '.mp3';
            $storage->put($storagePath, $audioBytes);

            $asset = Asset::query()->create([
                'workspace_id'       => $scene->project->workspace_id,
                'channel_id'         => $scene->project->channel_id,
                // 'music' (not 'audio') so the editor's music picker
                // — which queries /assets?asset_type=music — finds it.
                // Matches what MusicTrackSeeder uses for stock tracks.
                'asset_type'         => 'music',
                'title'              => 'AI Music — ' . \Illuminate\Support\Str::limit($this->prompt, 40),
                'description'        => $this->prompt,
                'storage_url'        => $storagePath,
                'thumbnail_url'      => null,
                'duration_seconds'   => $result['duration'],
                'mime_type'          => 'audio/mpeg',
                'tags'               => ['ai_generated', 'replicate:musicgen', $this->genre ?? 'general'],
                'source'             => 'ai_generated',
                'usage_count'        => 1,
                'status'             => 'active',
                'created_by_user_id' => $scene->project->created_by_user_id,
            ]);

            // Music is the project-wide BED — it lives only on
            // project.music_asset_id. Do NOT also write it to
            // scene.sound_asset_id: that slot is per-scene SOUND EFFECTS
            // (door opening, wind), the renderer mixes BOTH layers into
            // every segment, and writing music there mixed it in twice
            // (doubled volume) and made it show up under the scene's Sound.
            $scene->project->forceFill([
                'music_asset_id'      => $asset->getKey(),
                'music_settings_json' => array_merge(
                    $scene->project->music_settings_json ?? [],
                    ['volume' => 0.3, 'fade_in_ms' => 500, 'fade_out_ms' => 800, 'source' => 'ai_generated'],
                ),
            ])->save();

            rescue(fn () => app(CreditService::class)->deduct(
                (int) $scene->project->workspace_id,
                CreditService::AI_MUSIC,
                'ai_music',
                [
                    'project_id' => $this->projectId,
                    'scene_id'   => $this->sceneId,
                    'user_id'    => $scene->project->created_by_user_id,
                    'upstream_cost_usd' => CreditService::cogsUsd('music'),
                    'metadata'   => ['provider_key' => $result['provider_key'], 'genre' => $this->genre],
                ],
            ));

            GenerationProgressed::dispatch($this->projectId, 'ai_music', 'completed', null, [
                'scene_id' => $this->sceneId,
                'asset_id' => $asset->getKey(),
            ]);
            app(CruiseActionRunService::class)->markStageCompleted($this->projectId, 'ai_music', $this->sceneId);
        } catch (\Throwable $e) {
            // ERROR not warning: this is the path that ate the prod 404
            // for ~a day without surfacing — silent swallow + no retry
            // meant nobody knew music was broken. Loud-log so it lands
            // in Sentry / log aggregation immediately next time.
            Log::error('GenerateAIMusicJob: failed; scene continues without music', [
                'scene_id'   => $this->sceneId,
                'project_id' => $this->projectId,
                'prompt'     => $this->prompt,
                'error'      => $e->getMessage(),
            ]);
            // Music failure should NOT block the rest of the one-shot.
            // The scene already has its image + animation + voice; missing
            // music is recoverable in the editor.
            GenerationProgressed::dispatch($this->projectId, 'ai_music', 'failed', $e->getMessage(), ['scene_id' => $this->sceneId]);
            app(CruiseActionRunService::class)->markStageFailed($this->projectId, 'ai_music', $e->getMessage(), $this->sceneId);
        }
    }
}
