<?php

namespace App\Traits;

use App\Events\ExportProgressed;
use App\Models\Asset;
use App\Models\BatchJob;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Variant;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Shared FFmpeg rendering helpers used by RenderSceneSegmentJob,
 * ConcatenateExportJob, and (legacy) ProcessExportJob.
 */
trait RendersExportScenes
{
    // ── Dimensions ───────────────────────────────────────────────────────────

    /**
     * @return array{width:int,height:int}
     */
    protected function dimensionsForAspectRatio(string $aspectRatio): array
    {
        return match ($aspectRatio) {
            '16:9' => ['width' => 1920, 'height' => 1080],
            '1:1'  => ['width' => 1080, 'height' => 1080],
            default => ['width' => 1080, 'height' => 1920],
        };
    }

    // ── Scene rendering ──────────────────────────────────────────────────────

    /**
     * @param array{width:int,height:int} $dimensions
     */
    protected function renderSceneSegment(
        Project $project,
        Scene $scene,
        ?Asset $visualAsset,
        ?Asset $audioAsset,
        ?Asset $projectMusicAsset,
        array $dimensions,
        string $tempDir,
        int $index,
        float $elapsedSeconds,
        float $totalSeconds
    ): string {
        $duration = max(1.0, (float) ($audioAsset?->duration_seconds ?: $scene->duration_seconds ?: 3.0));

        if ($scene->visual_type === 'waveform') {
            return $this->renderAudiogramSegment(
                $project,
                $scene,
                $audioAsset,
                $projectMusicAsset,
                $dimensions,
                $tempDir,
                $index,
                $elapsedSeconds,
                $totalSeconds
            );
        }

        $segmentPath = sprintf('%s/segment-%03d.mp4', $tempDir, $index + 1);
        $captionSettings = is_array($scene->caption_settings_json) ? $scene->caption_settings_json : [];
        $captionEnabled = ($captionSettings['enabled'] ?? true) !== false;
        $captionStyle = (string) ($captionSettings['style_key'] ?? 'impact');
        $captionPosition = (string) ($captionSettings['position'] ?? 'bottom_third');
        $captionFont = (string) ($captionSettings['font'] ?? 'Bebas Neue');
        $captionHighlightMode = (string) ($captionSettings['highlight_mode'] ?? 'keywords');
        $captionColor = (string) ($captionSettings['color'] ?? '#ffffff');
        $captionSize = (string) ($captionSettings['size'] ?? 'medium');
        $captionHighlightColor = (string) ($captionSettings['highlight_color'] ?? '#ff6b35');
        $captionText = (string) ($scene->script_text ?: $scene->label ?: 'Framecast');

        if ($captionHighlightMode === 'none') {
            $captionEnabled = false;
        }

        $durationForFilter = $this->formatFilterDuration($duration);
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

            $isVideo = $visualAsset !== null
                && ($visualAsset->asset_type === 'video' || str_starts_with((string) $visualAsset->mime_type, 'video/'));

            $motionFilter = null;
            if ($visualAsset && ! $isVideo) {
                $motionFilter = $this->buildMotionFilter($scene, $dimensions, $duration);
            }

            $scaleW = $motionFilter ? (int) (ceil($dimensions['width'] * 1.5 / 2) * 2) : $dimensions['width'];
            $scaleH = $motionFilter ? (int) (ceil($dimensions['height'] * 1.5 / 2) * 2) : $dimensions['height'];

            $baseFilter = sprintf(
                'setpts=PTS-STARTPTS,scale=%d:%d:force_original_aspect_ratio=increase,crop=%d:%d,setsar=1',
                $scaleW, $scaleH, $scaleW, $scaleH
            );

            if ($motionFilter !== null) {
                $baseFilter .= ','.$motionFilter.',setpts=PTS-STARTPTS';
            }

            $baseFilter .= ',trim=duration='.$durationForFilter;
            $filters = [$baseFilter];

            $assFile = null;
            if ($captionEnabled && trim($captionText) !== '') {
                $assFile = sprintf('%s/caption-%03d.ass', $tempDir, $index);
                $this->buildASSCaption(
                    $captionText, $captionStyle, $captionPosition, $captionFont, $duration,
                    $dimensions, $assFile, $captionHighlightMode,
                    $this->captionTimingWordsFromAsset($audioAsset),
                    $captionColor, $captionSize, $captionHighlightColor,
                );
                $filters[] = "subtitles={$assFile}";
                $cleanupPaths[] = $assFile;
            }

            $filter = implode(',', $filters);

            if ($visualAsset) {
                $visualPath = $this->materializeAsset($visualAsset, $tempDir, 'visual-'.$index);
                $cleanupPaths[] = $visualPath;

                if ($isVideo) {
                    array_push($command, '-stream_loop', '-1', '-i', $visualPath);
                } else {
                    array_push($command, '-loop', '1', '-framerate', '30', '-i', $visualPath);
                }
            } else {
                array_push(
                    $command,
                    '-f', 'lavfi',
                    '-i', sprintf('color=c=black:s=%dx%d:d=%s', $dimensions['width'], $dimensions['height'], $duration)
                );
            }

            if ($audioPath !== null) {
                array_push($command, '-i', $audioPath);
            } else {
                array_push($command, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo');
            }

            if (! $audioAsset) {
                array_push($command, '-t', (string) $duration);
            }

            array_push(
                $command,
                '-r', '30',
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-vf', $filter,
                '-af', 'atrim=duration='.$durationForFilter.',aresample=async=1:first_pts=0,asetpts=PTS-STARTPTS',
                '-c:v', 'libx264',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-ar', '44100',
                '-shortest',
                '-movflags', '+faststart',
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
     * @param array{width:int,height:int} $dimensions
     */
    protected function renderAudiogramSegment(
        Project $project,
        Scene $scene,
        ?Asset $audioAsset,
        ?Asset $projectMusicAsset,
        array $dimensions,
        string $tempDir,
        int $index,
        float $elapsedSeconds,
        float $totalSeconds
    ): string {
        $imgSettings = is_array($scene->image_generation_settings_json) ? $scene->image_generation_settings_json : [];
        $captionSettings = is_array($scene->caption_settings_json) ? $scene->caption_settings_json : [];
        $segmentPath = sprintf('%s/segment-%03d.mp4', $tempDir, $index + 1);
        $framesDir = sprintf('%s/audiogram-frames-%03d', $tempDir, $index + 1);
        $payloadPath = sprintf('%s/audiogram-%03d.json', $tempDir, $index + 1);
        $cleanupPaths = [$payloadPath];
        $duration = max(1.0, (float) ($audioAsset?->duration_seconds ?: $scene->duration_seconds ?: 3.0));
        $audioPath = null;
        $analysisAudioPath = null;
        $musicAnalysisPath = null;
        $pcmPath = null;

        try {
            if ($audioAsset) {
                $audioPath = $this->materializeAsset($audioAsset, $tempDir, 'audio-'.$index);
                $cleanupPaths[] = $audioPath;
                $duration = max(0.1, $this->probeMediaDuration($audioPath) ?? $duration);
                $analysisAudioPath = $audioPath;
            }

            if ($projectMusicAsset) {
                $musicAnalysisPath = $this->materializeAsset($projectMusicAsset, $tempDir, 'analysis-music-'.$index);
                $cleanupPaths[] = $musicAnalysisPath;
            }

            if ($musicAnalysisPath !== null) {
                $analysisAudioPath = $this->buildAudiogramAnalysisMix(
                    $project, $audioPath, $musicAnalysisPath, $tempDir, $index, $duration, $elapsedSeconds
                );
                $cleanupPaths[] = $analysisAudioPath;
            }

            if ($analysisAudioPath !== null) {
                $pcmPath = $this->buildBrowserPcmFile($analysisAudioPath, $tempDir, $index);
                $cleanupPaths[] = $pcmPath;
            }

            if (! is_dir($framesDir) && ! @mkdir($framesDir, 0777, true) && ! is_dir($framesDir)) {
                throw new \RuntimeException('Unable to allocate audiogram frames directory.');
            }
            $cleanupPaths[] = $framesDir;

            $payload = [
                'width'  => $dimensions['width'],
                'height' => $dimensions['height'],
                'duration' => $duration,
                'fps' => 20,
                'sampleRate' => 16000,
                'style' => $this->normalizeAudiogramStyle((string) ($imgSettings['audiogram_style'] ?? 'bars')),
                'color' => $this->normalizeHexColor((string) ($imgSettings['audiogram_color'] ?? '#ff6b35')),
                'backgroundCss' => $this->audiogramBackgroundCss((string) ($imgSettings['audiogram_bg'] ?? 'dark')),
                'captionEnabled' => ($captionSettings['enabled'] ?? true) !== false
                    && (string) ($captionSettings['highlight_mode'] ?? 'keywords') !== 'none',
                'captionStyle'         => (string) ($captionSettings['style_key'] ?? 'impact'),
                'captionHighlightMode' => (string) ($captionSettings['highlight_mode'] ?? 'keywords'),
                'captionPosition'      => (string) ($captionSettings['position'] ?? 'bottom_third'),
                'captionFont'          => (string) ($captionSettings['font'] ?? 'Bebas Neue'),
                'captionColor'         => $this->normalizeHexColor((string) ($captionSettings['color'] ?? '#ffffff')),
                'captionSize'          => (string) ($captionSettings['size'] ?? 'medium'),
                'captionHighlightColor'=> $this->normalizeHexColor((string) ($captionSettings['highlight_color'] ?? '#ff6b35')),
                'captionText'   => (string) ($scene->script_text ?: $scene->label ?: 'Framecast'),
                'timedWords'    => $this->captionTimingWordsFromAsset($audioAsset),
                'pcmPath'       => $pcmPath,
            ];

            file_put_contents(
                $payloadPath,
                json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            );

            $renderer = new Process([
                'node',
                base_path('scripts/render-audiogram.mjs'),
                $payloadPath,
                $framesDir,
            ], base_path());
            $renderer->setTimeout(900);
            $renderer->mustRun();

            $command = ['ffmpeg', '-y', '-framerate', '20', '-i', $framesDir.'/frame-%06d.png'];

            if ($audioPath !== null) {
                array_push($command, '-i', $audioPath);
            } else {
                array_push($command, '-f', 'lavfi', '-i', 'anullsrc=r=44100:cl=stereo', '-t', (string) $duration);
            }

            array_push(
                $command,
                '-r', '30',
                '-map', '0:v:0',
                '-map', '1:a:0',
                '-c:v', 'libx264',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-ar', '44100',
                '-shortest',
                '-movflags', '+faststart',
                $segmentPath
            );

            $process = new Process($command);
            $process->setTimeout(600);
            $process->mustRun();

            return $segmentPath;
        } finally {
            foreach ($cleanupPaths as $cleanupPath) {
                if (is_string($cleanupPath) && is_dir($cleanupPath)) {
                    $this->cleanupTempDir($cleanupPath);
                    continue;
                }
                if (is_string($cleanupPath) && is_file($cleanupPath)) {
                    @unlink($cleanupPath);
                }
            }
        }
    }

    protected function buildAudiogramAnalysisMix(
        Project $project,
        ?string $voicePath,
        string $musicPath,
        string $tempDir,
        int $index,
        float $duration,
        float $elapsedSeconds
    ): string {
        $musicSettings = is_array($project->music_settings_json) ? $project->music_settings_json : [];
        $volume = max(0, (int) ($musicSettings['volume'] ?? 30));
        $fadeInMs = max(0, (int) ($musicSettings['fade_in_ms'] ?? 500));
        $loop = (bool) ($musicSettings['loop'] ?? true);
        $duckDuringVoice = (bool) ($musicSettings['duck_during_voice'] ?? true);
        $volumeFraction = $volume / 100.0;
        $fadeInSec = $fadeInMs / 1000.0;
        $analysisPath = sprintf('%s/audiogram-analysis-%03d.wav', $tempDir, $index + 1);

        $command = ['ffmpeg', '-y'];
        $voiceInputIndex = null;
        $musicInputIndex = null;

        if ($voicePath !== null) {
            array_push($command, '-i', $voicePath);
            $voiceInputIndex = 0;
            $musicInputIndex = 1;
        } else {
            $musicInputIndex = 0;
        }

        if ($loop) {
            array_push($command, '-stream_loop', '-1');
        }
        array_push($command, '-i', $musicPath);

        $musicFilters = [
            sprintf('[%d:a]atrim=start=%.3f:duration=%.3f', $musicInputIndex, max(0.0, $elapsedSeconds), max(0.1, $duration)),
            'asetpts=PTS-STARTPTS',
        ];

        if ($fadeInSec > 0) {
            $musicFilters[] = sprintf('afade=t=in:st=0:d=%.3f', $fadeInSec);
        }
        $musicFilters[] = sprintf('volume=%.4f', $volumeFraction);

        $filters = [implode(',', $musicFilters).'[music_bed]'];

        if ($voiceInputIndex !== null) {
            $filters[] = sprintf('[%d:a]atrim=duration=%.3f,asetpts=PTS-STARTPTS[voice_main]', $voiceInputIndex, max(0.1, $duration));

            if ($duckDuringVoice) {
                $filters[] = sprintf('[%d:a]atrim=duration=%.3f,asetpts=PTS-STARTPTS[voice_sc]', $voiceInputIndex, max(0.1, $duration));
                $filters[] = '[music_bed][voice_sc]sidechaincompress=threshold=0.02:ratio=8:attack=200:release=1000[music_ducked]';
                $filters[] = '[voice_main][music_ducked]amix=inputs=2:normalize=0[outa]';
            } else {
                $filters[] = '[voice_main][music_bed]amix=inputs=2:normalize=0[outa]';
            }
        }

        array_push(
            $command,
            '-filter_complex', implode(';', $filters),
            '-map', $voiceInputIndex !== null ? '[outa]' : '[music_bed]',
            '-t', (string) max(0.1, $duration),
            '-ac', '1',
            '-ar', '44100',
            '-c:a', 'pcm_s16le',
            $analysisPath
        );

        $process = new Process($command);
        $process->setTimeout(180);
        $process->mustRun();

        return $analysisPath;
    }

    protected function buildBrowserPcmFile(string $audioPath, string $tempDir, int $index): string
    {
        $pcmPath = sprintf('%s/audio-%03d.pcm', $tempDir, $index + 1);
        $process = new Process([
            'ffmpeg', '-y', '-i', $audioPath,
            '-vn', '-ac', '1', '-ar', '16000', '-f', 'f32le', $pcmPath,
        ]);
        $process->setTimeout(180);
        $process->mustRun();

        return $pcmPath;
    }

    // ── Concatenation + music ────────────────────────────────────────────────

    /**
     * @param list<string> $segmentPaths
     */
    protected function concatSegments(array $segmentPaths, string $outputFile, string $tempDir): void
    {
        if ($segmentPaths === []) {
            throw new \RuntimeException('No rendered scene segments available.');
        }

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

    protected function applyMusicMix(Project $project, Asset $musicAsset, string $videoFile, string $outputFile, string $tempDir): void
    {
        $musicSettings = is_array($project->music_settings_json) ? $project->music_settings_json : [];
        $volume = (int) ($musicSettings['volume'] ?? 30);
        $fadeInMs = (int) ($musicSettings['fade_in_ms'] ?? 500);
        $loop = (bool) ($musicSettings['loop'] ?? true);
        $duckDuringVoice = (bool) ($musicSettings['duck_during_voice'] ?? true);

        $musicPath = $this->materializeAsset($musicAsset, $tempDir, 'music');

        if (! $this->probeHasAudioStream($musicPath)) {
            return;
        }

        $videoDuration = $this->probeMediaDuration($videoFile) ?? 60.0;
        $volumeFraction = $volume / 100.0;
        $fadeInSec = $fadeInMs / 1000.0;
        $loopFilter = $loop ? "aloop=loop=-1:size=2147483647," : '';
        $fadeFilter = $fadeInSec > 0 ? sprintf('afade=t=in:st=0:d=%.3f,', $fadeInSec) : '';
        $musicChain = "[2:a]{$loopFilter}{$fadeFilter}atrim=duration={$videoDuration},asetpts=PTS-STARTPTS,volume={$volumeFraction}[music_vol]";

        if ($duckDuringVoice) {
            $filterComplex = implode(';', [
                $musicChain,
                "[1:a]asplit=2[voice_main][voice_sc]",
                "[music_vol][voice_sc]sidechaincompress=threshold=0.02:ratio=8:attack=200:release=1000[music_ducked]",
                "[voice_main][music_ducked]amix=inputs=2:normalize=0[outa]",
            ]);
            $command = [
                'ffmpeg', '-y',
                '-i', $videoFile,
                '-i', $videoFile,
                '-i', $musicPath,
                '-filter_complex', $filterComplex,
                '-map', '0:v', '-map', '[outa]',
                '-c:v', 'copy', '-c:a', 'aac',
                '-shortest', '-movflags', '+faststart', $outputFile,
            ];
        } else {
            $filterComplex = implode(';', [
                "[2:a]{$loopFilter}{$fadeFilter}atrim=duration={$videoDuration},asetpts=PTS-STARTPTS,volume={$volumeFraction}[music_vol]",
                "[1:a][music_vol]amix=inputs=2:normalize=0[outa]",
            ]);
            $command = [
                'ffmpeg', '-y',
                '-i', $videoFile,
                '-i', $musicPath,
                '-filter_complex', $filterComplex,
                '-map', '0:v', '-map', '[outa]',
                '-c:v', 'copy', '-c:a', 'aac',
                '-shortest', '-movflags', '+faststart', $outputFile,
            ];
        }

        $process = new Process($command);
        $process->setTimeout(300);
        $process->mustRun();

        @unlink($musicPath);
    }

    // ── Asset materialisation ────────────────────────────────────────────────

    protected function materializeAsset(Asset $asset, string $tempDir, string $prefix): string
    {
        $storageUrl = trim((string) $asset->storage_url);
        $label = $asset->title ? "\"{$asset->title}\"" : "asset #{$asset->id}";

        if ($storageUrl === '') {
            throw new \RuntimeException("ASSET_MISSING: {$label} has no storage URL.");
        }

        $extension = pathinfo(parse_url($storageUrl, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION) ?: 'bin';
        $targetPath = sprintf('%s/%s-%s.%s', $tempDir, $prefix, Str::uuid(), $extension);

        if (app(StorageService::class)->isManagedUrl($storageUrl)) {
            $stream = app(StorageService::class)->readStream($storageUrl);

            if (! is_resource($stream)) {
                throw new \RuntimeException("ASSET_MISSING: {$label} could not be read from storage.");
            }

            $target = fopen($targetPath, 'wb');
            if (! is_resource($target)) {
                fclose($stream);
                throw new \RuntimeException("ASSET_MISSING: Could not write temp file for {$label}.");
            }
            stream_copy_to_stream($stream, $target);
            fclose($stream);
            fclose($target);

            return $targetPath;
        }

        $response = Http::connectTimeout(10)
            ->timeout(180)
            ->retry(2, 500)
            ->withOptions(['allow_redirects' => true])
            ->sink($targetPath)
            ->get($storageUrl);

        if (! $response->successful()) {
            @unlink($targetPath);
            throw new \RuntimeException("ASSET_MISSING: {$label} returned HTTP {$response->status()} and could not be downloaded.");
        }

        return $targetPath;
    }

    // ── FFprobe helpers ──────────────────────────────────────────────────────

    protected function probeMediaDuration(string $path): ?float
    {
        $process = new Process([
            'ffprobe', '-v', 'error',
            '-show_entries', 'format=duration',
            '-of', 'default=noprint_wrappers=1:nokey=1',
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

    protected function probeHasAudioStream(string $path): bool
    {
        $process = new Process([
            'ffprobe', '-v', 'error',
            '-select_streams', 'a:0',
            '-show_entries', 'stream=codec_type',
            '-of', 'default=noprint_wrappers=1:nokey=1',
            $path,
        ]);
        $process->setTimeout(30);

        try {
            $process->mustRun();
        } catch (\Throwable) {
            return false;
        }

        return trim($process->getOutput()) === 'audio';
    }

    // ── Storage helpers ──────────────────────────────────────────────────────

    protected function storageUrlExists(string $storageUrl): bool
    {
        $storageUrl = trim($storageUrl);
        if ($storageUrl === '') {
            return false;
        }

        $storage = app(StorageService::class);
        if ($storage->isManagedUrl($storageUrl)) {
            return $storage->exists($storageUrl);
        }

        try {
            return Http::connectTimeout(5)->timeout(10)->head($storageUrl)->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    protected function tempSegmentStoragePath(int $exportJobId, int $sceneIndex): string
    {
        return sprintf('exports/tmp/%d/segment-%03d.mp4', $exportJobId, $sceneIndex + 1);
    }

    protected function deleteTempSegments(int $exportJobId, int $sceneCount): void
    {
        $storage = app(StorageService::class);
        for ($i = 0; $i < $sceneCount; $i++) {
            rescue(fn () => $storage->delete($this->tempSegmentStoragePath($exportJobId, $i)), false);
        }
    }

    // ── Filesystem helpers ───────────────────────────────────────────────────

    protected function cleanupTempDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }

    // ── Caption / ASS helpers ────────────────────────────────────────────────

    /**
     * @param array{width:int,height:int} $dimensions
     */
    protected function buildASSCaption(
        string $text,
        string $captionStyle,
        string $captionPosition,
        string $captionFont,
        float $duration,
        array $dimensions,
        string $outputPath,
        string $highlightMode = 'keywords',
        ?array $timedWords = null,
        string $captionColor = '#ffffff',
        string $captionSize = 'medium',
        string $captionHighlightColor = '#ff6b35',
    ): void {
        $playResX = $dimensions['width'];
        $playResY = $dimensions['height'];

        $alignment = match ($captionPosition) {
            'center'    => 5,
            'top_third' => 8,
            default     => 2,
        };
        $marginV = match ($captionPosition) {
            'center'    => 0,
            'top_third' => (int) round(80 * $playResY / 1920),
            default     => (int) round(400 * $playResY / 1920),
        };
        $marginLR = (int) round(60 * $playResX / 1080);
        $fontName = $this->sanitizeASSFontName($captionFont);

        [$baseFontSize, $bold, $italic] = match ($captionStyle) {
            'editorial' => [(int) round(22 * $playResY / 480), 0, 1],
            'hacker'    => [(int) round(16 * $playResY / 480), -1, 0],
            default     => [(int) round(22 * $playResY / 480), -1, 0],
        };

        $sizeMultiplier = match ($captionSize) {
            'small'  => 0.72,
            'large'  => 1.35,
            'xlarge' => 1.72,
            default  => 1.0,
        };
        $fontSize = (int) round($baseFontSize * $sizeMultiplier);
        $primaryColor = $this->hexToASS($captionColor);

        $events = match ($highlightMode) {
            'word_by_word'           => $this->buildWordByWordEvents($text, $captionStyle, $duration, $timedWords, $captionHighlightColor),
            'line_by_line', 'keywords' => $this->buildKaraokeLineEvents($text, $captionStyle, $duration, $timedWords, $captionHighlightColor),
            default                  => $this->buildKaraokeLineEvents($text, $captionStyle, $duration, $timedWords, $captionHighlightColor),
        };

        $dialogueLines = array_map(
            static fn (array $e): string => "Dialogue: 0,{$e[0]},{$e[1]},Default,,0,0,0,,{$e[2]}",
            $events
        );

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
            "Style: Default,{$fontName},{$fontSize},{$primaryColor},&H000000FF&,&H00000000&,&H80000000&,{$bold},{$italic},0,0,100,100,0,0,1,3,2,{$alignment},{$marginLR},{$marginLR},{$marginV},1",
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
            implode("\n", $dialogueLines),
        ]);

        file_put_contents($outputPath, $content);
    }

    /** @return list<array{string,string,string}> */
    protected function buildWordByWordEvents(string $text, string $captionStyle, float $duration, ?array $timedWords = null, string $highlightColor = '#ff6b35'): array
    {
        $words = array_values(array_filter(preg_split('/\\s+/', trim($text)) ?: []));
        if (empty($words)) {
            return [['0:00:00.00', $this->formatASSTime($duration), '']];
        }

        $highlightASS = $this->hexToASS($highlightColor);
        $underline = $captionStyle === 'editorial' ? '\\u1' : '';
        $highlightCode = "\\c{$highlightASS}{$underline}";

        if (! empty($timedWords)) {
            $events = [];
            foreach ($timedWords as $timedWord) {
                $word  = trim((string) ($timedWord['text'] ?? ''));
                $start = (float) ($timedWord['start'] ?? -1);
                $end   = (float) ($timedWord['end'] ?? -1);
                if ($word === '' || $start < 0 || $end <= $start) { continue; }
                $start = max(0.0, min($duration, $start));
                $end   = max($start + 0.03, min($duration, $end));
                $events[] = [$this->formatASSTime($start), $this->formatASSTime($end), '{' . $highlightCode . '}' . $this->escapeASSText($word) . '{\\r}'];
            }
            if (! empty($events)) { return $events; }
        }

        $wordDuration = $duration / count($words);
        $events = [];
        foreach ($words as $i => $word) {
            $events[] = [
                $this->formatASSTime($i * $wordDuration),
                $this->formatASSTime(($i + 1) * $wordDuration),
                '{' . $highlightCode . '}' . $this->escapeASSText($word) . '{\\r}',
            ];
        }

        return $events;
    }

    /** @return list<array{string,string,string}> */
    protected function buildKaraokeLineEvents(string $text, string $captionStyle, float $duration, ?array $timedWords = null, string $highlightColor = '#ff6b35'): array
    {
        $words = array_values(array_filter(preg_split('/\s+/', trim($text)) ?: []));
        if (empty($words)) {
            return [['0:00:00.00', $this->formatASSTime($duration), '']];
        }

        $highlightASS = $this->hexToASS($highlightColor);
        $underline = $captionStyle === 'editorial' ? '\\u1' : '';

        $makeEvent = function (array $lineWords, int $activeIdx, float $start, float $end) use ($highlightASS, $underline): array {
            $parts = [];
            foreach ($lineWords as $j => $w) {
                $escaped = $this->escapeASSText($w);
                $parts[] = $j === $activeIdx
                    ? "{\\c{$highlightASS}{$underline}}{$escaped}{\\r}"
                    : $escaped;
            }
            return [$this->formatASSTime($start), $this->formatASSTime($end), implode(' ', $parts)];
        };

        if (! empty($timedWords)) {
            $chunks = array_chunk($timedWords, 4);
            $events = [];
            foreach ($chunks as $chunk) {
                $valid = array_values(array_filter($chunk, static fn (array $w): bool =>
                    trim((string) ($w['text'] ?? '')) !== ''
                    && (float) ($w['start'] ?? -1) >= 0
                    && (float) ($w['end'] ?? -1) > (float) ($w['start'] ?? -1)
                ));
                if (empty($valid)) { continue; }
                $lineWords = array_map(static fn (array $w): string => (string) $w['text'], $valid);
                foreach ($valid as $wi => $tw) {
                    $start = max(0.0, min($duration, (float) $tw['start']));
                    $end   = max($start + 0.03, min($duration, (float) $tw['end']));
                    $events[] = $makeEvent($lineWords, $wi, $start, $end);
                }
            }
            if (! empty($events)) { return $events; }
        }

        $wordDuration = $duration / count($words);
        $chunks = array_chunk($words, 4);
        $events = [];
        $offset = 0;
        foreach ($chunks as $chunk) {
            foreach ($chunk as $wi => $word) {
                $events[] = $makeEvent($chunk, $wi, $offset * $wordDuration, ($offset + 1) * $wordDuration);
                $offset++;
            }
        }

        return $events;
    }

    /** @return array<int,array{text:string,start:float,end:float}>|null */
    protected function captionTimingWordsFromAsset(?Asset $asset): ?array
    {
        if (! $asset) { return null; }
        $words = data_get($asset->metadata_json, 'caption_timing.words');
        if (! is_array($words) || empty($words)) { return null; }

        $normalized = [];
        foreach ($words as $word) {
            if (! is_array($word)) { continue; }
            $text  = trim((string) ($word['text'] ?? $word['word'] ?? ''));
            $start = (float) ($word['start'] ?? -1);
            $end   = (float) ($word['end'] ?? -1);
            if ($text === '' || $start < 0 || $end <= $start) { continue; }
            $normalized[] = compact('text', 'start', 'end');
        }

        return $normalized ?: null;
    }

    protected function sanitizeASSFontName(string $fontName): string
    {
        $fontName = trim(str_replace([',', "\r", "\n"], ' ', $fontName));
        return $fontName !== '' ? $fontName : 'Bebas Neue';
    }

    protected function buildASSStyledText(string $text, string $captionStyle, string $highlightColor = '#ff6b35'): string
    {
        $normalized = trim(preg_replace('/[\r\n]+/', ' ', $text));
        $words = array_values(array_filter(preg_split('/\s+/', $normalized) ?: []));
        if (count($words) === 0) { return ''; }

        $highlightStart = min(1, count($words) - 1);
        $highlightEnd   = min(count($words), $highlightStart + 2);
        $highlightASS   = $this->hexToASS($highlightColor);
        $underline      = $captionStyle === 'editorial' ? '\\u1' : '';
        $highlightCode  = "\\c{$highlightASS}{$underline}";

        $result = '';
        foreach ($words as $i => $word) {
            if ($i > 0) { $result .= ' '; }
            $escaped = $this->escapeASSText($word);
            $result .= ($i >= $highlightStart && $i < $highlightEnd)
                ? '{' . $highlightCode . '}' . $escaped . '{\\r}'
                : $escaped;
        }

        return $result;
    }

    protected function escapeASSText(string $text): string
    {
        return str_replace(['{', '}', "\n", "\r"], ['\{', '\}', '\N', ''], $text);
    }

    protected function formatASSTime(float $seconds): string
    {
        $cs = (int) round($seconds * 100);
        $h  = intdiv($cs, 360000); $cs %= 360000;
        $m  = intdiv($cs, 6000);   $cs %= 6000;
        $s  = intdiv($cs, 100);    $cs %= 100;
        return sprintf('%d:%02d:%02d.%02d', $h, $m, $s, $cs);
    }

    protected function hexToASS(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '&H00FFFFFF&';
        }
        return '&H00'.strtoupper(substr($hex, 4, 2)).strtoupper(substr($hex, 2, 2)).strtoupper(substr($hex, 0, 2)).'&';
    }

    protected function normalizeHexColor(string $value, string $fallback = '#ff6b35'): string
    {
        $hex = ltrim(trim($value), '#');
        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) { return $fallback; }
        return '#'.strtolower($hex);
    }

    protected function normalizeAudiogramStyle(string $style): string
    {
        $normalized = strtolower(trim($style));
        return match ($normalized) {
            'radial' => 'circle',
            'bars', 'mirror', 'circle', 'minimal' => $normalized,
            default => 'bars',
        };
    }

    protected function audiogramBackgroundCss(string $backgroundKey): string
    {
        return match ($backgroundKey) {
            'black'  => '#000',
            'purple' => 'linear-gradient(135deg,#1a0a2e 0%,#0d0d2b 50%,#14102a 100%)',
            'ocean'  => 'linear-gradient(135deg,#0a1628 0%,#0d1f3c 50%,#0a0e1a 100%)',
            default  => 'linear-gradient(180deg,#0d0d1a 0%,#0a0a14 100%)',
        };
    }

    protected function formatFilterDuration(float $duration): string
    {
        return sprintf('%.3F', max(0.1, $duration));
    }

    // ── Motion / Ken Burns ───────────────────────────────────────────────────

    /** @param array{width:int,height:int} $dimensions */
    protected function buildMotionFilter(Scene $scene, array $dimensions, float $duration): ?string
    {
        $motionSettings = is_array($scene->motion_settings_json) ? $scene->motion_settings_json : [];
        $effect    = (string) ($motionSettings['effect'] ?? 'zoom_in');
        $intensity = (string) ($motionSettings['intensity'] ?? 'moderate');

        if ($effect === 'static') { return null; }

        $frames = max(30, (int) round($duration * 30));
        $size   = "{$dimensions['width']}x{$dimensions['height']}";
        $speed  = match ($intensity) {
            'subtle'   => 0.0008,
            'dramatic' => 0.003,
            default    => 0.0015,
        };

        [$zExpr, $xExpr, $yExpr] = match ($effect) {
            'zoom_in'  => ["min(1.0+{$speed}*on,1.5)", 'iw/2-(iw/zoom/2)', 'ih/2-(ih/zoom/2)'],
            'zoom_out' => ["max(1.5-{$speed}*on,1.0)", 'iw/2-(iw/zoom/2)', 'ih/2-(ih/zoom/2)'],
            'pan_left' => ['1.2', "iw*(1-1/zoom)*(1-on/{$frames})", 'ih/2-(ih/zoom/2)'],
            'pan_right'=> ['1.2', "iw*(1-1/zoom)*on/{$frames}", 'ih/2-(ih/zoom/2)'],
            'pan_up'   => ['1.2', 'iw/2-(iw/zoom/2)', "ih*(1-1/zoom)*(1-on/{$frames})"],
            'pan_down' => ['1.2', 'iw/2-(iw/zoom/2)', "ih*(1-1/zoom)*on/{$frames}"],
            'pan_zoom' => ["min(1.0+{$speed}*on,1.4)", "iw*(1-1/zoom)*on/{$frames}", 'ih/2-(ih/zoom/2)'],
            default    => ['1.0', 'iw/2-(iw/zoom/2)', 'ih/2-(ih/zoom/2)'],
        };

        return "zoompan=z='{$zExpr}':x='{$xExpr}':y='{$yExpr}':d={$frames}:s={$size}:fps=30";
    }

    // ── Progress + batch sync ────────────────────────────────────────────────

    protected function dispatchProgress(
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

    protected function syncBatchJob(?ExportJob $exportJob): void
    {
        if (! $exportJob || ! $exportJob->batch_job_id) { return; }
        $batchJob = BatchJob::query()->find((int) $exportJob->batch_job_id);
        if (! $batchJob) { return; }

        $children = ExportJob::query()->where('batch_job_id', $batchJob->getKey())->get();
        $completedCount = $children->where('status', 'completed')->count();
        $failedCount    = $children->where('status', 'failed')->count();
        $queuedCount    = $children->whereIn('status', ['queued', 'processing'])->count();

        $status = 'processing';
        if ($queuedCount === 0) {
            if ($completedCount > 0 && $failedCount > 0) { $status = 'partial_success'; }
            elseif ($completedCount > 0) { $status = 'completed'; }
            else { $status = 'failed'; }
        }

        $batchJob->forceFill([
            'completed_count'    => $completedCount,
            'failed_count'       => $failedCount,
            'status'             => $status,
            'failure_summary_json' => $failedCount > 0
                ? ['failed_export_job_ids' => $children->where('status', 'failed')->pluck('id')->values()->all()]
                : null,
        ])->save();
    }

    protected function purgePreviousExports(ExportJob $completedJob, int $newAssetId): void
    {
        $staleJobs = ExportJob::query()
            ->where('project_id', $completedJob->project_id)
            ->where('aspect_ratio', $completedJob->aspect_ratio)
            ->where('language', $completedJob->language)
            ->where('status', 'completed')
            ->where('id', '!=', $completedJob->getKey())
            ->whereNotNull('output_asset_id')
            ->where('output_asset_id', '!=', $newAssetId)
            ->get();

        $storage = app(StorageService::class);
        foreach ($staleJobs as $staleJob) {
            $oldAsset = Asset::query()->find($staleJob->output_asset_id);
            if ($oldAsset && $oldAsset->storage_url) {
                $storage->delete((string) $oldAsset->storage_url);
                $oldAsset->forceFill(['status' => 'archived', 'storage_url' => ''])->save();
            }
            $staleJob->forceFill(['output_asset_id' => null])->save();
        }
    }

    protected function summarizeFailureForUser(\Throwable $exception): string
    {
        $message = trim(preg_replace('/\s+/', ' ', $exception->getMessage()) ?: '');
        if ($message === '') { return 'Export failed before a video could be produced.'; }

        if (str_starts_with($message, 'ASSET_MISSING:')) {
            $detail = trim(substr($message, strlen('ASSET_MISSING:')));
            return "A media file could not be loaded for export: {$detail} Please check your assets and try again.";
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
}
