<?php

namespace App\Jobs;

use App\Events\ExportProgressed;
use App\Models\Asset;
use App\Models\ExportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public string $queue = 'exports';

    public function __construct(public readonly int $exportJobId)
    {
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

        ExportProgressed::dispatch(
            (int) $exportJob->project_id,
            (int) $exportJob->getKey(),
            'processing',
            5,
            'Export processing started.'
        );

        $dimensions = $this->dimensionsForAspectRatio((string) $exportJob->aspect_ratio);
        $tempFile = tempnam(sys_get_temp_dir(), 'framecast-export-');

        if ($tempFile === false) {
            throw new \RuntimeException('Unable to allocate export temp file.');
        }

        $outputFile = $tempFile.'.mp4';
        @unlink($tempFile);

        $process = new Process([
            'ffmpeg',
            '-y',
            '-f',
            'lavfi',
            '-i',
            sprintf('color=c=black:s=%dx%d:d=3', $dimensions['width'], $dimensions['height']),
            '-f',
            'lavfi',
            '-i',
            'anullsrc=r=44100:cl=stereo',
            '-shortest',
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
        $process->setTimeout(120);
        $process->mustRun();

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

        $asset = Asset::query()->create([
            'workspace_id' => $exportJob->workspace_id,
            'channel_id' => null,
            'asset_type' => 'video',
            'title' => $exportJob->file_name,
            'description' => 'Rendered export output',
            'storage_url' => 'b2://'.$storagePath,
            'duration_seconds' => 3,
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

        ExportProgressed::dispatch(
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

        ExportProgressed::dispatch(
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
}
