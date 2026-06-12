<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CharacterImageGeneration extends Model
{
    protected $fillable = [
        'workspace_id',
        'character_id',
        'user_id',
        'prompt',
        'style',
        'model_key',
        'aspect_ratio',
        'quality',
        'set_as_reference',
        'used_reference',
        'status',
        'result_asset_id',
        'error_message',
        'credits_charged',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'set_as_reference' => 'boolean',
            'used_reference'   => 'boolean',
            'credits_charged'  => 'integer',
            'started_at'       => 'datetime',
            'completed_at'     => 'datetime',
        ];
    }

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }

    public function resultAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'result_asset_id');
    }
}
