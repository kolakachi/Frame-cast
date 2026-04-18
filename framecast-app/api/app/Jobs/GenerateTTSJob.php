<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Notification\NotificationService;
use App\Services\Generation\TTS\TTSAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GenerateTTSJob implements ShouldQueue
{
    use Queueable;

    public int $timeout = 900;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(TTSAdapter $tts, NotificationService $notifications): void
    {
        GenerationProgressed::dispatch($this->projectId, 'tts', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project) {
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

            DB::transaction(function () use ($project, $scene, $sceneText, $audio, $speed, $language): void {
                $asset = Asset::query()->create([
                    'workspace_id' => $project->workspace_id,
                    'channel_id' => $project->channel_id,
                    'asset_type' => 'audio',
                    'title' => 'TTS audio for project '.$project->getKey().' scene '.$scene->scene_order,
                    'description' => mb_substr($sceneText, 0, 180),
                    'storage_url' => $audio['audio_url'],
                    'duration_seconds' => $audio['duration_seconds'],
                    'mime_type' => 'audio/mpeg',
                    'tags' => ['tts', $audio['provider_key']],
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
    }

    public function failed(\Throwable $exception): void
    {
        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);

        GenerationProgressed::dispatch($this->projectId, 'tts', 'failed', $exception->getMessage());
    }
}
