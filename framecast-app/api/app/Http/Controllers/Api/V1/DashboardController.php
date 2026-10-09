<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The dashboard's "Set up your studio" steps, read from what the workspace already has (2026-10-08 dashboard).
 * Nothing is locked: the steps only say what is done, so the dashboard can tick them and hide the card when all are.
 */
class DashboardController extends Controller
{
    public function setup(Request $request): JsonResponse
    {
        $ws = (int) $request->user()->workspace_id;
        $has = fn (string $table, callable $where) => Schema::hasTable($table) && $where(DB::table($table)->where('workspace_id', $ws))->exists();
        $steps = self::steps($ws);

        // The industry picked at onboarding lives on the first project; the dashboard tunes its cards with it.
        $niche = Schema::hasTable('niches') ? DB::table('projects')->join('niches', 'niches.id', '=', 'projects.niche_id')
            ->where('projects.workspace_id', $ws)->orderBy('projects.id')->value('niches.name') : null;

        // The name "Say your name right" asks about: the brand kit's, else the workspace's.
        $brandName = trim((string) (DB::table('brand_kits')->where('workspace_id', $ws)->orderBy('id')->value('name') ?: DB::table('workspaces')->where('id', $ws)->value('name')));

        $clonedVoice = $has('voice_profiles', fn ($q) => $q->where('is_cloned', true)->where('status', '!=', 'archived'));

        // What the workspace sells orders the video cards. Not yet known: learned from its videos in the background,
        // at most once a day, so a later visit is tuned.
        $industry = DB::table('workspaces')->where('id', $ws)->value('industry');
        if ($industry === null && \Illuminate\Support\Facades\Cache::add('industry-learn:'.$ws, 1, 86400)) \App\Jobs\LearnWorkspaceIndustry::dispatch($ws);

        // What the onboarding said the workspace makes most leads the cards (2026-10-09).
        $goal = data_get(json_decode((string) (Schema::hasColumn('workspaces', 'onboarding_json') ? DB::table('workspaces')->where('id', $ws)->value('onboarding_json') : ''), true), 'goal');
        return response()->json(['data' => ['goal' => is_string($goal) ? $goal : null, 'cloned_voice' => $clonedVoice, 'steps' => $steps, 'done' => count(array_filter($steps)), 'total' => count($steps), 'niche' => $niche, 'industry' => $industry, 'brand_name' => $brandName !== '' ? $brandName : null]]);
    }

    /** The four studio steps for one workspace, ticked from what it has (also each client's progress on the agency view). */
    public static function steps(int $ws): array
    {
        $has = fn (string $table, callable $where) => Schema::hasTable($table) && $where(DB::table($table)->where('workspace_id', $ws))->exists();

        return [
            // Logo, colours or fonts saved in a brand kit.
            'brand' => $has('brand_kits', fn ($q) => $q->where(fn ($w) => $w->whereNotNull('logo_asset_id')->orWhereNotNull('primary_color')->orWhereNotNull('font_primary'))),
            // At least one name with a saved way of saying it.
            'pronunciation' => $has('create_pronunciations', fn ($q) => $q),
            // The user's own character or a cloned voice (optional step).
            'face_voice' => $has('characters', fn ($q) => $q->where('status', 'active')->where('is_auto', false))
                || $has('voice_profiles', fn ($q) => $q->where('is_cloned', true)->where('status', '!=', 'archived')),
            // Any video made, in the classic editor or in Create.
            'first_video' => $has('projects', fn ($q) => $q)
                || DB::table('composition_revisions')->join('create_conversations', 'create_conversations.id', '=', 'composition_revisions.conversation_id')
                    ->where('create_conversations.workspace_id', $ws)->exists(),
        ];
    }

    /** The agency's view (phase 3): Needs you, a card per client and the month's credits; null data when none. */
    public function agency(Request $request, \App\Services\Agency\AgencyDashboard $dashboard): JsonResponse
    {
        return response()->json(['data' => $dashboard->for($request->user())]);
    }

    /** Next videos for you (D8): three ideas from the workspace's own videos; none before its first video. */
    public function ideas(Request $request, \App\Services\Create\DashboardIdeas $ideas): JsonResponse
    {
        return response()->json(['data' => ['ideas' => $ideas->for((int) $request->user()->workspace_id)]]);
    }

    /**
     * Recent videos (D6, 2026-10-09): the newest videos made in Weave, each with a frame of it and its video for a
     * silent hover, and a frame for each of the newest classic videos (their first scene's picture). The dashboard
     * puts both in one row, newest first.
     */
    public function recent(Request $request): JsonResponse
    {
        $ws = (int) $request->user()->workspace_id;
        $weave = DB::table('create_conversations')->join('composition_revisions', 'composition_revisions.id', '=', 'create_conversations.head_revision_id')
            ->where('create_conversations.workspace_id', $ws)->whereNull('create_conversations.archived_at')->where('composition_revisions.artifact_path', 'like', '%.mp4')
            ->orderByDesc('create_conversations.updated_at')->limit(8)
            ->get(['create_conversations.id', 'create_conversations.title', 'create_conversations.settings_json', 'create_conversations.updated_at', 'composition_revisions.id as revision_id'])
            ->map(function ($c) {
                $signed = fn (string $route) => \Illuminate\Support\Facades\URL::temporarySignedRoute($route, now()->addHours(2), ['revisionId' => $c->revision_id]);
                return ['id' => $c->id, 'title' => (string) ($c->title ?: 'Untitled video'), 'updated_at' => \Illuminate\Support\Carbon::parse($c->updated_at)->toIso8601String(),
                    'aspect_ratio' => json_decode((string) $c->settings_json, true)['aspect_ratio'] ?? '9:16',
                    'poster_url' => \App\Services\Media\ProjectPosters::createPoster($c->revision_id), 'video_url' => $signed('media.create.version')];
            })->values();

        // A classic video's frame: its export's, else its first scene's picture.
        $ids = DB::table('projects')->where('workspace_id', $ws)->orderByDesc('updated_at')->limit(8)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $classic = app(\App\Services\Media\ProjectPosters::class)->forProjects($ws, $ids);

        return response()->json(['data' => ['weave' => $weave, 'classic_posters' => (object) $classic]]);
    }
}
