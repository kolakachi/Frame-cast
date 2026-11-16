<?php

namespace App\Jobs;

use App\Mail\Onboarding\OnboardingDay14WinBack;
use App\Mail\Onboarding\OnboardingDay1Activation;
use App\Mail\Onboarding\OnboardingDay1FinishSignup;
use App\Mail\Onboarding\OnboardingDay3CaseStudy;
use App\Mail\Onboarding\OnboardingDay7Upgrade;
use App\Mail\Onboarding\OnboardingFreeAdWaiting;
use App\Services\Create\FreeFirst;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Hourly scanner for the day-1/3/7/14 onboarding emails.
 *
 * Day-0 is the welcome, sent on payment by App\Services\Onboarding\WelcomeMail
 * — this job only handles the delayed steps. Each step has a minimum age
 * threshold (in hours since signup). When a user crosses the threshold AND is
 * still at the prior step, they get the email and advance one step.
 *
 * Paid and unpaid get different mail at the same slots. The activation and
 * case-study emails are written for someone using the product; sent to an
 * account that never picked a plan they ask about work the person was never
 * let in to do, which reads as not paying attention.
 *
 * The day-7 upgrade nudge is suppressed for already-paid workspaces — those
 * users skip straight to step 5 (sequence complete, no win-back either).
 */
class ProcessOnboardingEmailsJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /** Minimum hours since signup before the corresponding step fires. */
    private const STEP_THRESHOLDS_HOURS = [
        1 => 24,   // day-1 activation (sent when user is at step 1, age >= 24h)
        2 => 72,   // day-3 case study
        3 => 168,  // day-7 upgrade
        4 => 336,  // day-14 win-back
    ];

    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'process-onboarding-emails';
    }

    /** Hours after signup the free-ad email waits for (owner, 2026-11-16); signups older than a day are left alone. */
    public const FREE_AD_AFTER_HOURS = 2;

    public function handle(): void
    {
        $now = CarbonImmutable::now();

        // A free sign-up who has not started the free first ad hears about it after two hours, once.
        User::query()
            ->where('onboarding_step', 1)
            ->whereBetween('created_at', [$now->subDay(), $now->subHours(self::FREE_AD_AFTER_HOURS)])
            ->with('workspace:id,plan_tier')
            ->chunkById(100, function ($users) {
                foreach ($users as $user) {
                    if ($this->freeAdWaiting($user) && $this->markFreeAdEmail($user)) {
                        rescue(fn () => Mail::to($user->email)->queue(new OnboardingFreeAdWaiting($user)), null, true);
                    }
                }
            });

        foreach (self::STEP_THRESHOLDS_HOURS as $currentStep => $hours) {
            // currentStep is what the user is at NOW; we're about to advance
            // them to currentStep+1.
            $threshold = $now->subHours($hours);

            User::query()
                ->where('onboarding_step', $currentStep)
                ->where('created_at', '<=', $threshold)
                ->with('workspace:id,plan_tier')
                ->chunkById(100, function ($users) use ($currentStep) {
                    foreach ($users as $user) {
                        $this->sendStep($user, $currentStep + 1);
                    }
                });
        }
    }

    /** A free plan whose free first ad is open and not started. */
    private function freeAdWaiting(User $user): bool
    {
        $id = (int) ($user->workspace?->id ?? 0);
        return $id && FreeFirst::open() && FreeFirst::freePlan($id) && FreeFirst::conversationId($id) === null;
    }

    /** Records the free-ad email on the workspace (onboarding_json.free_ad_email_at); false when it was already sent. */
    private function markFreeAdEmail(User $user): bool
    {
        if (! \Illuminate\Support\Facades\Schema::hasColumn('workspaces', 'onboarding_json')) return false;
        return \Illuminate\Support\Facades\DB::transaction(function () use ($user) {
            $row = \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $user->workspace_id)->lockForUpdate()->first(['onboarding_json']);
            $kept = json_decode((string) ($row->onboarding_json ?? ''), true) ?: [];
            if (! empty($kept['free_ad_email_at'])) return false;
            \Illuminate\Support\Facades\DB::table('workspaces')->where('id', $user->workspace_id)->update(['onboarding_json' => json_encode([...$kept, 'free_ad_email_at' => now()->toIso8601String()])]);
            return true;
        });
    }

    private function sendStep(User $user, int $nextStep): void
    {
        try {
            // Paid users skip the upgrade nudge AND the win-back —
            // jump them straight to step 5 (sequence terminated).
            $isPaid = $user->workspace
                && $user->workspace->plan_tier
                && $user->workspace->plan_tier !== 'free';

            if ($nextStep === 4 && $isPaid) {
                $user->forceFill([
                    'onboarding_step' => 5,
                    'onboarding_last_sent_at' => now(),
                ])->save();
                return;
            }

            // A free first ad's account had the free-ad email instead: the day-1 "finish signup" one says there is
            // no free trial. Step on without it.
            if ($nextStep === 2 && ! $isPaid && $user->workspace && FreeFirst::freePlan((int) $user->workspace->id) && FreeFirst::open()) {
                $user->forceFill(['onboarding_step' => 2, 'onboarding_last_sent_at' => now()])->save();
                return;
            }

            $mailable = match ($nextStep) {
                // Never got through checkout: tell them what they have not seen
                // yet, rather than asking how the video went.
                2 => $isPaid ? new OnboardingDay1Activation($user) : new OnboardingDay1FinishSignup($user),
                // The case study is the right email either way — it shows the
                // output — so it is not branched.
                3 => new OnboardingDay3CaseStudy($user),
                4 => new OnboardingDay7Upgrade($user),
                5 => new OnboardingDay14WinBack($user),
                default => null,
            };

            if ($mailable === null) {
                return;
            }

            Mail::to($user->email)->queue($mailable);

            $user->forceFill([
                'onboarding_step' => $nextStep,
                'onboarding_last_sent_at' => now(),
            ])->save();
        } catch (\Throwable $e) {
            // Don't let one user's failure break the batch. Log and move on —
            // they'll be picked up again on the next hourly tick.
            Log::warning('Onboarding email step failed', [
                'user_id' => $user->id,
                'next_step' => $nextStep,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
