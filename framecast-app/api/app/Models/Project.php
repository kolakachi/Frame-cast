<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'channel_id',
        'brand_kit_id',
        'template_id',
        'niche_id',
        'source_type',
        'source_content_raw',
        'source_content_normalized',
        'source_image_asset_ids',
        'visual_generation_mode',
        'ai_broll_style',
        'content_goal',
        'platform_target',
        'duration_target_seconds',
        'aspect_ratio',
        'tone',
        'primary_language',
        'title',
        'script_text',
        'status',
        'generation_status_json',
        'current_revision_id',
        'family_id',
        'created_by_user_id',
        'music_asset_id',
        'music_settings_json',
        'series_id',
        'series_episode_number',
        'series_episode_summary',
    ];

    protected function casts(): array
    {
        return [
            'duration_target_seconds' => 'integer',
            'niche_id' => 'integer',
            'source_image_asset_ids' => 'array',
            'music_asset_id' => 'integer',
            'music_settings_json' => 'array',
            'generation_status_json' => 'array',
            'visual_brief' => 'array',
            'series_id' => 'integer',
            'series_episode_number' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }

    public function hookOptions(): HasMany
    {
        return $this->hasMany(ProjectHookOption::class);
    }

    public function scenes(): HasMany
    {
        return $this->hasMany(Scene::class);
    }

    public function variantSets(): HasMany
    {
        return $this->hasMany(VariantSet::class, 'base_project_id');
    }

    public function localizationGroups(): HasMany
    {
        return $this->hasMany(LocalizationGroup::class, 'source_project_id');
    }
}
