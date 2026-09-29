<?php
namespace App\Services\Create;

use App\Models\{Asset, User};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Exact immutable revisions, separate consent, shared publishing entitlements. */
class DeliveryService
{
    public static function stale(object $c,object $revision): bool
    {
        if($c->head_revision_id!==$revision->id) return true;
        // Renaming is not a content edit. Messages, settings and attachments are.
        $meta=json_decode($revision->metadata_json??'{}',true);
        $current=json_decode($c->settings_json,true);
        if(isset($meta['requested_settings']) && $meta['requested_settings']!==$current) return true;
        if(DB::table('create_messages')->where('conversation_id',$c->id)->where('role','user')->where('sequence','>',($meta['source_version']??PHP_INT_MAX))->exists()) return true;
        $attachments=DB::table('create_attachments')->where('conversation_id',$c->id)->orderBy('asset_id')->get(['asset_id','purpose'])->map(fn($a)=>(array)$a)->all();
        return isset($meta['attachments']) && $meta['attachments']!==$attachments;
    }

    public function deliver(User $user,string $id,string $revisionId,array $input,?Request $request=null): array
    {
        $service=app(ConversationService::class);$service->authorize($user,true);
        return DB::transaction(function()use($service,$user,$id,$revisionId,$input,$request){
            $c=$service->conversation($user,$id,true);
            $revision=DB::table('composition_revisions')->where('conversation_id',$id)->where('id',$revisionId)->lockForUpdate()->firstOrFail();
            abort_if($c->archived_at,409,'Restore the conversation first.');
            abort_unless((int)$c->version===$input['expected_version'],409,'The conversation changed. Review this version again.');
            $action=$input['action'];
            if($action==='unshare') {DB::table('composition_revisions')->where('id',$revisionId)->update(['share_enabled'=>false]);return ['enabled'=>false];}
            abort_unless($input['confirmed']??false,422,'Confirm this delivery action.');
            abort_if(self::stale($c,$revision) && !($input['allow_older']??false),409,'Newer changes are not in this version. Update the creation or explicitly continue with the selected version.');
            $asset=Asset::where('workspace_id',$user->workspace_id)->where('status','!=','archived')->find($revision->output_asset_id);
            abort_unless($asset,422,'Save this result to your library first.');
            if($action==='share') {
                $token=$revision->share_token ?: Str::random(48);
                DB::table('composition_revisions')->where('id',$revisionId)->update(['share_token'=>$token,'share_enabled'=>true]);
                return ['enabled'=>true,'url'=>rtrim(config('app.frontend_url'),'/').'/creation/'.$token,'revision_id'=>$revisionId];
            }
            abort_unless($action==='schedule' && $revision->export_job_id && $request,422,'Scheduling requires a saved video.');
            // The normal publishing controller checks plan, export ownership and connected account.
            $hash=hash('sha256',json_encode($input));
            $old=DB::table('composition_deliveries')->where('revision_id',$revisionId)->where('idempotency_key',$input['idempotency_key'])->first();
            if($old) {abort_unless(hash_equals($old->request_hash,$hash),409,'Delivery key belongs to a different request.');return json_decode($old->response_json,true);}
            $request->merge(['export_job_id'=>$revision->export_job_id]);
            $response=app(\App\Http\Controllers\Api\V1\Publishing\ScheduledPostController::class)->store($request);
            abort_unless($response->isSuccessful(),$response->status(),$response->getData(true)['error']['message']??'Scheduling failed.');
            $result=$response->getData(true)['data'];
            DB::table('composition_deliveries')->insert(['revision_id'=>$revisionId,'idempotency_key'=>$input['idempotency_key'],'request_hash'=>$hash,'response_json'=>json_encode($result),'created_at'=>now(),'updated_at'=>now()]);
            return $result;
        });
    }
}
