<?php

namespace App\Console\Commands;

use App\Services\Billing\RevenueService;
use Illuminate\Console\Command;

/** Money in: refunds of recent Kelviq orders and every AppSumo licence's state (daily); --backfill fills past sales. */
class RevenueSyncCommand extends Command
{
    protected $signature = 'revenue:sync {--backfill : Also record past sales from the stored Kelviq webhooks}';

    protected $description = 'Record money in (revenue_events): refunds, AppSumo licences, and optionally past sales';

    public function handle(RevenueService $revenue): int
    {
        $out = $this->option('backfill') ? $revenue->backfill() : $revenue->sync();
        $this->line(json_encode($out));
        return self::SUCCESS;
    }
}
