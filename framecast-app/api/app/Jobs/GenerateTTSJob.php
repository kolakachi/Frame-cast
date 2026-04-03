<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Generation\TTS\TTSAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class GenerateTTSJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(TTSAdapter $tts): void
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

        DB::transaction(function () use ($project, $scenes, $tts): void {
            foreach ($scenes as $scene) {
                $voiceId = (string) data_get($scene->voice_settings_json, 'voice_id', 'alloy');
                $speed = (float) data_get($scene->voice_settings_json, 'speed', 1.0);
                $language = $project->primary_language ?: 'en';
                $sceneText = (string) ($scene->script_text ?: '');

                $audio = $tts->synthesize($sceneText, $language, $voiceId, $speed);

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

                $scene->forceFill([
                    'duration_seconds' => $audio['duration_seconds'],
                    'voice_settings_json' => $voiceSettings,
                ])->save();
            }

            $project->forceFill([
                'status' => 'ready_for_review',
            ])->save();
        });

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
