<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/** Explicit local spending envelope; never enables production or resets on restart. */
class PilotPolicy
{
    public static function enabled(): bool
    {
        return app()->environment(['local','testing']) && config('create.paid_execution_enabled')
            && config('create.pilot_budget_id') && config('create.pilot_budget_microusd',0)>0;
    }

    public static function execution(array $settings): array
    {
        abort_unless(self::enabled(),503,'Paid local testing is not enabled.');
        if(($settings['video_mode']??'composition')==='animate_image') {
            abort_unless(config('services.replicate.i2v_quick_model')==='wan-video/wan-2.5-i2v',503,'Animation model needs a verified local tariff.');
            return ['media'=>['provider'=>'replicate','model'=>'wan-video/wan-2.5-i2v','credits'=>\App\Services\CreditService::animationCost('quick','480p',$settings['duration_seconds']),'cost_limit_microusd'=>600000,'max_calls'=>1]];
        }
        if (($settings['output_kind']??'video')==='image') {
            return ['media'=>['provider'=>'replicate','model'=>'google/nano-banana','credits'=>app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor('nano-banana'),
                'cost_limit_microusd'=>100000,'max_calls'=>1]];
        }
        if(config('create.agent_provider')==='anthropic') {
            abort_unless((string)config('services.anthropic.key')!=='',503,'The Claude API key is not configured.');
            // Deeper effort thinks longer, and thinking is output: give it room and a matching per-call cap.
            $effort=(string)config('create.agent_effort','medium'); $deep=in_array($effort,['high','xhigh','max'],true);
            return ['agent'=>['provider'=>'anthropic','model'=>(string)config('create.agent_model'),'credits'=>75,'effort'=>$effort,
                // Opus 5.5 thinks adaptively and thinking counts as output; a full composition needs the room.
                // 16 calls (owner, 2026-10-01): UI-heavy parity builds with visual repairs need 14 to 16;
                // plain builds still finish in 7 to 8. Worst case 16 x $0.30.
                'cost_limit_microusd'=>$deep?450000:300000,'max_calls'=>16,'max_output_tokens'=>$deep?16384:8192,'context_bytes'=>96000],
                'render'=>['provider'=>'offline','model'=>'hyperframes-0.8.82','credits'=>0,'cost_limit_microusd'=>0,'max_calls'=>1]];
        }
        return ['agent'=>['provider'=>'replicate','model'=>'anthropic/claude-4.5-sonnet','credits'=>75,
            'cost_limit_microusd'=>300000,'max_calls'=>8,'max_output_tokens'=>4096,'context_bytes'=>64000],
            'render'=>['provider'=>'offline','model'=>'hyperframes-0.8.82','credits'=>0,'cost_limit_microusd'=>0,'max_calls'=>1]];
    }

    public static function ceiling(array $policy): int
    {
        // Plan items have exact catalogue prices, so their ceiling is their total at the peg, not the priciest item times the count.
        return array_sum(array_map(fn($p)=>isset($p['total_credits'])?$p['total_credits']*4000:$p['cost_limit_microusd']*$p['max_calls'],$policy));
    }

    /** Caller holds pool/workspace locks. Global lock also serializes distinct workspaces. */
    public static function admit(array $policy): void
    {
        abort_unless(self::enabled(),503);
        if(DB::connection()->getDriverName()==='pgsql') DB::select('select pg_advisory_xact_lock(783430)');
        $used=0;
        foreach(DB::table('composition_runs')->get(['id','input_json','status']) as $run) {
            $p=json_decode($run->input_json,true);
            if(($p['pilot_budget_id']??null)!==config('create.pilot_budget_id')) continue;
            $attempts=DB::table('composition_attempts')->where('run_id',$run->id)->get();
            $used+=$attempts->sum(fn($a)=>$a->cost_microusd??$a->cost_limit_microusd);
            if(in_array($run->status,ConversationService::ACTIVE,true)) {
                foreach($p['execution_policy'] as $kind=>$limit) $used+=max(0,$limit['max_calls']-$attempts->where('kind',$kind)->count())*$limit['cost_limit_microusd'];
            }
        }
        abort_if($used+self::ceiling($policy)>(int)config('create.pilot_budget_microusd'),402,'The approved local test budget cannot cover this run. Existing results are safe.');
    }
}
