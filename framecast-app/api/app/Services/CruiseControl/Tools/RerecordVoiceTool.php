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
                'required' => false,
                'enum' => self::VALID_VOICES,
                'description' => 'OpenAI voice key. Omit if you only want to change speed/stability and keep the current voice.',
            ],
            'speed' => [
                'type' => 'number',
                'required' => false,
                'description' => 'Playback speed. Range 0.25-4. Map user intent: "slower" -> 0.85, "faster" / "energetic" -> 1.15, "much slower" -> 0.7, "much faster" -> 1.3. Omit to keep the current speed.',
            ],
            'stability' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['low', 'medium', 'high'],
                'description' => 'Voice stability. low = more expressive / variable, high = more consistent / monotone. Map: "more confident", "calm", "steady" -> high. "expressive", "dramatic", "varied" -> low.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'voice'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        $previousVoice = (string) data_get($scene?->voice_settings_json, 'voice_id', 'alloy');
        $previousSpeed = (float) data_get($scene?->voice_settings_json, 'speed', 1.0);
        $previousStab  = (string) data_get($scene?->voice_settings_json, 'stability', 'medium');
        $lines = [];
        if (! empty($params['voice_id']) && $params['voice_id'] !== $previousVoice) {
            $lines[] = "Voice: {$previousVoice} → {$params['voice_id']}";
        }
        if (isset($params['speed']) && (float) $params['speed'] !== $previousSpeed) {
            $lines[] = "Speed: {$previousSpeed} → " . number_format((float) $params['speed'], 2);
        }
        if (! empty($params['stability']) && $params['stability'] !== $previousStab) {
            $lines[] = "Stability: {$previousStab} → {$params['stability']}";
        }
        if (empty($lines)) {
            $lines[] = "Voice: re-record with current settings";
        }
        $lines[] = "Scene: {$scene?->scene_order} only";
        return $lines;
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
        // voice_id is optional now — speed/stability changes alone are valid.
        // Validate when provided.
        if (! empty($params['voice_id']) && ! in_array($params['voice_id'], self::VALID_VOICES, true)) {
            throw new RuntimeException('Unknown voice_id.');
        }
        if (isset($params['speed'])) {
            $speed = (float) $params['speed'];
            if ($speed < 0.25 || $speed > 4) {
                throw new RuntimeException('Speed must be between 0.25 and 4.');
            }
        }
        if (! empty($params['stability']) && ! in_array($params['stability'], ['low', 'medium', 'high'], true)) {
            throw new RuntimeException('Stability must be low, medium, or high.');
        }

        // Mark voice outdated + update changed fields; GenerateTTSJob
        // re-synthesizes scenes whose voice_settings_json.is_outdated is
        // true (lines 79-83 of GenerateTTSJob.php).
        $voiceSettings = $scene->voice_settings_json ?? [];
        if (! empty($params['voice_id']))   $voiceSettings['voice_id']  = $params['voice_id'];
        if (isset($params['speed']))         $voiceSettings['speed']     = (float) $params['speed'];
        if (! empty($params['stability']))   $voiceSettings['stability'] = $params['stability'];
        $voiceSettings['is_outdated'] = true;
        $voiceSettings['last_error'] = null;
        $scene->forceFill(['voice_settings_json' => $voiceSettings])->save();

        GenerateTTSJob::dispatch($project->getKey(), [$scene->getKey()], false)->afterCommit();

        $summaryBits = [];
        if (! empty($params['voice_id']))  $summaryBits[] = $params['voice_id'];
        if (isset($params['speed']))       $summaryBits[] = number_format((float) $params['speed'], 2) . 'x speed';
        if (! empty($params['stability'])) $summaryBits[] = $params['stability'] . ' stability';
        $detail = empty($summaryBits) ? '' : ' (' . implode(', ', $summaryBits) . ')';

        return [
            'summary'         => "Re-recording Scene {$scene->scene_order}{$detail}",
            'credits_spent'   => CreditService::TTS,
            'affected_scene_id' => (int) $scene->getKey(),
        ];
    }
}
