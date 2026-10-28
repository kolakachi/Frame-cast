<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateTTSJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use RuntimeException;

/**
 * One narrator for the whole video (2026-10-28): "change the voice" used to take one re-record per scene, six to a
 * turn. Every spoken scene gets the voice, the project's default follows, and the lines are re-recorded together.
 * Undo puts every scene's previous voice back.
 */
class ChangeVoiceEverywhereTool implements CruiseTool
{
    public function name(): string { return 'change_voice_everywhere'; }

    public function description(): string
    {
        return 'Change the narrator for the WHOLE video: every spoken scene gets this voice and is re-recorded. Use for "change the voice", "use a male/deeper/calmer voice", "make the narrator X" when no single scene is named. voice_id is a Gemini/OpenAI voice name or a cloned voice name; voice_prompt is an optional delivery direction for Gemini voices.';
    }

    public function paramsSchema(): array
    {
        return [
            'voice_id' => ['type' => 'string', 'required' => true],
            'voice_prompt' => ['type' => 'string', 'required' => false, 'description' => 'How it should sound, e.g. "slow, low and hushed".'],
        ];
    }

    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'voice'; }

    /** @return \Illuminate\Support\Collection<int, Scene> */
    private function spoken(Project $project)
    {
        return Scene::query()->where('project_id', $project->getKey())->orderBy('scene_order')->get()
            ->filter(fn (Scene $s) => trim((string) $s->script_text) !== '' && data_get($s->voice_settings_json, 'enabled', true) !== false)->values();
    }

    public function diffLines(Project $project, array $params): array
    {
        $from = (string) data_get($project->default_voice_settings_json, 'voice_id', '(mixed)');

        return ["Voice: {$from} → {$params['voice_id']}", 'All '.$this->spoken($project)->count().' spoken scenes re-recorded'];
    }

    public function estimateCost(Project $project, array $params): int
    {
        $v = VoiceCatalog::resolve((int) $project->workspace_id, (string) ($params['voice_id'] ?? ''));
        $per = $v ? CreditService::ttsCostForVoice((string) $v['voice_id'], ['provider' => $v['provider'] ?? '']) : CreditService::ttsCostForVoice((string) ($params['voice_id'] ?? ''));

        return $this->spoken($project)->count() * $per;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $v = VoiceCatalog::resolve((int) $workspace->getKey(), (string) ($params['voice_id'] ?? ''));
        if (! $v) throw new RuntimeException("Unknown voice '{$params['voice_id']}'. Use an expressive (Gemini) or classic (OpenAI) voice, or one of the user's cloned voice names.");
        $ids = [];
        foreach ($this->spoken($project) as $scene) {
            $settings = array_merge((array) ($scene->voice_settings_json ?? []), ['voice_id' => $v['voice_id'], 'provider' => $v['provider'], 'is_outdated' => true, 'last_error' => null]);
            if (array_key_exists('voice_prompt', $params)) $settings['voice_prompt'] = trim((string) $params['voice_prompt']);
            $scene->forceFill(['voice_settings_json' => $settings])->save();
            $ids[] = (int) $scene->getKey();
        }
        $project->forceFill(['default_voice_settings_json' => array_merge((array) ($project->default_voice_settings_json ?? []), array_filter(['voice_id' => $v['voice_id'], 'provider' => $v['provider'], 'voice_prompt' => array_key_exists('voice_prompt', $params) ? trim((string) $params['voice_prompt']) : null], fn ($x) => $x !== null))])->save();
        if ($ids !== []) GenerateTTSJob::dispatch($project->getKey(), $ids, false)->afterCommit();

        return ['summary' => 'Narrator changed to '.$params['voice_id'].' on '.count($ids).' scenes; re-recording', 'credits_spent' => $this->estimateCost($project, $params), 'affected_scene_id' => $ids[0] ?? null];
    }
}
