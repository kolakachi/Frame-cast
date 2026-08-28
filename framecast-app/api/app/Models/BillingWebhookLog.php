<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One row per inbound billing webhook. See the migration for why this exists
 * in the database rather than in storage/logs.
 */
class BillingWebhookLog extends Model
{
    public const UPDATED_AT = null;

    public const PROVIDER_KELVIQ  = 'kelviq';
    public const PROVIDER_APPSUMO = 'appsumo';

    /** How long rows are kept — payloads carry customer PII. */
    public const RETENTION_DAYS = 90;

    protected $fillable = [
        'provider',
        'event',
        'event_id',
        'signature_valid',
        'outcome',
        'http_status',
        'message',
        'workspace_id',
        'payload',
        'ip',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'signature_valid' => 'boolean',
            'payload'         => 'array',
            'created_at'      => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Record a delivery. Best-effort by design: logging must never be the
     * reason a webhook fails, so callers wrap this in rescue() and a write
     * failure here is swallowed rather than surfaced to the provider.
     *
     * @param  array<string, mixed>|null  $payload
     */
    public static function record(
        string $provider,
        string $outcome,
        ?array $payload = null,
        ?string $event = null,
        ?string $eventId = null,
        bool $signatureValid = false,
        int $httpStatus = 200,
        ?string $message = null,
        ?int $workspaceId = null,
        ?string $ip = null,
    ): void {
        rescue(fn () => self::query()->create([
            'provider'        => $provider,
            'event'           => $event,
            'event_id'        => $eventId,
            'signature_valid' => $signatureValid,
            'outcome'         => $outcome,
            'http_status'     => $httpStatus,
            'message'         => $message ? mb_substr($message, 0, 2000) : null,
            'workspace_id'    => $workspaceId,
            'payload'         => $payload,
            'ip'              => $ip,
            'created_at'      => now(),
        ]), null, false);
    }
}
