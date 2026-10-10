<?php

namespace App\Services\Create;

use App\Mail\VendorAlertMail;
use App\Services\Vendors\VendorAlerts;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Create's health, checked every few minutes so an outage is heard about in minutes, not from the next day's digest.
 * Each problem names the conversation or run IDs support needs. One email an hour per problem while it lasts, and a
 * short note when it clears. Vendor billing and key failures alert on their own (VendorAlerts), the moment they happen.
 */
class CreateHealth
{
    /** A worker slot claims every few seconds, and a busy one sends a heartbeat every 20 s; silence this long from both
     *  means no worker is there. */
    public const WORKER_SILENT_SECONDS = 300;

    public static function workerSeen(): void
    {
        Cache::put('create:worker-seen', now()->timestamp, now()->addDays(2));
    }

    /** Problems now: [id => ['title' => ..., 'text' => ...]]. */
    public function problems(): array
    {
        $out = [];
        $ids = fn ($rows, string $key = 'id') => collect($rows)->pluck($key)->map(fn ($v) => substr((string) $v, 0, 8))->take(10)->implode(', ');

        $planning = DB::table('create_planning_jobs')->where(fn ($q) => $q
            // A plan waiting behind its own workspace's other plans is taking its turn, not stuck.
            ->where(fn ($q) => $q->where('state', 'queued')->where('created_at', '<', now()->subMinutes(5))
                ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('create_planning_jobs as busy')->whereColumn('busy.workspace_id', 'create_planning_jobs.workspace_id')->where('busy.state', 'running')))
            ->orWhere(fn ($q) => $q->where('state', 'running')->where('started_at', '<', now()->subMinutes(25)))
            // A plan that died mid-way charged nothing and the user can simply ask again (a new request replaces it): it is
            // reported once, while recent, and not once an hour for a week (the PLAN-DEAD drill job alerted all day, 2026-10-08).
            ->orWhere(fn ($q) => $q->where('state', 'needs_attention')->where('updated_at', '>', now()->subHours(2))
                ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('create_planning_jobs as later')->whereColumn('later.conversation_id', 'create_planning_jobs.conversation_id')->whereColumn('later.created_at', '>', 'create_planning_jobs.created_at'))))
            ->get(['conversation_id', 'state']);
        if ($planning->isNotEmpty()) $out['planning'] = ['title' => 'Create planning is stuck',
            'text' => $planning->count().' plan request(s) waiting over 5 min, running over 25 min, or needing attention. Conversations: '.$ids($planning, 'conversation_id').'. Check the worker-create-planning container; `php artisan create:recover-planning`.'];

        $queued = DB::table('composition_runs')->where('status', 'queued')->where('created_at', '<', now()->subMinutes(20))->get(['id']);
        if ($queued->isNotEmpty()) $out['queue'] = ['title' => 'Create builds are waiting too long',
            'text' => $queued->count().' build(s) queued over 20 min. Runs: '.$ids($queued).'. Check the build worker (framecast-create) and `php artisan create:drain status`.'];

        if (config('create.enabled') && config('create.mode') === 'agent') {
            $seen = (int) Cache::get('create:worker-seen', 0);
            if (now()->timestamp - $seen > self::WORKER_SILENT_SECONDS) $out['worker'] = ['title' => 'No Create build worker is checking in',
                'text' => 'No worker has asked for a build or reported progress '.($seen ? 'since '.date('H:i', $seen).' UTC' : 'recently').'. Builds will queue. `ssh framecast-create` and `systemctl status wyv-create-worker`.'];
        }

        $stranded = DB::table('composition_runs')->where('status', 'needs_attention')->whereNull('worker_stopped_at')->get(['id']);
        if ($stranded->isNotEmpty()) $out['stranded'] = ['title' => 'A Create build lost its worker',
            'text' => $stranded->count().' build(s) need attention and their worker has not confirmed it stopped; each holds a build slot. Runs: '.$ids($stranded).'. See create-worker-recovery-runbook.md.'];

        $holds = DB::table('api_operations')->whereIn('status', ['running', 'needs_attention'])->where('reserved_credits', '>', 0)
            ->where('updated_at', '<', now()->subHours(3))->get(['id']);
        if ($holds->isNotEmpty()) $out['holds'] = ['title' => 'Credit holds left open',
            'text' => $holds->count().' operation(s) have held credits for over 3 hours: '.collect($holds)->pluck('id')->take(10)->implode(', ').'. Reconcile them; the credits:settle-holds pass keeps only what is uncertain and releases everything after 24 hours.'];

        // Our model account, before it runs dry (Anthropic has no balance API: see ModelBalance).
        if (($b = \App\Services\Vendors\ModelBalance::estimate()) && $b['left'] < (float) config('create.model_balance_warn_usd', 40)) $out['balance'] = ['title' => 'Our Anthropic balance is low',
            'text' => sprintf('About $%.2f is left of the $%.2f recorded on %s (about $%.2f spent since). At zero every Create plan and build stops. Top up at https://console.anthropic.com/settings/billing (or turn on auto-reload), then run `php artisan create:model-balance NEW_BALANCE`.', max(0, $b['left']), $b['balance'], substr($b['set_at'], 0, 10), $b['spent'])];

        $disk = app(DiskSpace::class);
        $bytes = (int) config('create.disk_working_bytes', 1073741824);
        foreach (['storage' => Storage::disk('local')->path(''), 'scratch' => sys_get_temp_dir()] as $name => $path) {
            $s = $disk->inspect($path, $bytes);
            if (! $s['ok']) { $out['disk'] = ['title' => 'Create is short of disk space',
                'text' => 'The API '.$name.' disk has '.round(($s['free_bytes'] ?? 0) / 1073741824, 1).' GB free; new Create work is refused below '.round(($s['required_bytes'] ?? 0) / 1073741824, 1).' GB. Clear old images or build cache (`docker system df`).']; break; }
        }
        return $out;
    }

    /** Alerts new problems (at most hourly each) and notes the ones that cleared. Returns what is wrong now. */
    public function check(): array
    {
        $now = $this->problems();
        foreach ($now as $id => $p) {
            Cache::put('create-health-open:'.$id, true, now()->addDays(2));
            if (Cache::add('create-health-alert:'.$id, true, now()->addHour())) $this->send('Action needed: '.$p['title'], $p['text']);
        }
        foreach (['planning', 'queue', 'worker', 'stranded', 'holds', 'balance', 'disk'] as $id) {
            if (isset($now[$id]) || ! Cache::pull('create-health-open:'.$id)) continue;
            Cache::forget('create-health-alert:'.$id);
            $this->send('Recovered: Create '.$id.' is healthy again', 'The earlier "'.$id.'" problem has cleared.');
        }
        return $now;
    }

    private function send(string $subject, string $text): void
    {
        foreach (VendorAlerts::recipients() as $to) rescue(fn () => Mail::to($to)->queue(new VendorAlertMail($subject, 'Create', 'health', $text)), report: false);
    }
}
