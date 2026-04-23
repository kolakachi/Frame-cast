<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SeriesCharacter extends Model
{
    protected $fillable = [
        'series_id',
        'name',
        'visual_description',
        'personality_notes',
        'appearance_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'appearance_json' => 'array',
        ];
    }

    public function series(): BelongsTo
    {
        return $this->belongsTo(Series::class);
    }
}
