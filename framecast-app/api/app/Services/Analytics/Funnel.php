<?php

namespace App\Services\Analytics;

use Illuminate\Support\Facades\DB;

/**
 * FUNNEL (CMO plan, experiment 1; owner 2026-10-19): what a new workspace does in its first session, step by step, and
 * where people stop. A step counts only inside the window after sign-up (24 hours by default, as the scoreboard's
 * "own video, day 1"). Staff and the owner's workspace are left out, and so is the sample project every workspace
 * gets ("Wyvstudio Ad"). From our own database, not PostHog.
 *
 * Steps: signed up → started a video (a Create conversation, a UGC chat or a Classic project) → approved one (a Create
 * build, the free first ad included; a UGC video started; or a paid Classic step) → delivered (a finished export or a
 * ready Create version) → downloaded or shared (downloads are logged from 2026-10-16; a Create share link, a share link
 * or a scheduled post).
 */
class Funnel
{
    public const STEPS = ['signed_up', 'started', 'approved', 'delivered', 'downloaded_or_shared'];

    /** @return array{steps: list<string>, weeks: list<array<string, mixed>>, total: array<string, int>, by_channel: array<string, array<string, int>>, biggest_drop: ?array{from: string, to: string, lost: int}} */
    public static function weeks(string $since, int $hours = 24): array
    {
        $staff = Scoreboard::staff();
        $monday = fn (string $at) => date('Y-m-d', strtotime('monday this week', strtotime($at)));
        $blank = fn () => array_fill_keys(self::STEPS, 0);
        $weeks = []; $total = $blank(); $byChannel = [];
        foreach (DB::table('workspaces')->where('created_at', '>=', $since)->whereNotIn('id', $staff)->orderBy('created_at')->get(['id', 'created_at', 'acquisition_channel']) as $w) {
            $reached = self::reached((int) $w->id, (string) $w->created_at, $hours);
            $week = $monday((string) $w->created_at);
            $channel = $w->acquisition_channel ?: 'unknown';
            $weeks[$week] ??= $blank();
            $byChannel[$channel] ??= $blank();
            foreach ($reached as $step) { $weeks[$week][$step]++; $total[$step]++; $byChannel[$channel][$step]++; }
        }
        ksort($weeks);
        uasort($byChannel, fn ($a, $b) => $b['signed_up'] <=> $a['signed_up']);
        $drop = null;
        foreach (array_slice(self::STEPS, 1) as $i => $step) {
            $lost = $total[self::STEPS[$i]] - $total[$step];
            if ($lost > 0 && ($drop === null || $lost > $drop['lost'])) $drop = ['from' => self::STEPS[$i], 'to' => $step, 'lost' => $lost];
        }
        $rows = [];
        foreach ($weeks as $week => $row) $rows[] = ['week' => $week] + $row;

        return ['steps' => self::STEPS, 'weeks' => $rows, 'total' => $total, 'by_channel' => $byChannel, 'biggest_drop' => $drop];
    }

    /**
     * The steps one workspace reached within $hours of signing up, in order; a step counts only when every one before it did.
     *
     * @return list<string>
     */
    public static function reached(int $workspaceId, string $joined, int $hours = 24): array
    {
        $until = date('Y-m-d H:i:s', strtotime($joined) + $hours * 3600);
        $own = DB::table('projects')->where('workspace_id', $workspaceId)->where('title', '!=', Scoreboard::SAMPLE_TITLE)->where('created_at', '<=', $until);
        $ownIds = (clone $own)->pluck('id');
        $ugcTakes = (clone $own)->whereNotNull('visual_brief->ugc_format')->exists();
        $conversations = DB::table('create_conversations')->where('workspace_id', $workspaceId)->where('created_at', '<=', $until)->pluck('id');
        $steps = ['signed_up'];

        $started = $conversations->isNotEmpty() || $ownIds->isNotEmpty()
            || DB::table('ugc_chats')->where('workspace_id', $workspaceId)->where('created_at', '<=', $until)->exists();
        if (! $started) return $steps;
        $steps[] = 'started';

        $approved = DB::table('composition_runs')->where('workspace_id', $workspaceId)->where('created_at', '<=', $until)->exists() || $ugcTakes
            || DB::table('credit_ledger')->where('workspace_id', $workspaceId)->where('created_at', '<=', $until)->where('credits', '>', 0)
                ->where('operation', 'not like', 'grant%')->where('operation', 'not like', 'refund%')->where('operation', '!=', 'create_planning')->exists();
        if (! $approved) return $steps;
        $steps[] = 'approved';

        if (! Scoreboard::ownVideoWithin($workspaceId, $joined, $hours)) return $steps;
        $steps[] = 'delivered';

        $kept = DB::table('video_downloads')->where('workspace_id', $workspaceId)->where('created_at', '<=', $until)->exists()
            || DB::table('share_links')->where('workspace_id', $workspaceId)->where('created_at', '<=', $until)->exists()
            || DB::table('scheduled_posts')->where('workspace_id', $workspaceId)->where('created_at', '<=', $until)->exists()
            // A Create share link keeps no date of its own: the version's last change stands in for it.
            || ($conversations->isNotEmpty() && DB::table('composition_revisions')->whereIn('conversation_id', $conversations)->where('share_enabled', true)->where('updated_at', '<=', $until)->exists());
        if ($kept) $steps[] = 'downloaded_or_shared';

        return $steps;
    }
}
