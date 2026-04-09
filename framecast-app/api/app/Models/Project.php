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
        'source_type',
        'source_content_raw',
        'source_content_normalized',
        'content_goal',
        'platform_target',
        'duration_target_seconds',
        'aspect_ratio',
        'tone',
        'primary_language',
        'title',
        'script_text',
        'status',
        'current_revision_id',
        'family_id',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'duration_target_seconds' => 'integer',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
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
}
