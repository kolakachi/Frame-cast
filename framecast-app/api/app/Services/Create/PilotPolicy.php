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
        return ['agent'=>['provider'=>'replicate','model'=>'anthropic/claude-4.5-sonnet','credits'=>75,
            'cost_limit_microusd'=>300000,'max_calls'=>8,'max_output_tokens'=>4096,'context_bytes'=>64000],
            'render'=>['provider'=>'offline','model'=>'hyperframes-0.8.82','credits'=>0,'cost_limit_microusd'=>0,'max_calls'=>1]];
    }

    public static function ceiling(array $policy): int
    { return array_sum(array_map(fn($p)=>$p['cost_limit_microusd']*$p['max_calls'],$policy)); }

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
