<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/**
 * What a stage will probably cost, so the price on a button is the likely figure, not the ceiling.
 *
 * The video's build agent is the only part that varies: its estimate is the median of real finished runs at the same
 * effort and a similar length (at least three of them), else a curve fitted to the runs measured on 2026-10-06
 * (Standard: about 250 credits for 15 s, 800 for 30 s), scaled by effort. Its ceiling is a budget of three times the
 * estimate: the most a step may spend, of which only what is used is charged. Images (the character and the
 * storyboard) and bought media are priced exactly, so they need no estimate.
 */
class CostEstimate
{
    public const EFFORTS = ['quick', 'standard', 'thorough'];
    /** Against Standard: Quick plans on Sonnet and builds with fewer, lighter calls; Thorough reviews more. */
    public const SCALE = ['quick' => 0.4, 'standard' => 1.0, 'thorough' => 1.9];
    public const CEILING_TIMES = 3;
    public const CEILING_FLOOR = 600;
    /** Planning is billed at half its real cost, never more than this. */
    public const PLANNING_CAP = 100;

    public static function effort(array $settings): string
    {
        return in_array($settings['effort'] ?? null, self::EFFORTS, true) ? $settings['effort'] : 'standard';
    }

    /** The build agent's likely credits for a video of this length at this effort. */
    public static function agent(string $effort, int $seconds): int
    {
        $learned = self::learned($effort, $seconds);
        if ($learned !== null) return $learned;
        $seconds = max(5, min(60, $seconds));
        return (int) round((120 + 0.75 * $seconds * $seconds) * (self::SCALE[$effort] ?? 1.0));
    }

    /** The most the build agent may spend: three times its estimate, never under the floor. */
    public static function agentCeiling(string $effort, int $seconds): int
    {
        return max(self::CEILING_FLOOR, self::CEILING_TIMES * self::agent($effort, $seconds));
    }

    /** Half the plan's real cost, at most PLANNING_CAP credits. $credits is its real cost in credits. */
    public static function planningCharge(int $credits): int
    {
        return min(self::PLANNING_CAP, (int) ceil(max(0, $credits) / 2));
    }

    /** A model call's real cost in credits from its tokens, at the tariff the gateway charges (create.anthropic_rates). */
    public static function callCredits(array $call): int
    {
        $r = config('create.anthropic_rates');
        $micro = ($call['input_tokens'] ?? 0) * $r['input'] + ($call['output_tokens'] ?? 0) * $r['output']
            + ($call['cache_write_tokens'] ?? 0) * $r['cache_write'] + ($call['cache_read_tokens'] ?? 0) * $r['cache_read'];
        return (int) ceil($micro / 4000);
    }

    /** The median build-agent spend of finished runs at this effort and a similar length, once there are enough. */
    private static function learned(string $effort, int $seconds): ?int
    {
        $spent = DB::table('composition_runs')->where('status', 'preview_ready')->where('input_json->build_stage', 'full_video')
            ->where('created_at', '>=', now()->subDays(60))->orderByDesc('created_at')->limit(200)->get(['id', 'input_json'])
            ->filter(function ($r) use ($effort, $seconds) {
                $s = json_decode((string) $r->input_json, true)['settings'] ?? [];
                return self::effort($s) === $effort && abs((int) ($s['duration_seconds'] ?? 15) - $seconds) <= 5;
            })->take(30)->map(fn ($r) => (int) DB::table('composition_attempts')->where('run_id', $r->id)->whereIn('kind', ['agent', 'critic'])->sum('charged_credits'))
            ->filter(fn ($c) => $c > 0)->sort()->values();
        return $spent->count() >= 3 ? (int) $spent[intdiv($spent->count(), 2)] : null;
    }
}
