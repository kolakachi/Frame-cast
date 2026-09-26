<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Services\CreditService;
use App\Jobs\GenerateVisualBriefJob;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use App\Traits\TracksJobFailure;
use Illuminate\Support\Facades\DB;

class BreakdownScenesJob implements ShouldQueue
{
    use Queueable;
    use TracksJobFailure;

    public int $timeout = 900;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(AIGenerationAdapter $aiGeneration): void
    {
        GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project || ! $project->script_text || $project->status === 'failed') {
            return;
        }

        $niche = $project->niche_id ? \App\Models\Niche::query()->find($project->niche_id) : null;

        $duration = (int) ($project->duration_target_seconds ?: 60);

        // Plan animated scenes around clip length; export does not loop footage.
        $animated = $this->projectAnimatesScenes($project);
        $shotSeconds = \App\Services\AnimatedShotPlan::clipSeconds(
            data_get($project->visual_brief, 'animate_tier'),
            data_get($project->visual_brief, 'animation_pacing'),
        );

        $variables = [
            'script_text' => $project->script_text,
            'niche_guidance' => $niche ? $niche->guidance() : \App\Models\Niche::guidanceForSlug(null),
            'duration' => $duration,
            'scene_guidance' => \App\Services\ScenePacing::guidance(
                $duration,
                $project->visual_generation_mode,
                $animated,
                $shotSeconds,
            ),
            'structure_guidance' => \App\Services\ScenePacing::structureGuidance($duration),
            'language' => $project->primary_language ?: 'en',
        ];
        $options = ['usage_context' => [
            'workspace_id' => $project->workspace_id, 'project_id' => $project->getKey(),
            'user_id' => $project->created_by_user_id, 'template' => 'scene_breakdown',
        ]];
        $options['deadline'] = microtime(true) + 300;
        $accepted = false;
        $reason = 'attempts_exhausted';
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            if (microtime(true) >= $options['deadline']) { $reason = 'time_budget'; break; }
            $options['draft_attempt'] = $attempt;
            GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'processing', 'Planning your scenes');
            try {
                $result = $aiGeneration->generate('scene_breakdown', $variables,
                    $this->breakdownTokenBudget($duration, $project->visual_generation_mode, $animated), 0.2, $options);
            } catch (\Throwable $e) {
                $result = ['content' => '', 'provider_key' => 'local_fallback'];
            }
            if (\App\Services\Generation\AI\ContentReview::refused($result)) { $reason = 'unsupported'; break; }
            if (\App\Services\Generation\AI\ContentReview::unusable($result)) {
                \App\Services\Generation\AI\ContentReview::logDecision('retry', 'generator_unusable_response', [
                    'project_id' => $project->id, 'stage' => 'scene_plan', 'draft_attempt' => $attempt,
                    'provider' => $result['provider_key'] ?? 'unknown', 'model' => $result['model'] ?? 'unknown',
                ]);
                continue;
            }
            $scenes = $this->extractScenes($result['content'], $project->script_text);
            GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'processing', 'Checking your scene plan');
            $review = app(\App\Services\Generation\AI\ContentReview::class)->review($aiGeneration,
                $project->script_text, json_encode($scenes, JSON_THROW_ON_ERROR), [
                    'stage' => 'scene_plan', 'language' => $project->primary_language ?: 'en',
                ], $options);
            if ($review['decision'] === 'pass') { $accepted = true; break; }
            if ($review['decision'] !== 'repair') { $reason = $review['decision']; break; }
            $options['system_prefix'] = 'Preserve the ORIGINAL script in the scene plan. Correct these review findings (data): '.json_encode($review['issues']);
        }
        if (! $accepted) {
            \App\Services\Generation\AI\ContentReview::logDecision('paused', $reason, [
                'project_id' => $project->id, 'workspace_id' => $project->workspace_id, 'stage' => 'scene_plan', 'draft_attempt' => min($attempt, 3),
            ]);
            $project->forceFill(['status' => 'failed'])->save();
            GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'failed',
                "We couldn't verify a scene plan faithful to your script. Production is paused before images and narration. Your existing scenes are unchanged; review the brief or retry later.",
                ['validation_reason' => $reason]);
            return;
        }
        $defaultVoiceSettings = is_array($project->default_voice_settings_json) ? $project->default_voice_settings_json : [];
        $defaultVisualStyle = $project->default_visual_style ?: $project->ai_broll_style;
        $waveformSettings = is_array($project->waveform_settings_json) ? $project->waveform_settings_json : [];

        DB::transaction(function () use ($project, $scenes, $defaultVoiceSettings, $defaultVisualStyle, $waveformSettings): void {
            Scene::query()->where('project_id', $project->getKey())->delete();

            foreach ($scenes as $index => $scene) {
                $sceneVoiceSettings = $defaultVoiceSettings;

                if ($sceneVoiceSettings !== [] && empty($sceneVoiceSettings['language']) && $project->primary_language) {
                    $sceneVoiceSettings['language'] = $project->primary_language;
                }

                Scene::query()->create([
                    'project_id' => $project->getKey(),
                    'scene_order' => $index + 1,
                    'scene_type' => $scene['scene_type'],
                    'label' => $scene['label'],
                    'script_text' => $scene['script_text'],
                    'duration_seconds' => $scene['duration_seconds'],
                    'voice_settings_json' => $sceneVoiceSettings !== [] ? $sceneVoiceSettings : null,
                    'visual_style' => $defaultVisualStyle,
                    'image_generation_settings_json' => $project->visual_generation_mode === 'waveform' && $waveformSettings !== []
                        ? $waveformSettings
                        : null,
                    // Inherit project.default_character_id so every scene generated by this
                    // breakdown picks up the recurring spokesperson — wizard-selected
                    // characters thread through here without per-scene assignment.
                    'character_id' => $project->default_character_id,
                    'status' => 'draft',
                ]);
            }
        });

        GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'completed', null, [
            'total' => count($scenes),
        ]);
        app(CreditService::class)->deductQuietly(
            (int) $project->workspace_id,
            CreditService::BREAKDOWN,
            'breakdown',
            ['project_id' => $project->getKey(), 'user_id' => $project->created_by_user_id, 'metadata' => ['scene_count' => count($scenes)]],
        );
        GenerateVisualBriefJob::dispatch($project->getKey());
    }

    public function failed(\Throwable $exception): void
    {
        $this->recordFailureTrace($exception, 'project', $this->projectId, null, $this->projectId);

        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);

        GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'failed', $exception->getMessage());
    }

    /**
     * @return list<array{scene_type:string,label:string,script_text:string,duration_seconds:float}>
     */
    /**
     * Room for the JSON the breakdown must return.
     *
     * Was a flat 1100 tokens, which is fine for 8 scenes and truncates the
     * response for 30 — and a truncated JSON body fails to decode, silently
     * dropping the whole AI breakdown in favour of naive chunking. Budget
     * scales with the scenes we actually asked for.
     */
    private function breakdownTokenBudget(int $duration, ?string $visualMode, bool $animated): int
    {
        $scenes = \App\Services\ScenePacing::targetScenes($duration, $visualMode, $animated);

        // ~110 tokens per scene of JSON (narration dominates), plus headroom.
        return (int) min(8000, max(1100, 400 + $scenes * 110 * 1.3));
    }

    /**
     * Whether this project's scenes will be animated (i2v b-roll).
     *
     * There is no project-level animation flag yet — the wizard's b-roll mode
     * is still to come — so this reads the visual brief for one. Written as a
     * single method so that when the flag lands, only this changes.
     */
    private function projectAnimatesScenes(\App\Models\Project $project): bool
    {
        $brief = is_array($project->visual_brief) ? $project->visual_brief : [];

        return (bool) ($brief['animate'] ?? false)
            || ! empty($brief['animate_tier'])
            || $project->visual_generation_mode === 'ai_video';
    }

    private function extractScenes(string $content, string $scriptText): array
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            $sceneRows = $decoded['scenes'] ?? $decoded;

            if (is_array($sceneRows) && $sceneRows !== []) {
                $normalized = [];

                foreach ($sceneRows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $text = $this->sanitizeSceneText(trim((string) ($row['script_text'] ?? $row['text'] ?? '')));

                    if ($text === '') {
                        continue;
                    }

                    $normalized[] = [
                        'scene_type' => $this->normalizeSceneType((string) ($row['scene_type'] ?? 'narration')),
                        'label' => $this->normalizeLabel((string) ($row['label'] ?? 'Scene')),
                        'script_text' => $text,
                        'duration_seconds' => $this->normalizeDuration($row['duration_seconds'] ?? null),
                    ];
                }

                if ($normalized !== []) {
                    // Guard against a malformed response only. Slicing here
                    // discards the user's narration, so the bound is far above
                    // any real breakdown — it was a flat 20, which quietly cut
                    // long videos short and lost the script past scene 20.
                    return array_slice($normalized, 0, \App\Services\ScenePacing::PARSER_HARD_LIMIT);
                }
            }
        }

        return $this->fallbackScenes($scriptText);
    }

    private function sanitizeSceneText(string $text): string
    {
        // Strip bracketed stage directions: [CUT TO: ...], [INT. ...], [FADE OUT], etc.
        $text = preg_replace('/\[[^\]]*\]/', '', $text) ?? $text;

        // Strip INT./EXT. sluglines at the start of a line
        $text = preg_replace('/^(INT|EXT)\.[^\n]*/mi', '', $text) ?? $text;

        // Strip FADE IN/OUT, DISSOLVE TO, CUT TO at the start of a line
        $text = preg_replace('/^(FADE\s+(IN|OUT)|DISSOLVE\s+TO|CUT\s+TO)[^\n]*/mi', '', $text) ?? $text;

        // Strip ALL-CAPS character cues (e.g. "NARRATOR:", "VOICE OVER:") at line start
        $text = preg_replace('/^[A-Z][A-Z\s]{2,}:\s*/m', '', $text) ?? $text;

        // Strip parenthetical action lines: "(beat)", "(sighs)", "(walks away)"
        $text = preg_replace('/^\([^)]+\)\s*$/m', '', $text) ?? $text;

        // Collapse multiple blank lines into one and trim
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return trim($text);
    }

    private function normalizeSceneType(string $sceneType): string
    {
        $allowed = ['hook', 'narration', 'transition', 'text_card', 'quote'];

        return in_array($sceneType, $allowed, true) ? $sceneType : 'narration';
    }

    private function normalizeLabel(string $label): string
    {
        $trimmed = trim($label);

        return $trimmed !== '' ? mb_substr($trimmed, 0, 255) : 'Scene';
    }

    /**
     * @param  mixed  $duration
     */
    private function normalizeDuration(mixed $duration): float
    {
        $value = (float) $duration;

        if ($value <= 0) {
            return 6.0;
        }

        return min(max($value, 2.0), 20.0);
    }

    /**
     * @return list<array{scene_type:string,label:string,script_text:string,duration_seconds:float}>
     */
    private function fallbackScenes(string $scriptText): array
    {
        $chunks = preg_split('/\n{2,}/', trim($scriptText)) ?: [];

        if ($chunks === []) {
            return [[
                'scene_type' => 'narration',
                'label' => 'Scene 1',
                'script_text' => trim($scriptText),
                'duration_seconds' => 6.0,
            ]];
        }

        $scenes = [];

        foreach (array_slice($chunks, 0, \App\Services\ScenePacing::PARSER_HARD_LIMIT) as $index => $chunk) {
            $text = trim($chunk);

            if ($text === '') {
                continue;
            }

            $scenes[] = [
                'scene_type' => $index === 0 ? 'hook' : 'narration',
                'label' => 'Scene '.($index + 1),
                'script_text' => $text,
                'duration_seconds' => 6.0,
            ];
        }

        return $scenes === [] ? [[
            'scene_type' => 'narration',
            'label' => 'Scene 1',
            'script_text' => trim($scriptText),
            'duration_seconds' => 6.0,
        ]] : $scenes;
    }

}
