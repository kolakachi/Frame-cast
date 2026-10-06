<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** A vendor account of ours failed (out of credit, a bad key), recovered, or the day's vendor failures (VendorAlerts). */
class VendorAlertMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $title, public readonly string $vendor, public readonly string $kind, public readonly string $text, public readonly array $extra = [])
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.vendor-alert', with: ['title' => $this->title, 'vendor' => $this->vendor, 'kind' => $this->kind, 'text' => $this->text, 'extra' => $this->extra]);
    }
}
