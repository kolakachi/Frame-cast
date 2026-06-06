<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateAIMusicJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use RuntimeException;

/**
 * Regenerate the project's background music with a new mood seed. Reuses
 * the same GenerateAIMusicJob the one-shot music regen panel uses, so
 * the result lands in project.music_asset_id and the editor's music
 * picker picks it up.
 *
 * Phase 1B only supports "regenerate" mode (mood seed). Library-pick
 * mode (set music_asset_id from existing track) is queued for later — the
 * LLM doesn't reliably know which library track to pick yet.
 */
class ChangeMusicTool implements CruiseTool
{
    private const ALLOWED_MOODS = [
        'calm acoustic', 'cinematic ambient', 'upbeat indie pop',
        'lo-fi chill', 'tense electronic', 'inspiring orchestral',
        'warm folk', 'energetic synth', 'hopeful piano',
    ];

    public function name(): string { return 'change_music'; }

    public function description(): string
    {
        return 'Regenerate the project background music. Pick a mood from: '
            . implode(', ', self::ALLOWED_MOODS)
            . '. If user says "more upbeat", lean toward "upbeat indie pop" or "energetic synth". If "more chill" → "lo-fi chill" or "calm acoustic". The music applies to the whole video, not a single scene.';
    }

    public function paramsSchema(): array
    {
        return [
            'mood' => [
                'type' => 'string',
                'required' => true,
                'enum' => self::ALLOWED_MOODS,
            ],
            'duration_seconds' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Defaults to 8s, max 30.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'music'; }

    public function diffLines(Project $project, array $params): array
    {
        $mood = $params['mood'] ?? '?';
        return [
            "Music: regenerate with mood \"{$mood}\"",
            "Scope: whole project (replaces current bed)",
        ];
    }

    public function estimateCost(Project $project, array $params): int
    {
        return CreditService::AI_MUSIC;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $mood = $params['mood'] ?? null;
        if (! in_array($mood, self::ALLOWED_MOODS, true)) {
            throw new RuntimeException('Unknown music mood.');
        }
        $duration = (int) min(30, max(3, $params['duration_seconds'] ?? 8));

        // GenerateAIMusicJob is keyed off the first scene id so it has
        // somewhere to attach. Music lands on project.music_asset_id (the
        // job already does this — verified earlier this session).
        $firstScene = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->first();
        if (! $firstScene) {
            throw new RuntimeException('Project has no scenes to anchor the music to.');
        }

        GenerateAIMusicJob::dispatch(
            $firstScene->getKey(),
            $project->getKey(),
            $mood,
            $mood,
            $duration,
        );

        return [
            'summary'       => "Regenerating music with \"{$mood}\" mood",
            'credits_spent' => CreditService::AI_MUSIC,
        ];
    }
}
