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

    public $tries = 5;

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function __construct(
        public readonly User $user,
        public readonly ?Workspace $workspace = null,
    ) {
        $this->afterCommit();
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your WyvStudio plan is active — welcome');
    }

    public function content(): Content
    {
        $workspace = $this->workspace ?? $this->user->workspace;
        $tier = (string) ($workspace->plan_tier ?? '');

        // Read from the workspace rather than written into the copy: the old
        // wording promised 200 free credits to everyone, which a plan-gated
        // signup never receives and a paying customer has long passed.
        $recurring = (int) ($workspace->credits_monthly ?? 0) > 0;
        $credits = $recurring ? (int) $workspace->credits_monthly : (int) ($workspace->credits_topup ?? 0);
        $planName = $tier === 'ugc_pass' ? 'UGC Test Pass' : ucwords(str_replace('_', ' ', $tier));

        return new Content(view: 'mail.onboarding.day0-welcome', with: [
            'planName' => $planName ?: null,
            'isTestPass' => $tier === 'ugc_pass',
            'accountUrl' => rtrim((string) config('app.frontend_url'), '/'),
            'credits' => $credits,
            // A lifetime or AppSumo bucket does not come back every month, and
            // saying it does is the kind of promise support has to walk back.
            'recurring' => $recurring,
        ]);
    }
}
