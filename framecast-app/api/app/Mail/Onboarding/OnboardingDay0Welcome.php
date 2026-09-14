<?php

namespace App\Mail\Onboarding;

use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class OnboardingDay0Welcome extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly ?Workspace $workspace = null,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Welcome to WyvStudio — start here');
    }

    public function content(): Content
    {
        $workspace = $this->workspace ?? $this->user->workspace;
        $tier = (string) ($workspace->plan_tier ?? '');

        // Read from the workspace rather than written into the copy: the old
        // wording promised 200 free credits to everyone, which a plan-gated
        // signup never receives and a paying customer has long passed.
        $credits = (int) ($workspace->credits_monthly ?: (CreditService::PLAN_CREDITS[$tier] ?? 0));

        return new Content(view: 'mail.onboarding.day0-welcome', with: [
            'planName' => $tier !== '' ? ucfirst($tier) : null,
            'credits' => $credits,
            // A lifetime or AppSumo bucket does not come back every month, and
            // saying it does is the kind of promise support has to walk back.
            'recurring' => (int) ($workspace->credits_monthly ?? 0) > 0,
        ]);
    }
}
