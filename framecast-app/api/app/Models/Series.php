<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Series extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'channel_id',
        'name',
        'description',
        'concept_text',
        'audience_text',
        'tone',
        'episode_format_template',
        'always_include_tags',
        'never_include_tags',
        'memory_window',
        'auto_summarise',
        'status',
        'created_by_user_id',
        'platform_targets',
        'aspect_ratio',
        'duration_target_seconds',
        'posting_cadence',
        'visual_mode',
        'visual_style',
        'visual_palette',
        'visual_description',
        'default_voice_profile_id',
        'default_caption_preset_id',
        'default_music_setting',
        'default_music_volume',
        'default_language',
    ];

    protected function casts(): array
    {
        return [
            'always_include_tags' => 'array',
            'never_include_tags' => 'array',
            'platform_targets' => 'array',
            'memory_window' => 'integer',
            'duration_target_seconds' => 'integer',
            'default_voice_profile_id' => 'integer',
            'default_caption_preset_id' => 'integer',
            'default_music_volume' => 'integer',
            'auto_summarise' => 'boolean',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function characters(): HasMany
    {
        return $this->hasMany(SeriesCharacter::class);
    }

    public function episodes(): HasMany
    {
        return $this->hasMany(Project::class);
    }
}
