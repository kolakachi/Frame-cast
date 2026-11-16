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
 * A free sign-up who has not started their free first ad, about two hours in (owner, 2026-11-16: 11 of 13 free
 * sign-ups never started one). It replaces the day-1 "finish signup" email for them, which says there is no free trial.
 */
class OnboardingFreeAdWaiting extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly User $user)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your free ad is waiting');
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', 'https://app.wyvstudio.com'), '/');

        return new Content(view: 'mail.onboarding.free-ad-waiting', with: [
            'ctaUrl' => $base.'/templates',
            'passUrl' => $base.'/continue?pass=1',
        ]);
    }
}
