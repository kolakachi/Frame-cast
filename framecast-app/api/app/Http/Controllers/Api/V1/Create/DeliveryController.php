<?php
namespace App\Http\Controllers\Api\V1\Create;
use App\Http\Controllers\Controller;
use App\Models\{Asset,Workspace};
use App\Services\Create\DeliveryService;
use App\Services\Media\StorageService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
class DeliveryController extends Controller
{
 public function store(Request $r,string $id,string $revisionId) {
  $input=$r->validate(['action'=>'required|in:share,unshare,schedule','expected_version'=>'required|integer|min:0','confirmed'=>'sometimes|boolean','allow_older'=>'sometimes|boolean','idempotency_key'=>'required_if:action,schedule|string|max:128',
   'social_account_id'=>'required_if:action,schedule|integer','caption'=>'nullable|string|max:5000','title'=>'nullable|string|max:512','description'=>'nullable|string|max:5000','category'=>'nullable|string|max:128','visibility'=>'nullable|in:public,unlisted,private','hashtags'=>'nullable|array','hashtags.*'=>'string|max:100','scheduled_at'=>'nullable|date|after:now','publish_now'=>'sometimes|boolean']);
  return response()->json(['data'=>app(DeliveryService::class)->deliver($r->user(),$id,$revisionId,$input,$r)]);
 }
 public function show(string $token,StorageService $storage) {
  abort_unless(config('create.enabled') && preg_match('/^[a-zA-Z0-9]{48}$/D',$token),404);
  $revision=DB::table('composition_revisions')->where('share_token',$token)->where('share_enabled',true)->firstOrFail();
  $c=DB::table('create_conversations')->where('id',$revision->conversation_id)->whereNull('archived_at')->firstOrFail();
  abort_unless(\App\Services\Create\ConversationService::workspaceAllowed((int) $c->workspace_id) && Workspace::whereKey($c->workspace_id)->where('status','active')->exists(),404);
  $asset=Asset::where('workspace_id',$c->workspace_id)->where('status','!=','archived')->findOrFail($revision->output_asset_id);
  return response()->json(['data'=>['title'=>$c->title,'version'=>$revision->number,'kind'=>$asset->asset_type,'url'=>$storage->url($asset->storage_url)]])->header('Cache-Control','no-store');
 }
}
