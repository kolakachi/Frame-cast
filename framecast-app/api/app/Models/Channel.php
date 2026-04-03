<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Channel extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'description',
        'default_language',
        'platform_targets',
        'default_voice_profile_id',
        'default_caption_preset_id',
        'allowed_template_ids',
        'brand_kit_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'platform_targets' => 'array',
            'allowed_template_ids' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
