<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\FootageSession;
use App\Models\Project;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\Generation\Video\ReplicateModifyVideoAdapter;
use App\Services\Media\StorageService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/**
 * Video-to-video restyle for the My Footage flow: the user's clip plus their
 * instruction, re-rendered by Luma Modify with the original motion kept, and
 * the source's own soundtrack muxed back on — Luma returns silent video, and
 * the screams are half the reel.
 *
 * Charge-on-success like every other generation: a failed restyle costs
 * nothing and leaves the error on the scene for a free retry.
 */
class RestyleVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 1100;

    public $tries = 1;

    public function __construct(
        public readonly int $footageSessionId,
        public readonly int $projectId,
        public readonly int $sceneId,
        public readonly string $prompt,
        public readonly string $mode,
        public readonly int $credits,
    ) {
        $this->onQueue('generation');
    }

    public function handle(ReplicateModifyVideoAdapter $adapter, StorageService $storage, CreditService $credits): void
    {
        $session = FootageSession::query()->find($this->footageSessionId);
        $scene = Scene::query()->find($this->sceneId);
        $source = $session?->sourceAsset;
        if (! $session || ! $scene || ! $source) {
            return; // deleted mid-flight; nothing to charge, nothing to do
        }

        $temps = [];
        $stagedKey = null;
        try {
            // Source out of managed storage onto disk.
            $srcPath = tempnam(sys_get_temp_dir(), 'restyle-in-').'.mp4';
            $temps[] = $srcPath;
            $stream = $storage->readStream((string) $source->storage_url);
            if (! is_resource($stream)) {
                throw new \RuntimeException('The source video could not be read from storage.');
            }
            file_put_contents($srcPath, $stream);

            $staged = $adapter->uploadSource($srcPath);
            $stagedKey = $staged['key'];

            $predictionId = $adapter->start($staged['url'], $this->prompt, $this->mode);
            $scene->forceFill(['image_generation_settings_json' => array_merge(
                $scene->image_generation_settings_json ?? [],
                ['restyle_prediction_id' => $predictionId],
            )])->save();

            $outputUrl = $adapter->pollUntilDone($predictionId);
            if ($outputUrl === null || $outputUrl === '') {
                throw new \RuntimeException('The restyle did not finish in time. Retry it — nothing was charged.');
            }

            $outPath = tempnam(sys_get_temp_dir(), 'restyle-out-').'.mp4';
            $temps[] = $outPath;
            file_put_contents($outPath, file_get_contents($outputUrl));
            if (! filesize($outPath)) {
                throw new \RuntimeException('The restyled video came back empty.');
            }

            $finalPath = $this->remuxSourceAudio($outPath, $srcPath, $temps);

            $path = sprintf('workspaces/%d/assets/restyled/%s.mp4', $session->workspace_id, Str::uuid());
            $storageUrl = $storage->put($path, file_get_contents($finalPath), ['ContentType' => 'video/mp4']);

            $probe = Process::timeout(30)->run([
                'ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $finalPath,
            ]);
            $duration = (float) trim($probe->output()) ?: null;

            $asset = Asset::query()->create([
                'workspace_id' => $session->workspace_id,
                'asset_type' => 'video',
                'title' => mb_substr('Restyled — '.$source->title, 0, 255),
                'description' => mb_substr('Restyle instruction: '.$this->prompt, 0, 1000),
                'storage_url' => $storageUrl,
                'mime_type' => 'video/mp4',
                'file_size_bytes' => filesize($finalPath),
                'duration_seconds' => $duration,
                'tags' => ['restyled'],
                'status' => 'active',
                'created_by_user_id' => $session->user_id,
            ]);

            $scene->forceFill([
                'visual_asset_id' => $asset->id,
                'duration_seconds' => $duration ?: $scene->duration_seconds,
                'status' => 'ready',
                'image_generation_settings_json' => array_merge(
                    $scene->image_generation_settings_json ?? [],
                    ['in_progress' => false, 'last_error' => null],
                ),
            ])->save();

            Project::query()->whereKey($this->projectId)->update(['status' => 'ready_for_review']);

            // Only now, with the output stored and attached, does money move.
            $credits->deduct($session->workspace_id, $this->credits, 'video_restyle', [
                'project_id' => $this->projectId,
                'scene_id' => $this->sceneId,
                'footage_session_id' => $this->footageSessionId,
            ]);
        } finally {
            if ($stagedKey !== null) {
                $adapter->removeSource($stagedKey);
            }
            foreach ($temps as $t) {
                @unlink($t);
            }
        }
    }

    public function failed(\Throwable $e): void
    {
        Scene::query()->whereKey($this->sceneId)->get()->each(function (Scene $scene) use ($e): void {
            $scene->forceFill(['image_generation_settings_json' => array_merge(
                $scene->image_generation_settings_json ?? [],
                ['in_progress' => false, 'last_error' => mb_substr($e->getMessage(), 0, 300)],
            )])->save();
        });
    }

    /**
     * Luma's output carries no audio; the source soundtrack goes back on when
     * it has one. Output video is never re-encoded.
     */
    private function remuxSourceAudio(string $outPath, string $srcPath, array &$temps): string
    {
        $hasAudio = trim(Process::timeout(30)->run([
            'ffprobe', '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=codec_type', '-of', 'csv=p=0', $srcPath,
        ])->output()) !== '';
        if (! $hasAudio) {
            return $outPath;
        }

        $muxed = tempnam(sys_get_temp_dir(), 'restyle-mux-').'.mp4';
        $temps[] = $muxed;
        $result = Process::timeout(180)->run([
            'ffmpeg', '-y', '-i', $outPath, '-i', $srcPath,
            '-map', '0:v:0', '-map', '1:a:0', '-c:v', 'copy', '-c:a', 'aac', '-shortest', $muxed,
        ]);

        return $result->successful() && filesize($muxed) ? $muxed : $outPath;
    }
}
