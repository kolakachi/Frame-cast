<?php

namespace App\Jobs;

use App\Events\ExportProgressed;
use App\Models\Asset;
use App\Models\BatchJob;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Variant;
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

        $this->syncBatchJob($exportJob);

        $this->dispatchProgress(
            $exportJob,
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
                    $exportJob,
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

        // Apply background music mix if a track is selected on the project.
        if ($project->music_asset_id) {
            $musicAsset = Asset::query()->find($project->music_asset_id);

            if ($musicAsset) {
                $musicedFile = $tempDir.'/output_music.mp4';
                $this->applyMusicMix($project, $musicAsset, $outputFile, $musicedFile, $tempDir);
                @unlink($outputFile);
                rename($musicedFile, $outputFile);
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

        if ($exportJob->variant_id) {
            Variant::query()
                ->whereKey((int) $exportJob->variant_id)
                ->update(['status' => 'rendered']);
        }

        $this->syncBatchJob($exportJob->fresh());

        $this->dispatchProgress(
            $exportJob,
            'completed',
            100,
            'Export complete.'
        );
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);

        $exportJob = ExportJob::query()->find($this->exportJobId);

        if (! $exportJob) {
            return;
        }

        $userSafeFailure = $this->summarizeFailureForUser($exception);

        $exportJob->forceFill([
            'status' => 'failed',
            'failure_reason' => $userSafeFailure,
        ])->save();

        if ($exportJob->variant_id) {
            Variant::query()
                ->whereKey((int) $exportJob->variant_id)
                ->update(['status' => 'failed']);
        }

        $this->syncBatchJob($exportJob->fresh());

        $this->dispatchProgress(
            $exportJob,
            'failed',
            (int) $exportJob->progress_percent,
            $userSafeFailure
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
        $duration = max(1.0, (float) ($audioAsset?->duration_seconds ?: $scene->duration_seconds ?: 3.0));
        $segmentPath = sprintf('%s/segment-%03d.mp4', $tempDir, $index + 1);
        $captionSettings = is_array($scene->caption_settings_json) ? $scene->caption_settings_json : [];
        $captionEnabled = ($captionSettings['enabled'] ?? true) !== false;
        $captionStyle = (string) ($captionSettings['style_key'] ?? 'impact');
        $captionPosition = (string) ($captionSettings['position'] ?? 'bottom_third');
        $captionText = (string) ($scene->script_text ?: $scene->label ?: 'Framecast');
        $durationForFilter = $this->formatFilterDuration($duration);

        $monoFont = '/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf';

        $command = ['ffmpeg', '-y'];
        $cleanupPaths = [];

        try {
            $audioPath = null;
            if ($audioAsset) {
                $audioPath = $this->materializeAsset($audioAsset, $tempDir, 'audio-'.$index);
                $cleanupPaths[] = $audioPath;
                $duration = max(0.1, $this->probeMediaDuration($audioPath) ?? $duration);
                $durationForFilter = $this->formatFilterDuration($duration);
            }

            // Pre-detect visual type so the motion filter can be computed before the filter chain is built.
            $isVideo = $visualAsset !== null
                && ($visualAsset->asset_type === 'video' || str_starts_with((string) $visualAsset->mime_type, 'video/'));

            // Ken Burns motion filter — applied only to still-image visuals (not video clips).
            $motionFilter = null;
            if ($visualAsset && ! $isVideo) {
                $motionFilter = $this->buildMotionFilter($scene, $dimensions, $duration);
            }

            // When motion is applied, scale source to 1.5× output so zoompan has headroom
            // to zoom/pan without interpolation quality loss. Dimensions kept even-numbered.
            $scaleW = $motionFilter ? (int) (ceil($dimensions['width'] * 1.5 / 2) * 2) : $dimensions['width'];
            $scaleH = $motionFilter ? (int) (ceil($dimensions['height'] * 1.5 / 2) * 2) : $dimensions['height'];

            $baseFilter = sprintf(
                'setpts=PTS-STARTPTS,scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1',
                $scaleW,
                $scaleH,
                $scaleW,
                $scaleH
            );
            if ($motionFilter !== null) {
                $baseFilter .= ','.$motionFilter.',setpts=PTS-STARTPTS';
            }

            $baseFilter .= ',trim=duration='.$durationForFilter;

            $filters = [
                // setpts=PTS-STARTPTS normalises non-zero start PTS from stock clips so
                // audio and video start at exactly the same moment within the segment.
                $baseFilter,
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
                $cleanupPaths[] = $assFile;
            }

            $filter = implode(',', $filters);

            if ($visualAsset) {
                $visualPath = $this->materializeAsset($visualAsset, $tempDir, 'visual-'.$index);
                $cleanupPaths[] = $visualPath;
                // $isVideo already detected above (before filter chain construction).

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

            if ($audioPath !== null) {
                array_push($command, '-i', $audioPath);
            } else {
                array_push($command, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo');
            }

            // Only cap duration with -t when there is no real audio asset.
            // For scenes with audio, rely solely on -shortest so the segment
            // ends when the actual MP3 finishes — not at the DB estimate which
            // can differ and would cut the audio early.
            if (! $audioAsset) {
                array_push($command, '-t', (string) $duration);
            }

            array_push(
                $command,
                '-r', '30',
                // Explicitly bind segment outputs to the scene's visual stream and
                // narration stream. Without this, FFmpeg can auto-select embedded
                // audio from stock clips, which shifts voice playback across scene
                // boundaries after concatenation.
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-vf',
                $filter,
                '-af', 'atrim=duration='.$durationForFilter.',aresample=async=1:first_pts=0,asetpts=PTS-STARTPTS',
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

    private function applyMusicMix(Project $project, Asset $musicAsset, string $videoFile, string $outputFile, string $tempDir): void
    {
        $musicSettings = is_array($project->music_settings_json) ? $project->music_settings_json : [];
        $volume = (int) ($musicSettings['volume'] ?? 30);
        $duckVolume = (int) ($musicSettings['duck_volume'] ?? 8);
        $fadeInMs = (int) ($musicSettings['fade_in_ms'] ?? 500);
        $loop = (bool) ($musicSettings['loop'] ?? true);
        $duckDuringVoice = (bool) ($musicSettings['duck_during_voice'] ?? true);

        $musicPath = $this->materializeAsset($musicAsset, $tempDir, 'music');

        // Probe video duration to know how long to trim/loop the music.
        $videoDuration = $this->probeMediaDuration($videoFile) ?? 60.0;

        $volumeFraction = $volume / 100.0;
        $duckFraction = $duckVolume / 100.0;
        $fadeInSec = $fadeInMs / 1000.0;

        // Build the music processing chain.
        // [2] is the music stream. We volume-adjust, optionally fade in, then loop+trim to video length.
        $loopFilter = $loop ? "aloop=loop=-1:size=2147483647," : '';
        $fadeFilter = $fadeInSec > 0 ? sprintf('afade=t=in:st=0:d=%.3f,', $fadeInSec) : '';
        $musicChain = "[2:a]{$loopFilter}{$fadeFilter}atrim=duration={$videoDuration},asetpts=PTS-STARTPTS,volume={$volumeFraction}[music_vol]";

        if ($duckDuringVoice) {
            // Sidechain-compress music against the video's voice track.
            // threshold=0.02 triggers ducking at very low voice levels; ratio=8 gives 8:1 reduction.
            $filterComplex = implode(';', [
                $musicChain,
                "[1:a]asplit=2[voice_main][voice_sc]",
                "[music_vol][voice_sc]sidechaincompress=threshold=0.02:ratio=8:attack=200:release=1000[music_ducked]",
                "[voice_main][music_ducked]amix=inputs=2:normalize=0[outa]",
            ]);
        } else {
            $filterComplex = implode(';', [
                $musicChain,
                "[1:a][music_vol]amix=inputs=2:normalize=0[outa]",
            ]);
        }

        $command = [
            'ffmpeg', '-y',
            '-i', $videoFile,   // [0] video+voice
            '-i', $videoFile,   // [1] voice (second input for sidechain split)
            '-i', $musicPath,   // [2] music
            '-filter_complex', $filterComplex,
            '-map', '0:v',
            '-map', '[outa]',
            '-c:v', 'copy',
            '-c:a', 'aac',
            '-shortest',
            '-movflags', '+faststart',
            $outputFile,
        ];

        // For non-ducked mode we only need 2 inputs (video + music).
        if (! $duckDuringVoice) {
            $command = [
                'ffmpeg', '-y',
                '-i', $videoFile,   // [0] video+voice
                '-i', $musicPath,   // [1] music
                '-filter_complex', $filterComplex,
                '-map', '0:v',
                '-map', '[outa]',
                '-c:v', 'copy',
                '-c:a', 'aac',
                '-shortest',
                '-movflags', '+faststart',
                $outputFile,
            ];
        }

        $process = new Process($command);
        $process->setTimeout(300);
        $process->mustRun();

        @unlink($musicPath);
    }

    /**
     * @param list<string> $segmentPaths
     */
    private function concatSegments(array $segmentPaths, string $outputFile, string $tempDir): void
    {
        if ($segmentPaths === []) {
            throw new \RuntimeException('No rendered scene segments available.');
        }

        // Use the concat filter (not the concat demuxer) so FFmpeg re-encodes
        // the join, guaranteeing audio and video stay frame-perfectly aligned at
        // every scene boundary with no accumulated PTS drift.
        $n = count($segmentPaths);

        $filterInputs = implode('', array_map(
            static fn (int $i): string => "[{$i}:v][{$i}:a]",
            range(0, $n - 1)
        ));
        $filterComplex = "{$filterInputs}concat=n={$n}:v=1:a=1[outv][outa]";

        $command = ['ffmpeg', '-y'];

        foreach ($segmentPaths as $path) {
            array_push($command, '-i', $path);
        }

        array_push(
            $command,
            '-filter_complex', $filterComplex,
            '-map', '[outv]',
            '-map', '[outa]',
            '-c:v', 'libx264',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-movflags', '+faststart',
            $outputFile,
        );

        $process = new Process($command);
        $process->setTimeout(600);
        $process->mustRun();
    }

    private function probeMediaDuration(string $path): ?float
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-show_entries',
            'format=duration',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        $process->setTimeout(30);

        try {
            $process->mustRun();
        } catch (\Throwable) {
            return null;
        }

        $duration = (float) trim($process->getOutput());

        return $duration > 0 ? $duration : null;
    }

    private function formatFilterDuration(float $duration): string
    {
        return sprintf('%.3F', max(0.1, $duration));
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
        return $this->escapeDrawtext($text, true);
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
        ExportJob $exportJob,
        string $status,
        int $progressPercent,
        ?string $message = null
    ): void {
        rescue(static function () use ($exportJob, $status, $progressPercent, $message): void {
            ExportProgressed::dispatch(
                (int) $exportJob->project_id,
                (int) $exportJob->getKey(),
                $status,
                $progressPercent,
                $message,
                (string) $exportJob->file_name,
                $exportJob->failure_reason
            );
        }, false);
    }

    private function syncBatchJob(?ExportJob $exportJob): void
    {
        if (! $exportJob || ! $exportJob->batch_job_id) {
            return;
        }

        $batchJob = BatchJob::query()->find((int) $exportJob->batch_job_id);

        if (! $batchJob) {
            return;
        }

        $children = ExportJob::query()
            ->where('batch_job_id', $batchJob->getKey())
            ->get();

        $completedCount = $children->where('status', 'completed')->count();
        $failedCount = $children->where('status', 'failed')->count();
        $queuedCount = $children->whereIn('status', ['queued', 'processing'])->count();

        $status = 'processing';

        if ($queuedCount === 0) {
            if ($completedCount > 0 && $failedCount > 0) {
                $status = 'partial_success';
            } elseif ($completedCount > 0) {
                $status = 'completed';
            } else {
                $status = 'failed';
            }
        }

        $batchJob->forceFill([
            'completed_count' => $completedCount,
            'failed_count' => $failedCount,
            'status' => $status,
            'failure_summary_json' => $failedCount > 0
                ? ['failed_export_job_ids' => $children->where('status', 'failed')->pluck('id')->values()->all()]
                : null,
        ])->save();
    }

    private function summarizeFailureForUser(\Throwable $exception): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?: '');

        if ($message === '') {
            return 'Export failed before a video could be produced.';
        }

        if (str_contains($message, 'do not match the corresponding output link') || str_contains($message, 'Failed to configure output pad on Parsed_concat')) {
            return 'One or more rendered scenes used an incompatible video format, so the final export could not be assembled.';
        }

        if (str_contains($message, 'Could not render one or more scene segments')) {
            return 'One or more scenes could not be rendered into export-ready video.';
        }

        if (str_contains($message, 'No such file or directory')) {
            return 'A required media file was missing during export.';
        }

        if (str_contains($message, 'Invalid data found when processing input')) {
            return 'One of the generated media files could not be read during export.';
        }

        if (str_contains($message, 'Conversion failed')) {
            return 'The final video could not be assembled from the rendered scenes.';
        }

        return 'Export failed while processing the video. Please retry, or regenerate the affected scene if it continues.';
    }

    /**
     * Build an FFmpeg zoompan filter string for Ken Burns motion on still images.
     * Returns null when no motion should be applied (effect = static, or none configured).
     *
     * The caller must pre-scale the source image to 1.5× the output dimensions so that
     * zoompan can zoom/pan without sampling beyond the input boundary.
     *
     * @param  array{width:int,height:int}  $dimensions  Final output dimensions.
     */
    private function buildMotionFilter(Scene $scene, array $dimensions, float $duration): ?string
    {
        $motionSettings = is_array($scene->motion_settings_json) ? $scene->motion_settings_json : [];
        $effect = (string) ($motionSettings['effect'] ?? 'zoom_in');
        $intensity = (string) ($motionSettings['intensity'] ?? 'moderate');

        if ($effect === 'static') {
            return null;
        }

        $frames = max(30, (int) round($duration * 30));
        $size = "{$dimensions['width']}x{$dimensions['height']}";

        // Zoom speed per frame at 30 fps.
        $speed = match ($intensity) {
            'subtle'   => 0.0008,
            'dramatic' => 0.003,
            default    => 0.0015, // moderate
        };

        // Source is pre-scaled to 1.5× output. iw/ih refer to those larger dimensions.
        // Center formula iw/2-(iw/zoom/2) positions the crop window at the image centre.
        // Pan range at zoom z: iw*(1-1/z) gives the total available travel distance.
        [$zExpr, $xExpr, $yExpr] = match ($effect) {
            'zoom_in' => [
                "min(1.0+{$speed}*on,1.5)",
                'iw/2-(iw/zoom/2)',
                'ih/2-(ih/zoom/2)',
            ],
            'zoom_out' => [
                "max(1.5-{$speed}*on,1.0)",
                'iw/2-(iw/zoom/2)',
                'ih/2-(ih/zoom/2)',
            ],
            'pan_left' => [
                '1.2',
                "iw*(1-1/zoom)*(1-on/{$frames})",
                'ih/2-(ih/zoom/2)',
            ],
            'pan_right' => [
                '1.2',
                "iw*(1-1/zoom)*on/{$frames}",
                'ih/2-(ih/zoom/2)',
            ],
            'pan_up' => [
                '1.2',
                'iw/2-(iw/zoom/2)',
                "ih*(1-1/zoom)*(1-on/{$frames})",
            ],
            'pan_down' => [
                '1.2',
                'iw/2-(iw/zoom/2)',
                "ih*(1-1/zoom)*on/{$frames}",
            ],
            'pan_zoom' => [
                "min(1.0+{$speed}*on,1.4)",
                "iw*(1-1/zoom)*on/{$frames}",
                'ih/2-(ih/zoom/2)',
            ],
            default => [
                '1.0',
                'iw/2-(iw/zoom/2)',
                'ih/2-(ih/zoom/2)',
            ],
        };

        return "zoompan=z='{$zExpr}':x='{$xExpr}':y='{$yExpr}':d={$frames}:s={$size}:fps=30";
    }
}
