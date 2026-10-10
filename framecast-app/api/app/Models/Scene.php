<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Scene extends Model
{
    use HasFactory;

    protected static function booted(): void
    {
        static::saving(fn (Scene $scene) => Project::find($scene->project_id)?->assertSceneEditor());
        // A new picture ends an earlier animation attempt's error and cancel flag: they were about the picture that is
        // gone, and a leftover error blocked export (2026-10-10: "Animation needs a still image" stayed on a scene
        // swapped back to an image). Not while an animation runs, and not when this save records a new error.
        static::saving(function (Scene $scene) {
            if (! $scene->exists || ! $scene->isDirty('visual_asset_id')) return;
            $now = $scene->image_generation_settings_json ?? [];
            $was = $scene->getOriginal('image_generation_settings_json') ?? [];
            if (! empty($now['animation_in_progress'])) return;
            if (($now['animation_last_error'] ?? null) !== ($was['animation_last_error'] ?? null)) return;
            if (($now['animation_last_error'] ?? null) === null && empty($now['animation_cancel_requested'])) return;
            $scene->image_generation_settings_json = array_merge($now, ['animation_last_error' => null, 'animation_cancel_requested' => false]);
        });
    }

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
        'character_id',
        'character_ids',
        'sound_asset_id',
        'sound_settings_json',
        'visual_prompt',
        'visual_style',
        'custom_visual_style',
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
            'sound_settings_json' => 'array',
            'character_ids' => 'array',
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

    public function character(): BelongsTo
    {
        return $this->belongsTo(Character::class);
    }
}
