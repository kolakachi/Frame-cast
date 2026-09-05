<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent once when a checkout session was created but no purchase ever landed.
 *
 * Deliberately quiet: they were one click from paying and something stopped
 * them, and the most useful thing we can learn is what. The plan name is
 * included so the mail reads as a continuation rather than a pitch.
 */
class AbandonedCheckoutMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $planName,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Did something go wrong at checkout?');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.abandoned-checkout');
    }
}
