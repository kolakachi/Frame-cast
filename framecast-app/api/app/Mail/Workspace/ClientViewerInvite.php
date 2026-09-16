<?php

namespace App\Mail\Workspace;

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Invites a client to watch the workspace an agency keeps for them.
 *
 * Deliberately not the ordinary magic link: that mail welcomes someone to
 * WyvStudio and points at plans, which is wrong twice here — the client is not
 * buying anything, and the account they are being given is someone else's
 * agency's. This one names the agency, because the trust being asked for
 * belongs to them rather than to us.
 */
class ClientViewerInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly Workspace $client,
        public readonly string $agencyName,
        public readonly string $magicLink,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: sprintf('%s invited you to WyvStudio', $this->agencyName),
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.client-viewer-invite', with: [
            'user' => $this->user,
            'clientName' => $this->client->client_label ?: $this->client->name,
            'agencyName' => $this->agencyName,
            'magicLink' => $this->magicLink,
        ]);
    }
}
