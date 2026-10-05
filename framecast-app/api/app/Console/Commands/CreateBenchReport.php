<?php

namespace App\Console\Commands;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * The bench numbers for one creation (docs/product/create-bench.md): how long each stage took, what was charged and
 * what failed, read from the records rather than measured by hand. The owner's scores are added beside it.
 */
class CreateBenchReport extends Command
{
    protected $signature = 'create:bench-report {conversation : Create conversation id} {--json : Print JSON}';
    protected $description = 'Time, credits, provider cost, failures and routes for every plan and run of one creation';

    public function handle(): int
    {
        $id = (string) $this->argument('conversation');
        $c = DB::table('create_conversations')->where('id', $id)->first();
        if (! $c) { $this->error('No such creation.'); return self::FAILURE; }
        $settings = json_decode((string) $c->settings_json, true) ?: [];
        $messages = DB::table('create_messages')->where('conversation_id', $id)->orderBy('sequence')->get(['role', 'created_at', 'sequence']);
        $secs = fn ($a, $b) => $a && $b ? Carbon::parse($a)->diffInSeconds(Carbon::parse($b)) : null;

        $plans = DB::table('create_plans')->where('conversation_id', $id)->orderBy('created_at')->get()->map(function ($p) use ($messages, $secs) {
            $plan = json_decode((string) $p->plan_json, true) ?: [];
            $asked = $messages->where('role', 'user')->where('sequence', '<=', (int) $p->brief_sequence)->last();
            return ['id' => $p->id, 'provider' => $p->provider, 'status' => $p->status, 'seconds' => $secs($asked?->created_at, $p->created_at),
                'media_credits' => $plan['credits']['media'] ?? null, 'video_tier' => $plan['selections']['video_tier'] ?? null,
                'routes' => collect($plan['media'] ?? [])->map(fn ($m) => $m['kind'].(isset($m['engine']) ? ':'.$m['engine'] : ''))->countBy()->all(),
                'beats' => count($plan['scenes'] ?? [])];
        })->all();

        $runs = DB::table('composition_runs')->where('conversation_id', $id)->orderBy('created_at')->get()->map(function ($r) use ($secs) {
            $input = json_decode((string) $r->input_json, true) ?: [];
            $attempts = DB::table('composition_attempts')->where('run_id', $r->id)->get();
            // Plan-media attempts carry the credit tariff, not a bill; generated video's real provider cost is on its media line.
            $tariff = $attempts->filter(fn ($a) => $a->kind === 'plan_media')->count() > 0;
            return ['id' => $r->id, 'stage' => $input['build_stage'] ?? null, 'status' => $r->status, 'seconds' => $secs($r->created_at, $r->updated_at),
                'held' => $r->worker_stopped_at !== null || $attempts->contains(fn ($a) => in_array($a->status, ['unknown', 'started'], true)),
                'credits' => (int) $attempts->sum('charged_credits'),
                'credits_by_kind' => $attempts->groupBy('kind')->map(fn ($g) => (int) $g->sum('charged_credits'))->all(),
                'provider_usd' => round($attempts->sum('cost_microusd') / 1e6, 2), 'provider_cost_basis' => $tariff ? 'tariff estimate' : 'receipts',
                'failed_attempts' => $attempts->whereIn('status', ['failed', 'unknown'])->count(), 'model_calls' => $attempts->where('kind', 'agent')->count(),
                'reconciled' => DB::table('composition_reconciliations')->whereIn('attempt_id', $attempts->pluck('id'))->count(),
                // Stage times (F3): from the first to the last attempt of each kind, so slow stages show.
                'stages' => $attempts->groupBy(fn ($a) => $a->kind === 'plan_media' ? 'media' : ($a->kind === 'render' ? 'render' : ($a->kind === 'agent' && str_starts_with((string) $a->attempt_key, 'repair') ? 'repair' : $a->kind)))
                    ->map(fn ($g) => $secs($g->min('created_at'), $g->max('updated_at')))->all()];
        })->all();

        $media = DB::table('create_plan_media')->where('conversation_id', $id)->orderBy('item_index')->get()
            ->map(fn ($m) => ['kind' => $m->kind, 'status' => $m->status, 'credits' => (int) $m->charged_credits, 'engine' => json_decode((string) $m->record_json, true)['engine'] ?? null,
                // Real provider cost where the item recorded it (generated video); otherwise unknown, never the tariff.
                'provider_usd' => json_decode((string) $m->record_json, true)['provider_cost_usd'] ?? null, 'error' => $m->error])->all();

        $report = ['conversation' => $id, 'settings' => array_intersect_key($settings, array_flip(['aspect_ratio', 'duration_seconds', 'reference_match'])),
            'plans' => $plans, 'runs' => $runs, 'media' => $media,
            'totals' => ['credits' => array_sum(array_column($runs, 'credits')), 'runs' => count($runs),
                'holds' => count(array_filter($runs, fn ($r) => $r['held'])), 'failed_media' => count(array_filter($media, fn ($m) => in_array($m['status'], ['failed', 'unknown'], true)))]];

        if ($this->option('json')) { $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)); return self::SUCCESS; }
        $this->info('Creation '.$id.' · '.json_encode($report['settings']));
        foreach ($plans as $p) $this->line(sprintf('Plan  %s  %-26s %4ss  media %s cr  tier %s  routes %s', substr($p['id'], 0, 8), $p['provider'], $p['seconds'] ?? '?', $p['media_credits'] ?? '?', $p['video_tier'] ?? '-', json_encode($p['routes'])));
        foreach ($runs as $r) $this->line(sprintf('Run   %s  %-11s %-14s %5ss  %5d cr  $%s (%s)  failed %d  calls %d%s  stages %s', substr($r['id'], 0, 8), $r['stage'] ?? '?', $r['status'], $r['seconds'] ?? '?', $r['credits'], $r['provider_usd'], $r['provider_cost_basis'], $r['failed_attempts'], $r['model_calls'], $r['held'] ? '  HELD' : '',
            collect($r['stages'])->map(fn ($v, $k) => $k.' '.$v.'s')->implode(', ')));
        foreach ($media as $m) $this->line(sprintf('Media %-16s %-10s %4d cr  %-11s %s%s', $m['kind'], $m['status'], $m['credits'], $m['engine'] ?? '', $m['provider_usd'] === null ? 'provider $?' : 'provider $'.$m['provider_usd'], $m['error'] ? '  '.mb_substr($m['error'], 0, 80) : ''));
        $this->info(sprintf('Total %d credits over %d runs; %d held; %d media failed.', $report['totals']['credits'], $report['totals']['runs'], $report['totals']['holds'], $report['totals']['failed_media']));
        return self::SUCCESS;
    }
}
