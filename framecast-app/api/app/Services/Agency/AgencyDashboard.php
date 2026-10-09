<?php

namespace App\Services\Agency;

use App\Http\Controllers\Api\V1\DashboardController;
use App\Models\{Asset, User, Workspace};
use App\Services\CreditService;
use Illuminate\Support\Facades\{DB, URL};

/**
 * The agency's dashboard (phase 3, 2026-10-09; mockup view 3; owner: "Needs you" comes first). Across the clients in
 * view, what is waiting on the agency: changes a client asked for, approved videos not yet scheduled, finished videos
 * not yet sent to the client, and videos waiting on the client. Then a card per client and the month's credits. The
 * owner sees every client and everyone's spend; a collaborator sees the clients they were given and their allowance.
 * A client's "Ask for changes" is the approval's rejected decision with its note.
 */
class AgencyDashboard
{
    private const ORDER = ['changes' => 0, 'request' => 1, 'schedule' => 2, 'review' => 3, 'with_client' => 4];

    public function __construct(private CreditService $credits) {}

    /** Null when this person has no agency view (no clients in view). */
    public function for(User $user): ?array
    {
        $identity = User::find($user->id);
        $agency = app(WorkspaceAccess::class)->agency($identity);
        $collaborator = $identity?->role === User::ROLE_COLLABORATOR;
        if (! $agency && ! $collaborator) return null;
        $home = $agency ?? Workspace::find($identity->workspace_id);
        $clients = $agency
            ? Workspace::where('parent_workspace_id', $agency->id)->where('status', '!=', 'archived')->orderBy('name')->get()
            : Workspace::whereIn('id', DB::table('workspace_memberships')->where('user_id', $identity->id)->where('role', User::ROLE_COLLABORATOR)->whereNull('revoked_at')->pluck('workspace_id'))
                ->where('parent_workspace_id', $home->id)->where('status', '!=', 'archived')->orderBy('name')->get();
        if ($clients->isEmpty() && ! $collaborator) return null;

        $names = $clients->mapWithKeys(fn ($w) => [$w->id => $w->client_label ?: $w->name])->all();
        $needs = $this->needs(array_keys($names), $names);
        $byClient = collect($needs)->groupBy(fn ($n) => $n['client']['id']);
        $cards = $clients->map(function ($w) use ($byClient, $names) {
            $steps = DashboardController::steps((int) $w->id);
            $mine = $byClient->get($w->id, collect());
            return ['id' => $w->id, 'name' => $names[$w->id], 'status' => $w->status,
                'changes' => $mine->where('kind', 'changes')->count(), 'to_review' => $mine->where('kind', 'review')->count(),
                'with_client' => $mine->where('kind', 'with_client')->count(), 'to_schedule' => $mine->where('kind', 'schedule')->count(),
                'scheduled' => DB::table('scheduled_posts')->where('workspace_id', $w->id)->where('status', 'scheduled')->where('scheduled_at', '>=', now())->count(),
                'setup_done' => count(array_filter($steps)), 'setup_total' => count($steps),
                'credits_this_month' => $this->credits->spentThisMonth((int) $w->id), 'monthly_cap' => $w->monthly_credit_cap ? (int) $w->monthly_credit_cap : null];
        })->values()->all();

        $out = ['role' => $collaborator ? 'collaborator' : 'owner', 'agency_name' => $home?->name, 'needs' => array_slice($needs, 0, 30), 'clients' => $cards];
        if ($collaborator) {
            $limit = Allowance::limitFor($identity->id);
            $out['allowance'] = ['limit' => $limit, 'spent' => Allowance::spentThisMonth($identity->id), 'reserved' => Allowance::reserved($identity->id)];
        } else {
            $team = User::where('workspace_id', $agency->id)->where('role', User::ROLE_COLLABORATOR)->whereIn('status', ['active', 'paused'])->orderBy('name')->get();
            $out['credits'] = [
                'own' => $this->credits->spentThisMonth((int) $agency->id),
                'by_client' => collect($cards)->map(fn ($c) => ['id' => $c['id'], 'name' => $c['name'], 'credits' => $c['credits_this_month']])->sortByDesc('credits')->values()->all(),
                'by_collaborator' => $team->map(fn ($u) => ['id' => $u->id, 'name' => $u->name ?: $u->email, 'credits' => Allowance::spentThisMonth($u->id),
                    'allowance' => $u->monthly_credit_allowance !== null ? (int) $u->monthly_credit_allowance : null])->sortByDesc('credits')->values()->all(),
            ];
        }

        return $out;
    }

    /** What is waiting on the agency across these clients, most urgent first. */
    private function needs(array $ids, array $names): array
    {
        if (! $ids) return [];
        $items = [];
        // The latest approval of each video decides where it stands.
        $latest = DB::table('approvals')->whereIn('workspace_id', $ids)->selectRaw('max(id) as id')->groupBy('project_id')->pluck('id');
        $approvals = DB::table('approvals')->whereIn('id', $latest)->get();
        $scheduled = DB::table('scheduled_posts')->whereIn('project_id', $approvals->pluck('project_id')->filter())->whereIn('status', ['scheduled', 'processing', 'published'])->pluck('project_id')->flip();
        foreach ($approvals as $a) {
            $who = $a->reviewer_name ?: $a->reviewer_email ?: 'The client';
            $kind = match (true) {
                $a->status === 'rejected' => 'changes',
                $a->status === 'approved' && ! isset($scheduled[$a->project_id]) => 'schedule',
                $a->status === 'pending' && (! $a->expires_at || strtotime((string) $a->expires_at) > time()) => 'with_client',
                default => null,
            };
            if (! $kind) continue;
            $at = $kind === 'with_client' ? $a->created_at : ($a->reviewed_at ?: $a->updated_at);
            $days = max(0, (int) floor((time() - strtotime((string) $at)) / 86400));
            $items[] = $this->item($kind, (int) $a->workspace_id, $names, $this->videoOf((int) $a->project_id, $a->export_job_id ? (int) $a->export_job_id : null), (string) $at, match ($kind) {
                'changes' => $who.' asked: “'.mb_strimwidth(trim((string) $a->comment) ?: 'see the note', 0, 140, '…').'”',
                'schedule' => 'Approved by '.$who.($days ? ' '.$days.' '.($days === 1 ? 'day' : 'days').' ago' : ' today').'. Schedule it.',
                default => 'Waiting '.($days ? $days.' '.($days === 1 ? 'day' : 'days') : 'since today').' for the client',
            });
        }
        // A message or request from the client, not yet picked up.
        foreach (DB::table('client_requests')->whereIn('workspace_id', $ids)->where('status', 'requested')->orderByDesc('created_at')->limit(20)->get() as $q) {
            $from = (string) (DB::table('users')->where('id', $q->created_by_user_id)->value('name') ?: 'The client');
            $items[] = $this->item('request', (int) $q->workspace_id, $names, ['title' => (string) $q->title, 'path' => '/clients/'.$q->workspace_id, 'poster' => null], (string) $q->created_at,
                $from.' wrote: “'.mb_strimwidth(trim((string) $q->brief), 0, 140, '…').'”');
        }
        // Finished in the last two weeks and never sent to the client: Weave versions and classic exports.
        $sentExports = DB::table('approvals')->whereIn('workspace_id', $ids)->whereNotNull('export_job_id')->pluck('export_job_id')->flip();
        $sentProjects = DB::table('approvals')->whereIn('workspace_id', $ids)->pluck('project_id')->flip();
        $weave = DB::table('create_conversations')->join('composition_revisions', 'composition_revisions.id', '=', 'create_conversations.head_revision_id')
            ->whereIn('create_conversations.workspace_id', $ids)->whereNull('create_conversations.archived_at')->where('composition_revisions.artifact_path', 'like', '%.mp4')
            ->where('composition_revisions.created_at', '>=', now()->subDays(14))
            ->get(['create_conversations.id', 'create_conversations.title', 'create_conversations.workspace_id', 'composition_revisions.id as revision_id', 'composition_revisions.export_job_id', 'composition_revisions.created_at']);
        foreach ($weave as $c) {
            if ($c->export_job_id && isset($sentExports[$c->export_job_id])) continue;
            $items[] = $this->item('review', (int) $c->workspace_id, $names, $this->weaveVideo($c->id, (string) $c->title, $c->revision_id), (string) $c->created_at, 'Ready for you to check, then send to the client');
        }
        $exports = DB::table('export_jobs')->join('projects', 'projects.id', '=', 'export_jobs.project_id')->whereIn('projects.workspace_id', $ids)
            ->where('export_jobs.status', 'completed')->whereNull('export_jobs.composition_revision_id')->where('export_jobs.completed_at', '>=', now()->subDays(14))
            ->orderByDesc('export_jobs.completed_at')->get(['projects.id as project_id', 'projects.workspace_id', 'export_jobs.completed_at'])->unique('project_id');
        foreach ($exports as $e) {
            if (isset($sentProjects[$e->project_id])) continue;
            $items[] = $this->item('review', (int) $e->workspace_id, $names, $this->videoOf((int) $e->project_id, null), (string) $e->completed_at, 'Ready for you to check, then send to the client');
        }
        usort($items, fn ($a, $b) => [self::ORDER[$a['kind']], -strtotime($a['at'])] <=> [self::ORDER[$b['kind']], -strtotime($b['at'])]);

        return $items;
    }

    private function item(string $kind, int $workspaceId, array $names, array $video, string $at, string $detail): array
    {
        return ['kind' => $kind, 'client' => ['id' => $workspaceId, 'name' => $names[$workspaceId] ?? 'Client'], 'title' => $video['title'], 'detail' => $detail,
            'at' => \Illuminate\Support\Carbon::parse($at)->toIso8601String(), 'poster_url' => $video['poster'], 'open' => ['workspace_id' => $workspaceId, 'path' => $video['path']]];
    }

    /** A video by its project: a Weave version opens in Create, a classic one in the editor. */
    private function videoOf(int $projectId, ?int $exportJobId): array
    {
        $revisionId = $exportJobId ? DB::table('export_jobs')->where('id', $exportJobId)->value('composition_revision_id') : null;
        $revision = $revisionId ? DB::table('composition_revisions')->where('id', $revisionId)->first(['id', 'conversation_id']) : null;
        if ($revision) {
            $title = (string) DB::table('create_conversations')->where('id', $revision->conversation_id)->value('title');
            return $this->weaveVideo($revision->conversation_id, $title, $revision->id);
        }
        $title = (string) (DB::table('projects')->where('id', $projectId)->value('title') ?: 'Video #'.$projectId);
        $assetId = DB::table('scenes')->where('project_id', $projectId)->whereNotNull('visual_asset_id')->orderBy('scene_order')->value('visual_asset_id');
        $asset = $assetId ? Asset::find($assetId) : null;

        return ['title' => $title, 'path' => '/projects/'.$projectId.'/editor', 'poster' => $asset ? app(\App\Http\Controllers\Api\V1\Asset\AssetController::class)->posterUrl($asset) : null];
    }

    private function weaveVideo(string $conversationId, string $title, string $revisionId): array
    {
        $hasPoster = app(\App\Services\Create\CreateStorage::class)->exists(\App\Jobs\MakeCreatePoster::path($revisionId));

        return ['title' => $title ?: 'Untitled video', 'path' => '/create/'.$conversationId,
            'poster' => $hasPoster ? URL::temporarySignedRoute('media.create.poster', now()->addHours(2), ['revisionId' => $revisionId]) : null];
    }
}
