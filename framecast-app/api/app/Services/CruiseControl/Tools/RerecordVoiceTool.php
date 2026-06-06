<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateTTSJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use RuntimeException;

/**
 * Swap a scene's voice. Updates voice_settings_json then dispatches the
 * existing GenerateTTSJob — the same path the editor's "Regenerate voice"
 * button uses, so behavior is identical.
 */
class RerecordVoiceTool implements CruiseTool
{
    private const VALID_VOICES = [
        'alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer',
        // gpt-4o-mini-tts additions
        'ash', 'coral', 'sage', 'ballad', 'verse',
    ];

    public function name(): string { return 'rerecord_voice'; }

    public function description(): string
    {
        return 'Change the voice on a single scene. Voice options: alloy (neutral), echo (warm male), fable (British male), onyx (deep male, authoritative), nova (energetic female), shimmer (soft female), ash, coral, sage, ballad, verse.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'The scene to change. Use the user\'s current scope.',
            ],
            'voice_id' => [
                'type' => 'string',
                'required' => true,
                'enum' => self::VALID_VOICES,
                'description' => 'OpenAI voice key.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'voice'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        $previous = (string) data_get($scene?->voice_settings_json, 'voice_id', 'alloy');
        return [
            "Voice: {$previous} → " . ($params['voice_id'] ?? '?'),
            "Scene: {$scene?->scene_order} only",
        ];
    }

    public function estimateCost(Project $project, array $params): int
    {
        return CreditService::TTS;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scene = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereKey($params['scene_id'] ?? null)
            ->first();
        if (! $scene) {
            throw new RuntimeException('Scene not found in this project.');
        }
        if (! in_array($params['voice_id'] ?? null, self::VALID_VOICES, true)) {
            throw new RuntimeException('Unknown voice_id.');
        }

        // Mark voice outdated + update voice_id; GenerateTTSJob re-synthesizes
        // scenes whose voice_settings_json.is_outdated is true (lines 79-83 of
        // GenerateTTSJob.php). Same path the editor's voice regen button takes.
        $voiceSettings = $scene->voice_settings_json ?? [];
        $voiceSettings['voice_id']    = $params['voice_id'];
        $voiceSettings['is_outdated'] = true;
        $scene->forceFill(['voice_settings_json' => $voiceSettings])->save();

        GenerateTTSJob::dispatch($project->getKey());

        return [
            'summary'         => "Re-recording Scene {$scene->scene_order} with {$params['voice_id']}",
            'credits_spent'   => CreditService::TTS,
        ];
    }
}
