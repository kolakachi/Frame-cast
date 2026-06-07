<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\CruiseControl\CruiseActionRunService;
use App\Services\Notification\NotificationService;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Media\MediaTranscriptionService;
use App\Services\CreditService;
use App\Services\WorkspaceUsageService;
use App\Traits\TracksJobFailure;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Throwable;

class GenerateTTSJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public int $timeout = 900;

    public function __construct(
        public readonly int $projectId,
        /** @var array<int>|null */
        public readonly ?array $sceneIds = null,
        public readonly bool $shouldFinalizeProject = true,
    ) {
        $this->onQueue('generation');
    }

    public function handle(TTSAdapter $tts, NotificationService $notifications, MediaTranscriptionService $transcription, WorkspaceUsageService $usageService): void
    {
        GenerationProgressed::dispatch($this->projectId, 'tts', 'processing', null, $this->progressMeta());

        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $creator = $project->created_by_user_id
            ? User::query()->with('workspace')->find($project->created_by_user_id)
            : null;

        if ($creator && $usageService->hasReachedVoiceLimit($creator)) {
            $ctx = $usageService->voiceLimitContext($creator);
            if ($this->shouldFinalizeProject) {
                $project->forceFill(['status' => 'failed'])->save();
                $notifications->create(
                    (int) $project->workspace_id,
                    'Voice limit reached',
                    "Project #{$project->getKey()} could not generate voice audio — your workspace has used {$ctx['used']} of {$ctx['limit']} voice minutes on the {$ctx['plan']} plan.",
                    'error',
                    $creator ? (int) $creator->getKey() : null,
                    ['project_id' => $project->getKey(), 'limit_context' => $ctx],
                );
            }
            $this->markVoiceError($project, "Voice limit reached for the current workspace plan.");
            GenerationProgressed::dispatch($this->projectId, 'tts', 'failed', 'Voice limit reached for the current workspace plan.', $this->progressMeta());
            app(CruiseActionRunService::class)->markStageFailed(
                $this->projectId,
                'tts',
                'Voice limit reached for the current workspace plan.',
                $this->singleSceneId(),
            );

            return;
        }

        $scenesQuery = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order');
        if (is_array($this->sceneIds) && $this->sceneIds !== []) {
            $scenesQuery->whereIn('id', $this->sceneIds);
        }
        $scenes = $scenesQuery->get();

        if ($scenes->isEmpty()) {
            // No voiceable scenes (e.g. a future voiceless one-shot, or a
            // scene-scoped re-record whose scenes were deleted). Still
            // finalize so the project doesn't strand at 'generating' on the
            // progress view — the status flip used to live ONLY past the
            // per-scene loop, which this early return skipped.
            $this->finalizeIfNeeded($project, $notifications);
            GenerationProgressed::dispatch($this->projectId, 'tts', 'completed', null, $this->progressMeta());
            return;
        }

        $total = $scenes->count();
        $done  = 0;

        foreach ($scenes as $scene) {
            $existingVoiceSettings = $scene->voice_settings_json ?? [];
            $existingAudioAssetId = (int) data_get($existingVoiceSettings, 'audio_asset_id', 0);
            $voiceIsOutdated = (bool) data_get($existingVoiceSettings, 'is_outdated', false);

            if ($existingAudioAssetId > 0 && ! $voiceIsOutdated && Asset::query()->whereKey($existingAudioAssetId)->exists()) {
                continue;
            }

            $voiceId = (string) data_get($scene->voice_settings_json, 'voice_id', 'alloy');
            $speed = (float) data_get($scene->voice_settings_json, 'speed', 1.0);
            $language = $project->primary_language ?: 'en';
            $sceneText = (string) ($scene->script_text ?: '');

            $audio = $tts->synthesize($sceneText, $language, $voiceId, $speed, [
                'usage_context' => [
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->getKey(),
                    'user_id' => $project->created_by_user_id,
                    'scene_id' => $scene->getKey(),
                ],
            ]);

            $asset = null;

            DB::transaction(function () use ($project, $scene, $sceneText, $audio, $speed, $language, &$asset): void {
                $asset = Asset::query()->create([
                    'workspace_id' => $project->workspace_id,
                    'channel_id' => $project->channel_id,
                    'asset_type' => 'audio',
                    'title' => 'TTS audio for project '.$project->getKey().' scene '.$scene->scene_order,
                    'description' => mb_substr($sceneText, 0, 180),
                    'storage_url' => $audio['audio_url'],
                    'duration_seconds' => $audio['duration_seconds'],
                    'mime_type' => 'audio/mpeg',
                    'transcription_status' => 'queued',
                    'tags' => ['tts', $audio['provider_key']],
                    'metadata_json' => [
                        'caption_timing_status' => 'queued',
                    ],
                    'usage_count' => 1,
                    'status' => 'active',
                    'created_by_user_id' => $project->created_by_user_id,
                ]);

                $voiceSettings = $scene->voice_settings_json ?? [];
                $voiceSettings['provider_key'] = $audio['provider_key'];
                $voiceSettings['voice_id'] = $audio['provider_voice_id'];
                $voiceSettings['speed'] = $speed;
                $voiceSettings['language'] = $language;
                $voiceSettings['audio_asset_id'] = $asset->getKey();
                $voiceSettings['is_outdated'] = false;
                $voiceSettings['last_error'] = null;

                $scene->forceFill([
                    'duration_seconds' => $audio['duration_seconds'],
                    'voice_settings_json' => $voiceSettings,
                    'status' => $this->shouldFinalizeProject ? $scene->status : 'edited',
                ])->save();
            });

            if ($asset) {
                $this->attachCaptionTiming($asset, $transcription);
            }

            rescue(fn () => app(CreditService::class)->deduct(
                (int) $project->workspace_id,
                CreditService::TTS,
                'tts',
                [
                    'project_id' => $project->getKey(),
                    'scene_id'   => $scene->getKey(),
                    'user_id'    => $project->created_by_user_id,
                    'metadata'   => ['voice_id' => $voiceId, 'language' => $language],
                ],
            ));
            $done++;
            GenerationProgressed::dispatch($this->projectId, 'tts', 'processing', null, [
                ...$this->progressMeta((int) $scene->getKey()),
                'done' => $done, 'total' => $total,
            ]);
        }

        $this->finalizeIfNeeded($project, $notifications);

        GenerationProgressed::dispatch($this->projectId, 'tts', 'completed', null, $this->progressMeta());
        if ($sceneId = $this->singleSceneId()) {
            app(CruiseActionRunService::class)->markStageCompleted($this->projectId, 'tts', $sceneId);
        }

        if ($this->shouldFinalizeProject && $project->series_id) {
            SummarizeEpisodeJob::dispatch($project->getKey());
        }
    }

    /**
     * Flip the project to ready_for_review and notify — the single place
     * that finalizes a one-shot/brief generation. Called both after the
     * per-scene voice loop AND on the no-voiceable-scenes early return, so
     * completion never depends on there being audio to generate. Idempotent:
     * re-running just re-saves the same status.
     */
    private function finalizeIfNeeded(Project $project, NotificationService $notifications): void
    {
        if (! $this->shouldFinalizeProject) {
            return;
        }

        DB::transaction(function () use ($project): void {
            $project->forceFill(['status' => 'ready_for_review'])->save();
        });

        $notifications->create(
            (int) $project->workspace_id,
            'Generation complete',
            'Project #'.$project->getKey().' is ready for review.',
            'success',
            $project->created_by_user_id ? (int) $project->created_by_user_id : null,
            [
                'project_id' => $project->getKey(),
                'status' => 'ready_for_review',
            ],
        );
    }

    private function attachCaptionTiming(Asset $asset, MediaTranscriptionService $transcription): void
    {
        $asset->forceFill([
            'transcription_status' => 'processing',
            'metadata_json' => array_merge($asset->metadata_json ?? [], [
                'caption_timing_status' => 'processing',
            ]),
        ])->save();

        try {
            $result = $transcription->transcribeAssetWithTimestamps($asset);
            $words = $result['words'] ?? [];
            $segments = $result['segments'] ?? [];

            $metadata = array_merge($asset->metadata_json ?? [], [
                'transcription_provider' => $result['provider_key'],
                'transcription_model' => $result['model'],
                'transcribed_at' => now()->toIso8601String(),
                'caption_timing_status' => count($words) > 0 ? 'completed' : 'unavailable',
                'caption_timing' => [
                    'source' => $result['provider_key'],
                    'model' => $result['model'],
                    'words' => $words,
                    'segments' => $segments,
                    'generated_at' => now()->toIso8601String(),
                ],
            ]);

            $asset->forceFill([
                'transcript_text' => $result['transcript'],
                'transcription_status' => 'completed',
                'transcription_error' => null,
                'metadata_json' => $metadata,
            ])->save();
        } catch (Throwable $exception) {
            $asset->forceFill([
                'transcription_status' => 'failed',
                'transcription_error' => $exception->getMessage(),
                'metadata_json' => array_merge($asset->metadata_json ?? [], [
                    'caption_timing_status' => 'failed',
                    'caption_timing_error' => $exception->getMessage(),
                ]),
            ])->save();
        }
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'project', $this->projectId, null, $this->projectId);

        if ($this->shouldFinalizeProject) {
            Project::query()
                ->whereKey($this->projectId)
                ->update([
                    'status' => 'failed',
                ]);
        }

        $project = Project::query()->find($this->projectId);
        if ($project) {
            $this->markVoiceError($project, mb_substr($exception->getMessage(), 0, 500));
        }

        GenerationProgressed::dispatch($this->projectId, 'tts', 'failed', $exception->getMessage(), $this->progressMeta());
        app(CruiseActionRunService::class)->markStageFailed($this->projectId, 'tts', $exception->getMessage(), $this->singleSceneId());
    }

    /**
     * @return array<string, int>
     */
    private function progressMeta(?int $sceneId = null): array
    {
        $meta = [];
        $targetSceneId = $sceneId ?? $this->singleSceneId();
        if ($targetSceneId !== null) {
            $meta['scene_id'] = $targetSceneId;
        }

        return $meta;
    }

    private function singleSceneId(): ?int
    {
        if (! is_array($this->sceneIds) || count($this->sceneIds) !== 1) {
            return null;
        }

        return (int) $this->sceneIds[0];
    }

    private function markVoiceError(Project $project, string $error): void
    {
        $query = Scene::query()->where('project_id', $project->getKey());
        if (is_array($this->sceneIds) && $this->sceneIds !== []) {
            $query->whereIn('id', $this->sceneIds);
        }

        $query->get()->each(function (Scene $scene) use ($error): void {
            $voiceSettings = is_array($scene->voice_settings_json) ? $scene->voice_settings_json : [];
            $voiceSettings['last_error'] = $error;
            $scene->forceFill([
                'voice_settings_json' => $voiceSettings,
            ])->save();
        });
    }
}
