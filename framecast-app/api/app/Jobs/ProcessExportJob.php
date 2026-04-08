<?php

namespace App\Jobs;

use App\Events\ExportProgressed;
use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly int $exportJobId)
    {
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        $exportJob = ExportJob::query()->find($this->exportJobId);

        if (! $exportJob) {
            return;
        }

        $exportJob->forceFill([
            'status' => 'processing',
            'progress_percent' => 5,
            'started_at' => now(),
        ])->save();

        $this->dispatchProgress(
            (int) $exportJob->project_id,
            (int) $exportJob->getKey(),
            'processing',
            5,
            'Export processing started.'
        );

        $project = Project::query()->find($exportJob->project_id);

        if (! $project) {
            throw new \RuntimeException('Project not found for export.');
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        if ($scenes->isEmpty()) {
            throw new \RuntimeException('Project has no scenes to export.');
        }

        $dimensions = $this->dimensionsForAspectRatio((string) $exportJob->aspect_ratio);
        $tempDir = sys_get_temp_dir().'/framecast-export-'.Str::uuid();

        if (! @mkdir($tempDir, 0777, true) && ! is_dir($tempDir)) {
            throw new \RuntimeException('Unable to allocate export temp directory.');
        }

        $outputFile = $tempDir.'/output.mp4';

        $assetIds = $scenes
            ->flatMap(function (Scene $scene): array {
                $ids = [];

                if ($scene->visual_asset_id) {
                    $ids[] = (int) $scene->visual_asset_id;
                }

                $audioAssetId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
                if ($audioAssetId > 0) {
                    $ids[] = $audioAssetId;
                }

                return $ids;
            })
            ->unique()
            ->values();

        /** @var Collection<int, Asset> $assetMap */
        $assetMap = Asset::query()
            ->whereIn('id', $assetIds)
            ->get()
            ->keyBy('id');

        $segmentPaths = [];

        try {
            foreach ($scenes->values() as $index => $scene) {
                $visualAsset = $scene->visual_asset_id
                    ? $assetMap->get((int) $scene->visual_asset_id)
                    : null;
                $audioAssetId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
                $audioAsset = $audioAssetId > 0 ? $assetMap->get($audioAssetId) : null;

                $segmentPaths[] = $this->renderSceneSegment(
                    $scene,
                    $visualAsset,
                    $audioAsset,
                    $dimensions,
                    $tempDir,
                    $index
                );

                $progress = min(
                    90,
                    10 + (int) floor((($index + 1) / max(1, $scenes->count())) * 70)
                );

                $exportJob->forceFill([
                    'progress_percent' => $progress,
                ])->save();

                $this->dispatchProgress(
                    (int) $exportJob->project_id,
                    (int) $exportJob->getKey(),
                    'processing',
                    $progress,
                    'Rendered scene '.($index + 1).' of '.$scenes->count().'.'
                );
            }

            $this->concatSegments($segmentPaths, $outputFile, $tempDir);
        } finally {
            foreach ($segmentPaths as $segmentPath) {
                if (is_string($segmentPath) && is_file($segmentPath)) {
                    @unlink($segmentPath);
                }
            }
        }

        $storagePath = 'exports/'.Str::uuid().'.mp4';
        $stream = fopen($outputFile, 'rb');

        if (! is_resource($stream)) {
            @unlink($outputFile);
            throw new \RuntimeException('Unable to open rendered export output.');
        }

        Storage::disk('b2')->put($storagePath, $stream, [
            'ContentType' => 'video/mp4',
        ]);
        fclose($stream);

        $fileSize = filesize($outputFile) ?: null;
        @unlink($outputFile);
        @rmdir($tempDir);

        $asset = Asset::query()->create([
            'workspace_id' => $exportJob->workspace_id,
            'channel_id' => null,
            'asset_type' => 'video',
            'title' => $exportJob->file_name,
            'description' => 'Rendered export output',
            'storage_url' => 'b2://'.$storagePath,
            'duration_seconds' => (float) $scenes->sum(fn (Scene $scene): float => (float) ($scene->duration_seconds ?: 0)),
            'dimensions_json' => $dimensions,
            'file_size_bytes' => $fileSize,
            'mime_type' => 'video/mp4',
            'tags' => ['export', $exportJob->aspect_ratio, $exportJob->language],
            'usage_count' => 1,
            'status' => 'active',
            'created_by_user_id' => null,
        ]);

        $exportJob->forceFill([
            'status' => 'completed',
            'progress_percent' => 100,
            'completed_at' => now(),
            'output_asset_id' => $asset->getKey(),
        ])->save();

        $this->dispatchProgress(
            (int) $exportJob->project_id,
            (int) $exportJob->getKey(),
            'completed',
            100,
            'Export complete.'
        );
    }

    public function failed(\Throwable $exception): void
    {
        $exportJob = ExportJob::query()->find($this->exportJobId);

        if (! $exportJob) {
            return;
        }

        $exportJob->forceFill([
            'status' => 'failed',
            'failure_reason' => $exception->getMessage(),
        ])->save();

        $this->dispatchProgress(
            (int) $exportJob->project_id,
            (int) $exportJob->getKey(),
            'failed',
            (int) $exportJob->progress_percent,
            $exception->getMessage()
        );
    }

    /**
     * @return array{width:int,height:int}
     */
    private function dimensionsForAspectRatio(string $aspectRatio): array
    {
        return match ($aspectRatio) {
            '16:9' => ['width' => 1920, 'height' => 1080],
            '1:1' => ['width' => 1080, 'height' => 1080],
            default => ['width' => 1080, 'height' => 1920],
        };
    }

    /**
     * @param array{width:int,height:int} $dimensions
     */
    private function renderSceneSegment(
        Scene $scene,
        ?Asset $visualAsset,
        ?Asset $audioAsset,
        array $dimensions,
        string $tempDir,
        int $index
    ): string {
        $duration = max(
            1.0,
            (float) ($audioAsset?->duration_seconds ?: $scene->duration_seconds ?: 3.0)
        );
        $segmentPath = sprintf('%s/segment-%03d.mp4', $tempDir, $index + 1);
        $captionText = $this->escapeDrawtext((string) ($scene->script_text ?: $scene->label ?: 'Framecast'));
        $filter = sprintf(
            "scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,drawtext=text='%s':fontcolor=white:fontsize=48:x=(w-text_w)/2:y=h-th-120:box=1:boxcolor=black@0.45:boxborderw=24",
            $dimensions['width'],
            $dimensions['height'],
            $dimensions['width'],
            $dimensions['height'],
            $captionText
        );

        $command = ['ffmpeg', '-y'];
        $cleanupPaths = [];

        try {
            if ($visualAsset) {
                $visualPath = $this->materializeAsset($visualAsset, $tempDir, 'visual-'.$index);
                $cleanupPaths[] = $visualPath;
                $isVideo = $visualAsset->asset_type === 'video'
                    || str_starts_with((string) $visualAsset->mime_type, 'video/');

                if ($isVideo) {
                    array_push($command, '-stream_loop', '-1', '-i', $visualPath);
                } else {
                    array_push($command, '-loop', '1', '-framerate', '30', '-i', $visualPath);
                }
            } else {
                array_push(
                    $command,
                    '-f',
                    'lavfi',
                    '-i',
                    sprintf('color=c=black:s=%dx%d:d=%s', $dimensions['width'], $dimensions['height'], $duration)
                );
            }

            if ($audioAsset) {
                $audioPath = $this->materializeAsset($audioAsset, $tempDir, 'audio-'.$index);
                $cleanupPaths[] = $audioPath;
                array_push($command, '-i', $audioPath);
            } else {
                array_push($command, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo');
            }

            array_push(
                $command,
                '-t',
                (string) $duration,
                '-vf',
                $filter,
                '-c:v',
                'libx264',
                '-pix_fmt',
                'yuv420p',
                '-c:a',
                'aac',
                '-shortest',
                '-movflags',
                '+faststart',
                $segmentPath
            );

            $process = new Process($command);
            $process->setTimeout(180);
            $process->mustRun();

            return $segmentPath;
        } finally {
            foreach ($cleanupPaths as $cleanupPath) {
                if (is_file($cleanupPath)) {
                    @unlink($cleanupPath);
                }
            }
        }
    }

    /**
     * @param list<string> $segmentPaths
     */
    private function concatSegments(array $segmentPaths, string $outputFile, string $tempDir): void
    {
        if ($segmentPaths === []) {
            throw new \RuntimeException('No rendered scene segments available.');
        }

        $concatFile = $tempDir.'/segments.txt';
        $concatBody = implode(
            PHP_EOL,
            array_map(
                static fn (string $path): string => "file '".str_replace("'", "'\\''", $path)."'",
                $segmentPaths
            )
        );
        file_put_contents($concatFile, $concatBody.PHP_EOL);

        $process = new Process([
            'ffmpeg',
            '-y',
            '-f',
            'concat',
            '-safe',
            '0',
            '-i',
            $concatFile,
            '-c:v',
            'libx264',
            '-pix_fmt',
            'yuv420p',
            '-c:a',
            'aac',
            '-movflags',
            '+faststart',
            $outputFile,
        ]);
        $process->setTimeout(240);
        $process->mustRun();

        @unlink($concatFile);
    }

    private function materializeAsset(Asset $asset, string $tempDir, string $prefix): string
    {
        $storageUrl = trim((string) $asset->storage_url);

        if ($storageUrl === '') {
            throw new \RuntimeException('Asset storage URL is empty.');
        }

        $extension = pathinfo(parse_url($storageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'bin';
        $targetPath = sprintf('%s/%s-%s.%s', $tempDir, $prefix, Str::uuid(), $extension);

        if (str_starts_with($storageUrl, 'b2://')) {
            $diskPath = ltrim(substr($storageUrl, 5), '/');
            $stream = Storage::disk('b2')->readStream($diskPath);

            if (! is_resource($stream)) {
                throw new \RuntimeException('Unable to read asset from storage.');
            }

            $target = fopen($targetPath, 'wb');

            if (! is_resource($target)) {
                fclose($stream);
                throw new \RuntimeException('Unable to write temp asset file.');
            }

            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            return $targetPath;
        }

        $response = Http::timeout(60)->get($storageUrl);

        if (! $response->successful()) {
            throw new \RuntimeException('Unable to download asset from source URL.');
        }

        file_put_contents($targetPath, $response->body());

        return $targetPath;
    }

    private function escapeDrawtext(string $text): string
    {
        $normalized = preg_replace("/[\r\n]+/", ' ', trim($text)) ?: 'Framecast';
        $shortened = mb_substr($normalized, 0, 140);

        return str_replace(
            ['\\', ':', "'", '%', '[', ']', ','],
            ['\\\\', '\:', "\\'", '\%', '\[', '\]', '\,'],
            $shortened
        );
    }

    private function dispatchProgress(
        int $projectId,
        int $exportJobId,
        string $status,
        int $progressPercent,
        ?string $message = null
    ): void {
        rescue(static function () use ($projectId, $exportJobId, $status, $progressPercent, $message): void {
            ExportProgressed::dispatch(
                $projectId,
                $exportJobId,
                $status,
                $progressPercent,
                $message
            );
        }, report: false);
    }
}
