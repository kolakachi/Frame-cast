<?php

namespace App\Console\Commands;

use App\Services\Developer\CreditHolds;
use Illuminate\Console\Command;

/** Every few minutes: reviews hold only what is uncertain, and nothing is held past 24 hours (CreditHolds). */
class SettleCreditHolds extends Command
{
    protected $signature = 'credits:settle-holds';

    protected $description = 'Shrink held credits under review to what is uncertain and release holds older than 24 hours';

    public function handle(): int
    {
        $r = CreditHolds::sweep();
        foreach ($r['shrunk'] as $id => $n) $this->line("shrunk {$id}: released {$n}");
        foreach ($r['expired'] as $id => $n) $this->line("expired {$id}: released {$n}");
        if ($r['expired']) {
            // The team hears what was released unresolved: any late provider charge for it is ours.
            $to = (array) config('create.admin_alert_emails', []) ?: \App\Models\User::query()->where('role', 'super_admin')->pluck('email')->all();
            $body = "Credits held for more than ".CreditHolds::REVIEW_HOURS." hours were released to their workspaces:\n\n"
                .implode("\n", array_map(fn ($id, $n) => "  {$id}: {$n} credits", array_keys($r['expired']), $r['expired']))
                ."\n\nAny provider charge still arriving for these is ours. Review them with create:worker-recovery or api:release-hold.";
            foreach ($to as $email) {
                try { \Illuminate\Support\Facades\Mail::raw($body, fn ($m) => $m->to($email)->subject('WyvStudio: held credits released after 24 hours')); }
                catch (\Throwable $e) { report($e); }
            }
        }
        return self::SUCCESS;
    }
}
