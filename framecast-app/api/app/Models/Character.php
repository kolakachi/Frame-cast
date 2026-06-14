<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Character extends Model
{
    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'style',
        'reference_asset_id',
        'reference_asset_ids',
        'consistency_method',
        'identity_strength',
        'status',
        'is_auto',
        'consent_acknowledged_at',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'reference_asset_ids' => 'array',
            'is_auto' => 'boolean',
            'consent_acknowledged_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function referenceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'reference_asset_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }
}
