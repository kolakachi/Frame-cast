<?php

use App\Jobs\RefreshSocialTokensJob;

use App\Jobs\DetectAbusePatternsJob;
use App\Jobs\ProcessOnboardingEmailsJob;
use App\Jobs\SendAbandonedCheckoutEmailsJob;
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

// Nudge anyone who was handed a Kelviq checkout URL and never completed the
// purchase. BillingController stamps the intent, the webhook clears it on
// success, so what's left after the grace period is a real abandonment. One
// email per attempt.
Schedule::job(new SendAbandonedCheckoutEmailsJob())->hourly()->name('send-abandoned-checkout-emails')->withoutOverlapping();

// Watchdog: clear stuck image-generation / animation `in_progress` flags so a
// crashed worker, dropped Reverb event, or silent-save quirk doesn't leave
// scenes spinning forever. Runs every 5 min; thresholds are 10 min for image
// gen and 15 min for animation (both well above worst-case real run times).
// Publishing tokens are short-lived by design — Google's last an hour, TikTok's
// a day — and were only refreshed at the moment of posting. Keeping them warm
// here means Settings can show the truth, and a revoked connection surfaces on
// a quiet hour instead of as a failed scheduled post.
Schedule::job(new RefreshSocialTokensJob())->hourly()->name('refresh-social-tokens')->withoutOverlapping();

Schedule::job(new ReapStuckGenerationsJob())->everyFiveMinutes()->name('reap-stuck-generations')->withoutOverlapping();

// Billing webhook logs carry raw provider payloads, which include customer
// name, email and billing address. Keep them long enough to debug a disputed
// charge or a failed redemption, then drop them.
Schedule::call(function (): void {
    \App\Models\BillingWebhookLog::query()
        ->where('created_at', '<', now()->subDays(\App\Models\BillingWebhookLog::RETENTION_DAYS))
        ->delete();
})->dailyAt('04:20')->name('prune-billing-webhook-logs')->withoutOverlapping();

// Trust & Safety: scan the last 24h of generations + moderation events for
// abuse patterns (rejection bursts, high-risk-term prompts), create
// pattern_alert events, and email a single digest to the configured admin
// address if any new alerts landed. Runs once per day at 09:00 UTC.
Schedule::job(new DetectAbusePatternsJob())->dailyAt('09:00')->name('detect-abuse-patterns')->withoutOverlapping();

// Reconcile credit balances against the ledger, and say something only when
// they disagree. The ledger is written best-effort — every write wrapped in
// rescue() so a logging failure can never cost a customer their generation —
// so it can fall behind silently, and did: a customer's 5,000-credit purchase
// reached their balance and never reached their history, and nothing noticed
// for fifteen days.
//
// Read-only and quick. Runs before the abuse digest so a bad night shows up in
// the morning rather than the following one.
Schedule::call(function (): void {
    // Run it as the command would, but capture what it printed so the alert
    // carries the finding rather than just the fact that there was one.
    $exit = \Illuminate\Support\Facades\Artisan::call('credits:verify');
    $report = trim(\Illuminate\Support\Facades\Artisan::output());

    if ($exit === 0) {
        return;   // consistent; say nothing
    }

    \Illuminate\Support\Facades\Log::error('credits:verify found a discrepancy', ['report' => $report]);

    $email = (string) config('moderation.digest_email', 'hello@wyvstudio.com');
    if ($email === '') {
        return;
    }

    rescue(fn () => \Illuminate\Support\Facades\Mail::to($email)
        ->queue(new \App\Mail\CreditLedgerAlert($report)), null, false);
})->dailyAt('08:30')->name('verify-credit-ledger')->withoutOverlapping();
