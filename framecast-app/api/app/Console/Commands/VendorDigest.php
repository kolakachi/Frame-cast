<?php

namespace App\Console\Commands;

use App\Mail\VendorAlertMail;
use App\Services\Vendors\VendorAlerts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Mail};

/**
 * The day's vendor failures (vendor_incidents) by vendor and kind, with the latest message of each, mailed to the
 * alert list at 09:05. Quiet days send nothing.
 */
class VendorDigest extends Command
{
    protected $signature = 'create:vendor-digest {--hours=24} {--print : Print instead of mailing}';
    protected $description = 'Summarise vendor failures (refusals, busy, our credit or key) and mail the alert list';

    public function handle(): int
    {
        $rows = DB::table('vendor_incidents')->where('created_at', '>=', now()->subHours(max(1, (int) $this->option('hours'))))->orderBy('created_at')->get();
        if ($rows->isEmpty()) { $this->info('No vendor failures.'); return self::SUCCESS; }
        $text = $rows->groupBy(fn ($r) => $r->vendor.' · '.$r->kind)->map(fn ($g, $k) => sprintf('%-32s %3d  runs %d  last: %s', $k, $g->count(), $g->pluck('run_id')->filter()->unique()->count(), mb_substr((string) $g->last()->message, 0, 140)))->implode("\n");
        if ($this->option('print')) { $this->line($text); return self::SUCCESS; }
        foreach (VendorAlerts::recipients() as $to) rescue(fn () => Mail::to($to)->queue(new VendorAlertMail('Vendor failures, last 24 hours: '.$rows->count(), 'all', 'digest', $text)), report: false);
        $this->info($rows->count().' vendor failures summarised.');
        return self::SUCCESS;
    }
}
