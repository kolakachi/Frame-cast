<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkspaceNotification extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'workspace_id',
        'user_id',
        'type',
        'title',
        'message',
        'payload_json',
        'is_read',
        'read_at',
    ];

    protected function casts(): array
    {
        return [
            'payload_json' => 'array',
            'is_read' => 'boolean',
            'read_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
