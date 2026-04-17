<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Niche extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'icon_emoji',
        'default_template_type',
        'default_visual_style',
        'default_caption_preset_name',
        'default_voice_tone',
        'default_music_mood',
    ];
}
