<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class LocalizationGroup extends Model
{
    use HasFactory;

    protected $fillable = [
        'source_project_id',
        'source_language',
        'target_languages',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'target_languages' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function sourceProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'source_project_id');
    }

    public function links(): HasMany
    {
        return $this->hasMany(LocalizationLink::class);
    }
}
