<?php

namespace App\Jobs;

use App\Mail\AbandonedCheckoutMail;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Hourly scan for checkouts that were started and never completed.
 *
 * BillingController stamps pending_checkout_* when it hands out a Kelviq URL;
 * the webhook clears it on any successful purchase. Anything still set after a
 * grace period is a genuine abandonment.
 *
 * Sent once per attempt — reminded_at is stamped after sending, and a fresh
 * checkout resets it, so a second attempt can earn a second email but a single
 * abandonment never nags.
 */
class SendAbandonedCheckoutEmailsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Long enough that someone who simply took a while at the payment page —
     * fetching a card, finishing on their phone — has landed before we write
     * to them, and their webhook has had time to arrive.
     */
    private const GRACE_HOURS = 6;

    /**
     * Past this an abandonment is stale; a fortnight-old checkout is not worth
     * reopening and the mail would read as odd.
     */
    private const STALE_DAYS = 14;

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'send-abandoned-checkout-emails';
    }

    /** Plan key -> the name a human would recognise on their invoice. */
    private const PLAN_NAMES = [
        'lifetime_starter' => 'Starter — $89 one-time',
        'lifetime_creator' => 'Creator — $199 one-time',
        'lifetime_agency'  => 'Agency — $399 one-time',
        'starter'          => 'Starter — $19/month',
        'creator'          => 'Creator — $39/month',
        'pro'              => 'Pro — $79/month',
        'agency'           => 'Agency — $149/month',
        'small'            => 'a credit top-up',
        'medium'           => 'a credit top-up',
        'large'            => 'a credit top-up',
        'xl'               => 'a credit top-up',
    ];

    public function handle(): void
    {
        $workspaces = Workspace::query()
            ->whereNotNull('pending_checkout_at')
            ->whereNull('pending_checkout_reminded_at')
            ->where('pending_checkout_at', '<=', now()->subHours(self::GRACE_HOURS))
            ->where('pending_checkout_at', '>=', now()->subDays(self::STALE_DAYS))
            ->where('status', 'active')
            ->limit(200)
            ->get();

        foreach ($workspaces as $workspace) {
            // Stamp first. A mail failure must not leave the row eligible on
            // the next run — better one missed nudge than a loop of them.
            $workspace->forceFill(['pending_checkout_reminded_at' => now()])->save();

            $user = User::query()
                ->where('workspace_id', $workspace->getKey())
                ->orderBy('id')
                ->first();

            if (! $user || ! $user->email) {
                continue;
            }

            $planName = self::PLAN_NAMES[(string) $workspace->pending_checkout_plan] ?? 'a plan';

            try {
                Mail::to($user->email)->send(new AbandonedCheckoutMail($user, $planName));
                Log::info('AbandonedCheckout: nudge sent', [
                    'workspace_id' => $workspace->getKey(),
                    'plan'         => $workspace->pending_checkout_plan,
                ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
