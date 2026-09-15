<?php

namespace App\Mail\Affiliate;

use App\Models\Affiliate;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Everything a new affiliate needs, sent once when they are created.
 *
 * This was a copy-and-paste job: the operator hit a button, then wrote the
 * email themselves. Fine for one affiliate and a reliable source of mistakes
 * for ten — a key retyped wrong is an affiliate who cannot sign in and assumes
 * we are disorganised.
 *
 * It carries the access key in plain text, which is deliberate. The key is a
 * read-only credential for figures about their own referrals, they have no way
 * to set one themselves, and an email they can search for beats a key they
 * have to ask for twice.
 */
class AffiliateWelcomeMail extends Mailable implements ShouldQueue
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly Affiliate $affiliate,
        public readonly string $accessKey,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Your WyvStudio referral link');
    }

    public function content(): Content
    {
        $base = rtrim((string) config('app.frontend_url', 'https://app.wyvstudio.com'), '/');

        return new Content(view: 'mail.affiliate.welcome', with: [
            'name' => $this->affiliate->name,
            'code' => $this->affiliate->code,
            'accessKey' => $this->accessKey,
            'rate' => (float) $this->affiliate->commission_percent,
            'link' => rtrim((string) config('app.marketing_url', 'https://wyvstudio.com'), '/').'/?ref='.$this->affiliate->code,
            'dashboard' => $base.'/affiliates',
            'holdDays' => (int) config('affiliates.hold_days', 21),
            'cycleDays' => (int) config('affiliates.cycle_days', 14),
            'payoutCurrency' => (string) config('affiliates.payout_currency', 'NGN'),
        ]);
    }
}
