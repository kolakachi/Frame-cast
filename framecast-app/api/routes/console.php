<?php

use App\Jobs\DetectAbusePatternsJob;
use App\Jobs\ProcessOnboardingEmailsJob;
use App\Jobs\ReapStuckGenerationsJob;
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

// Advance the day-1/3/7/14 onboarding email sequence. Day-0 is sent inline
// from AuthController at signup; this scanner picks up everyone whose signup
// age has crossed the next threshold and dispatches the matching Mailable.
Schedule::job(new ProcessOnboardingEmailsJob())->hourly()->name('process-onboarding-emails')->withoutOverlapping();

// Watchdog: clear stuck image-generation / animation `in_progress` flags so a
// crashed worker, dropped Reverb event, or silent-save quirk doesn't leave
// scenes spinning forever. Runs every 5 min; thresholds are 10 min for image
// gen and 15 min for animation (both well above worst-case real run times).
Schedule::job(new ReapStuckGenerationsJob())->everyFiveMinutes()->name('reap-stuck-generations')->withoutOverlapping();

// Trust & Safety: scan the last 24h of generations + moderation events for
// abuse patterns (rejection bursts, high-risk-term prompts), create
// pattern_alert events, and email a single digest to the configured admin
// address if any new alerts landed. Runs once per day at 09:00 UTC.
Schedule::job(new DetectAbusePatternsJob())->dailyAt('09:00')->name('detect-abuse-patterns')->withoutOverlapping();
