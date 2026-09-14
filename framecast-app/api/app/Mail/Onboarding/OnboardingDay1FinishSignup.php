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

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Still want to make that video?');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.onboarding.day1-finish-signup');
    }
}
