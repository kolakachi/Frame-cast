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
    /**
     * Fair credits 2.1/2.2 (owner, 2026-10-20): the builder and planning are charged at what the model costs us, with
     * no markup: one credit per 11,500 µ$ (1.15¢, what we keep from a yearly-plan credit after fees), rounded once per
     * build and once per plan. Media keeps its catalogue price (4,000 µ$ a credit at the peg).
     */
    public const MICRO_PER_CREDIT = 11500;
    /** The peg the builder was charged at before 2026-10-20 (and media still is): old estimates are converted by it. */
    public const PEG_MICRO_PER_CREDIT = 4000;
    /**
     * Fix D (2026-10-16 proof: neo-brutalism spent 844 builder credits against a 340 quote, blueprint 499 against 216):
     * styles with many separate elements take more builder rounds. Until a style has three real runs of its own, its
     * estimate is scaled by this measured guess; with three, its own median replaces it.
     */
    public const STYLE_WEIGHT = ['neo-brutalism' => 2.0, 'comic-book' => 1.8, 'isometric' => 1.8, 'collage-zine' => 1.6, 'whiteboard' => 1.4];
    /** Room for a few of the dearest calls (each may cost up to $1.20) even on a short Quick build: $4, as before. */
    public const CEILING_FLOOR = 350;

    public static function effort(array $settings): string
    {
        return in_array($settings['effort'] ?? null, self::EFFORTS, true) ? $settings['effort'] : 'standard';
    }

    /** The build agent's likely credits for a video of this length at this effort; $kind narrows it (see kindOf). */
    public static function agent(string $effort, int $seconds, array $kind = []): int
    {
        $learned = self::learned($effort, $seconds, $kind);
        if ($learned !== null) return (int) round($learned * self::styleWeight($effort, $seconds, $kind));
        $seconds = max(5, min(60, $seconds));
        // The curve was measured in peg credits; converted to at-cost credits.
        return (int) round((120 + 0.75 * $seconds * $seconds) * (self::SCALE[$effort] ?? 1.0) * self::styleWeight($effort, $seconds, $kind) * self::PEG_MICRO_PER_CREDIT / self::MICRO_PER_CREDIT);
    }

    /** The style's weight when it has no three real runs of its own (their median already carries it). */
    private static function styleWeight(string $effort, int $seconds, array $kind): float
    {
        $w = self::STYLE_WEIGHT[$kind['style'] ?? ''] ?? 1.0;
        return $w !== 1.0 && count(self::learnedSpread($effort, $seconds, array_intersect_key($kind, ['style' => 1]), true)) >= 3 ? 1.0 : $w;
    }

    /** The most the build agent may spend: five times its estimate, never under the floor. */
    public static function agentCeiling(string $effort, int $seconds, array $kind = []): int
    {
        return max(self::CEILING_FLOOR, self::CEILING_TIMES * self::agent($effort, $seconds, $kind));
    }

    /**
     * What planning usually costs at cost (fair credits 2.4): the middle half of the last 60 plans' real cost, as
     * [low, high] credits; plans charged before 2026-10-20 are converted from their peg credits. Kept 10 minutes.
     */
    public static function planningRange(): array
    {
        return \Illuminate\Support\Facades\Cache::remember('create:planning-range', 600, function () {
            $credits = DB::table('create_plans')->orderByDesc('created_at')->limit(60)->pluck('plan_json')
                ->map(fn ($j) => (array) (json_decode((string) $j, true)['planning_charge'] ?? []))
                ->map(fn ($c) => ! empty($c['at_cost']) ? (int) ($c['cost_credits'] ?? 0) : (int) ceil((int) ($c['cost_credits'] ?? 0) * self::PEG_MICRO_PER_CREDIT / self::MICRO_PER_CREDIT))
                ->filter(fn ($c) => $c > 0)->sort()->values();
            if ($credits->count() < 5) return [10, 60];
            return [(int) $credits[intdiv($credits->count(), 4)], (int) $credits[intdiv(3 * $credits->count(), 4)]];
        });
    }

    /**
     * The build's spending limit: the ceiling, or the lower limit the user set in Details (build_ceiling_credits), never
     * under the estimate, so a set limit still leaves room for a typical build (fair credits 2.3, 2026-10-20).
     */
    public static function buildCeiling(string $effort, int $seconds, array $kind = [], array $settings = []): int
    {
        $ceiling = self::agentCeiling($effort, $seconds, $kind);
        $set = $settings['build_ceiling_credits'] ?? null;
        return is_numeric($set) ? max(self::agent($effort, $seconds, $kind), min($ceiling, (int) $set)) : $ceiling;
    }

    /** What kind of build a plan is: its video type and whether it makes a new video or changes one. */
    public static function kindOf(?array $plan): array
    {
        $row = ! empty($plan['plan_id']) ? DB::table('create_plans')->where('id', $plan['plan_id'])->first(['plan_json', 'conversation_id', 'created_at']) : null;
        $p = json_decode((string) ($row->plan_json ?? ''), true) ?: [];
        $task = $p['planner_task'] ?? null;
        $style = is_string($p['style_route'] ?? null) && StylePacks::exists($p['style_route']) ? $p['style_route'] : (is_string($plan['style_route'] ?? null) && StylePacks::exists($plan['style_route']) ? $plan['style_route'] : null);
        // A plan amended before any video exists builds a whole new video: it is "creative" for the estimate, whatever
        // the planner called the amendment (GTM-1: a brand reply made a first build look like a small edit, 245 vs 734).
        if ($task === 'edit' && $row && ! DB::table('composition_revisions')->where('conversation_id', $row->conversation_id)->where('created_at', '<', $row->created_at)->exists()) $task = 'creative';
        // A conversation started from a template (Templates, 2026-10-16) is priced from other template runs once there are
        // three, without the style's weight: following a finished video is not building that style from scratch.
        $template = $row && ! empty(json_decode((string) DB::table('create_conversations')->where('id', $row->conversation_id)->value('settings_json'), true)['template_id']);
        return array_filter(['type' => $p['video_type'] ?? null, 'task' => $task, 'style' => $template ? null : $style, 'template' => $template ? 1 : null]);
    }

    /** The likely spread of the build agent's credits: the middle half of real runs of this kind (or around the formula). */
    public static function agentRange(string $effort, int $seconds, array $kind = []): array
    {
        $spent = self::learnedSpread($effort, $seconds, $kind);
        $w = self::styleWeight($effort, $seconds, $kind);
        if ($spent) return [(int) round($spent[intdiv(count($spent), 4)] * $w), (int) round($spent[min(count($spent) - 1, intdiv(3 * count($spent), 4))] * $w)];
        $mid = self::agent($effort, $seconds, $kind);
        return [(int) round($mid * 0.8), (int) round($mid * 1.8)];
    }

    /** Planning at cost (fair credits 2.2, 2026-10-20; was half its cost at the peg): $micro is its real cost in µ$. */
    public static function planningCharge(int $micro): int
    {
        return (int) ceil(max(0, $micro) / self::MICRO_PER_CREDIT);
    }

    /**
     * What a builder or reviewer call is charged: the build's running total of real cost at cost, less what its earlier
     * calls were charged, so the whole build is rounded once (fair credits 2.1). Repair rounds are never counted.
     */
    public static function buildCharge(string $runId, string $attemptId, int $cost, int $limit): int
    {
        $earlier = DB::table('composition_attempts')->where('run_id', $runId)->where('id', '!=', $attemptId)->whereIn('kind', ['agent', 'critic'])
            ->whereNotNull('result_hash')->where(fn ($q) => $q->whereNull('attempt_key')->orWhere('attempt_key', 'not like', 'repair%'))
            ->selectRaw('coalesce(sum(cost_microusd), 0) as cost, coalesce(sum(charged_credits), 0) as charged')->first();
        $due = (int) ceil(((int) $earlier->cost + max(0, $cost)) / self::MICRO_PER_CREDIT) - (int) $earlier->charged;
        return max(0, min($limit, $due));
    }

    /**
     * A model call's real cost in credits from its tokens, at the rates of the model that ran it (create.model_rates),
     * else the gateway's tariff. Fair credits 1.1 (2026-10-20): Sonnet plans were priced at Opus rates, 883 credits
     * too much over 30 days.
     */
    public static function callCredits(array $call, ?string $model = null): int
    {
        return (int) ceil(self::callMicro($call, $model) / self::PEG_MICRO_PER_CREDIT);
    }

    /** A model call's real cost in µ$ from its tokens, at the rates of the model that ran it. */
    public static function callMicro(array $call, ?string $model = null): int
    {
        $model = $model !== null ? (string) preg_replace('#^anthropic[:/]#', '', $model) : '';
        $r = ($model !== '' ? config('create.model_rates.'.$model) : null) ?? config('create.anthropic_rates');
        $tokens = ($call['input_tokens'] ?? 0) + ($call['cache_write_tokens'] ?? 0) + ($call['cache_read_tokens'] ?? 0);
        if (isset($r['long_above'], $r['long']) && $tokens > (int) $r['long_above']) $r = $r['long'];
        $micro = ($call['input_tokens'] ?? 0) * $r['input'] + ($call['output_tokens'] ?? 0) * $r['output']
            + ($call['cache_write_tokens'] ?? 0) * ($r['cache_write'] ?? $r['input']) + ($call['cache_read_tokens'] ?? 0) * ($r['cache_read'] ?? 0);
        return (int) ceil($micro);
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
    /** @var array<string, array{at:int, v:int[]}> a short memo: a quote asks for the same spread several times */
    private static array $memo = [];

    private static function learnedSpread(string $effort, int $seconds, array $kind = [], bool $styleOnly = false): array
    {
        $key = $effort.'|'.$seconds.'|'.json_encode($kind).'|'.(int) $styleOnly;
        // Kept for a minute: long-running workers reuse this class, and new runs must count.
        if (app()->runningUnitTests() || (self::$memo[$key]['at'] ?? 0) < time() - 60) self::$memo[$key] = ['at' => time(), 'v' => self::spreadOf($effort, $seconds, $kind, $styleOnly)];
        return self::$memo[$key]['v'];
    }

    private static function spreadOf(string $effort, int $seconds, array $kind, bool $styleOnly): array
    {
        $runs = DB::table('composition_runs')->where('status', 'preview_ready')->where('input_json->build_stage', 'full_video')
            ->where('created_at', '>=', now()->subDays(60))->orderByDesc('created_at')->limit(300)->get(['id', 'input_json'])
            ->map(fn ($r) => ['id' => $r->id, 'input' => json_decode((string) $r->input_json, true) ?: []])
            ->filter(fn ($r) => self::effort($r['input']['settings'] ?? []) === $effort && abs((int) ($r['input']['settings']['duration_seconds'] ?? 15) - $seconds) <= 5)
            ->take(60)->values();
        if ($runs->count() < 3) return [];
        $plans = DB::table('create_plans')->whereIn('id', $runs->pluck('input.plan.plan_id')->filter()->unique()->all())->pluck('plan_json', 'id')
            ->map(fn ($j) => json_decode((string) $j, true) ?: []);
        $runs = $runs->map(fn ($r) => $r + ['type' => $plans[$r['input']['plan']['plan_id'] ?? '']['video_type'] ?? null, 'task' => $plans[$r['input']['plan']['plan_id'] ?? '']['planner_task'] ?? null, 'style' => $r['input']['style_pack']['slug'] ?? null,
            'template' => ! empty($r['input']['settings']['template_id']) ? 1 : null,
            // What the build would cost at cost (fair credits): its real cost, repairs left out, whatever it was charged then.
            'credits' => (int) ceil((int) DB::table('composition_attempts')->where('run_id', $r['id'])->whereIn('kind', ['agent', 'critic'])
                ->where(fn ($q) => $q->whereNull('attempt_key')->orWhere('attempt_key', 'not like', 'repair%'))->sum('cost_microusd') / self::MICRO_PER_CREDIT)])->filter(fn ($r) => $r['credits'] > 0);
        // A style with three runs of its own decides first; then the video type and request, the type, any.
        $style = ! empty($kind['style']) ? [['style' => $kind['style']]] : [];
        $template = ! empty($kind['template']) ? [['template' => 1]] : [];
        $wants = $styleOnly ? $style : [...$template, ...$style, array_intersect_key($kind, ['type' => 1, 'task' => 1]), array_intersect_key($kind, ['type' => 1]), []];
        foreach ($wants as $want) {
            if (count($want) !== count(array_filter($want))) continue;
            $spent = $runs->filter(fn ($r) => ! array_diff_assoc($want, array_intersect_key($r, $want)))->take(30)->pluck('credits')->sort()->values();
            if ($spent->count() >= 3) return $spent->all();
        }
        return [];
    }
}
