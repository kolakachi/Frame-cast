<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MailLogEntry extends Model
{
    protected $table = 'mail_log';

    protected $fillable = [
        'user_id', 'workspace_id', 'email', 'mailable', 'subject',
        'provider_message_id', 'message_id', 'status', 'sent_at', 'delivered_at',
        'first_opened_at', 'last_opened_at', 'open_count', 'bounced_at',
        'complained_at', 'failure_reason', 'meta',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'first_opened_at' => 'datetime',
            'last_opened_at' => 'datetime',
            'bounced_at' => 'datetime',
            'complained_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Status only ever moves forward.
     *
     * Resend does not guarantee event order, so a delivered webhook arriving
     * after an opened one must not walk the row backwards and report an opened
     * email as merely delivered.
     */
    public const RANK = [
        'queued' => 0, 'sent' => 1, 'delivered' => 2, 'opened' => 3,
        'complained' => 4, 'bounced' => 5, 'failed' => 5,
    ];

    public function advanceTo(string $status): void
    {
        $now = self::RANK[$status] ?? 0;
        $have = self::RANK[$this->status] ?? 0;
        if ($now > $have) {
            $this->status = $status;
        }
    }
}
