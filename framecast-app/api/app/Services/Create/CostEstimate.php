<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/**
 * What a stage will probably cost, so the price on a button is the likely figure, not the ceiling.
 *
 * The video's build agent is the only part that varies: its estimate is the median of real finished runs at the same
 * effort and a similar length (at least three of them), of the same video type and kind of request (a new video or
 * a change) when there are enough of those, else of the same type, else of any; failing that, a curve fitted to the runs measured on 2026-10-06
 * (Standard: about 250 credits for 15 s, 800 for 30 s), scaled by effort. Its ceiling is a budget of five times the
 * estimate (2 of 18 real builds went past three times): the most a step may spend, of which only what is used is
 * charged. Runaway builds are stopped by the build's own guards (calls, repairs, time, no progress), not by a tight
 * budget. Images (the character and the
 * storyboard) and bought media are priced exactly, so they need no estimate.
 */
class CostEstimate
{
    public const EFFORTS = ['quick', 'standard', 'thorough'];
    /** Against Standard: Quick plans on Sonnet and builds with fewer, lighter calls; Thorough reviews more. */
    public const SCALE = ['quick' => 0.4, 'standard' => 1.0, 'thorough' => 1.9];
    public const CEILING_TIMES = 5;
    /** Room for a few of the dearest calls (each may cost up to $1.20) even on a short Quick build. */
    public const CEILING_FLOOR = 1000;

    public static function effort(array $settings): string
    {
        return in_array($settings['effort'] ?? null, self::EFFORTS, true) ? $settings['effort'] : 'standard';
    }

    /** The build agent's likely credits for a video of this length at this effort; $kind narrows it (see kindOf). */
    public static function agent(string $effort, int $seconds, array $kind = []): int
    {
        $learned = self::learned($effort, $seconds, $kind);
        if ($learned !== null) return $learned;
        $seconds = max(5, min(60, $seconds));
        return (int) round((120 + 0.75 * $seconds * $seconds) * (self::SCALE[$effort] ?? 1.0));
    }

    /** The most the build agent may spend: five times its estimate, never under the floor. */
    public static function agentCeiling(string $effort, int $seconds, array $kind = []): int
    {
        return max(self::CEILING_FLOOR, self::CEILING_TIMES * self::agent($effort, $seconds, $kind));
    }

    /** What kind of build a plan is: its video type and whether it makes a new video or changes one. */
    public static function kindOf(?array $plan): array
    {
        $row = ! empty($plan['plan_id']) ? DB::table('create_plans')->where('id', $plan['plan_id'])->first(['plan_json', 'conversation_id', 'created_at']) : null;
        $p = json_decode((string) ($row->plan_json ?? ''), true) ?: [];
        $task = $p['planner_task'] ?? null;
        // A plan amended before any video exists builds a whole new video: it is "creative" for the estimate, whatever
        // the planner called the amendment (GTM-1: a brand reply made a first build look like a small edit, 245 vs 734).
        if ($task === 'edit' && $row && ! DB::table('composition_revisions')->where('conversation_id', $row->conversation_id)->where('created_at', '<', $row->created_at)->exists()) $task = 'creative';
        return array_filter(['type' => $p['video_type'] ?? null, 'task' => $task]);
    }

    /** The likely spread of the build agent's credits: the middle half of real runs of this kind (or around the formula). */
    public static function agentRange(string $effort, int $seconds, array $kind = []): array
    {
        $spent = self::learnedSpread($effort, $seconds, $kind);
        if ($spent) return [$spent[intdiv(count($spent), 4)], $spent[min(count($spent) - 1, intdiv(3 * count($spent), 4))]];
        $mid = self::agent($effort, $seconds, $kind);
        return [(int) round($mid * 0.8), (int) round($mid * 1.8)];
    }

    /** Half the plan's real cost (owner, 2026-10-06: no cap). $credits is its real cost in credits. */
    public static function planningCharge(int $credits): int
    {
        return (int) ceil(max(0, $credits) / 2);
    }

    /** A model call's real cost in credits from its tokens, at the tariff the gateway charges (create.anthropic_rates). */
    public static function callCredits(array $call): int
    {
        $r = config('create.anthropic_rates');
        $micro = ($call['input_tokens'] ?? 0) * $r['input'] + ($call['output_tokens'] ?? 0) * $r['output']
            + ($call['cache_write_tokens'] ?? 0) * $r['cache_write'] + ($call['cache_read_tokens'] ?? 0) * $r['cache_read'];
        return (int) ceil($micro / 4000);
    }

    /**
     * The median build-agent spend of finished runs at this effort and a similar length, once there are three: of the
     * same type and kind of request first, then the same type, then any.
     */
    private static function learned(string $effort, int $seconds, array $kind = []): ?int
    {
        $spent = self::learnedSpread($effort, $seconds, $kind);
        return $spent ? (int) $spent[intdiv(count($spent), 2)] : null;
    }

    /** @return int[] the agent credits of up to 30 real runs of this kind, sorted ([] when fewer than 3) */
    private static function learnedSpread(string $effort, int $seconds, array $kind = []): array
    {
        $runs = DB::table('composition_runs')->where('status', 'preview_ready')->where('input_json->build_stage', 'full_video')
            ->where('created_at', '>=', now()->subDays(60))->orderByDesc('created_at')->limit(300)->get(['id', 'input_json'])
            ->map(fn ($r) => ['id' => $r->id, 'input' => json_decode((string) $r->input_json, true) ?: []])
            ->filter(fn ($r) => self::effort($r['input']['settings'] ?? []) === $effort && abs((int) ($r['input']['settings']['duration_seconds'] ?? 15) - $seconds) <= 5)
            ->take(60)->values();
        if ($runs->count() < 3) return [];
        $plans = DB::table('create_plans')->whereIn('id', $runs->pluck('input.plan.plan_id')->filter()->unique()->all())->pluck('plan_json', 'id')
            ->map(fn ($j) => json_decode((string) $j, true) ?: []);
        $runs = $runs->map(fn ($r) => $r + ['type' => $plans[$r['input']['plan']['plan_id'] ?? '']['video_type'] ?? null, 'task' => $plans[$r['input']['plan']['plan_id'] ?? '']['planner_task'] ?? null,
            'credits' => (int) DB::table('composition_attempts')->where('run_id', $r['id'])->whereIn('kind', ['agent', 'critic'])->sum('charged_credits')])->filter(fn ($r) => $r['credits'] > 0);
        foreach ([array_intersect_key($kind, ['type' => 1, 'task' => 1]), array_intersect_key($kind, ['type' => 1]), []] as $want) {
            if (count($want) !== count(array_filter($want))) continue;
            $spent = $runs->filter(fn ($r) => ! array_diff_assoc($want, array_intersect_key($r, $want)))->take(30)->pluck('credits')->sort()->values();
            if ($spent->count() >= 3) return $spent->all();
        }
        return [];
    }
}
