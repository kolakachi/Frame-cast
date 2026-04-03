<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Template extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'template_type',
        'description',
        'scene_structure_json',
        'caption_style_json',
        'voice_style_json',
        'color_font_rules_json',
        'transition_rules_json',
        'timing_rules_json',
        'supported_formats',
        'supported_languages',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'scene_structure_json' => 'array',
            'caption_style_json' => 'array',
            'voice_style_json' => 'array',
            'color_font_rules_json' => 'array',
            'transition_rules_json' => 'array',
            'timing_rules_json' => 'array',
            'supported_formats' => 'array',
            'supported_languages' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
