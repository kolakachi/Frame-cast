<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The sign-in link, and — for a brand-new account — the only email we send at
 * registration.
 *
 * Registration is passwordless, so this link is the one thing a new customer
 * must open before anything else can happen. A second "welcome" alongside it
 * would arrive in the same minute, say less, and compete with the link for the
 * click. So the first-run greeting lives here instead of in a mail of its own.
 */
class MagicLinkMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $magicLink,
        /** Brand-new account: this is their first contact from us. */
        public readonly bool $firstRun = false,
        /** The plan they picked on the site, if they arrived having picked one. */
        public readonly ?string $planLabel = null,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->firstRun
            ? 'Welcome to WyvStudio — your sign-in link'
            : 'Your WyvStudio sign-in link');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.magic-link', with: [
            'firstRun' => $this->firstRun,
            'planLabel' => $this->planLabel,
            'requiresPlan' => (bool) config('billing.require_plan_on_register'),
        ]);
    }
}
