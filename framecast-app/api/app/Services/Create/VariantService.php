<?php
namespace App\Services\Create;

use App\Models\{ApiQuote,User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Each variation owns its hold, attempts and immutable result. A group approval is atomic. */
class VariantService
{
    public function quote(User $user,string $id,int $version,int $count): ApiQuote
    {
        abort_unless($count>=1 && $count<=3,422,'Choose at most three variations.');
        $service=app(ConversationService::class);
        if($count===1) return $service->quote($user,$id,$version);
        abort_unless(PilotPolicy::enabled() && config('create.mode')==='agent',422,'Variations require prompt generation.');
        $group=(string)Str::uuid();$quotes=[];
        for($i=1;$i<=$count;$i++) {
            $q=$service->quote($user,$id,$version);$p=$q->payload_json;
            $p['variant_group']=$group;$p['variant_index']=$i;
            $p['variant_direction']=['Quiet editorial composition','Bold graphic composition','Airy minimal composition'][$i-1];
            if($p['media_input']) $p['media_input']['prompt'].=' Visual variation: '.$p['variant_direction'].'. Preserve supplied subjects and facts.';
            $q->update(['payload_json'=>$p]);$quotes[]=$q;
        }
        return ApiQuote::create(['id'=>ApiQuote::newId(),'workspace_id'=>$user->workspace_id,'created_by_user_id'=>$user->id,
            'payload_json'=>['kind'=>'composition_variant_group','conversation_id'=>$id,'version'=>$version,'mode'=>'agent',
                'settings'=>$quotes[0]->payload_json['settings'],'variant_quotes'=>array_map(fn($q)=>$q->id,$quotes),'variant_group'=>$group],
            'credits_min'=>0,'credits_max'=>array_sum(array_map(fn($q)=>$q->credits_max,$quotes)),'expires_at'=>$quotes[0]->expires_at]);
    }

    public function approve(User $user,string $id,string $quoteId,string $key,bool $providerApproved): object
    {
        $service=app(ConversationService::class);$service->authorize($user,true);
        $quote=ApiQuote::where('workspace_id',$user->workspace_id)->findOrFail($quoteId);
        if(($quote->payload_json['kind']??'')!=='composition_variant_group') return $service->approve($user,$id,$quoteId,$key,$providerApproved);
        abort_unless(($quote->payload_json['conversation_id']??null)===$id,422);
        return DB::transaction(function()use($user,$id,$quote,$key,$providerApproved,$service){
            // Admission service takes the shared pool locks before each conversation lock.
            $runs=[];
            foreach($quote->payload_json['variant_quotes'] as $i=>$child) $runs[]=$service->approve($user,$id,$child,hash('sha256',$key.':'.$i),$providerApproved);
            return $runs[0];
        });
    }

    public function retryQuote(User $user,string $id,string $runId,int $version): ApiQuote
    {
        $service=app(ConversationService::class);$service->authorize($user,true);
        $c=$service->conversation($user,$id);
        abort_unless(!$c->archived_at && (int)$c->version===$version,409);
        $run=DB::table('composition_runs')->where('conversation_id',$id)->where('workspace_id',$user->workspace_id)->where('id',$runId)->firstOrFail();
        abort_unless($run->status==='failed' && !AttemptService::unresolved($runId),409,'Only a confirmed failed result can be retried. Recover uncertain work first.');
        abort_if(DB::table('composition_runs')->where('input_json->retry_of',$runId)->exists(),409,'This failed result already has a retry. Inspect that result instead.');
        $p=json_decode($run->input_json,true);app(InputSnapshotService::class)->verify($p['input_files']??[]);
        $p['source_revision_id']=$p['base_revision_id'];$p['base_revision_id']=$c->head_revision_id;$p['version']=$version;$p['retry_of']=$runId;
        abort_unless($p['mode']===config('create.mode'),409);
        return ApiQuote::create(['id'=>ApiQuote::newId(),'workspace_id'=>$user->workspace_id,'created_by_user_id'=>$user->id,'payload_json'=>$p,
            'credits_min'=>0,'credits_max'=>array_sum(array_map(fn($v)=>$v['credits']*$v['max_calls'],$p['execution_policy'])),'expires_at'=>now()->addMinutes(10)]);
    }
}
