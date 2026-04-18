<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApiUsageEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'user_id',
        'provider',
        'service',
        'operation',
        'model',
        'status',
        'prompt_tokens',
        'completion_tokens',
        'total_tokens',
        'units',
        'estimated_cost_usd',
        'error_code',
        'error_message',
        'metadata_json',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'workspace_id' => 'integer',
            'project_id' => 'integer',
            'user_id' => 'integer',
            'prompt_tokens' => 'integer',
            'completion_tokens' => 'integer',
            'total_tokens' => 'integer',
            'units' => 'integer',
            'estimated_cost_usd' => 'decimal:6',
            'metadata_json' => 'array',
            'occurred_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
