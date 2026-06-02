<?php

namespace App\Jobs;

use App\Mail\Onboarding\OnboardingDay14WinBack;
use App\Mail\Onboarding\OnboardingDay1Activation;
use App\Mail\Onboarding\OnboardingDay3CaseStudy;
use App\Mail\Onboarding\OnboardingDay7Upgrade;
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
 * Day-0 is sent inline from AuthController at signup — this job only handles
 * the delayed steps. Each step has a minimum age threshold (in hours since
 * signup). When a user crosses the threshold AND is still at the prior step,
 * they get the email and advance one step.
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

    public function handle(): void
    {
        $now = CarbonImmutable::now();

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

            $mailable = match ($nextStep) {
                2 => new OnboardingDay1Activation($user),
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
