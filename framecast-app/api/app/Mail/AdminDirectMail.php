<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * A hand-written email from the admin panel — single customer or broadcast.
 *
 * Body arrives as PRE-RENDERED safe HTML: the controller escapes the admin's
 * plain text, substitutes {name}, and converts line breaks. The mailable
 * itself never touches raw input, so there is exactly one place where
 * escaping can go wrong, and it is not here.
 */
class AdminDirectMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly string $subjectLine,
        public readonly string $bodyHtml,
    ) {
        $this->onQueue('default');
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.admin-direct', with: ['bodyHtml' => $this->bodyHtml]);
    }
}
