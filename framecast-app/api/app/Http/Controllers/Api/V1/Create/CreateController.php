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
        return response()->json(['data'=>$query->select('create_conversations.*')->addSelect([
            'latest_run_status'=>DB::table('composition_runs')->select('status')->whereColumn('conversation_id','create_conversations.id')->orderByDesc('created_at')->orderByDesc('id')->limit(1),
            'last_message'=>DB::table('create_messages')->select('content')->whereColumn('conversation_id','create_conversations.id')->orderByDesc('sequence')->limit(1),
        ])->orderByDesc('updated_at')->limit(100)->get()]);
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
        $bundles = DB::table('composition_revisions')->where('conversation_id', $id)->pluck('bundle_json', 'id');
        $revisions->each(function($revision)use($bundles){$revision->variables=\App\Services\Create\CompositionVariables::declarations(json_decode($bundles[$revision->id] ?? '{}', true)['index.html'] ?? null);});
        // Storage keys, worker credentials and source HTML never enter the browser response.
        return response()->json(['data' => [
            'conversation' => $c,
            'messages' => DB::table('create_messages')->where('conversation_id', $id)->orderBy('sequence')->get(['id', 'role', 'content', 'created_at']),
            'attachments' => DB::table('create_attachments')->where('conversation_id',$id)->get()->map(function($attachment) use($r) {
                $asset = Asset::where('workspace_id',$r->user()->workspace_id)->find($attachment->asset_id);
                if (!$asset) return null;
                $storage = app(StorageService::class);
                return ['asset_id'=>$asset->id,'purpose'=>$attachment->purpose,'title'=>$asset->title,'asset_type'=>$asset->asset_type,
                    'attached_at'=>$attachment->created_at,'duration_seconds'=>$asset->duration_seconds,'dimensions'=>$asset->dimensions_json,
                    'bytes'=>$asset->file_size_bytes,'source'=>data_get($asset->metadata_json,'reference_source'),'reference'=>data_get($asset->metadata_json,'reference_analysis.notes'),'suggested_claims'=>data_get($asset->metadata_json,'reference_analysis.suggested_claims',[]),'preview_url'=>$asset->status!=='archived' && $asset->storage_url && $storage->isManagedUrl($asset->storage_url) ? $storage->url($asset->storage_url) : null];
            })->filter()->values(),
            'revisions' => $revisions,
            'runs' => DB::table('composition_runs')->where('conversation_id', $id)->orderBy('created_at')->get(['id', 'status', 'stage', 'error', 'created_at']),
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
                $settings=\App\Services\Create\OutputSettings::normalize(array_merge(json_decode($c->settings_json,true),$input['settings']));
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
        $input = $r->validate(['asset_file'=>'required|file|max:102400','purpose'=>'required|in:source,reference',
            'idempotency_key'=>'required|string|max:128','expected_version'=>'required|integer|min:0',
            'reuse_confirmed'=>'exclude_unless:purpose,source|required|accepted']);
        app(AttachmentUploadService::class)->upload($r->user(),$id,$r->file('asset_file'),$input['purpose'],$input['idempotency_key'],$input['expected_version']);
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
        return response()->json(['data' => app(\App\Services\Create\StyleService::class)->list($r->user()), 'packs' => \App\Services\Create\StylePacks::catalogue()]);
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
        // Video posts are studied as style references; any other public page is read and captured.
        $host = strtolower((string) parse_url(trim($input['url']), PHP_URL_HOST));
        $service = in_array($host, config('create.reference_hosts'), true) ? \App\Services\Create\References\ReferenceLinkService::class : \App\Services\Create\References\PageReferenceService::class;
        app($service)->add($r->user(), $id, $input['url'], $input['expected_version'], $input['idempotency_key']);
        return $this->show($r, $id);
    }

    public function attach(Request $r, string $id)
    {
        $input = $r->validate(['asset_id' => 'required|integer|min:1', 'purpose' => 'required|in:source,reference', 'reuse_confirmed'=>'exclude_unless:purpose,source|required|accepted', 'expected_version' => 'required|integer|min:0']);
        $this->service->attach($r->user(), $id, $input['asset_id'], $input['purpose'], $input['expected_version']);
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

    public function plan(Request $r, string $id)
    {
        $input = $r->validate(['expected_version' => 'required|integer|min:0', 'idempotency_key' => 'required|string|max:128']);
        return response()->json(['data' => app(\App\Services\Create\PlanService::class)->propose($r->user(), $id, $input['expected_version'], $input['idempotency_key'])], 201);
    }

    public function selectPlan(Request $r, string $id, string $planId)
    {
        $input = $r->validate(['expected_version' => 'required|integer|min:0', 'callouts' => 'sometimes|array|max:6', 'callouts.*' => 'nullable|string|max:120',
            'choices' => 'sometimes|array|max:3', 'choices.*' => 'string|max:32', 'kept' => 'sometimes|array|max:8', 'kept.*' => 'string|max:80',
            'narration' => 'sometimes|array|max:8', 'narration.*' => 'nullable|string|max:160', 'voice' => 'sometimes|string|max:40',
            'style' => 'sometimes|array', 'style.route' => 'required_with:style|in:pack,saved,reference,free', 'style.pack' => 'nullable|string|max:40']);
        return response()->json(['data' => app(\App\Services\Create\PlanService::class)->select($r->user(), $id, $planId, $input['expected_version'], $input)]);
    }

    public function quote(Request $r, string $id)
    {
        $input = $r->validate(['expected_version'=>'required|integer|min:0','variant_count'=>'sometimes|integer|min:1|max:3','retry_run_id'=>'sometimes|uuid']);
        $variants=app(\App\Services\Create\VariantService::class);
        $q=isset($input['retry_run_id']) ? $variants->retryQuote($r->user(),$id,$input['retry_run_id'],$input['expected_version']) : $variants->quote($r->user(),$id,$input['expected_version'],$input['variant_count']??1);
        return response()->json(['data' => ['id' => $q->id, 'credits_max' => $q->credits_max, 'expires_at' => $q->expires_at,
            'variants'=>count($q->payload_json['variant_quotes']??[1]),'plan_media'=>array_map(fn($m)=>['kind'=>$m['kind'],'description'=>$m['description'],'credits'=>$m['credits']],$q->payload_json['plan_media']??[]),'auto_run'=>$this->service->autoRunEligible($r->user(),$this->service->conversation($r->user(),$id),$q),'paid'=>$q->payload_json['mode']==='agent','settings'=>$q->payload_json['settings'],
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

    public function artifact(Request $r, string $id, string $revisionId)
    {
        $this->service->conversation($r->user(), $id);
        $revision = DB::table('composition_revisions')->where('conversation_id', $id)->where('id', $revisionId)->firstOrFail();
        abort_unless($revision->artifact_path, 404, 'This version has no video.');
        abort_unless(Storage::disk('local')->exists($revision->artifact_path), 410, 'The video file for this version is no longer stored on this server. Its summary, checks and cost are kept; rebuild from it to get a new file.');
        return response()->file(Storage::disk('local')->path($revision->artifact_path), ['Content-Type' => match(pathinfo($revision->artifact_path,PATHINFO_EXTENSION)) {'png'=>'image/png','jpg'=>'image/jpeg','webp'=>'image/webp',default=>'video/mp4'}, 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
