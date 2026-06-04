<?php

namespace App\Mail;

use App\Models\ModerationEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ModerationDigestMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    /**
     * @param array<int,ModerationEvent> $alerts
     */
    public function __construct(public readonly array $alerts)
    {
    }

    public function envelope(): Envelope
    {
        $count = count($this->alerts);
        return new Envelope(subject: "[WyvStudio T&S] {$count} new pattern alert" . ($count === 1 ? '' : 's'));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.moderation.digest', with: ['alerts' => $this->alerts]);
    }
}
