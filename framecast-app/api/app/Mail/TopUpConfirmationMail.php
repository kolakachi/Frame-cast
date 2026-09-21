<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class TopUpConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function __construct(
        public readonly string $customerName,
        public readonly int $creditsAdded,
        public readonly int $balanceAfter,
        public readonly string $workspaceName,
    ) {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: number_format($this->creditsAdded).' credits added to your WyvStudio account');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.topup-confirmation', with: [
            'accountUrl' => rtrim((string) config('app.frontend_url'), '/').'/settings',
        ]);
    }
}
