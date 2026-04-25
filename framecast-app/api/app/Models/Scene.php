<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scene extends Model
{
    use HasFactory;

    protected $fillable = [
        'project_id',
        'scene_order',
        'scene_type',
        'label',
        'script_text',
        'duration_seconds',
        'voice_profile_id',
        'voice_settings_json',
        'caption_settings_json',
        'visual_type',
        'visual_asset_id',
        'sound_asset_id',
        'visual_prompt',
        'visual_style',
        'image_generation_settings_json',
        'motion_settings_json',
        'transition_rule',
        'status',
        'locked_fields_json',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'float',
            'voice_settings_json' => 'array',
            'caption_settings_json' => 'array',
            'image_generation_settings_json' => 'array',
            'motion_settings_json' => 'array',
            'locked_fields_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
