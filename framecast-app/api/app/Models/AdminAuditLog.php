<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AdminAuditLog extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'admin_user_id',
        'action',
        'target_type',
        'target_id',
        'payload_json',
        'ip_address',
        'user_agent',
    ];

    protected $casts = [
        'payload_json' => 'array',
        'created_at' => 'datetime',
    ];

    public static function record(
        int $adminUserId,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        ?array $payload = null,
        ?string $ip = null,
        ?string $userAgent = null,
    ): self {
        return self::create([
            'admin_user_id' => $adminUserId,
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'payload_json' => $payload,
            'ip_address' => $ip,
            'user_agent' => $userAgent,
        ]);
    }
}
