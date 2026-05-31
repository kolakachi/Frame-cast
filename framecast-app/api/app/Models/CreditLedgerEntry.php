<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CreditLedgerEntry extends Model
{
    protected $table = 'credit_ledger';

    protected $fillable = [
        'workspace_id',
        'user_id',
        'project_id',
        'scene_id',
        'operation',
        'credits',
        'balance_after',
        'upstream_cost_usd',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'credits'           => 'integer',
            'balance_after'     => 'integer',
            'upstream_cost_usd' => 'decimal:6',
            'metadata'          => 'array',
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

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function scene(): BelongsTo
    {
        return $this->belongsTo(Scene::class);
    }
}
