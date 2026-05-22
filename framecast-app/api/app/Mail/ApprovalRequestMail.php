<?php

namespace App\Mail;

use App\Models\Approval;
use App\Models\Project;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ApprovalRequestMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public readonly Approval $approval,
        public readonly Project $project,
        public readonly string $publicUrl,
        public readonly User $requester,
    ) {}

    public function envelope(): Envelope
    {
        $requesterName = $this->requester->name ?: $this->requester->email;
        return new Envelope(
            subject: "{$requesterName} would like your approval on a video",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'mail.approval-request');
    }
}
