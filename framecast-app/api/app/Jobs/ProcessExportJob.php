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
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $timeout = 1800;

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

        // Already completed on a prior attempt — nothing to do unless the file vanished.
        if ($exportJob->status === 'completed' && $exportJob->output_asset_id) {
            $outputAsset = Asset::query()->find((int) $exportJob->output_asset_id);

            if ($outputAsset && $this->storageUrlExists((string) $outputAsset->storage_url)) {
                return;
            }

            $exportJob->forceFill([
                'status' => 'queued',
                'progress_percent' => 0,
                'failure_reason' => null,
                'output_asset_id' => null,
                'completed_at' => null,
            ])->save();
        }

        $exportJob->forceFill([
            'status' => 'processing',
            'progress_percent' => 5,
            'failure_reason' => null,
            'started_at' => now(),
            'completed_at' => null,
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

        try {
            $this->processInTempDir($exportJob, $project, $scenes, $dimensions, $tempDir);
        } finally {
            $this->cleanupTempDir($tempDir);
        }
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Collection<int, Scene>  $scenes
     * @param  array{width:int,height:int}  $dimensions
     */
    private function processInTempDir(ExportJob $exportJob, Project $project, $scenes, array $dimensions, string $tempDir): void
    {
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

        if ($project->music_asset_id) {
            $assetIds->push((int) $project->music_asset_id);
        }

        /** @var Collection<int, Asset> $assetMap */
        $assetMap = Asset::query()
            ->whereIn('id', $assetIds)
            ->get()
            ->keyBy('id');
        $projectMusicAsset = $project->music_asset_id
            ? $assetMap->get((int) $project->music_asset_id)
            : null;

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
                    $project,
                    $scene,
                    $visualAsset,
                    $audioAsset,
                    $projectMusicAsset,
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

        // Deterministic path so retries overwrite rather than leak a second file.
        $storagePath = 'exports/export-'.$exportJob->getKey().'.mp4';
        $stream = fopen($outputFile, 'rb');

        if (! is_resource($stream)) {
            @unlink($outputFile);
            throw new \RuntimeException('Unable to open rendered export output.');
        }

        $exportStorageUrl = app(StorageService::class)->put($storagePath, $stream, [
            'ContentType' => 'video/mp4',
        ]);
        fclose($stream);

        if (! $this->storageUrlExists($exportStorageUrl)) {
            @unlink($outputFile);
            throw new \RuntimeException('Export output could not be verified in storage.');
        }

        $fileSize = filesize($outputFile) ?: null;
        @unlink($outputFile);

        $totalDurationSeconds = (float) $scenes->sum(fn (Scene $scene): float => (float) ($scene->duration_seconds ?: 0));

        DB::transaction(function () use ($exportJob, $exportStorageUrl, $fileSize, $totalDurationSeconds, $dimensions): void {
            $fresh = ExportJob::query()->lockForUpdate()->find($exportJob->getKey());

            // Another attempt already completed — skip asset creation.
            if ($fresh && $fresh->output_asset_id) {
                return;
            }

            $asset = Asset::query()->create([
                'workspace_id' => $exportJob->workspace_id,
                'channel_id' => null,
                'asset_type' => 'video',
                'title' => $exportJob->file_name,
                'description' => 'Rendered export output',
                'storage_url' => $exportStorageUrl,
                'duration_seconds' => $totalDurationSeconds,
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
        });

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

    private function cleanupTempDir(string $dir): void
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

        // 'none' mode disables captions entirely
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
            ];

            $assFile = null;
            if ($captionEnabled && trim($captionText) !== '') {
                $assFile = sprintf('%s/caption-%03d.ass', $tempDir, $index);
                $this->buildASSCaption(
                    $captionText,
                    $captionStyle,
                    $captionPosition,
                    $captionFont,
                    $duration,
                    $dimensions,
                    $assFile,
                    $captionHighlightMode,
                    $this->captionTimingWordsFromAsset($audioAsset),
                    $captionColor,
                    $captionSize,
                    $captionHighlightColor,
                );
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

    /**
     * @param array{width:int,height:int} $dimensions
     */
    private function renderAudiogramSegment(
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
                    $project,
                    $audioPath,
                    $musicAnalysisPath,
                    $tempDir,
                    $index,
                    $duration,
                    $elapsedSeconds
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
                'width' => $dimensions['width'],
                'height' => $dimensions['height'],
                'duration' => $duration,
                'fps' => 20,
                'sampleRate' => 16000,
                'style' => $this->normalizeAudiogramStyle((string) ($imgSettings['audiogram_style'] ?? 'bars')),
                'color' => $this->normalizeHexColor((string) ($imgSettings['audiogram_color'] ?? '#ff6b35')),
                'backgroundCss' => $this->audiogramBackgroundCss((string) ($imgSettings['audiogram_bg'] ?? 'dark')),
                'captionEnabled' => ($captionSettings['enabled'] ?? true) !== false
                    && (string) ($captionSettings['highlight_mode'] ?? 'keywords') !== 'none',
                'captionStyle' => (string) ($captionSettings['style_key'] ?? 'impact'),
                'captionHighlightMode' => (string) ($captionSettings['highlight_mode'] ?? 'keywords'),
                'captionPosition' => (string) ($captionSettings['position'] ?? 'bottom_third'),
                'captionFont' => (string) ($captionSettings['font'] ?? 'Bebas Neue'),
                'captionColor' => $this->normalizeHexColor((string) ($captionSettings['color'] ?? '#ffffff')),
                'captionSize' => (string) ($captionSettings['size'] ?? 'medium'),
                'captionHighlightColor' => $this->normalizeHexColor((string) ($captionSettings['highlight_color'] ?? '#ff6b35')),
                'captionText' => (string) ($scene->script_text ?: $scene->label ?: 'Framecast'),
                'timedWords' => $this->captionTimingWordsFromAsset($audioAsset),
                'pcmPath' => $pcmPath,
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

    private function buildAudiogramAnalysisMix(
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

        $filters = [
            implode(',', $musicFilters).'[music_bed]',
        ];

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
            '-filter_complex',
            implode(';', $filters),
            '-map',
            $voiceInputIndex !== null ? '[outa]' : '[music_bed]',
            '-t',
            (string) max(0.1, $duration),
            '-ac',
            '1',
            '-ar',
            '44100',
            '-c:a',
            'pcm_s16le',
            $analysisPath
        );

        $process = new Process($command);
        $process->setTimeout(180);
        $process->mustRun();

        return $analysisPath;
    }

    private function buildBrowserPcmFile(string $audioPath, string $tempDir, int $index): string
    {
        $pcmPath = sprintf('%s/audio-%03d.pcm', $tempDir, $index + 1);
        $process = new Process([
            'ffmpeg',
            '-y',
            '-i',
            $audioPath,
            '-vn',
            '-ac',
            '1',
            '-ar',
            '16000',
            '-f',
            'f32le',
            $pcmPath,
        ]);
        $process->setTimeout(180);
        $process->mustRun();

        return $pcmPath;
    }

    private function normalizeHexColor(string $value, string $fallback = '#ff6b35'): string
    {
        $hex = ltrim(trim($value), '#');

        if (strlen($hex) === 3 && ctype_xdigit($hex)) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return $fallback;
        }

        return '#'.strtolower($hex);
    }

    private function normalizeAudiogramStyle(string $style): string
    {
        $normalized = strtolower(trim($style));

        return match ($normalized) {
            'radial' => 'circle',
            'bars', 'mirror', 'circle', 'minimal' => $normalized,
            default => 'bars',
        };
    }

    private function audiogramBackgroundCss(string $backgroundKey): string
    {
        return match ($backgroundKey) {
            'black' => '#000',
            'purple' => 'linear-gradient(135deg,#1a0a2e 0%,#0d0d2b 50%,#14102a 100%)',
            'ocean' => 'linear-gradient(135deg,#0a1628 0%,#0d1f3c 50%,#0a0e1a 100%)',
            default => 'linear-gradient(180deg,#0d0d1a 0%,#0a0a14 100%)',
        };
    }

    private function applyMusicMix(Project $project, Asset $musicAsset, string $videoFile, string $outputFile, string $tempDir): void
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

        // Probe video duration to know how long to trim/loop the music.
        $videoDuration = $this->probeMediaDuration($videoFile) ?? 60.0;

        $volumeFraction = $volume / 100.0;
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

    private function probeHasAudioStream(string $path): bool
    {
        $process = new Process([
            'ffprobe',
            '-v',
            'error',
            '-select_streams',
            'a:0',
            '-show_entries',
            'stream=codec_type',
            '-of',
            'default=noprint_wrappers=1:nokey=1',
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

    private function formatFilterDuration(float $duration): string
    {
        return sprintf('%.3F', max(0.1, $duration));
    }

    private function storageUrlExists(string $storageUrl): bool
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
            return Http::connectTimeout(5)
                ->timeout(10)
                ->head($storageUrl)
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }

    private function materializeAsset(Asset $asset, string $tempDir, string $prefix): string
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

    private function hexToASS(string $hex): string
    {
        $hex = ltrim(trim($hex), '#');

        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }

        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return '&H00FFFFFF&';
        }

        $r = strtoupper(substr($hex, 0, 2));
        $g = strtoupper(substr($hex, 2, 2));
        $b = strtoupper(substr($hex, 4, 2));

        return "&H00{$b}{$g}{$r}&";
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
            'center' => 5,
            'top_third' => 8,
            default => 2,
        };

        $marginV = match ($captionPosition) {
            'center' => 0,
            'top_third' => (int) round(80 * $playResY / 1920),
            default => (int) round(400 * $playResY / 1920),
        };
        $marginLR = (int) round(60 * $playResX / 1080);

        $fontName = $this->sanitizeASSFontName($captionFont);

        [$baseFontSize, $bold, $italic] = match ($captionStyle) {
            'editorial' => [(int) round(22 * $playResY / 480), 0, 1],
            'hacker' => [(int) round(16 * $playResY / 480), -1, 0],
            default => [(int) round(22 * $playResY / 480), -1, 0],
        };

        $sizeMultiplier = match ($captionSize) {
            'small' => 0.72,
            'large' => 1.35,
            'xlarge' => 1.72,
            default => 1.0,
        };
        $fontSize = (int) round($baseFontSize * $sizeMultiplier);

        $primaryColor = $this->hexToASS($captionColor);

        $events = match ($highlightMode) {
            'word_by_word' => $this->buildWordByWordEvents($text, $captionStyle, $duration, $timedWords, $captionHighlightColor),
            'line_by_line', 'keywords' => $this->buildKaraokeLineEvents($text, $captionStyle, $duration, $timedWords, $captionHighlightColor),
            default => $this->buildKaraokeLineEvents($text, $captionStyle, $duration, $timedWords, $captionHighlightColor),
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

    /**
     * Word-by-word: one dialogue event per word, evenly timed.
     *
     * @return list<array{string, string, string}>
     */
    private function buildWordByWordEvents(string $text, string $captionStyle, float $duration, ?array $timedWords = null, string $highlightColor = '#ff6b35'): array
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
                $word = trim((string) ($timedWord['text'] ?? ''));
                $start = (float) ($timedWord['start'] ?? -1);
                $end = (float) ($timedWord['end'] ?? -1);

                if ($word === '' || $start < 0 || $end <= $start) {
                    continue;
                }

                $start = max(0.0, min($duration, $start));
                $end = max($start + 0.03, min($duration, $end));
                $styledText = '{' . $highlightCode . '}' . $this->escapeASSText($word) . '{\\r}';
                $events[] = [$this->formatASSTime($start), $this->formatASSTime($end), $styledText];
            }

            if (! empty($events)) {
                return $events;
            }
        }

        $wordDuration = $duration / count($words);
        $events = [];

        foreach ($words as $i => $word) {
            $start = $this->formatASSTime($i * $wordDuration);
            $end = $this->formatASSTime(($i + 1) * $wordDuration);
            $styledText = '{' . $highlightCode . '}' . $this->escapeASSText($word) . '{\\r}';
            $events[] = [$start, $end, $styledText];
        }

        return $events;
    }

    /**
     * Line-by-line: groups of ~4 words per event, timed proportionally by word count.
     *
     * @return list<array{string, string, string}>
     */
    private function buildLineByLineEvents(string $text, string $captionStyle, float $duration, ?array $timedWords = null, string $highlightColor = '#ff6b35'): array
    {
        $words = array_values(array_filter(preg_split('/\\s+/', trim($text)) ?: []));

        if (empty($words)) {
            return [['0:00:00.00', $this->formatASSTime($duration), '']];
        }

        if (! empty($timedWords)) {
            $chunks = array_chunk($timedWords, 4);
            $events = [];

            foreach ($chunks as $chunk) {
                $validWords = array_values(array_filter($chunk, static function (array $word): bool {
                    return trim((string) ($word['text'] ?? '')) !== ''
                        && (float) ($word['start'] ?? -1) >= 0
                        && (float) ($word['end'] ?? -1) > (float) ($word['start'] ?? -1);
                }));

                if (empty($validWords)) {
                    continue;
                }

                $start = max(0.0, min($duration, (float) $validWords[0]['start']));
                $end = max($start + 0.03, min($duration, (float) $validWords[array_key_last($validWords)]['end']));
                $line = implode(' ', array_map(static fn (array $word): string => (string) $word['text'], $validWords));
                $events[] = [$this->formatASSTime($start), $this->formatASSTime($end), $this->buildASSStyledText($line, $captionStyle, $highlightColor)];
            }

            if (! empty($events)) {
                return $events;
            }
        }

        $lines = array_chunk($words, 4);
        $totalWords = count($words);
        $events = [];
        $elapsed = 0.0;

        foreach ($lines as $lineWords) {
            $lineDuration = ($duration * count($lineWords)) / $totalWords;
            $start = $this->formatASSTime($elapsed);
            $end = $this->formatASSTime($elapsed + $lineDuration);
            $styledText = $this->buildASSStyledText(implode(' ', $lineWords), $captionStyle, $highlightColor);
            $events[] = [$start, $end, $styledText];
            $elapsed += $lineDuration;
        }

        return $events;
    }

    /**
     * Karaoke-line: shows a full chunk of ~4 words, one event per word,
     * highlighting only the active word while the rest render normally.
     * Matches the editor's word-highlight-within-line behaviour.
     *
     * @return list<array{string, string, string}>
     */
    private function buildKaraokeLineEvents(string $text, string $captionStyle, float $duration, ?array $timedWords = null, string $highlightColor = '#ff6b35'): array
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
                if ($j === $activeIdx) {
                    $parts[] = "{\\c{$highlightASS}{$underline}}{$escaped}{\\r}";
                } else {
                    $parts[] = $escaped;
                }
            }

            return [$this->formatASSTime($start), $this->formatASSTime($end), implode(' ', $parts)];
        };

        if (! empty($timedWords)) {
            $chunks = array_chunk($timedWords, 4);
            $events = [];

            foreach ($chunks as $chunk) {
                $valid = array_values(array_filter($chunk, static function (array $w): bool {
                    return trim((string) ($w['text'] ?? '')) !== ''
                        && (float) ($w['start'] ?? -1) >= 0
                        && (float) ($w['end'] ?? -1) > (float) ($w['start'] ?? -1);
                }));

                if (empty($valid)) {
                    continue;
                }

                $lineWords = array_map(static fn (array $w): string => (string) $w['text'], $valid);

                foreach ($valid as $wi => $timedWord) {
                    $start = max(0.0, min($duration, (float) $timedWord['start']));
                    $end   = max($start + 0.03, min($duration, (float) $timedWord['end']));
                    $events[] = $makeEvent($lineWords, $wi, $start, $end);
                }
            }

            if (! empty($events)) {
                return $events;
            }
        }

        // Fallback: no timing data — evenly distribute, still highlight active word in context
        $wordDuration = $duration / count($words);
        $chunks = array_chunk($words, 4);
        $events = [];
        $offset = 0;

        foreach ($chunks as $chunk) {
            foreach ($chunk as $wi => $word) {
                $start = $offset * $wordDuration;
                $end   = ($offset + 1) * $wordDuration;
                $events[] = $makeEvent($chunk, $wi, $start, $end);
                $offset++;
            }
        }

        return $events;
    }

    /**
     * @return array<int, array{text:string,start:float,end:float}>|null
     */
    private function captionTimingWordsFromAsset(?Asset $asset): ?array
    {
        if (! $asset) {
            return null;
        }

        $words = data_get($asset->metadata_json, 'caption_timing.words');

        if (! is_array($words) || empty($words)) {
            return null;
        }

        $normalized = [];
        foreach ($words as $word) {
            if (! is_array($word)) {
                continue;
            }

            $text = trim((string) ($word['text'] ?? $word['word'] ?? ''));
            $start = (float) ($word['start'] ?? -1);
            $end = (float) ($word['end'] ?? -1);

            if ($text === '' || $start < 0 || $end <= $start) {
                continue;
            }

            $normalized[] = compact('text', 'start', 'end');
        }

        return $normalized ?: null;
    }


    private function sanitizeASSFontName(string $fontName): string
    {
        $fontName = trim(str_replace([',', "\r", "\n"], ' ', $fontName));

        return $fontName !== '' ? $fontName : 'Bebas Neue';
    }

    private function buildASSStyledText(string $text, string $captionStyle, string $highlightColor = '#ff6b35'): string
    {
        $normalized = trim(preg_replace('/[\r\n]+/', ' ', $text));
        $words = array_values(array_filter(preg_split('/\s+/', $normalized) ?: []));

        if (count($words) === 0) {
            return '';
        }

        // Match preview previewWords(): highlight indices 1 and 2 (2nd and 3rd words)
        $highlightStart = min(1, count($words) - 1);
        $highlightEnd = min(count($words), $highlightStart + 2);

        $highlightASS = $this->hexToASS($highlightColor);
        $underline = $captionStyle === 'editorial' ? '\\u1' : '';
        $highlightCode = "\\c{$highlightASS}{$underline}";

        $result = '';
        foreach ($words as $i => $word) {
            if ($i > 0) {
                $result .= ' ';
            }
            $escaped = $this->escapeASSText($word);
            if ($i >= $highlightStart && $i < $highlightEnd) {
                $result .= '{' . $highlightCode . '}' . $escaped . '{\\r}';
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

        // Asset-not-found errors from materializeAsset — surface the label directly.
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
