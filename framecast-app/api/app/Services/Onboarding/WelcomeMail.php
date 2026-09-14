<?php

namespace App\Services\Onboarding;

use App\Mail\Onboarding\OnboardingDay0Welcome;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * The day-0 welcome, sent once a workspace has actually paid.
 *
 * It used to go out at registration, which put it in front of people who had
 * not bought anything: it congratulated them, pointed at the dashboard, and
 * promised free credits that a plan-gated signup never receives. The first
 * thing they saw was a paywall the email had not mentioned.
 *
 * Payment now triggers it, from whichever route the payment arrived by.
 */
class WelcomeMail
{
    /**
     * Send at most once per workspace, ever.
     *
     * The claim is a conditional UPDATE rather than a read-then-write: the
     * subscription webhook fires on every change and can be redelivered, and
     * two deliveries racing through a check would both pass it.
     */
    public static function sendOnce(?Workspace $workspace): void
    {
        if (! $workspace) {
            return;
        }

        $claimed = DB::table('workspaces')
            ->where('id', $workspace->getKey())
            ->whereNull('welcome_email_sent_at')
            ->update(['welcome_email_sent_at' => now()]);

        if ($claimed === 0) {
            return; // already sent, or another delivery won the race
        }

        $user = $workspace->owner_user_id
            ? User::query()->find($workspace->owner_user_id)
            : User::query()->where('workspace_id', $workspace->getKey())->orderBy('id')->first();

        if (! $user?->email) {
            // Release the claim: no mail went out, and an owner may yet appear.
            DB::table('workspaces')->where('id', $workspace->getKey())
                ->update(['welcome_email_sent_at' => null]);
            Log::warning('WelcomeMail: paid workspace has no addressable owner', [
                'workspace_id' => $workspace->getKey(),
            ]);

            return;
        }

        // Queued and rescued: a mail outage must not roll back or fail an
        // account the customer has already paid for.
        rescue(fn () => Mail::to($user->email)->queue(new OnboardingDay0Welcome($user, $workspace->fresh())));
    }
}
