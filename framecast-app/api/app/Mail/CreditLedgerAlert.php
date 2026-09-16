<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sent only when the nightly reconciliation finds the ledger and the balances
 * disagreeing. Silence means they agree — there is no "all clear" mail,
 * because a daily all-clear is a mail nobody reads and therefore a mail that
 * hides the one that matters.
 */
class CreditLedgerAlert extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public readonly string $report)
    {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Credit ledger: something does not reconcile');
    }

    public function content(): Content
    {
        return new Content(view: 'mail.credit-ledger-alert', with: ['report' => $this->report]);
    }
}
