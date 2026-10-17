<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * The growth scoreboard (CMO-4, owner 2026-10-17; docs/product/cmo-plan.md §1): one row a week from our own data, not
 * PostHog (mixed with local and owner sessions). Staff and the owner's workspace are left out, and so is the sample
 * project every new workspace gets ("Wyvstudio Ad"), which is not the customer's own work.
 */
class Scoreboard
{
    public const SAMPLE_TITLE = 'Wyvstudio Ad';

    /** @return list<int> */
    public static function staff(): array
    {
        return DB::table('users')->where('role', 'super_admin')->pluck('workspace_id')->push(1)->filter()->unique()->map(fn ($w) => (int) $w)->values()->all();
    }

    /** Every purchase: AppSumo licences and direct sales, oldest first. @return list<array{ws:int, at:string, refunded:bool}> */
    public static function purchases(): array
    {
        $staff = self::staff();
        $rows = [];
        foreach (DB::table('appsumo_licenses')->whereNotNull('workspace_id')->get(['workspace_id', 'status', 'created_at', 'updated_at']) as $l) {
            $rows[] = ['ws' => (int) $l->workspace_id, 'at' => (string) $l->created_at, 'refunded' => $l->status === 'deactivated', 'refunded_at' => $l->status === 'deactivated' ? (string) $l->updated_at : null];
        }
        foreach (DB::table('revenue_events')->where('provider', '!=', 'appsumo')->whereNotNull('workspace_id')->get(['workspace_id', 'occurred_at', 'refunded_usd', 'updated_at']) as $r) {
            $rows[] = ['ws' => (int) $r->workspace_id, 'at' => (string) $r->occurred_at, 'refunded' => (float) $r->refunded_usd > 0, 'refunded_at' => (float) $r->refunded_usd > 0 ? (string) $r->updated_at : null];
        }
        $rows = array_values(array_filter($rows, fn ($r) => ! in_array($r['ws'], $staff, true)));
        usort($rows, fn ($a, $b) => strcmp($a['at'], $b['at']));

        return $rows;
    }

    /** Whether a workspace had a video of its own (not the sample) within $hours of signing up. */
    public static function ownVideoWithin(int $workspaceId, string $joined, int $hours = 24): bool
    {
        $until = date('Y-m-d H:i:s', strtotime($joined) + $hours * 3600);
        $own = DB::table('projects')->where('workspace_id', $workspaceId)->where('title', '!=', self::SAMPLE_TITLE)->pluck('id');
        return DB::table('export_jobs')->whereIn('project_id', $own)->where('status', 'completed')->where('completed_at', '<=', $until)->exists()
            || DB::table('composition_runs')->where('workspace_id', $workspaceId)->where('status', 'preview_ready')->where('updated_at', '<=', $until)->exists();
    }

    /**
     * One row per week (Monday start) from $since: sign-ups, by channel and by utm_source, first-day own videos,
     * purchases, second purchases and refunds; and the goal (teams that bought again, to date).
     *
     * @return array{weeks: list<array<string, mixed>>, bought_again_to_date: int}
     */
    public static function weeks(string $since): array
    {
        $staff = self::staff();
        $monday = fn (string $at) => date('Y-m-d', strtotime('monday this week', strtotime($at)));
        $weeks = [];
        $blank = fn () => ['signups' => 0, 'own_video_first_day' => 0, 'by_channel' => [], 'by_source' => [], 'purchases' => 0, 'bought_again' => 0, 'refunds' => 0];
        $ws = DB::table('workspaces')->where('created_at', '>=', $since)->whereNotIn('id', $staff)->get(['id', 'created_at', 'acquisition_channel', 'acquisition_json']);
        foreach ($ws as $w) {
            $k = $monday((string) $w->created_at);
            $weeks[$k] ??= $blank();
            $weeks[$k]['signups']++;
            $channel = $w->acquisition_channel ?: 'unknown';
            $weeks[$k]['by_channel'][$channel] = ($weeks[$k]['by_channel'][$channel] ?? 0) + 1;
            $source = (string) (json_decode((string) $w->acquisition_json, true)['utm_source'] ?? '');
            if ($source !== '') $weeks[$k]['by_source'][$source] = ($weeks[$k]['by_source'][$source] ?? 0) + 1;
            if (self::ownVideoWithin((int) $w->id, (string) $w->created_at)) $weeks[$k]['own_video_first_day']++;
        }
        $seen = [];
        $again = [];
        foreach (self::purchases() as $p) {
            $isAgain = isset($seen[$p['ws']]);
            $seen[$p['ws']] = true;
            if ($isAgain) $again[$p['ws']] = true;
            if ($p['at'] >= $since) {
                $k = $monday($p['at']);
                $weeks[$k] ??= $blank();
                $weeks[$k]['purchases']++;
                if ($isAgain) $weeks[$k]['bought_again']++;
            }
            if ($p['refunded_at'] && $p['refunded_at'] >= $since) {
                $k = $monday($p['refunded_at']);
                $weeks[$k] ??= $blank();
                $weeks[$k]['refunds']++;
            }
        }
        ksort($weeks);
        $out = [];
        foreach ($weeks as $week => $row) $out[] = ['week' => $week] + $row;

        return ['weeks' => $out, 'bought_again_to_date' => count($again)];
    }
}
