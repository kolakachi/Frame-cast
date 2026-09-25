<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A priced, frozen create request. The developer API refuses to spend
 * without one: the assistant shows the quote to a person, then passes the
 * id back. Expiry and single use are enforced here, not by the client.
 */
class ApiQuote extends Model
{
    public const TTL_MINUTES = 10;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = [
        'id', 'workspace_id', 'api_key_id', 'created_by_user_id', 'payload_json',
        'credits_min', 'credits_max', 'expires_at', 'consumed_at', 'idempotency_key', 'project_id',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'credits_min' => 'integer',
            'credits_max' => 'integer',
            'expires_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public static function newId(): string
    {
        return 'q_'.strtolower((string) Str::ulid());
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}
