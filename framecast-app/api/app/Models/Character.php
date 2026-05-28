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
        'reference_asset_id',
        'consistency_method',
        'status',
        'created_by_user_id',
    ];

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
