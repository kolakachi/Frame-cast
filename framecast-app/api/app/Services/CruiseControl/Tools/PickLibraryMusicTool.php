<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Pick a music track from the workspace's seeded library and set it as the
 * project's background music. Zero cost (no generation). Different from
 * change_music which DOES cost credits (5 cr for AI regen). The LLM picks
 * this when the user wants library music, not a fresh AI generation.
 *
 * Library music lives in the assets table with asset_type='music' and a
 * mood tag (calm, upbeat, cinematic, etc.). We do a simple text-match
 * against title + description + tags. Top hit wins.
 */
class PickLibraryMusicTool implements CruiseTool
{
    public function name(): string { return 'pick_library_music'; }

    public function description(): string
    {
        return 'Set the project music to a track from the user\'s music library. ZERO COST — no generation. Use when the user says "pick a calm piano track", "use library music", or describes a mood without asking to generate. If the user explicitly says "regenerate" or "make new music" use change_music instead.';
    }

    public function paramsSchema(): array
    {
        return [
            'query' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Search words matched against music titles + tags. E.g. "calm piano", "upbeat folk", "cinematic ambient".',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'music'; }

    public function diffLines(Project $project, array $params): array
    {
        return [
            "Music: pick from library",
            "Query: " . mb_substr((string) ($params['query'] ?? ''), 0, 60),
        ];
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            throw new RuntimeException('Empty search query.');
        }

        // Tokenise the query and look for tracks whose title / description /
        // tags contain ANY of the words. Order by match count so the most
        // relevant track ranks first. Tiny query so no need for FTS.
        $words = array_filter(array_map('trim', preg_split('/\s+/u', mb_strtolower($query)) ?: []));
        if (empty($words)) {
            throw new RuntimeException('Empty search query after tokenisation.');
        }

        $candidates = Asset::query()
            ->where('workspace_id', $workspace->getKey())
            ->where('asset_type', 'music')
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
            throw new RuntimeException("No music in your library matched \"{$query}\". Want me to generate something instead?");
        }

        // Pick by match-count — title match weighs 3x, description 2x, tags 1x.
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

        $project->forceFill([
            'music_asset_id'      => $best->getKey(),
            'music_settings_json' => array_merge($project->music_settings_json ?? [], [
                'volume'      => 0.3,
                'fade_in_ms'  => 500,
                'fade_out_ms' => 800,
                'source'      => 'library',
            ]),
        ])->save();

        return [
            'summary'       => "Music set to \"{$best->title}\"",
            'credits_spent' => 0,
        ];
    }
}
