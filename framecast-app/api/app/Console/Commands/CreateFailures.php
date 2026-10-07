<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The failure ledger (docs/product/archive/create/create-verify-and-teach-scope.md, 1e): every Create run that failed or was held,
 * with the stage it stopped in, its cause as the trace recorded it, the credits it spent and whether a later run of
 * the same creation got a video. Counted weekly in the progress tracker.
 */
class CreateFailures extends Command
{
    protected $signature = 'create:failures {--days=7 : How far back} {--json : Print JSON}';
    protected $description = 'List failed and held Create runs with cause, cost and recovery';

    public function handle(): int
    {
        $since = now()->subDays(max(1, (int) $this->option('days')));
        $runs = DB::table('composition_runs')->where('created_at', '>=', $since)->whereIn('status', ['failed', 'needs_attention', 'cancelled'])->orderBy('created_at')->get();
        $rows = $runs->map(function ($r) {
            $input = json_decode((string) $r->input_json, true) ?: [];
            // The cause: the worker's own error event, else the last failed step before the end.
            $events = DB::table('composition_trace_events')->where('run_id', $r->id)->orderByDesc('sequence')->limit(40)->pluck('event_json')->map(fn ($e) => json_decode((string) $e, true) ?: []);
            $cause = $events->first(fn ($e) => ($e['phase'] ?? '') === 'run' && ($e['status'] ?? '') === 'failed')['detail']
                ?? $events->first(fn ($e) => ($e['status'] ?? '') === 'failed' && ($e['tool'] ?? '') !== 'snapshot')['detail'] ?? null;
            $recovered = DB::table('composition_runs')->where('conversation_id', $r->conversation_id)->where('created_at', '>', $r->created_at)->where('status', 'preview_ready')->exists();
            return ['run' => $r->id, 'at' => (string) $r->created_at, 'creation' => $r->conversation_id, 'stage' => $input['build_stage'] ?? null, 'status' => $r->status,
                'cause' => mb_substr((string) ($cause ?? $r->error ?? 'unknown'), 0, 200), 'credits' => (int) DB::table('composition_attempts')->where('run_id', $r->id)->sum('charged_credits'),
                'recovered' => $recovered];
        })->all();
        $totals = ['runs' => DB::table('composition_runs')->where('created_at', '>=', $since)->count(), 'failed' => count($rows),
            'credits_lost' => array_sum(array_column($rows, 'credits')), 'recovered' => count(array_filter($rows, fn ($r) => $r['recovered']))];
        if ($this->option('json')) { $this->line(json_encode(['since' => $since->toIso8601String(), 'totals' => $totals, 'failures' => $rows], JSON_PRETTY_PRINT)); return self::SUCCESS; }
        foreach ($rows as $r) $this->line(sprintf('%s  %s  %-11s %-15s %4d cr  %s  %s', substr($r['at'], 5, 11), substr($r['run'], 0, 8), $r['stage'] ?? '-', $r['status'], $r['credits'], $r['recovered'] ? 'recovered' : 'NOT recovered', $r['cause']));
        $this->info(sprintf('%d of %d runs failed or were held since %s; %d credits spent on them; %d later recovered.', $totals['failed'], $totals['runs'], $since->toDateString(), $totals['credits_lost'], $totals['recovered']));
        return self::SUCCESS;
    }
}
