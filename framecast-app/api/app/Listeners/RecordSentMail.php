<?php

namespace App\Listeners;

use App\Models\MailLogEntry;
use App\Models\User;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Log;

/**
 * Write a row for every email that leaves the application.
 *
 * Hooked to the framework event rather than to each call site, so a mailable
 * added next month is logged without anyone remembering to log it — which is
 * the only version of this that stays true.
 */
class RecordSentMail
{
    public function handle(MessageSent $event): void
    {
        // Never let bookkeeping cost a delivery: the mail has already gone by
        // the time this runs, and throwing here would only lose the record.
        try {
            $message = $event->message;
            $subject = (string) $message->getSubject();
            $mailable = $event->data['__laravel_mailable'] ?? null;

            foreach ($message->getTo() ?? [] as $address) {
                $email = strtolower($address->getAddress());
                $user = User::query()->whereRaw('LOWER(email) = ?', [$email])
                    ->first(['id', 'workspace_id']);

                MailLogEntry::query()->create([
                    'user_id' => $user?->id,
                    'workspace_id' => $user?->workspace_id,
                    'email' => $email,
                    'mailable' => $mailable ? class_basename($mailable) : null,
                    'subject' => mb_substr($subject, 0, 255),
                    'message_id' => mb_substr((string) $message->getHeaders()->getHeaderBody('Message-ID'), 0, 255) ?: null,
                    'status' => 'sent',
                    'sent_at' => now(),
                ]);
            }
        } catch (\Throwable $e) {
            Log::warning('RecordSentMail failed', ['error' => mb_substr($e->getMessage(), 0, 160)]);
        }
    }
}
