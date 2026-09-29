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
        return response()->json(['data' => ['enabled' => true, 'mode' => config('create.mode'), 'paid_generation' => false, 'image_generation' => false, 'publishing' => false,
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
        $r->validate(['aspect_ratio' => 'sometimes|in:9:16,16:9,1:1,4:5', 'duration_seconds' => 'sometimes|integer|min:5|max:30', 'output_kind'=>'sometimes|in:video,image']);
        return response()->json(['data' => $this->service->create($r->user(), [
            'output_kind'=>$r->input('output_kind','video'), 'aspect_ratio' => $r->input('aspect_ratio', '9:16'), 'duration_seconds' => $r->integer('duration_seconds', 15),
        ])], 201);
    }

    public function show(Request $r, string $id)
    {
        $c = $this->service->conversation($r->user(), $id);
        $revisions = DB::table('composition_revisions')->where('conversation_id', $id)->orderBy('number')->get([
            'id', 'number', 'output_asset_id', 'export_job_id', 'parent_revision_id', 'restored_from_id', 'run_id', 'summary', 'conflict', 'created_at', 'artifact_hash',
        ]);
        // Storage keys, worker credentials and source HTML never enter the browser response.
        return response()->json(['data' => [
            'conversation' => $c,
            'messages' => DB::table('create_messages')->where('conversation_id', $id)->orderBy('sequence')->get(['id', 'role', 'content', 'created_at']),
            'attachments' => DB::table('create_attachments')->where('conversation_id',$id)->get()->map(function($attachment) use($r) {
                $asset = Asset::where('workspace_id',$r->user()->workspace_id)->find($attachment->asset_id);
                if (!$asset) return null;
                $storage = app(StorageService::class);
                return ['asset_id'=>$asset->id,'purpose'=>$attachment->purpose,'title'=>$asset->title,'asset_type'=>$asset->asset_type,
                    'bytes'=>$asset->file_size_bytes,'preview_url'=>$asset->status!=='archived' && $asset->storage_url && $storage->isManagedUrl($asset->storage_url) ? $storage->url($asset->storage_url) : null];
            })->filter()->values(),
            'revisions' => $revisions,
            'runs' => DB::table('composition_runs')->where('conversation_id', $id)->orderBy('created_at')->get(['id', 'status', 'stage', 'error', 'created_at']),
        ]]);
    }

    public function update(Request $r, string $id)
    {
        $this->service->authorize($r->user(), true);
        $input = $r->validate(['title' => 'sometimes|required|string|max:160', 'archived' => 'sometimes|boolean', 'expected_version' => 'required|integer|min:0']);
        DB::transaction(function () use ($r, $id, $input) {
            $c = $this->service->conversation($r->user(), $id, true);
            abort_unless((int) $c->version === $input['expected_version'], 409);
            $changes = ['version' => $c->version + 1, 'updated_at' => now()];
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

    public function quote(Request $r, string $id)
    {
        $input = $r->validate(['expected_version' => 'required|integer|min:0']);
        $q = $this->service->quote($r->user(), $id, $input['expected_version']);
        return response()->json(['data' => ['id' => $q->id, 'credits_max' => $q->credits_max, 'expires_at' => $q->expires_at,
            'description' => 'Local integration test: render the fixed 15-second sample. This does not generate from your prompt or use your attachments. No paid model calls.']]);
    }

    public function approve(Request $r, string $id)
    {
        $input = $r->validate(['quote_id' => 'required|string|max:32', 'idempotency_key' => 'required|string|max:128', 'approved' => 'required|accepted']);
        $run = $this->service->approve($r->user(), $id, $input['quote_id'], $input['idempotency_key']);
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
        abort_unless($revision->artifact_path && Storage::disk('local')->exists($revision->artifact_path), 404);
        return response()->file(Storage::disk('local')->path($revision->artifact_path), ['Content-Type' => 'video/mp4', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
