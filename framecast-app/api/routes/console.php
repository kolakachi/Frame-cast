<?php

use App\Jobs\ResetMonthlyCreditsJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Reset monthly credits for any workspace whose billing cycle has rolled over.
// Runs every hour so resets happen within an hour of the billing_renews_at.
Schedule::job(new ResetMonthlyCreditsJob())->hourly()->name('reset-monthly-credits')->withoutOverlapping();
