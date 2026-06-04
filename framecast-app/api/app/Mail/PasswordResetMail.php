<?php

namespace App\Mail;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class PasswordResetMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $resetLink,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Reset your WyvStudio password');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.password-reset');
    }
}
