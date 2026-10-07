<?php

namespace App\Http\Controllers\Api\V1\Create;

use App\Http\Controllers\Controller;
use App\Services\Create\{ConversationService, RunService, AttachmentUploadService};
use App\Services\Media\StorageService;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{DB, Storage};

class CreateController extends Controller
{
    public function __construct(private ConversationService $service, private RunService $runs) {}

    public function capabilities(Request $r)
    {
        $this->service->authorize($r->user());
        return response()->json(['data' => ['enabled' => true, 'mode' => config('create.mode'), 'paid_generation' => \App\Services\Create\PilotPolicy::enabled(), 'image_generation' => \App\Services\Create\PilotPolicy::enabled(), 'publishing' => true,
            'uploads' => ['max_files'=>20,'max_file_bytes'=>config('create.input_file_bytes'),'max_total_bytes'=>config('create.input_total_bytes'),'mime_types'=>array_keys(AttachmentUploadService::TYPES)]]]);
    }

    public function index(Request $r)
    {
        $this->service->authorize($r->user());
        $query = DB::table('create_conversations')->where('workspace_id', $r->user()->workspace_id)
            ->when($r->boolean('archived'), fn($q)=>$q->whereNotNull('archived_at'), fn($q)=>$q->whereNull('archived_at'));
        if ($r->filled('search')) {
            $term = '%'.mb_substr((string)$r->string('search'),0,100).'%';
            $query->where(function($q) use($term) {
                $q->whereRaw('LOWER(title) LIKE ?', [mb_strtolower($term)])
                    ->orWhereExists(fn($m)=>$m->selectRaw('1')->from('create_messages')->whereColumn('conversation_id','create_conversations.id')->whereRaw('LOWER(content) LIKE ?', [mb_strtolower($term)]))
                    ->orWhereExists(fn($a)=>$a->selectRaw('1')->from('create_attachments')->join('assets','assets.id','=','asset_id')
                        ->whereColumn('conversation_id','create_conversations.id')->whereColumn('assets.workspace_id','create_conversations.workspace_id')->whereRaw('LOWER(assets.title) LIKE ?', [mb_strtolower($term)]));
            });
        }
        // A page at a time, newest first: the list loads more as it is scrolled (offset + limit, one extra row to
        // know whether more remain).
        $offset = max(0, (int) $r->integer('offset')); $limit = min(100, max(1, (int) ($r->integer('limit') ?: 100)));
        $rows = $query->select('create_conversations.*')->addSelect([
            'latest_run_status'=>DB::table('composition_runs')->select('status')->whereColumn('conversation_id','create_conversations.id')->orderByDesc('created_at')->orderByDesc('id')->limit(1),
            'last_message'=>DB::table('create_messages')->select('content')->whereColumn('conversation_id','create_conversations.id')->orderByDesc('sequence')->limit(1),
        ])->orderByDesc('updated_at')->orderByDesc('id')->offset($offset)->limit($limit + 1)->get();
        return response()->json(['data' => $rows->take($limit)->values(), 'meta' => ['next_offset' => $rows->count() > $limit ? $offset + $limit : null]]);
    }

    public function store(Request $r)
    {
        $settings = $r->validate(\App\Services\Create\OutputSettings::rules());
        abort_if(!empty($settings['style_id']) && !DB::table('create_styles')->where('workspace_id',$r->user()->workspace_id)->where('id',$settings['style_id'])->exists(),422,'That style no longer exists.');
        return response()->json(['data'=>$this->service->create($r->user(),$settings)],201);
    }

    public function show(Request $r, string $id)
    {
        $c = $this->service->conversation($r->user(), $id);
        $revisions = DB::table('composition_revisions')->where('conversation_id', $id)->orderBy('number')->get([
            'share_enabled', 'metadata_json', 'id', 'number', 'output_asset_id', 'export_job_id', 'parent_revision_id', 'restored_from_id', 'run_id', 'summary', 'conflict', 'created_at', 'artifact_hash',
        ]);
        $revisions->each(function($revision)use($c){$revision->has_newer_changes=\App\Services\Create\DeliveryService::stale($c,$revision);});
        // The player streams each version from a signed link; one link per 15-minute window, valid 30 to 45 minutes.
        $expires = \Illuminate\Support\Carbon::createFromTimestamp((intdiv(now()->timestamp, 900) + 3) * 900);
        $paths = DB::table('composition_revisions')->where('conversation_id', $id)->pluck('artifact_path', 'id');
        $revisions->each(function ($revision) use ($paths, $expires) {
            $path = $paths[$revision->id] ?? null;
            $revision->preview_url = $path && app(\App\Services\Create\CreateStorage::class)->exists($path) ? \Illuminate\Support\Facades\URL::temporarySignedRoute('media.create.version', $expires, ['revisionId' => $revision->id]) : null;
        });
        $bundles = DB::table('composition_revisions')->where('conversation_id', $id)->pluck('bundle_json', 'id');
        $revisions->each(function($revision)use($bundles){$revision->variables=\App\Services\Create\CompositionVariables::declarations(json_decode($bundles[$revision->id] ?? '{}', true)['index.html'] ?? null);});
        // Storage keys, worker credentials and source HTML never enter the browser response.
        return response()->json(['data' => [
            'conversation' => $c,
            'credit_availability' => $this->service->creditAvailability($r->user()),
            // What Details may change now: the format is fixed once the current plan has bought pictures or clips.
            'settings_locks' => ['format' => \App\Services\Create\PlanService::formatLocked($id)],
            // A question asked before planning is marked, so the conversation can offer to skip it.
            'messages' => DB::table('create_messages')->where('conversation_id', $id)->orderBy('sequence')->get(['id', 'role', 'content', 'created_at', 'idempotency_key'])
                ->map(fn ($m) => ['id' => $m->id, 'role' => $m->role, 'content' => $m->content, 'created_at' => $m->created_at,
                    'kind' => $m->role === 'assistant' && preg_match('/^(clarify|clarify-change|reference-match|role|study|materials|file):/', (string) $m->idempotency_key) ? 'question' : null,
                    // A question about one of the user's files offers the answers its reading suggested.
                    ...(preg_match('/^file:(\d+):/', (string) $m->idempotency_key, $f) ? ['options' => (array) (data_get(json_decode((string) DB::table('create_attachments')->where('conversation_id', $id)->where('asset_id', (int) $f[1])->value('notes_json'), true), 'options') ?? [])] : [])]),
            'attachments' => DB::table('create_attachments')->where('conversation_id',$id)->get()->map(function($attachment) use($r) {
                $asset = Asset::where('workspace_id',$r->user()->workspace_id)->find($attachment->asset_id);
                if (!$asset) return null;
                $storage = app(StorageService::class);
                return ['asset_id'=>$asset->id,'purpose'=>$attachment->purpose,'title'=>$asset->title,'asset_type'=>$asset->asset_type,
                    'attached_at'=>$attachment->created_at,'duration_seconds'=>$asset->duration_seconds,'dimensions'=>$asset->dimensions_json,
                    'bytes'=>$asset->file_size_bytes,'source'=>data_get($asset->metadata_json,'reference_source'),'reference'=>data_get($asset->metadata_json,'reference_analysis.notes'),'suggested_claims'=>data_get($asset->metadata_json,'reference_analysis.suggested_claims',[]),'rig'=>data_get($asset->metadata_json,'rig'),'preview_url'=>$asset->status!=='archived' && $asset->storage_url && $storage->isManagedUrl($asset->storage_url) ? $storage->url($asset->storage_url) : null];
            })->filter()->values(),
            'revisions' => $revisions,
            // held_credits: what a run still holds of its approval (released as it settles), shown beside an active build.
            'runs' => DB::table('composition_runs')->leftJoin('api_operations', 'api_operations.id', '=', 'composition_runs.operation_id')
                ->where('composition_runs.conversation_id', $id)->orderBy('composition_runs.created_at')
                ->get(['composition_runs.id', 'composition_runs.status', 'composition_runs.stage', 'composition_runs.error', 'composition_runs.created_at', 'api_operations.reserved_credits as held_credits', 'api_operations.spent_credits', 'composition_runs.input_json->build_stage as build_stage', 'composition_runs.input_json->retry_of as retry_of']),
            'plans' => \Illuminate\Support\Facades\Schema::hasTable('create_plans') ? DB::table('create_plans')->where('conversation_id', $id)->orderBy('created_at')->get()->map(fn ($p) => app(\App\Services\Create\PlanService::class)->present($p, $c))->values() : [],
        ]]);
    }

    public function update(Request $r, string $id)
    {
        $this->service->authorize($r->user(), true);
        $input = $r->validate(['title' => 'sometimes|required|string|max:160', 'archived' => 'sometimes|boolean', 'settings'=>'sometimes|array', 'expected_version' => 'required|integer|min:0']);
        DB::transaction(function () use ($r, $id, $input) {
            $c = $this->service->conversation($r->user(), $id, true);
            abort_unless((int) $c->version === $input['expected_version'], 409);
            $changes = ['version' => $c->version + 1, 'updated_at' => now()];
            if(isset($input['settings'])) {
                abort_if($c->archived_at || DB::table('composition_runs')->where('conversation_id',$id)->whereIn('status',ConversationService::ACTIVE)->exists(),409,'Wait for the current creation before changing settings.');
                $settings=\App\Services\Create\OutputSettings::normalize(array_merge(json_decode($c->settings_json,true),$input['settings'],isset($input['settings']['duration_seconds'])?['duration_chosen'=>true]:[]));
                abort_if(($settings['aspect_ratio'] ?? null) !== (json_decode($c->settings_json,true)['aspect_ratio'] ?? null) && \App\Services\Create\PlanService::formatLocked($id),409,'The format is set by the pictures already made for this plan. Start a new version to change it.');
                abort_if(!empty($settings['style_id']) && !DB::table('create_styles')->where('workspace_id',$r->user()->workspace_id)->where('id',$settings['style_id'])->exists(),422,'That style no longer exists.');
                abort_unless($settings['output_kind']===(json_decode($c->settings_json,true)['output_kind']??'video'),422,'Start a new conversation for a different output type.');
                $changes['settings_json']=json_encode($settings);
            }
            if (isset($input['title'])) $changes['title'] = $input['title'];
            if (($input['archived']??false) && DB::table('composition_runs')->where('conversation_id',$id)->whereIn('status',ConversationService::ACTIVE)->exists()) abort(409,'Stop or reconcile the current run before archiving.');
            if (isset($input['archived'])) $changes['archived_at'] = $input['archived'] ? now() : null;
            DB::table('create_conversations')->where('id', $id)->update($changes);
        });
        return $this->show($r, $id);
    }

    public function message(Request $r, string $id)
    {
        $input = $r->validate(['content' => 'required|string|max:10000', 'idempotency_key' => 'required|string|max:128', 'expected_version' => 'required|integer|min:0']);
        return response()->json(['data' => $this->service->message($r->user(), $id, $input)], 201);
    }

    public function upload(Request $r, string $id)
    {
        $this->service->authorize($r->user(),true);
        // Without a purpose the file's role is read from the brief when it is sent (AttachmentRoles).
        $input = $r->validate(['asset_file'=>'required|file|max:102400','purpose'=>'nullable|in:source,reference,auto',
            'idempotency_key'=>'required|string|max:128','expected_version'=>'required|integer|min:0']);
        app(AttachmentUploadService::class)->upload($r->user(),$id,$r->file('asset_file'),$input['purpose'] ?? 'auto',$input['idempotency_key'],$input['expected_version']);
        return $this->show($r,$id);
    }

    public function pronunciations(Request $r)
    {
        return response()->json(['data' => DB::table('create_pronunciations')->where('workspace_id', $r->user()->workspace_id)->orderBy('written')->get(['written', 'spoken'])]);
    }

    /** Replace the workspace's pronunciation list. */
    public function savePronunciations(Request $r)
    {
        $this->service->authorize($r->user(), true);
        $input = $r->validate(['items' => 'present|array|max:30', 'items.*.written' => 'required|string|max:60', 'items.*.spoken' => 'required|string|max:80']);
        $ws = $r->user()->workspace_id;
        DB::transaction(function () use ($input, $ws) {
            DB::table('create_pronunciations')->where('workspace_id', $ws)->delete();
            foreach (collect($input['items'])->unique(fn ($i) => mb_strtolower(trim($i['written']))) as $i) {
                DB::table('create_pronunciations')->insert(['workspace_id' => $ws, 'written' => trim($i['written']), 'spoken' => trim($i['spoken']), 'created_at' => now(), 'updated_at' => now()]);
            }
        });
        return $this->pronunciations($r);
    }

    public function styles(Request $r)
    {
        return response()->json(['data' => app(\App\Services\Create\StyleService::class)->list($r->user()), 'packs' => \App\Services\Create\StylePacks::catalogue(),
            'notes' => app(\App\Services\Create\StyleNotes::class)->all((int) $r->user()->workspace_id)]);
    }

    /** What the user thought of a finished video, kept for the style it was built in. */
    public function noteRevision(Request $r, string $id, string $revisionId)
    {
        $input = $r->validate(['note' => 'required|string|max:400']);
        return response()->json(['data' => app(\App\Services\Create\StyleNotes::class)->add($r->user(), $id, $revisionId, $input['note'])], 201);
    }

    public function saveStyle(Request $r)
    {
        $input = $r->validate(['name' => 'required|string|max:80', 'conversation_id' => 'required_with:revision_id|uuid', 'revision_id' => 'required_without:asset_id|uuid', 'asset_id' => 'required_without:revision_id|integer|min:1']);
        $styles = app(\App\Services\Create\StyleService::class);
        return response()->json(['data' => isset($input['revision_id']) ? $styles->fromRevision($r->user(), $input['conversation_id'], $input['revision_id'], $input['name'])
            : $styles->fromReference($r->user(), (int) $input['asset_id'], $input['name'])], 201);
    }

    public function updateStyle(Request $r, string $styleId)
    {
        $input = $r->validate(['name' => 'sometimes|required|string|max:80', 'style' => 'sometimes|array', 'style.palette' => 'sometimes|array|max:5', 'style.borrow' => 'sometimes|array|max:4', 'style.avoid_copying' => 'sometimes|array|max:6']);
        return response()->json(['data' => app(\App\Services\Create\StyleService::class)->update($r->user(), $styleId, $input)]);
    }

    public function deleteStyle(Request $r, string $styleId)
    {
        app(\App\Services\Create\StyleService::class)->delete($r->user(), $styleId);
        return response()->json(['data' => ['deleted' => true]]);
    }

    public function reference(Request $r, string $id)
    {
        $input = $r->validate(['url' => 'required|string|max:500', 'idempotency_key' => 'required|string|max:128', 'expected_version' => 'required|integer|min:0']);
        // Video posts are studied as style references; a direct link to a video or image file is the user's own media;
        // any other public page is read and captured.
        $host = strtolower((string) parse_url(trim($input['url']), PHP_URL_HOST));
        $service = in_array($host, config('create.reference_hosts'), true) ? \App\Services\Create\References\ReferenceLinkService::class
            : (\App\Services\Create\References\MediaLinkService::isMediaFile($input['url']) ? \App\Services\Create\References\MediaLinkService::class : \App\Services\Create\References\PageReferenceService::class);
        app($service)->add($r->user(), $id, $input['url'], $input['expected_version'], $input['idempotency_key']);
        return $this->show($r, $id);
    }

    public function attach(Request $r, string $id)
    {
        $input = $r->validate(['asset_id' => 'required|integer|min:1', 'purpose' => 'nullable|in:source,reference,auto', 'expected_version' => 'required|integer|min:0']);
        $this->service->attach($r->user(), $id, $input['asset_id'], $input['purpose'] ?? 'auto', $input['expected_version']);
        return $this->show($r, $id);
    }

    public function detach(Request $r, string $id, int $assetId)
    {
        $this->service->authorize($r->user(), true);
        $input = $r->validate(['expected_version' => 'required|integer|min:0']);
        DB::transaction(function () use ($r, $id, $assetId, $input) {
            $c = $this->service->conversation($r->user(), $id, true);
            abort_if($c->archived_at || (int) $c->version !== $input['expected_version'], 409);
            DB::table('create_attachments')->where('conversation_id', $id)->where('asset_id', $assetId)->delete();
            DB::table('create_conversations')->where('id', $id)->update(['version' => $c->version + 1, 'updated_at' => now()]);
        });
        return $this->show($r, $id);
    }

    public function freeEdit(Request $r, string $id, string $revisionId)
    {
        $input = $r->validate(['expected_version' => 'required|integer|min:0', 'idempotency_key' => 'required|string|max:128', 'values' => 'required|array|min:1|max:20']);
        $run = app(\App\Services\Create\FreeEditService::class)->apply($r->user(), $id, $revisionId, $input['values'], $input['expected_version'], $input['idempotency_key']);
        return response()->json(['data' => ['id' => $run->id, 'status' => $run->status]], 202);
    }

    /** "More ways" in the directions drawer: three new directions for this plan's brief. */
    public function moreDirections(Request $r, string $id, string $planId)
    {
        return response()->json(['data' => app(\App\Services\Create\DirectionService::class)->more($r->user(), $id, $planId)]);
    }

    /** The Change drawer (S9): the parts this version is made of, with prices. */
    public function changeParts(Request $r, string $id, string $revisionId)
    {
        return response()->json(['data' => app(\App\Services\Create\ChangeService::class)->parts($r->user(), $id, $revisionId)]);
    }

    /** "Suggest a change" for one moment, from its frame. */
    public function suggestChange(Request $r, string $id, string $revisionId)
    {
        $input = $r->validate(['time' => 'required|numeric|min:0|max:600', 'frame' => 'required|file|mimetypes:image/jpeg,image/png|max:3000']);
        return response()->json(['data' => app(\App\Services\Create\ChangeService::class)->suggest($r->user(), $id, $revisionId, (float) $input['time'], $r->file('frame'))]);
    }

    /** The drawer's edits as one change request, then planning (multipart: payload JSON plus frames[i] per moment). */
    public function change(Request $r, string $id, string $revisionId)
    {
        $r->validate(['payload' => 'required|string|max:20000', 'frames' => 'sometimes|array|max:6', 'frames.*' => 'file|mimetypes:image/jpeg,image/png|max:3000']);
        $payload = json_decode((string) $r->input('payload'), true);
        $input = validator(is_array($payload) ? $payload : [], [
            'expected_version' => 'required|integer|min:0',
            'moments' => 'sometimes|array|max:6', 'moments.*.time' => 'required|numeric|min:0|max:600', 'moments.*.text' => 'nullable|string|max:600',
            'parts' => 'sometimes|array|max:30', 'parts.*.id' => 'required|string|max:40', 'parts.*.action' => 'required|in:file,remake,describe', 'parts.*.asset_id' => 'nullable|integer', 'parts.*.text' => 'nullable|string|max:600',
            'words' => 'sometimes|array|max:40', 'words.*.index' => 'required|integer|min:0', 'words.*.text' => 'required|string|max:300',
            'music' => 'sometimes|in:keep,new,mine,none', 'music_asset_id' => 'nullable|integer', 'voice' => 'sometimes|in:keep,another', 'note' => 'nullable|string|max:1000',
        ])->validate();
        $frames = array_filter((array) $r->file('frames', []));
        return response()->json(['data' => app(\App\Services\Create\ChangeService::class)->change($r->user(), $id, $revisionId, $input, $frames)], 201);
    }

    private function asksTopUpBeforePlanning($user, string $id, string $key): void
    {
        // A repeat of a plan already made (or under way) returns it as before.
        if (DB::table('create_plans')->where('conversation_id', $id)->where('idempotency_key', $key)->exists()) return;
        app(\App\Services\Create\ConversationService::class)->conversation($user, $id);
        app(\App\Services\Create\PlanService::class)->assertCanPayPlanning($user);
    }

    public function plan(Request $r, string $id)
    {
        $input = $r->validate(['expected_version' => 'required|integer|min:0', 'idempotency_key' => 'required|string|max:128', 'skip_questions' => 'sometimes|boolean', 'async' => 'sometimes|boolean']);
        $user = $r->user(); $skip = $r->boolean('skip_questions');
        // Asked before anything is queued, so a short balance hears it now rather than from a failed background plan.
        $this->asksTopUpBeforePlanning($user, $id, $input['idempotency_key']);
        if (config('create.durable_planning')) {
            $job = app(\App\Services\Create\PlanningJobService::class)->submit($user, $id, $input['expected_version'], $input['idempotency_key'], $skip);
            return response()->json(['data' => $job], in_array($job['state'], ['queued', 'running'], true) ? 202 : 200);
        }
        if (! $r->boolean('async')) return response()->json(['data' => app(\App\Services\Create\PlanService::class)->propose($user, $id, $input['expected_version'], $input['idempotency_key'], $skip)], 201);
        // Planning takes minutes, longer than a proxy (Cloudflare: 100 s) keeps a request open: the request answers at once
        // and planning finishes after the response is sent; plan-activity reports when it is done (or why it failed).
        $this->service->authorize($user, true);
        $this->service->conversation($user, $id);
        $key = 'create:plan-job:'.$id;
        $job = \Illuminate\Support\Facades\Cache::get($key);
        if ($job && ($job['key'] ?? null) === $input['idempotency_key']) return response()->json(['data' => $job], $job['state'] === 'running' ? 202 : 200);
        app(\App\Services\Create\AdmissionControl::class)->assertOpen();
        $job = ['key' => $input['idempotency_key'], 'state' => 'running', 'started_at' => now()->toIso8601String()];
        \Illuminate\Support\Facades\Cache::put($key, $job, now()->addMinutes(30));
        // Runs once the response has been sent (PHP-FPM finishes the request first, then the app's terminating step).
        app()->terminating(function () use ($user, $id, $input, $skip, $key) {
            try {
                $out = app(\App\Services\Create\PlanService::class)->propose($user, $id, $input['expected_version'], $input['idempotency_key'], $skip);
                \Illuminate\Support\Facades\Cache::put($key, ['key' => $input['idempotency_key'], 'state' => 'done', 'needs_answer' => $out['needs_answer'] ?? null, 'plan_id' => $out['id'] ?? null], now()->addMinutes(30));
            } catch (\Throwable $e) {
                $status = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? $e->getStatusCode() : 500;
                if ($status >= 500) report($e);
                \Illuminate\Support\Facades\Cache::put($key, ['key' => $input['idempotency_key'], 'state' => 'failed', 'status' => $status,
                    'error' => $status >= 500 && ! $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface ? 'Planning did not finish. Nothing was charged; try again.' : $e->getMessage()], now()->addMinutes(30));
            }
        });
        return response()->json(['data' => $job], 202);
    }

    /** What planning is doing right now, step by step (PlanActivity), polled while a plan is being made. */
    public function planActivity(Request $r, string $id)
    {
        $this->service->authorize($r->user(), false);
        $this->service->conversation($r->user(), $id);
        $input = $r->validate(['key' => 'sometimes|string|max:128']);
        $job = config('create.durable_planning')
            ? app(\App\Services\Create\PlanningJobService::class)->latest($id, $input['key'] ?? null)
            : \Illuminate\Support\Facades\Cache::get('create:plan-job:'.$id);
        return response()->json(['data' => \App\Services\Create\PlanActivity::live($id), 'job' => $job]);
    }

    public function selectPlan(Request $r, string $id, string $planId)
    {
        $input = $r->validate(['expected_version' => 'required|integer|min:0', 'callouts' => 'sometimes|array|max:6', 'callouts.*' => 'nullable|string|max:120',
            'choices' => 'sometimes|array|max:3', 'choices.*' => 'string|max:32', 'kept' => 'sometimes|array|max:8', 'kept.*' => 'string|max:80',
            'narration' => 'sometimes|array|max:8', 'narration.*' => 'nullable|string|max:160', 'voice' => 'sometimes|string|max:40', 'colours' => 'sometimes|array|max:4', 'colours.*' => ['string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'style' => 'sometimes|array', 'style.route' => 'required_with:style|in:pack,saved,reference,free', 'style.pack' => 'nullable|string|max:40', 'omitted_performance' => 'sometimes|array|max:24', 'omitted_performance.*' => 'string|max:40', 'look_first' => 'sometimes|boolean', 'video_tier' => 'sometimes|in:standard,premium', 'agreement' => 'sometimes|array', 'agreement.*' => 'array|max:6', 'agreement.*.*' => 'nullable|string|max:120', 'panel_notes' => 'sometimes|array|max:16', 'panel_notes.*' => 'nullable|string|max:240', 'engine_overrides' => 'sometimes|array|max:16', 'engine_overrides.*' => 'string|max:20', 'character_approval' => 'sometimes|string|regex:/^[a-f0-9]{64}$/', 'storyboard_approval' => 'sometimes|string|regex:/^[a-f0-9]{64}$/', 'character_looks' => 'sometimes|array|max:4', 'character_looks.*' => 'nullable|string|max:240',
            'asks' => 'sometimes|array|max:5', 'asks.*.id' => 'required|string|max:20', 'asks.*.asset_id' => 'nullable|integer|min:1', 'asks.*.skip' => 'sometimes|boolean']);
        return response()->json(['data' => app(\App\Services\Create\PlanService::class)->select($r->user(), $id, $planId, $input['expected_version'], $input)]);
    }

    public function quote(Request $r, string $id)
    {
        $input = $r->validate(['expected_version'=>'required|integer|min:0','variant_count'=>'sometimes|integer|min:1|max:3','retry_run_id'=>'sometimes|uuid','build_stage'=>'sometimes|in:character,storyboard,full_video',
            'assume'=>'sometimes|array','assume.character_approval'=>'sometimes|string|regex:/^[a-f0-9]{64}$/','assume.storyboard_approval'=>'sometimes|string|regex:/^[a-f0-9]{64}$/']);
        $variants=app(\App\Services\Create\VariantService::class);
        $q=isset($input['retry_run_id']) ? $variants->retryQuote($r->user(),$id,$input['retry_run_id'],$input['expected_version']) : $variants->quote($r->user(),$id,$input['expected_version'],$input['variant_count']??1,$input['build_stage']??null,$input['assume']??null);
        return response()->json(['data' => ['id' => $q->id, 'credits_max' => $q->credits_max, 'estimate' => $q->payload_json['estimate'] ?? null,
            // "Never more than": the real hold; while testing without limits, what a real hold would be.
            'ceiling' => \App\Services\Create\PilotPolicy::unlimited() && isset($q->payload_json['shown_ceiling']) ? min((int) $q->credits_max, max((int) $q->payload_json['shown_ceiling'], (int) ($q->payload_json['estimate'] ?? 0))) : $q->credits_max, 'effort' => $q->payload_json['effort'] ?? null, 'expires_at' => $q->expires_at,
            'credit_availability' => $this->service->creditAvailability($r->user()),
            'build_stage'=>$q->payload_json['build_stage']??null,'variants'=>count($q->payload_json['variant_quotes']??[1]),'plan_media'=>array_map(fn($m)=>['kind'=>$m['kind'],'description'=>$m['description'],'credits'=>$m['credits']],$q->payload_json['plan_media']??[]),'media_estimate'=>$q->payload_json['media_estimate']??0,'media_ceiling'=>$q->payload_json['media_ceiling']??0,'auto_run'=>$this->service->autoRunEligible($r->user(),$this->service->conversation($r->user(),$id),$q),'paid'=>$q->payload_json['mode']==='agent','settings'=>$q->payload_json['settings'],
            'description' => $q->payload_json['mode']==='agent' ? 'Create from your brief with our AI providers. Your brief and approved media may be sent to them. Only used calls are charged; unused reserved credits are released. The displayed amount is a maximum, not a flat charge.' : 'Local integration test: render the fixed 15-second sample. This does not generate from your prompt or use your attachments. No paid model calls.']]);
    }

    public function approve(Request $r, string $id)
    {
        $input = $r->validate(['quote_id' => 'required|string|max:32', 'idempotency_key' => 'required|string|max:128', 'approved' => 'required|accepted', 'provider_approved'=>'sometimes|boolean', 'auto'=>'sometimes|boolean']);
        $run = $r->boolean('auto')
            ? $this->service->approve($r->user(), $id, $input['quote_id'], $input['idempotency_key'], $r->boolean('provider_approved'), true)
            : app(\App\Services\Create\VariantService::class)->approve($r->user(), $id, $input['quote_id'], $input['idempotency_key'], $r->boolean('provider_approved'));
        return response()->json(['data' => ['id' => $run->id, 'status' => $run->status]], 202);
    }

    /**
     * Approve the character or the storyboard and start the next step in one action. The price the user saw is the
     * limit: when the next step costs more, the approval is kept and the new price is returned instead of a run.
     */
    public function approveStep(Request $r, string $id, string $planId)
    {
        $input = $r->validate(['step' => 'required|in:character,storyboard', 'token' => 'required|string|regex:/^[a-f0-9]{64}$/', 'expected_version' => 'required|integer|min:0',
            'max_credits' => 'required|integer|min:0', 'idempotency_key' => 'required|string|max:128', 'variant_count' => 'sometimes|integer|min:1|max:3']);
        $plans = app(\App\Services\Create\PlanService::class);
        $plans->select($r->user(), $id, $planId, $input['expected_version'], [$input['step'] === 'character' ? 'character_approval' : 'storyboard_approval' => $input['token']]);
        $version = (int) $this->service->conversation($r->user(), $id)->version;
        $q = app(\App\Services\Create\VariantService::class)->quote($r->user(), $id, $version, $input['variant_count'] ?? 1);
        if ($q->credits_max > $input['max_credits']) return response()->json(['data' => ['needs_confirm' => true, 'credits_max' => $q->credits_max, 'build_stage' => $q->payload_json['build_stage'] ?? null]], 200);
        $run = app(\App\Services\Create\VariantService::class)->approve($r->user(), $id, $q->id, $input['idempotency_key'], true);
        return response()->json(['data' => ['id' => $run->id, 'status' => $run->status, 'build_stage' => $q->payload_json['build_stage'] ?? null]], 202);
    }

    /**
     * Retry a failed run in one action: the user already approved this work and its cost, so the same approval is
     * used again (the same hold, the same provider consent). It resumes from what the failed run finished and buys
     * nothing twice.
     */
    public function retry(Request $r, string $id, string $runId)
    {
        $input = $r->validate(['idempotency_key' => 'required|string|max:128']);
        $c = $this->service->conversation($r->user(), $id);
        $q = app(\App\Services\Create\VariantService::class)->retryQuote($r->user(), $id, $runId, (int) $c->version);
        $run = $this->service->approve($r->user(), $id, $q->id, $input['idempotency_key'], true);
        return response()->json(['data' => ['id' => $run->id, 'status' => $run->status]], 202);
    }

    /** The workspace's brand library: its logo, mascot, products and illustrations, kept for every creation. */
    public function brandLibrary(Request $r)
    {
        $this->service->authorize($r->user());
        return response()->json(['data' => \App\Services\Create\BrandLibrary::items((int) $r->user()->workspace_id)]);
    }

    public function saveBrandItem(Request $r)
    {
        $this->service->authorize($r->user(), true);
        $input = $r->validate(['asset_id' => 'required|integer|min:1', 'role' => 'required|in:'.implode(',', \App\Services\Create\BrandLibrary::ROLES)]);
        return response()->json(['data' => \App\Services\Create\BrandLibrary::save($r->user(), $input['asset_id'], $input['role'])], 201);
    }

    public function removeBrandItem(Request $r, int $assetId)
    {
        $this->service->authorize($r->user(), true);
        \App\Services\Create\BrandLibrary::remove($r->user(), $assetId);
        return response()->json(['data' => ['removed' => true]]);
    }

    public function cancel(Request $r, string $id, string $runId)
    {
        $this->service->authorize($r->user(), true);
        $this->service->conversation($r->user(), $id);
        $this->runs->cancel($r->user()->workspace_id, $id, $runId);
        return $this->show($r, $id);
    }

    public function restore(Request $r, string $id, string $revisionId)
    {
        $input = $r->validate(['expected_version' => 'required|integer|min:0']);
        $revision = $this->service->restore($r->user(), $id, $revisionId, $input['expected_version']);
        return response()->json(['data' => ['revision_id' => $revision]], 201);
    }

    public function saveOutput(Request $r, string $id, string $revisionId)
    {
        $input = $r->validate(['expected_version'=>'required|integer|min:0']);
        return response()->json(['data'=>app(\App\Services\Create\CompositionOutputService::class)
            ->register($r->user(),$id,$revisionId,$input['expected_version'])]);
    }

    /** The version's video by signed link (no session): the link itself is the permission, and it expires. */
    public function signedVideo(string $revisionId)
    {
        $revision = DB::table('composition_revisions')->where('id', $revisionId)->firstOrFail();
        $workspace = DB::table('create_conversations')->where('id', $revision->conversation_id)->value('workspace_id');
        abort_unless($workspace && \App\Models\Workspace::whereKey($workspace)->where('status', 'active')->exists(), 404);
        abort_unless($revision->artifact_path && app(\App\Services\Create\CreateStorage::class)->exists($revision->artifact_path), 404);
        $ext = pathinfo($revision->artifact_path, PATHINFO_EXTENSION);
        return response()->file(app(\App\Services\Create\CreateStorage::class)->path($revision->artifact_path), ['Content-Type' => match ($ext) { 'png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', default => 'video/mp4' }, 'Cache-Control' => 'private, max-age=600']);
    }

    public function artifact(Request $r, string $id, string $revisionId)
    {
        $this->service->conversation($r->user(), $id);
        $revision = DB::table('composition_revisions')->where('conversation_id', $id)->where('id', $revisionId)->firstOrFail();
        abort_unless($revision->artifact_path, 404, 'This version has no video.');
        abort_unless(app(\App\Services\Create\CreateStorage::class)->exists($revision->artifact_path), 410, 'The video file for this version is no longer stored on this server. Its summary, checks and cost are kept; rebuild from it to get a new file.');
        return response()->file(app(\App\Services\Create\CreateStorage::class)->path($revision->artifact_path), ['Content-Type' => match(pathinfo($revision->artifact_path,PATHINFO_EXTENSION)) {'png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp',default=>'video/mp4'}, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
