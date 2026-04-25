<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Media\StorageService;
use App\Traits\RendersExportScenes;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Str;

/**
 * Renders one scene of an export to a temp MP4 and uploads it to MinIO.
 * Dispatched in a Bus::batch() by ProcessExportJob; ConcatenateExportJob
 * runs after all scenes complete to assemble the final video.
 */
class RenderSceneSegmentJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;
    use RendersExportScenes;

    public int $timeout = 1200;

    /** Tracked for cleanup if the worker is killed mid-render. */
    private ?string $tempDir = null;

    public function __construct(
        public readonly int $exportJobId,
        public readonly int $sceneId,
        public readonly int $sceneIndex,          // 0-based position
        public readonly int $totalScenes,
        public readonly float $elapsedSeconds,    // cumulative duration of preceding scenes
        public readonly float $totalDurationSeconds,
    ) {
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        $exportJob = ExportJob::query()->find($this->exportJobId);

        if (! $exportJob || $exportJob->status === 'failed') {
            return;
        }

        $scene   = Scene::query()->find($this->sceneId);
        $project = $exportJob->project_id ? Project::query()->find($exportJob->project_id) : null;

        if (! $scene || ! $project) {
            throw new \RuntimeException("Scene or project missing for export segment (scene #{$this->sceneId}).");
        }

        $dimensions = $this->dimensionsForAspectRatio((string) $exportJob->aspect_ratio);

        $this->tempDir = sys_get_temp_dir().'/framecast-scene-'.Str::uuid();
        if (! @mkdir($this->tempDir, 0777, true) && ! is_dir($this->tempDir)) {
            throw new \RuntimeException('Unable to allocate scene render temp directory.');
        }

        try {
            $audioAssetId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
            $audioAsset   = $audioAssetId > 0 ? Asset::query()->find($audioAssetId) : null;
            $visualAsset  = $scene->visual_asset_id ? Asset::query()->find((int) $scene->visual_asset_id) : null;
            $musicAsset   = $project->music_asset_id ? Asset::query()->find((int) $project->music_asset_id) : null;

            $segmentPath = $this->renderSceneSegment(
                $project,
                $scene,
                $visualAsset,
                $audioAsset,
                $musicAsset,
                $dimensions,
                $this->tempDir,
                $this->sceneIndex,
                $this->elapsedSeconds,
                $this->totalDurationSeconds,
            );

            // Upload to deterministic MinIO path so ConcatenateExportJob can find it.
            $storagePath = $this->tempSegmentStoragePath($this->exportJobId, $this->sceneIndex);
            $stream = fopen($segmentPath, 'rb');

            if (! is_resource($stream)) {
                throw new \RuntimeException("Could not open rendered segment for upload (scene #{$this->sceneId}).");
            }

            app(StorageService::class)->put($storagePath, $stream, ['ContentType' => 'video/mp4']);
            fclose($stream);
            @unlink($segmentPath);
        } finally {
            if ($this->tempDir !== null) {
                $this->cleanupTempDir($this->tempDir);
                $this->tempDir = null;
            }
        }

        // Update export progress proportionally.
        $progress = min(85, 10 + (int) floor((($this->sceneIndex + 1) / max(1, $this->totalScenes)) * 70));
        $exportJob->forceFill(['progress_percent' => $progress])->save();
        $this->dispatchProgress(
            $exportJob,
            'processing',
            $progress,
            'Rendered scene '.($this->sceneIndex + 1).' of '.$this->totalScenes.'.'
        );
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->tempDir !== null) {
            $this->cleanupTempDir($this->tempDir);
            $this->tempDir = null;
        }

        $this->recordFailureTrace($exception, 'export', $this->exportJobId);
    }
}
