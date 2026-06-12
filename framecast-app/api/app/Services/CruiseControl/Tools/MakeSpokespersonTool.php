<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateTalkingVideoJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Turn a scene into a talking spokesperson: lip-sync the scene's character
 * still to its voiceover (VEED Fabric 1.0 via fal.ai). The person on screen
 * speaks the line, mouth + head synced to the audio. Reuses the animation
 * slot, so the result behaves like an animated clip in the editor.
 *
 * Requires the scene to already have BOTH a generated image and a voiceover —
 * lip-sync needs the audio.
 */
class MakeSpokespersonTool implements CruiseTool
{
    public function name(): string { return 'make_spokesperson'; }

    public function description(): string
    {
        return 'Make the character on a scene TALK — lip-sync their still image to the scene\'s voiceover so they speak the line (mouth + head synced to the audio). Use when the user wants a talking head / UGC creator / spokesperson look. Costs '.CreditService::VIDEO_SPOKESPERSON.' credits. The scene must already have a generated image AND a voiceover.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
        ];
    }

    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'motion'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);

        return [
            'Make the character talk (lip-synced spokesperson)',
            "Scene: {$scene?->scene_order}",
            'Model: VEED Fabric 1.0',
        ];
    }

    public function estimateCost(Project $project, array $params): int
    {
        return CreditService::VIDEO_SPOKESPERSON;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        if (config('services.replicate.api_token', '') === '') {
            throw new RuntimeException('Talking spokesperson is not configured yet (missing Replicate token).');
        }

        $scene = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereKey($params['scene_id'] ?? null)
            ->first();
        if (! $scene) {
            throw new RuntimeException('Scene not found in this project.');
        }
        if (! $scene->visual_asset_id) {
            throw new RuntimeException('Generate the scene image first, then make the character talk.');
        }
        if (! data_get($scene->voice_settings_json, 'audio_asset_id')) {
            throw new RuntimeException('Generate the voiceover first — lip-sync needs the audio.');
        }

        $token = (string) Str::uuid();
        $scene->forceFill([
            'image_generation_settings_json' => array_merge($scene->image_generation_settings_json ?? [], [
                'animation_in_progress' => true,
                'animation_last_error'  => null,
                'animation_started_at'  => now()->toIso8601String(),
                'animation_tier'        => 'spokesperson',
                'animation_cost'        => CreditService::VIDEO_SPOKESPERSON,
                'generation_token'      => $token,
            ]),
        ])->save();

        GenerateTalkingVideoJob::dispatch($scene->getKey(), $project->getKey(), $token)->afterCommit();

        return [
            'summary'           => "Making Scene {$scene->scene_order} a talking spokesperson",
            'credits_spent'     => CreditService::VIDEO_SPOKESPERSON,
            'affected_scene_id' => (int) $scene->getKey(),
        ];
    }
}
