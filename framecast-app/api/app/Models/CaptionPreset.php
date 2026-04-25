<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CaptionPreset extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'preset_type',
        'font',
        'font_size_rule',
        'highlight_mode',
        'highlight_color',
        'caption_color',
        'caption_position',
        'animation_type',
        'safe_area_profile',
        'line_break_rules_json',
    ];

    protected function casts(): array
    {
        return [
            'line_break_rules_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
