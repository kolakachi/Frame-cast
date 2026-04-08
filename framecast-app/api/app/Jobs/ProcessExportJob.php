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

    public int $timeout = 300;

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
        $totalDuration = max(
            1.0,
            (float) $scenes->sum(fn (Scene $scene): float => (float) ($scene->duration_seconds ?: 0))
        );
        $elapsedDuration = 0.0;

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
                    $index,
                    $elapsedDuration,
                    $totalDuration
                );

                $elapsedDuration += max(
                    1.0,
                    (float) ($audioAsset?->duration_seconds ?: $scene->duration_seconds ?: 3.0)
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
        int $index,
        float $elapsedSeconds,
        float $totalSeconds
    ): string {
        $duration = max(
            1.0,
            (float) ($audioAsset?->duration_seconds ?: $scene->duration_seconds ?: 3.0)
        );
        $segmentPath = sprintf('%s/segment-%03d.mp4', $tempDir, $index + 1);
        $captionSettings = is_array($scene->caption_settings_json) ? $scene->caption_settings_json : [];
        $captionEnabled = ($captionSettings['enabled'] ?? true) !== false;
        $captionStyle = (string) ($captionSettings['style_key'] ?? 'impact');
        $captionPosition = (string) ($captionSettings['position'] ?? 'bottom_third');
        $captionText = (string) ($scene->script_text ?: $scene->label ?: 'Framecast');

        $monoFont = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

        $filters = [
            sprintf(
                'scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d',
                $dimensions['width'],
                $dimensions['height'],
                $dimensions['width'],
                $dimensions['height']
            ),
            "drawtext=fontfile={$monoFont}:text='FRAMECAST':fontcolor=white@0.3:fontsize=20:x=16:y=20",
            sprintf(
                "drawtext=fontfile={$monoFont}:text='%s':fontcolor=white@0.5:fontsize=22:x=w-text_w-16:y=18:box=1:boxcolor=black@0.4:boxborderw=12",
                $this->escapeDrawtext($this->formatClock($elapsedSeconds).' / '.$this->formatClock($totalSeconds))
            ),
        ];

        $assFile = null;
        if ($captionEnabled && trim($captionText) !== '') {
            $assFile = sprintf('%s/caption-%03d.ass', $tempDir, $index);
            $this->buildASSCaption($captionText, $captionStyle, $captionPosition, $duration, $dimensions, $assFile);
            $filters[] = "subtitles={$assFile}";
        }

        $filter = implode(',', $filters);

        $command = ['ffmpeg', '-y'];
        $cleanupPaths = [];
        if ($assFile !== null) {
            $cleanupPaths[] = $assFile;
        }

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
                '-r', '30',
                '-vf',
                $filter,
                '-c:v',
                'libx264',
                '-pix_fmt',
                'yuv420p',
                '-c:a',
                'aac',
                '-ar', '44100',
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
            '-c',
            'copy',
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

        try {
            $response = Http::connectTimeout(10)
                ->timeout(20)
                ->retry(1, 250)
                ->withOptions(['allow_redirects' => true])
                ->get($storageUrl);

            if (! $response->successful()) {
                throw new \RuntimeException('Unable to download asset from source URL.');
            }

            file_put_contents($targetPath, $response->body());

            return $targetPath;
        } catch (\Throwable $exception) {
            return $this->fallbackAssetFile($asset, $tempDir, $prefix);
        }
    }

    private function escapeDrawtext(string $text, bool $preserveNewlines = false): string
    {
        $normalized = $preserveNewlines
            ? trim(str_replace(["\r\n", "\r"], "\n", $text))
            : (preg_replace("/[\r\n]+/", ' ', trim($text)) ?: 'Framecast');
        $shortened = mb_substr($normalized, 0, 140);

        return str_replace(
            ['\\', ':', "'", '%', '[', ']', ',', "\n"],
            ['\\\\', '\:', "\\'", '\%', '\[', '\]', '\,', '\n'],
            $shortened
        );
    }

    private function wrapCaptionText(string $text, int $lineLength = 16): string
    {
        $normalized = trim(preg_replace("/[\r\n]+/", ' ', $text) ?: '');
        if ($normalized === '') {
            return '';
        }

        $words = preg_split('/\s+/', $normalized) ?: [];
        $lines = [];
        $current = '';

        foreach ($words as $word) {
            $candidate = trim($current === '' ? $word : $current.' '.$word);
            if (mb_strlen($candidate) > $lineLength && $current !== '') {
                $lines[] = $current;
                $current = $word;
                continue;
            }

            $current = $candidate;
        }

        if ($current !== '') {
            $lines[] = $current;
        }

        return implode("\n", array_slice($lines, 0, 5));
    }

    private function buildHighlightedCaption(string $text, string $captionStyle): string
    {
        return $this->escapeDrawtext($text, preserveNewlines: true);
    }

    private function captionFontColor(string $captionStyle): string
    {
        return match ($captionStyle) {
            'editorial' => 'white@0.85',
            'hacker' => 'white@0.9',
            default => 'white',
        };
    }

    private function captionFontSize(string $captionStyle): int
    {
        return match ($captionStyle) {
            'hacker' => 34,
            'editorial' => 42,
            default => 64,
        };
    }

    /**
     * @param array{width:int,height:int} $dimensions
     */
    private function buildASSCaption(
        string $text,
        string $captionStyle,
        string $captionPosition,
        float $duration,
        array $dimensions,
        string $outputPath
    ): void {
        $playResX = $dimensions['width'];
        $playResY = $dimensions['height'];
        $endTime = $this->formatASSTime($duration);

        // Numpad alignment: 2=bottom-center, 5=middle-center, 8=top-center
        $alignment = match ($captionPosition) {
            'center' => 5,
            'top_third' => 8,
            default => 2,
        };

        // Mirror preview: bottom:100px on 480px canvas = 20.8% → 400px on 1920px video
        $marginV = match ($captionPosition) {
            'center' => 0,
            'top_third' => (int) round(80 * $playResY / 1920),
            default => (int) round(400 * $playResY / 1920),
        };
        $marginLR = (int) round(60 * $playResX / 1080);

        // Mirror preview font sizing: 22px on 480px canvas scaled to export resolution
        [$fontName, $fontSize, $bold, $italic] = match ($captionStyle) {
            'editorial' => ['DejaVu Serif', (int) round(22 * $playResY / 480), 0, 1],
            'hacker' => ['DejaVu Sans Mono', (int) round(16 * $playResY / 480), -1, 0],
            default => ['DejaVu Sans', (int) round(22 * $playResY / 480), -1, 0],
        };

        $styledText = $this->buildASSStyledText($text, $captionStyle);

        $content = implode("\n", [
            '[Script Info]',
            'ScriptType: v4.00+',
            "PlayResX: {$playResX}",
            "PlayResY: {$playResY}",
            'WrapStyle: 0',
            'ScaledBorderAndShadow: yes',
            '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            "Style: Default,{$fontName},{$fontSize},&H00FFFFFF&,&H000000FF&,&H00000000&,&H80000000&,{$bold},{$italic},0,0,100,100,0,0,1,3,2,{$alignment},{$marginLR},{$marginLR},{$marginV},1",
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
            "Dialogue: 0,0:00:00.00,{$endTime},Default,,0,0,0,,{$styledText}",
        ]);

        file_put_contents($outputPath, $content);
    }

    private function buildASSStyledText(string $text, string $captionStyle): string
    {
        $normalized = trim(preg_replace('/[\r\n]+/', ' ', $text));
        $words = array_values(array_filter(preg_split('/\s+/', $normalized) ?: []));

        if (count($words) === 0) {
            return '';
        }

        // Match preview previewWords(): highlight indices 1 and 2 (2nd and 3rd words)
        $highlightStart = min(1, count($words) - 1);
        $highlightEnd = min(count($words), $highlightStart + 2);

        // ASS color override format: {\c&HAABBGGRR&} (note: BGR order, not RGB)
        // Orange #FF6B35 → BB=35, GG=6B, RR=FF → &H00356BFF&
        // Yellow #FFFF00 → BB=00, GG=FF, RR=FF → &H0000FFFF&
        $highlightCode = match ($captionStyle) {
            'hacker' => '\c&H0000FFFF&',
            'editorial' => '\c&H00CCCCFF&\u1',
            default => '\c&H00356BFF&',
        };

        $result = '';
        foreach ($words as $i => $word) {
            if ($i > 0) {
                $result .= ' ';
            }
            $escaped = $this->escapeASSText($word);
            if ($i >= $highlightStart && $i < $highlightEnd) {
                $result .= '{' . $highlightCode . '}' . $escaped . '{\r}';
            } else {
                $result .= $escaped;
            }
        }

        return $result;
    }

    private function escapeASSText(string $text): string
    {
        return str_replace(['{', '}', "\n", "\r"], ['\{', '\}', '\N', ''], $text);
    }

    private function formatASSTime(float $seconds): string
    {
        $cs = (int) round($seconds * 100);
        $h = intdiv($cs, 360000);
        $cs %= 360000;
        $m = intdiv($cs, 6000);
        $cs %= 6000;
        $s = intdiv($cs, 100);
        $cs %= 100;

        return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
    }

    private function formatClock(float $seconds): string
    {
        $whole = max(0, (int) round($seconds));
        $mins = intdiv($whole, 60);
        $secs = $whole % 60;

        return sprintf('%02d:%02d', $mins, $secs);
    }

    private function fallbackAssetFile(Asset $asset, string $tempDir, string $prefix): string
    {
        $extension = str_starts_with((string) $asset->mime_type, 'video/') ? 'mp4' : 'png';
        $targetPath = sprintf('%s/%s-fallback-%s.%s', $tempDir, $prefix, Str::uuid(), $extension);

        if (str_starts_with((string) $asset->mime_type, 'video/')) {
            $process = new Process([
                'ffmpeg',
                '-y',
                '-f',
                'lavfi',
                '-i',
                'color=c=#111111:s=1080x1920:d=3',
                '-vf',
                "drawtext=text='MEDIA UNAVAILABLE':fontcolor=white@0.65:fontsize=48:x=(w-text_w)/2:y=(h-text_h)/2",
                '-c:v',
                'libx264',
                '-pix_fmt',
                'yuv420p',
                $targetPath,
            ]);
            $process->setTimeout(60);
            $process->mustRun();

            return $targetPath;
        }

        $process = new Process([
            'ffmpeg',
            '-y',
            '-f',
            'lavfi',
            '-i',
            'color=c=#111111:s=1080x1920',
            '-frames:v',
            '1',
            '-vf',
            "drawtext=text='MEDIA UNAVAILABLE':fontcolor=white@0.65:fontsize=48:x=(w-text_w)/2:y=(h-text_h)/2",
            $targetPath,
        ]);
        $process->setTimeout(60);
        $process->mustRun();

        return $targetPath;
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
