<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandKit extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'name',
        'primary_color',
        'secondary_color',
        'accent_color',
        'font_primary',
        'font_secondary',
        'logo_asset_id',
        'default_caption_style',
        'default_voice_profile_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
