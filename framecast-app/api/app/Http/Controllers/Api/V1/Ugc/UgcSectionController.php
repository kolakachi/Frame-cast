<?php

namespace App\Http\Controllers\Api\V1\Ugc;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateOneShotUgcJob;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * SECTIONS (owner, 2026-10-15): remake one section of a delivered one-take from the UGC chat's video card, charged per
 * section remade, with one step back. A section is one clip of the take; a clip that carried straight on from it (no
 * cut between them) is made again with it, and priced with it. The take's clips and recipe are kept for
 * GenerateOneShotUgcJob::SECTION_DAYS.
 */
class UgcSectionController extends Controller
{
    public function __construct(private readonly CreditService $credits)
    {
    }

    /** The sections of a delivered take, each with what remaking it covers and costs. */
    public function index(Request $request, int $projectId): JsonResponse
    {
        [$project, $scene, $recipe] = $this->take($request, $projectId);
        $s = $scene?->image_generation_settings_json ?? [];
        $sections = $s['sections'] ?? [];
        $kept = $recipe !== null && count($sections) > 1 && ! empty($s['sections_until']) && now()->lt(\Carbon\CarbonImmutable::parse($s['sections_until']));
        $pass = $project && $this->credits->planTier((int) $project->workspace_id) === 'ugc_pass';

        return response()->json(['data' => [
            'available' => $kept && ! $pass,
            'until' => $s['sections_until'] ?? null,
            'in_progress' => ! empty($s['section_remake']) && ! empty($s['in_progress']),
            'error' => $s['section_error'] ?? null,
            'can_go_back' => ! empty($s['sections_previous']),
            'sections' => $kept ? array_map(fn ($sec) => $sec + ['remake' => self::cover($recipe, $sections, (int) $sec['i'])], $sections) : [],
        ], 'meta' => []]);
    }

    /** Remake one section: its credits are held now and given back if it does not deliver. */
    public function remake(Request $request, int $projectId, int $index): JsonResponse
    {
        // The likeness and voice rights were confirmed when the video was made; a remake uses the same people.
        $v = $request->validate(['note' => ['nullable', 'string', 'max:500'], 'credits' => ['required', 'integer']]);
        [$project, $scene, $recipe] = $this->take($request, $projectId);
        if (! $project || ! $scene || $recipe === null) return $this->error('not_found', 'This video cannot be remade in sections.', 404);
        $s = $scene->image_generation_settings_json ?? [];
        $sections = $s['sections'] ?? [];
        if (! isset($sections[$index]) || empty($s['sections_until']) || now()->gte(\Carbon\CarbonImmutable::parse($s['sections_until']))) {
            return $this->error('not_kept', 'This video\'s sections are no longer kept, so one cannot be remade.', 410);
        }
        if ($this->credits->planTier((int) $project->workspace_id) === 'ugc_pass') return $this->error('upgrade_required', 'Remaking a section needs a plan.', 402);
        if (! empty($s['in_progress'])) return $this->error('busy', 'This video is already being remade.', 409);
        $cover = self::cover($recipe, $sections, $index);
        if ((int) $v['credits'] !== $cover['credits']) {
            return response()->json(['error' => ['code' => 'price_changed', 'message' => "Remaking this section is {$cover['credits']} credits.", 'context' => ['credits' => $cover['credits']]]], 422);
        }

        $token = (string) Str::uuid();
        $claimed = DB::transaction(function () use ($scene, $token, $project, $cover) {
            DB::table('scenes')->where('id', $scene->getKey())->lockForUpdate()->value('id');
            $scene->refresh();
            if (! empty(($scene->image_generation_settings_json ?? [])['in_progress'])) return false;
            if (! GenerateOneShotUgcJob::hold($scene, (int) $project->workspace_id, $cover['credits'], 'ugc_section')) return null;
            $scene->forceFill(['image_generation_settings_json' => array_merge($scene->refresh()->image_generation_settings_json ?? [], [
                'in_progress' => true, 'section_remake' => true, 'section_error' => null, 'oneshot_token' => $token,
                'generation_started_at' => now()->toIso8601String(),
            ])])->save();
            return true;
        });
        if ($claimed === false) return $this->error('busy', 'This video is already being remade.', 409);
        if ($claimed === null) return $this->error('insufficient_credits', "Remaking this section needs {$cover['credits']} credits.", 402);

        // The clips being replaced are kept for one step back.
        $parts = array_column($s['oneshot_parts'] ?? [], 'url', 'i');
        foreach ($cover['sections'] as $i) {
            if (isset($parts[$i])) Storage::disk('local')->copy($parts[$i], dirname($parts[$i]).'/previous-'.$i.'.mp4') ?: null;
        }
        $job = new GenerateOneShotUgcJob(
            (int) $recipe['projectId'], (int) $recipe['sceneId'], $recipe['chunks'], $cover['credits'], (string) $recipe['engine'],
            $recipe['referenceImages'] ?? [], $recipe['initialStartFrame'] ?? null, $recipe['seed'] ?? null, (string) ($recipe['resolution'] ?? '720p'),
            $recipe['demoStorageUrl'] ?? null, $token, (bool) ($recipe['checkFace'] ?? false), $recipe['composition'] ?? [], (bool) ($recipe['storyboard'] ?? false),
            (array) ($recipe['look'] ?? []), (string) ($recipe['aspect'] ?? '9:16'),
        );
        $job->remake = ['from' => $index, 'to' => max($cover['sections']), 'note' => trim((string) ($v['note'] ?? ''))];
        // The video stays as it is (and playable) until the remade one is ready.
        dispatch($job)->afterCommit();

        return response()->json(['data' => ['remaking' => $cover['sections'], 'credits' => $cover['credits']], 'meta' => []], 202);
    }

    /** One step back: the video before the last remake, and its clips. Free. */
    public function revert(Request $request, int $projectId): JsonResponse
    {
        [$project, $scene] = $this->take($request, $projectId);
        $s = $scene?->image_generation_settings_json ?? [];
        $previous = $s['sections_previous'] ?? null;
        if (! $project || ! $scene || ! $previous || ! empty($s['in_progress'])) return $this->error('nothing_to_undo', 'There is no earlier version to go back to.', 409);
        if (! Asset::query()->whereKey($previous['asset_id'])->where('workspace_id', $project->workspace_id)->exists()) {
            return $this->error('nothing_to_undo', 'The earlier version is no longer kept.', 410);
        }
        $parts = array_column($s['oneshot_parts'] ?? [], 'url', 'i');
        foreach (range((int) $previous['from'], (int) $previous['to']) as $i) {
            $old = isset($parts[$i]) ? dirname($parts[$i]).'/previous-'.$i.'.mp4' : null;
            if ($old && Storage::disk('local')->exists($old)) Storage::disk('local')->move($old, $parts[$i]) ?: null;
        }
        unset($s['sections_previous']);
        $s['sections'] = $previous['sections'] ?? ($s['sections'] ?? []);
        $scene->forceFill(['visual_asset_id' => (int) $previous['asset_id'], 'image_generation_settings_json' => $s])->save();

        return response()->json(['data' => ['reverted' => true], 'meta' => []]);
    }

    /**
     * What remaking section $index covers: it and every following section that carried straight on from the one before
     * (no cut between them), priced at the take's own rates: the model's per second, lip-sync on an Omni line, the music bed.
     *
     * @return array{sections: list<int>, seconds: int, credits: int}
     */
    public static function cover(array $recipe, array $sections, int $index): array
    {
        $covered = [$index];
        for ($j = $index + 1; isset($sections[$j]) && ! empty($sections[$j]['carried']); $j++) $covered[] = $j;
        $engine = (string) ($recipe['engine'] ?? 'seedance25');
        $rate = $engine === 'seedance25' && ($recipe['resolution'] ?? '720p') === '480p' ? CreditService::VIDEO_ONESHOT_SEEDANCE_DRAFT : (CreditService::VIDEO_ONESHOT_PER_SECOND[$engine] ?? CreditService::VIDEO_ONESHOT_PER_SECOND['seedance25']);
        $seconds = 0; $credits = 0;
        foreach ($covered as $j) {
            $chunk = $recipe['chunks'][$j] ?? [];
            $sec = (int) ($chunk['seconds'] ?? 0);
            $seconds += $sec;
            $credits += $sec * $rate;
            if ($engine === 'omni' && ! empty($chunk['voice']) && config('services.ugc.omni_lipsync', true)) $credits += $sec * CreditService::VIDEO_LIPSYNC_PER_SECOND;
        }
        if (! empty($recipe['chunks'][0]['music'])) $credits += CreditService::AI_MUSIC;

        return ['sections' => $covered, 'seconds' => $seconds, 'credits' => (int) $credits];
    }

    /** @return array{0: ?Project, 1: ?Scene, 2: ?array} the user's own one-take, its scene and its recipe */
    private function take(Request $request, int $projectId): array
    {
        $project = Project::query()->whereKey($projectId)->where('workspace_id', $request->user()->workspace_id)->where('visual_brief->ugc_format', 'one_shot')->first();
        $scene = $project ? Scene::query()->where('project_id', $project->id)->orderBy('scene_order')->first() : null;
        $path = ($scene?->image_generation_settings_json ?? [])['oneshot_recipe'] ?? null;
        $recipe = $path && Storage::disk('local')->exists($path) ? json_decode((string) Storage::disk('local')->get($path), true) : null;

        return [$project, $scene, is_array($recipe) ? $recipe : null];
    }

}
