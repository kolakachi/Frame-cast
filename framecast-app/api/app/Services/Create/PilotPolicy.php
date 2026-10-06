<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/** Explicit local spending envelope; never enables production or resets on restart. */
class PilotPolicy
{
    public static function enabled(): bool
    {
        // Paid builds where they are switched on. The budget id tags runs; a budget of 0 means no overall cap (owner,
        // 2026-10-06: each build's own budget and the user's balance still bound it).
        return (bool) config('create.paid_execution_enabled') && (bool) config('create.pilot_budget_id');
    }

    /**
     * Local testing without limits: no spend cap, and call/repair/time/size limits raised far past any
     * expected build. Never in production. Sandbox isolation, app-only providers, consent, source
     * protection and per-call cost recording are unchanged.
     */
    public static function unlimited(): bool
    {
        return app()->environment(['local','testing']) && (bool) config('create.unlimited');
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
            // Thinking is output. With style packs and craft rules pinned, even medium effort spends most of
            // 8k tokens planning a first draft and gets cut off, so every build gets 16k and the matching cap.
            // The user's effort (Quick, Standard, Thorough) sets how hard the build agent thinks, how many calls it
            // may make and how much review it gets; the ceiling is a credit budget sized from real runs (CostEstimate).
            $level=CostEstimate::effort($settings);
            $effort=['quick'=>'low','standard'=>(string)config('create.agent_effort','medium'),'thorough'=>'high'][$level];
            if(self::unlimited()) {
                // Per-call ceilings sized so a long, thinking-heavy call settles at its real cost (1 credit = $0.004).
                return ['agent'=>['provider'=>'anthropic','model'=>(string)config('create.agent_model'),'credits'=>1250,'effort'=>$effort,
                    'cost_limit_microusd'=>5000000,'max_calls'=>200,'max_output_tokens'=>32000,'context_bytes'=>600000,
                    'tool_mode'=>(bool) config('create.tool_mode', false),'unlimited'=>true],
                    'critic'=>['provider'=>'anthropic','model'=>(string)config('create.agent_model'),'credits'=>250,'effort'=>'low','cost_limit_microusd'=>1000000,'max_calls'=>10,'max_output_tokens'=>8192],
                    // A render per repair round (todo D): the first render and up to two re-renders.
                    'render'=>['provider'=>'offline','model'=>'hyperframes-0.8.82','credits'=>0,'cost_limit_microusd'=>0,'max_calls'=>3]];
            }
            // Real builds (2026-10-06) took 3 to 21 calls, the dearest $0.60, 95% under $0.26: calls are capped per effort
            // with room, a call at $1.20 (a reviewer call at $0.30, the dearest seen $0.14), and the whole build at its
            // budget, of which only what is used is charged.
            // The same room unlimited testing had (owner, 2026-10-06: real builds used up to 61 calls, 38 minutes, the
            // dearest call $0.60): calls, context and output are generous; the budget, the no-progress guard and the
            // user's balance are what stop a build.
            $calls=['quick'=>24,'standard'=>100,'thorough'=>150][$level];
            $budget=CostEstimate::agentCeiling($level,(int)($settings['duration_seconds']??15));
            return ['agent'=>['provider'=>'anthropic','model'=>(string)config('create.agent_model'),'credits'=>300,'effort'=>$effort,
                // Opus 5.5 thinks adaptively and thinking counts as output; a full composition needs the room.
                'cost_limit_microusd'=>1200000,'max_calls'=>$calls,'total_credits'=>$budget,'level'=>$level,'max_output_tokens'=>32000,'context_bytes'=>600000,
                // Tool mode: native tool calls, several per model call, through the same gateway and accounting.
                'tool_mode'=>(bool) config('create.tool_mode', false)],
                // The critic: a separate reviewer of short low-effort calls with the frames and the strip (none at Quick).
                ...($level==='quick'?[]:['critic'=>['provider'=>'anthropic','model'=>(string)config('create.agent_model'),'credits'=>75,'effort'=>'low','cost_limit_microusd'=>300000,'max_calls'=>$level==='thorough'?4:2,'max_output_tokens'=>4096]]),
                'render'=>['provider'=>'offline','model'=>'hyperframes-0.8.82','credits'=>0,'cost_limit_microusd'=>0,'max_calls'=>3]];
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
        if(self::unlimited()) return; // No spend cap while testing; every call is still recorded at its cost.
        if((int)config('create.pilot_budget_microusd',0)<=0) return; // No overall cap set.
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
