<?php

namespace App\Services\CruiseControl;

use App\Models\Project;
use App\Models\Scene;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Builds + maintains the Cruise "project brief": a compact synthesis of the
 * video's creative direction (theme / topic / visual_style / tone /
 * recurring_subject) derived from the scene scripts + visual prompts.
 *
 * The assistant injects this into its resolve prompt so new scenes and
 * regenerations stay on-theme by default — without the user repeating
 * "keep the doodle style" every turn. The user can edit + lock it.
 *
 * Two ways to fill it:
 *   - seed(): free, from data we already have (one-shot prompt + style).
 *   - synthesize(): one cheap gpt-4o-mini pass over the actual scenes.
 */
class ProjectBriefService
{
    public const FIELDS = ['theme', 'topic', 'visual_style', 'tone', 'recurring_subject'];

    /**
     * Cheap, no-LLM seed used at one-shot creation. Good enough to anchor
     * the first few turns; the user (or a refresh) can enrich it later.
     *
     * @return array<string, mixed>
     */
    public function seed(string $prompt, string $style, ?string $tone): array
    {
        return [
            'theme'             => null,
            'topic'             => Str::limit(trim($prompt), 140, ''),
            'visual_style'      => $style,
            'tone'              => $tone,
            'recurring_subject' => null,
            'source'            => 'seed',
        ];
    }

    /**
     * Synthesise the brief from the project's actual scenes. Never throws —
     * returns the existing brief (or a thin fallback) if the LLM call fails.
     *
     * @return array<string, mixed>
     */
    public function synthesize(Project $project): array
    {
        $existing = is_array($project->assistant_brief_json) ? $project->assistant_brief_json : [];

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get(['scene_order', 'script_text', 'visual_prompt', 'visual_style']);

        if ($scenes->isEmpty()) {
            return $existing;
        }

        $apiKey = config('services.openai.api_key');
        if (empty($apiKey)) {
            return $existing;
        }

        $sceneBlock = $scenes->map(function ($s) {
            $script = Str::limit((string) $s->script_text, 200, '');
            $visual = Str::limit((string) $s->visual_prompt, 200, '');
            return "Scene {$s->scene_order} [style={$s->visual_style}]\n  says: {$script}\n  shows: {$visual}";
        })->implode("\n");

        $allowed = implode(', ', ProjectBriefService::FIELDS);
        $system = <<<SYS
You distil a short-form video into a compact creative brief the editor
assistant will follow. Read ALL the scenes below and infer the through-line.

Return STRICT JSON with exactly these keys: {$allowed}.
  theme             — the creative format/treatment in a few words
                      (e.g. "hand-drawn doodle explainer", "cinematic
                      product ad", "talking-head founder story").
  topic             — what the video is about, one short phrase.
  visual_style      — the dominant visual style across scenes (use the
                      scenes' style values + what the visuals describe).
  tone              — voice/mood in 2-4 words (e.g. "friendly, educational").
  recurring_subject — the subject/motif that repeats across scenes, if any
                      (e.g. "a hand drawing on a whiteboard"); null if none.

Infer from EVIDENCE in the scenes — do not invent. Keep every value short.
No prose, no markdown.
SYS;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(20)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => config('services.openai.cheap_model', 'gpt-4o-mini'),
                    'temperature'     => 0.3,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user',   'content' => "Video title: {$project->title}\n\n{$sceneBlock}"],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('ProjectBriefService synth failed', ['status' => $response->status()]);
                return $existing;
            }

            $parsed = json_decode((string) data_get($response->json(), 'choices.0.message.content', ''), true);
            if (! is_array($parsed)) {
                return $existing;
            }

            $brief = ['source' => 'synthesized'];
            foreach (ProjectBriefService::FIELDS as $f) {
                $v = $parsed[$f] ?? ($existing[$f] ?? null);
                $brief[$f] = is_string($v) ? Str::limit(trim($v), 200, '') : null;
            }
            return $brief;
        } catch (\Throwable $e) {
            Log::warning('ProjectBriefService synth exception', ['error' => $e->getMessage()]);
            return $existing;
        }
    }

    /**
     * Render the brief as a prompt block for the resolver. Empty string when
     * there's nothing meaningful yet, to keep the prompt tight.
     */
    public function promptBlock(?array $brief): string
    {
        if (! is_array($brief)) return '';
        $lines = [];
        $labels = [
            'theme'             => 'Theme',
            'topic'             => 'Topic',
            'visual_style'      => 'Visual style',
            'tone'              => 'Tone',
            'recurring_subject' => 'Recurring subject',
        ];
        foreach ($labels as $key => $label) {
            $v = trim((string) ($brief[$key] ?? ''));
            if ($v !== '') $lines[] = "  {$label}: {$v}";
        }
        if (empty($lines)) return '';

        return "PROJECT BRIEF (the established creative direction — keep new scenes,\n"
            . "regenerations and rewrites consistent with this UNLESS the user\n"
            . "explicitly changes it. The user's wording this turn always wins.)\n"
            . implode("\n", $lines) . "\n";
    }
}
