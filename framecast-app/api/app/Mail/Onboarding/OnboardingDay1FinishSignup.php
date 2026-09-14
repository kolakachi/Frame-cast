<?php

namespace App\Mail\Onboarding;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Day 1 for someone who registered but never picked a plan.
 *
 * The activation email that used to go here asks "did you ship your first
 * short?" — of a person who has not been able to open the editor. Asking
 * someone about work they were never let in to do reads as not paying
 * attention, which is an expensive thing to say to a warm lead.
 */
class OnboardingDay1FinishSignup extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly User $user)
    {
    }

    /**
     * Where the button goes.
     *
     * Straight to checkout when we know what they came to buy — /continue
     * signs them in if needed and hands them to Kelviq without a plan page in
     * between. Otherwise the plans page, because there is nothing to check out
     * yet.
     */
    private function callToAction(): array
    {
        $base = rtrim((string) config('app.frontend_url', 'https://app.wyvstudio.com'), '/');
        $workspace = $this->user->workspace;
        $plan = $workspace?->pending_checkout_plan ?: $workspace?->intended_plan;

        if ($plan && isset(config('billing.kelviq.plan_labels')[$plan])) {
            return [
                $base.'/continue?plan='.urlencode($plan),
                config('billing.kelviq.plan_labels')[$plan],
            ];
        }

        return [$base.'/plans', null];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Still want to make that video?');
    }

    public function content(): Content
    {
        [$ctaUrl, $planLabel] = $this->callToAction();

        return new Content(view: 'mail.onboarding.day1-finish-signup', with: [
            'ctaUrl' => $ctaUrl,
            'planLabel' => $planLabel,
            'plansUrl' => rtrim((string) config('app.frontend_url', 'https://app.wyvstudio.com'), '/').'/plans',
        ]);
    }
}
