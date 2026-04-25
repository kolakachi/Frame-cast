<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\Notification\NotificationService;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Media\MediaTranscriptionService;
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
    ) {
        $this->onQueue('generation');
    }

    public function handle(TTSAdapter $tts, NotificationService $notifications, MediaTranscriptionService $transcription, WorkspaceUsageService $usageService): void
    {
        GenerationProgressed::dispatch($this->projectId, 'tts', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $creator = $project->created_by_user_id
            ? User::query()->with('workspace')->find($project->created_by_user_id)
            : null;

        if ($creator && $usageService->hasReachedVoiceLimit($creator)) {
            $ctx = $usageService->voiceLimitContext($creator);
            $project->forceFill(['status' => 'failed'])->save();
            $notifications->create(
                (int) $project->workspace_id,
                'Voice limit reached',
                "Project #{$project->getKey()} could not generate voice audio — your workspace has used {$ctx['used']} of {$ctx['limit']} voice minutes on the {$ctx['plan']} plan.",
                'error',
                $creator ? (int) $creator->getKey() : null,
                ['project_id' => $project->getKey(), 'limit_context' => $ctx],
            );
            GenerationProgressed::dispatch($this->projectId, 'tts', 'failed');

            return;
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        if ($scenes->isEmpty()) {
            return;
        }

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

                $scene->forceFill([
                    'duration_seconds' => $audio['duration_seconds'],
                    'voice_settings_json' => $voiceSettings,
                ])->save();
            });

            if ($asset) {
                $this->attachCaptionTiming($asset, $transcription);
            }
        }

        DB::transaction(function () use ($project): void {
            $project->forceFill([
                'status' => 'ready_for_review',
            ])->save();
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

        GenerationProgressed::dispatch($this->projectId, 'tts', 'completed');

        if ($project->series_id) {
            SummarizeEpisodeJob::dispatch($project->getKey());
        }
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

        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);

        GenerationProgressed::dispatch($this->projectId, 'tts', 'failed', $exception->getMessage());
    }
}
