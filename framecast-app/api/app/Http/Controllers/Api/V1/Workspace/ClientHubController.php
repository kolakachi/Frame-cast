<?php

namespace App\Http\Controllers\Api\V1\Workspace;

use App\Http\Controllers\Api\V1\Asset\AssetController;
use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Http\Controllers\Controller;
use App\Models\Approval;
use App\Models\Asset;
use App\Models\AuthSession;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use App\Services\Agency\ClientContext;
use App\Services\Agency\WorkspaceAccess;
use App\Services\Auth\JwtService;
use App\Services\CreditService;
use App\Services\CruiseControl\ProjectBriefService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ClientHubController extends Controller
{
    public function __construct(private readonly WorkspaceAccess $access) {}

    private function workspace(Request $request, ?int $id = null, bool $manage = false): Workspace
    {
        if ($id) {
            $agency = $this->access->agency($request->user());
            abort_unless($agency, 403);

            return Workspace::where('parent_workspace_id', $agency->id)->findOrFail($id);
        }
        $w = $request->user()->workspace;
        abort_unless($w?->parent_workspace_id, 404);
        if ($manage) {
            abort_unless(in_array($request->user()->role, ['owner', 'client_admin']), 403);
        }

        return $w;
    }

    private function activity(Workspace $w, User $u, string $description): void
    {
        DB::table('client_activity')->insert(['workspace_id' => $w->id, 'user_id' => $u->id, 'description' => $description, 'created_at' => now()]);
    }

    private function assetIds(Workspace $w, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        abort_unless(Asset::where('workspace_id', $w->id)->whereIn('id', $ids)->count() === count($ids), 422, 'One or more attachments do not belong to this client.');

        return $ids;
    }

    public function offboard(Request $r, int $id)
    {
        $w = $this->workspace($r, $id, true);
        $v = $r->validate(['reclaim_credits' => ['required', 'boolean']]);
        DB::transaction(function () use ($w, $v) {
            $ids = [$w->parent_workspace_id, $w->id];
            sort($ids);
            $locked = Workspace::whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $client = $locked[$w->id];
            if ($v['reclaim_credits'] && $client->credits_topup > 0) {
                [$ok] = app(CreditService::class)->transferToClient($locked[$w->parent_workspace_id], $client, -$client->credits_topup);
                abort_unless($ok, 422, 'Could not return allocated credits.');
            }
            $client->forceFill(['status' => 'archived'])->save();
            DB::table('workspace_memberships')->where('workspace_id', $w->id)->update(['revoked_at' => now()]);
            DB::table('client_deliveries')->where('workspace_id', $w->id)->update(['revoked_at' => now()]);
            Approval::where('workspace_id', $w->id)->where('status', 'pending')->update(['status' => 'cancelled']);
        });
        $this->activity($w, $r->user(), 'Offboarded client; revoked memberships and shared delivery links.');

        return response()->json(['data' => ['offboarded' => true]]);
    }

    public function overview(Request $r)
    {
        $agency = $this->access->agency($r->user());
        abort_unless($agency, 403);
        $ids = Workspace::where('parent_workspace_id', $agency->id)->pluck('id');
        $requests = DB::table('client_requests')->whereIn('workspace_id', $ids)->whereNotIn('status', ['delivered', 'cancelled'])->selectRaw('workspace_id, COUNT(*) as open_requests')->groupBy('workspace_id')->get()->keyBy('workspace_id');
        $reviews = Approval::whereIn('workspace_id', $ids)->where('status', 'pending')->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->selectRaw('workspace_id, COUNT(*) as pending_reviews')->groupBy('workspace_id')->get()->keyBy('workspace_id');

        return response()->json(['data' => $ids->map(fn ($id) => ['id' => $id, 'open_requests' => (int) ($requests[$id]->open_requests ?? 0), 'pending_reviews' => (int) ($reviews[$id]->pending_reviews ?? 0)])]);
    }

    public function attachment(Request $r, ?int $id = null)
    {
        $w = $this->workspace($r, $id);
        abort_unless($w->status === 'active', 422);
        $user = $r->user();
        $user->setRawAttributes(array_merge($user->getAttributes(), ['workspace_id' => $w->id]), true);
        $user->setRelation('workspace', $w);

        // Existing upload validation, storage, thumbnails and transcription remain authoritative.
        return app(AssetController::class)->store($r);
    }

    public function workspaces(Request $r)
    {
        $identity = User::findOrFail($r->user()->id);
        $ids = DB::table('workspace_memberships')->where('user_id', $identity->id)->whereNull('revoked_at')->pluck('workspace_id')->all();
        if ($identity->workspace_id) {
            $ids[] = $identity->workspace_id;
        }
        $agency = $this->access->agency($identity);
        if ($agency) {
            $ids = array_merge($ids, Workspace::where('parent_workspace_id', $agency->id)->pluck('id')->all());
        }
        $items = Workspace::whereIn('id', $ids)->where('status', 'active')->get()->filter(fn ($w) => $this->access->role($identity, $w))->map(fn ($w) => ['id' => $w->id, 'name' => $w->client_label ?: $w->name, 'role' => $this->access->role($identity, $w), 'is_client' => (bool) $w->parent_workspace_id])->values();

        return response()->json(['data' => $items]);
    }

    public function switch(Request $r, int $id, JwtService $jwt)
    {
        $user = $r->user();
        $w = Workspace::findOrFail($id);
        abort_unless($this->access->activate($user, $w), 403, 'This workspace is unavailable.');
        $session = AuthSession::where('user_id', $user->id)->whereNull('revoked_at')->findOrFail($r->attributes->get('auth_session_id'));
        $session->forceFill(['active_workspace_id' => $w->id])->save();

        return response()->json(['data' => ['access_token' => $jwt->issue($user, $w, $session), 'workspace_id' => $w->id, 'role' => $user->role]]);
    }

    public function show(Request $r, ?int $id = null)
    {
        $w = $this->workspace($r, $id);
        $profile = DB::table('client_profiles')->where('workspace_id', $w->id)->first();
        $requests = DB::table('client_requests')->where('workspace_id', $w->id)->orderByDesc('id')->limit(100)->get()->map(function ($x) {
            $x->asset_ids = json_decode($x->asset_ids ?? '[]', true);

            return $x;
        });
        $spend = DB::table('credit_ledger')->where(function ($q) use ($w) {
            $q->where('spent_by_workspace_id', $w->id)->orWhere(fn ($q) => $q->where('workspace_id', $w->id)->whereNull('spent_by_workspace_id'));
        })->where('created_at', '>=', now()->startOfMonth());
        CreditService::onlySpend($spend);
        $projectSpend = (clone $spend)->selectRaw('project_id, SUM(credits) as credits')->groupBy('project_id')->get();
        $spent = (int) (clone $spend)->sum('credits');
        $approvals = Approval::where('workspace_id', $w->id)->latest()->limit(100)->get();
        $exports = ExportJob::where('workspace_id', $w->id)->where('status', 'completed')->whereNotNull('output_asset_id')->orderByDesc('id')->limit(100)->get(['id', 'project_id', 'file_name', 'aspect_ratio', 'output_asset_id']);
        $members = DB::table('workspace_memberships')->join('users', 'users.id', '=', 'workspace_memberships.user_id')->where('workspace_memberships.workspace_id', $w->id)->whereNull('revoked_at')->get(['users.id', 'users.name', 'users.email', 'workspace_memberships.role']);
        $deliveries = DB::table('client_deliveries')->where('workspace_id', $w->id)->orderByDesc('id')->limit(50)->get()->map(fn ($d) => ['id' => $d->id, 'title' => $d->title, 'expires_at' => $d->expires_at, 'revoked_at' => $d->revoked_at, 'url' => rtrim(config('app.frontend_url'), '/').'/delivery/'.$d->token]);

        return response()->json(['data' => [
            'workspace' => ['id' => $w->id, 'name' => $w->client_label ?: $w->name, 'status' => $w->status],
            'can_manage' => (bool) $id || in_array($r->user()->role, ['owner', 'client_admin']),
            'can_produce' => (bool) $id || $r->user()->role !== 'client',
            'profile' => json_decode($profile->brief ?? '{}', true),
            'projects' => Project::where('workspace_id', $w->id)->latest()->limit(100)->get(['id', 'title', 'status', 'updated_at']),
            'requests' => $requests, 'members' => $members, 'approvals' => $approvals->map(fn ($a) => ['id' => $a->id, 'project_id' => $a->project_id, 'export_job_id' => $a->export_job_id, 'status' => $a->status, 'expires_at' => $a->expires_at, 'comment' => $a->comment, 'url' => rtrim(config('app.frontend_url'), '/').'/approve/'.$a->token]),
            'exports' => $exports, 'deliveries' => $deliveries,
            'assets' => Asset::where('workspace_id', $w->id)->orderByDesc('id')->limit(100)->get(['id', 'title as name', 'asset_type']),
            'spending' => ['month' => now()->format('Y-m'), 'credits' => $spent, 'cap' => $w->monthly_credit_cap, 'by_project' => $projectSpend, 'alert' => $w->monthly_credit_cap && $spent >= $w->monthly_credit_cap * .8],
            'activity' => DB::table('client_activity')->where('workspace_id', $w->id)->orderByDesc('id')->limit(30)->get(['description', 'created_at']),
        ]]);
    }

    public function profile(Request $r, ?int $id = null)
    {
        $w = $this->workspace($r, $id, true);
        $v = $r->validate([
            'audience' => ['nullable', 'string', 'max:3000'], 'goals' => ['nullable', 'string', 'max:3000'],
            'products' => ['nullable', 'string', 'max:5000'], 'approved_claims' => ['nullable', 'string', 'max:5000'],
            'restrictions' => ['nullable', 'string', 'max:5000'], 'preferences' => ['nullable', 'string', 'max:3000'],
            'pronunciations' => ['nullable', 'string', 'max:3000'], 'asset_ids' => ['array', 'max:100'], 'asset_ids.*' => ['integer'],
        ]);
        $v['asset_ids'] = $this->assetIds($w, $v['asset_ids'] ?? []);
        DB::table('client_profiles')->updateOrInsert(['workspace_id' => $w->id], ['brief' => json_encode($v), 'created_at' => now(), 'updated_at' => now()]);
        $this->activity($w, $r->user(), 'Updated the client brief and reference library.');

        return response()->json(['data' => $v]);
    }

    public function requestVideo(Request $r, ?int $id = null)
    {
        $w = $this->workspace($r, $id);
        abort_unless($w->status === 'active', 422, 'Restore this client before accepting new work.');
        $v = $r->validate(['title' => ['required', 'string', 'max:160'], 'brief' => ['required', 'string', 'max:10000'], 'due_at' => ['nullable', 'date_format:Y-m-d'], 'asset_ids' => ['array', 'max:20'], 'asset_ids.*' => ['integer']]);
        $v['asset_ids'] = json_encode($this->assetIds($w, $v['asset_ids'] ?? []));
        $requestId = DB::table('client_requests')->insertGetId($v + ['workspace_id' => $w->id, 'created_by_user_id' => $r->user()->id, 'status' => 'requested', 'created_at' => now(), 'updated_at' => now()]);
        $this->activity($w, $r->user(), 'Requested: '.$v['title']);

        return response()->json(['data' => ['id' => $requestId]], 201);
    }

    public function startRequest(Request $r, int $id, int $requestId)
    {
        $w = $this->workspace($r, $id, true);
        abort_unless($w->status === 'active', 422, 'Restore this client before starting work.');

        return DB::transaction(function () use ($w, $r, $requestId) {
            $brief = DB::table('client_requests')->where('workspace_id', $w->id)->where('id', $requestId)->lockForUpdate()->first();
            abort_unless($brief, 404);
            if ($brief->project_id) {
                return response()->json(['data' => ['project_id' => $brief->project_id]]);
            }
            abort_if(in_array($brief->status, ['cancelled', 'delivered']), 422, 'Reopen this request before creating a draft.');
            $user = clone $r->user();
            abort_unless($this->access->activate($user, $w), 403);
            $draft = Request::create('/api/v1/projects', 'POST', [
                'source_type' => 'blank', 'title' => $brief->title, 'source_content_raw' => $brief->brief,
                'aspect_ratio' => '9:16', 'visual_type' => 'ai_image', 'ai_broll_style' => 'cinematic',
            ]);
            $draft->setUserResolver(fn () => $user);
            $response = app(ProjectController::class)->store($draft);
            if ($response->getStatusCode() >= 400) {
                return $response;
            }
            $projectId = $response->getData(true)['data']['project']['id'];
            Project::findOrFail($projectId)->update(['assistant_brief_json' => app(ProjectBriefService::class)->seed($brief->brief."\n".app(ClientContext::class)->prompt($w->id), 'cinematic', null)]);
            DB::table('client_requests')->where('id', $requestId)->update(['project_id' => $projectId, 'status' => 'in_progress', 'updated_at' => now()]);
            $this->activity($w, $r->user(), 'Started a draft for: '.$brief->title);

            return response()->json(['data' => ['project_id' => $projectId]], 201);
        });
    }

    public function updateRequest(Request $r, int $id, int $requestId)
    {
        $w = $this->workspace($r, $id, true);
        $v = $r->validate(['status' => ['sometimes', Rule::in(['requested', 'in_progress', 'in_review', 'delivered', 'cancelled'])], 'assigned_to_user_id' => ['nullable', 'integer'], 'project_id' => ['nullable', 'integer'], 'due_at' => ['nullable', 'date_format:Y-m-d']]);
        abort_unless(DB::table('client_requests')->where('workspace_id', $w->id)->where('id', $requestId)->exists(), 404);
        if (! empty($v['assigned_to_user_id'])) {
            $assignee = User::find($v['assigned_to_user_id']);
            abort_unless($assignee && in_array($this->access->role($assignee, $w), ['owner', 'client_editor', 'client_admin']), 422, 'Assign someone with editing access to this client.');
        }
        if (! empty($v['project_id'])) {
            abort_unless(Project::where('workspace_id', $w->id)->whereKey($v['project_id'])->exists(), 422, 'Choose a project belonging to this client.');
        }
        DB::table('client_requests')->where('workspace_id', $w->id)->where('id', $requestId)->update($v + ['updated_at' => now()]);
        $this->activity($w, $r->user(), 'Updated creative request #'.$requestId.'.');

        return response()->json(['data' => ['saved' => true]]);
    }

    public function createDelivery(Request $r, int $id)
    {
        $w = $this->workspace($r, $id, true);
        $v = $r->validate(['title' => ['required', 'string', 'max:160'], 'message' => ['nullable', 'string', 'max:4000'], 'export_ids' => ['required', 'array', 'min:1', 'max:20'], 'export_ids.*' => ['integer', 'distinct'], 'asset_ids' => ['array', 'max:20'], 'asset_ids.*' => ['integer'], 'expires_in_days' => ['required', 'integer', 'min:1', 'max:90']]);
        $exports = ExportJob::where('workspace_id', $w->id)->whereIn('id', $v['export_ids'])->where('status', 'completed')->whereNotNull('output_asset_id')->get();
        abort_unless($exports->count() === count($v['export_ids']), 422, 'Select completed exports from this client.');
        foreach ($exports as $e) {
            abort_unless(Approval::where('workspace_id', $w->id)->where('export_job_id', $e->id)->where('status', 'approved')->exists(), 422, 'Every selected export must have its own approval.');
        }
        $assets = $this->assetIds($w, $v['asset_ids'] ?? []);
        $token = Str::random(64);
        DB::table('client_deliveries')->insert(['workspace_id' => $w->id, 'title' => $v['title'], 'message' => $v['message'] ?? null, 'token' => $token, 'export_ids' => json_encode($v['export_ids']), 'asset_ids' => json_encode($assets), 'expires_at' => now()->addDays($v['expires_in_days']), 'created_at' => now(), 'updated_at' => now()]);
        $this->activity($w, $r->user(), 'Created delivery: '.$v['title']);

        return response()->json(['data' => ['url' => rtrim(config('app.frontend_url'), '/').'/delivery/'.$token]], 201);
    }

    public function revokeDelivery(Request $r, int $id, int $deliveryId)
    {
        $w = $this->workspace($r, $id, true);
        abort_unless(DB::table('client_deliveries')->where('workspace_id', $w->id)->where('id', $deliveryId)->update(['revoked_at' => now()]), 404);
        $this->activity($w, $r->user(), 'Revoked a delivery link.');

        return response()->json(['data' => ['revoked' => true]]);
    }

    public function delivery(string $token)
    {
        $d = DB::table('client_deliveries')->where('token', $token)->whereNull('revoked_at')->where('expires_at', '>', now())->first();
        abort_unless($d, 404, 'This delivery link has expired or been revoked.');
        $w = Workspace::find($d->workspace_id);
        abort_unless($w?->status === 'active' && $w->parent?->status === 'active', 404);
        $exports = ExportJob::where('workspace_id', $d->workspace_id)->whereIn('id', json_decode($d->export_ids, true))->where('status', 'completed')->get();
        $files = $exports->filter(fn ($e) => Approval::where('workspace_id', $d->workspace_id)->where('export_job_id', $e->id)->where('status', 'approved')->exists())->map(fn ($e) => ['id' => $e->output_asset_id, 'name' => $e->file_name, 'format' => $e->aspect_ratio]);
        $extras = Asset::where('workspace_id', $d->workspace_id)->whereIn('id', json_decode($d->asset_ids ?? '[]', true))->get()->map(fn ($a) => ['id' => $a->id, 'name' => $a->title, 'format' => $a->asset_type]);
        $files = $files->concat($extras)->map(fn ($f) => $f + ['url' => URL::temporarySignedRoute('media.assets.content', now()->addMinutes(10), ['assetId' => $f['id']])])->values();

        return response()->json(['data' => ['title' => $d->title, 'message' => $d->message, 'expires_at' => $d->expires_at, 'files' => $files]]);
    }

    public function discussion(Request $r, string $token)
    {
        $a = Approval::where('token', $token)->firstOrFail();
        $w = Workspace::find($a->workspace_id);
        abort_unless($w?->status === 'active' && (! $w->parent_workspace_id || $w->parent?->status === 'active'), 404);
        abort_if($a->isExpired() || $a->status === 'cancelled', 410, 'This review link has expired or been revoked.');
        abort_unless($a->export_job_id, 422, 'Request review of a specific exported version first.');
        if ($r->isMethod('POST')) {
            abort_unless($a->status === 'pending',422,'This version has already been reviewed.');
            $v = $r->validate(['author' => ['required', 'string', 'max:160'], 'body' => ['required', 'string', 'max:2000'], 'at_seconds' => ['nullable', 'numeric', 'min:0', 'max:36000']]);
            DB::table('approval_comments')->insert($v + ['approval_id' => $a->id, 'export_job_id' => $a->export_job_id, 'created_at' => now()]);
        }

        return response()->json(['data' => DB::table('approval_comments')->where('approval_id',$a->id)->orderBy('id')->get(['id', 'author', 'body', 'at_seconds', 'export_job_id', 'created_at'])]);
    }
}
