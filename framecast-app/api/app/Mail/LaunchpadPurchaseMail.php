<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class LaunchpadPurchaseMail extends Mailable implements ShouldQueue
{
    use Queueable;

    public $tries = 5;

    public function __construct(
        public readonly string $purchaseEmail,
        public readonly string $productName,
        public readonly string $receipt,
    ) {
        $this->afterCommit();
    }

    public function backoff(): array
    {
        return [60, 300, 900, 1800];
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your WyvStudio purchase — how to sign in');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.launchpad-purchase', with: [
            // A lasting entry point: the buyer requests a fresh magic link when ready.
            'loginUrl' => rtrim((string) config('app.frontend_url'), '/').'/login?source=launchpad',
            'guideUrl' => 'https://special.wyvstudio.com/thank-you',
        ]);
    }
}
