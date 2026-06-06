<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use RuntimeException;

/**
 * Add a sound effect to a scene from the seeded library. Free, no
 * generation. AI SFX generation is intentionally out of scope until we
 * pick a vendor (Replicate audio-gen / Stable Audio) — see plan §10.
 *
 * Matches the existing per-scene SFX slot at scene.sound_asset_id (the
 * same field GenerateAIMusicJob writes to in one-shot mode). We're
 * keeping per-scene SFX and project-wide music separate.
 */
class AddSoundEffectTool implements CruiseTool
{
    public function name(): string { return 'add_sound_effect'; }

    public function description(): string
    {
        return 'Attach a sound effect from the library to a scene. Use for stings, transitions, ambient SFX. Search by query (e.g. "whoosh", "ambient rain", "tick clock"). If no library asset matches, reject with a hint — we don\'t generate SFX yet.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'query' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Words matched against SFX titles + tags. Use the most descriptive 2-3 words.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'sounds'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        return [
            "Sound: pick from library",
            "Query: " . mb_substr((string) ($params['query'] ?? ''), 0, 50),
            "Scene: {$scene?->scene_order}",
        ];
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scene = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereKey($params['scene_id'] ?? null)
            ->first();
        if (! $scene) {
            throw new RuntimeException('Scene not found in this project.');
        }

        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            throw new RuntimeException('Empty search query.');
        }

        $words = array_filter(array_map('trim', preg_split('/\s+/u', mb_strtolower($query)) ?: []));
        if (empty($words)) {
            throw new RuntimeException('Empty search query after tokenisation.');
        }

        // Sound effects share the assets table — they're asset_type='audio'
        // (NOT 'music' which is reserved for the project-wide bed). Tags
        // typically include 'sfx', mood words, etc.
        $candidates = Asset::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('asset_type', 'audio')
            ->where('status', 'active')
            ->where(function ($q) use ($words) {
                foreach ($words as $w) {
                    $q->orWhereRaw('LOWER(title) LIKE ?', ["%{$w}%"])
                      ->orWhereRaw('LOWER(description) LIKE ?', ["%{$w}%"])
                      ->orWhereRaw('LOWER(tags::text) LIKE ?', ["%{$w}%"]);
                }
            })
            ->limit(20)
            ->get();

        if ($candidates->isEmpty()) {
            throw new RuntimeException("No sound effects in your library matched \"{$query}\". We don't generate SFX yet — try uploading one to your asset library first.");
        }

        $scored = $candidates->map(function (Asset $a) use ($words) {
            $title = mb_strtolower((string) $a->title);
            $desc  = mb_strtolower((string) $a->description);
            $tags  = mb_strtolower(json_encode($a->tags));
            $score = 0;
            foreach ($words as $w) {
                if (str_contains($title, $w)) $score += 3;
                if (str_contains($desc, $w))  $score += 2;
                if (str_contains($tags, $w))  $score += 1;
            }
            return ['asset' => $a, 'score' => $score];
        })->sortByDesc('score');

        $best = $scored->first()['asset'];

        $scene->forceFill([
            'sound_asset_id'      => $best->getKey(),
            'sound_settings_json' => array_merge($scene->sound_settings_json ?? [], [
                'volume'      => 0.4,
                'fade_in_ms'  => 100,
                'fade_out_ms' => 300,
                'source'      => 'library',
            ]),
            'status' => 'edited',
        ])->save();

        return [
            'summary'       => "Added \"{$best->title}\" to Scene {$scene->scene_order}",
            'credits_spent' => 0,
        ];
    }
}
