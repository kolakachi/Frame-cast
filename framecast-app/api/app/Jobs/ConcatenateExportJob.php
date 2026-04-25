<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Variant;
use App\Services\ApiUsageService;
use App\Services\Media\StorageService;
use App\Traits\RendersExportScenes;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Downloads all per-scene segment files from MinIO, concatenates them,
 * applies the music mix, uploads the final MP4, and marks the export complete.
 * Dispatched by ProcessExportJob's batch `.then()` callback once every
 * RenderSceneSegmentJob has succeeded.
 */
class ConcatenateExportJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;
    use RendersExportScenes;

    public int $timeout = 900;

    private ?string $tempDir = null;

    public function __construct(
        public readonly int $exportJobId,
        public readonly int $sceneCount,
    ) {
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        $exportJob = ExportJob::query()->find($this->exportJobId);

        if (! $exportJob || $exportJob->status === 'failed') {
            return;
        }

        $project = Project::query()->find($exportJob->project_id);

        if (! $project) {
            throw new \RuntimeException('Project not found for export concatenation.');
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        $exportJob->forceFill(['progress_percent' => 88])->save();
        $this->dispatchProgress($exportJob, 'processing', 88, 'Assembling final video…');

        $this->tempDir = sys_get_temp_dir().'/framecast-concat-'.Str::uuid();
        if (! @mkdir($this->tempDir, 0777, true) && ! is_dir($this->tempDir)) {
            throw new \RuntimeException('Unable to allocate concat temp directory.');
        }

        $outputFile = $this->tempDir.'/output.mp4';

        try {
            $segmentPaths = $this->downloadSegments();

            $this->concatSegments($segmentPaths, $outputFile, $this->tempDir);

            // Clean up local segment copies immediately after concat.
            foreach ($segmentPaths as $path) {
                if (is_file($path)) { @unlink($path); }
            }

            if ($project->music_asset_id) {
                $musicAsset = Asset::query()->find($project->music_asset_id);
                if ($musicAsset) {
                    $musicedFile = $this->tempDir.'/output_music.mp4';
                    $this->applyMusicMix($project, $musicAsset, $outputFile, $musicedFile, $this->tempDir);
                    @unlink($outputFile);
                    rename($musicedFile, $outputFile);
                }
            }

            // Deterministic path so retries overwrite rather than leak a second file.
            $storagePath = 'exports/export-'.$exportJob->getKey().'.mp4';
            $stream = fopen($outputFile, 'rb');

            if (! is_resource($stream)) {
                throw new \RuntimeException('Unable to open rendered export output.');
            }

            $exportStorageUrl = app(StorageService::class)->put($storagePath, $stream, ['ContentType' => 'video/mp4']);
            fclose($stream);

            if (! $this->storageUrlExists($exportStorageUrl)) {
                throw new \RuntimeException('Export output could not be verified in storage.');
            }

            $fileSize = filesize($outputFile) ?: null;
            @unlink($outputFile);

            $totalDurationSeconds = (float) $scenes->sum(fn (Scene $s): float => (float) ($s->duration_seconds ?: 0));
            $dimensions = $this->dimensionsForAspectRatio((string) $exportJob->aspect_ratio);

            $assetCreated = false;

            DB::transaction(function () use ($exportJob, $exportStorageUrl, $fileSize, $totalDurationSeconds, $dimensions, &$assetCreated): void {
                $fresh = ExportJob::query()->lockForUpdate()->find($exportJob->getKey());

                // Another attempt already completed — skip.
                if ($fresh && $fresh->output_asset_id) {
                    return;
                }

                $asset = Asset::query()->create([
                    'workspace_id'    => $exportJob->workspace_id,
                    'channel_id'      => null,
                    'asset_type'      => 'video',
                    'title'           => $exportJob->file_name,
                    'description'     => 'Rendered export output',
                    'storage_url'     => $exportStorageUrl,
                    'duration_seconds'=> $totalDurationSeconds,
                    'dimensions_json' => $dimensions,
                    'file_size_bytes' => $fileSize,
                    'mime_type'       => 'video/mp4',
                    'tags'            => ['export', $exportJob->aspect_ratio, $exportJob->language],
                    'usage_count'     => 1,
                    'status'          => 'active',
                    'created_by_user_id' => null,
                ]);

                $exportJob->forceFill([
                    'status'           => 'completed',
                    'progress_percent' => 100,
                    'completed_at'     => now(),
                    'output_asset_id'  => $asset->getKey(),
                ])->save();

                $this->purgePreviousExports($exportJob, $asset->getKey());
                $assetCreated = true;
            });

            if ($assetCreated) {
                $renderSeconds = $exportJob->started_at
                    ? (int) now()->diffInSeconds($exportJob->started_at)
                    : 0;
                $fileSizeMb = ($fileSize ?? 0) / 1_048_576;
                $estimatedCostUsd = round($renderSeconds * 0.0001 + $fileSizeMb * 0.00001, 6);

                rescue(fn () => app(ApiUsageService::class)->record([
                    'workspace_id'       => $exportJob->workspace_id,
                    'project_id'         => $exportJob->project_id,
                    'provider'           => 'system',
                    'service'            => 'export',
                    'operation'          => 'render',
                    'status'             => 'succeeded',
                    'units'              => $renderSeconds,
                    'estimated_cost_usd' => $estimatedCostUsd,
                    'metadata_json'      => [
                        'export_job_id'           => $exportJob->getKey(),
                        'aspect_ratio'            => $exportJob->aspect_ratio,
                        'language'                => $exportJob->language,
                        'file_size_bytes'         => $fileSize,
                        'render_seconds'          => $renderSeconds,
                        'output_duration_seconds' => $totalDurationSeconds,
                        'scene_count'             => $this->sceneCount,
                    ],
                ]), false);
            }

            if ($exportJob->variant_id) {
                Variant::query()->whereKey((int) $exportJob->variant_id)->update(['status' => 'rendered']);
            }

            $this->syncBatchJob($exportJob->fresh());
            $this->dispatchProgress($exportJob, 'completed', 100, 'Export complete.');

        } finally {
            if ($this->tempDir !== null) {
                $this->cleanupTempDir($this->tempDir);
                $this->tempDir = null;
            }
            // Always delete temp segments from MinIO regardless of success/failure.
            $this->deleteTempSegments($this->exportJobId, $this->sceneCount);
        }
    }

    public function failed(\Throwable $exception): void
    {
        if ($this->tempDir !== null) {
            $this->cleanupTempDir($this->tempDir);
            $this->tempDir = null;
        }

        $this->deleteTempSegments($this->exportJobId, $this->sceneCount);

        $this->recordFailureTrace($exception, 'export', $this->exportJobId);

        $exportJob = ExportJob::query()->find($this->exportJobId);
        if (! $exportJob) { return; }

        $userSafeFailure = $this->summarizeFailureForUser($exception);
        $exportJob->forceFill(['status' => 'failed', 'failure_reason' => $userSafeFailure])->save();

        if ($exportJob->variant_id) {
            Variant::query()->whereKey((int) $exportJob->variant_id)->update(['status' => 'failed']);
        }

        $this->syncBatchJob($exportJob->fresh());
        $this->dispatchProgress($exportJob, 'failed', (int) $exportJob->progress_percent, $userSafeFailure);
    }

    /** @return list<string> Local paths of downloaded segment files, in order. */
    private function downloadSegments(): array
    {
        $storage = app(StorageService::class);
        $paths   = [];

        for ($i = 0; $i < $this->sceneCount; $i++) {
            $storagePath = $this->tempSegmentStoragePath($this->exportJobId, $i);
            $localPath   = sprintf('%s/segment-%03d.mp4', $this->tempDir, $i + 1);

            $stream = $storage->readStream($storagePath);

            if (! is_resource($stream)) {
                throw new \RuntimeException("Segment {$i} missing from storage (export #{$this->exportJobId}).");
            }

            $target = fopen($localPath, 'wb');
            if (! is_resource($target)) {
                fclose($stream);
                throw new \RuntimeException("Could not write local segment {$i} for concat.");
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            $paths[] = $localPath;
        }

        return $paths;
    }
}
