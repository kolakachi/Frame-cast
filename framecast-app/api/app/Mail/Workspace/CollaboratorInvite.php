<?php

namespace App\Mail\Workspace;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/** Invites a person onto an agency's team as a collaborator (phase 3, 2026-10-09). The agency's name carries it. */
class CollaboratorInvite extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly User $user,
        public readonly string $agencyName,
        public readonly ?int $allowance,
        public readonly string $magicLink,
    ) {
    }

    public function envelope(): Envelope
    {
        return new Envelope(subject: sprintf('%s added you to their team on WyvStudio', $this->agencyName));
    }

    public function content(): Content
    {
        return new Content(view: 'mail.collaborator-invite', with: [
            'user' => $this->user, 'agencyName' => $this->agencyName, 'allowance' => $this->allowance, 'magicLink' => $this->magicLink,
        ]);
    }
}
