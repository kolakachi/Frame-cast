<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VariantSet extends Model
{
    use HasFactory;

    protected $fillable = [
        'base_project_id',
        'generation_dimensions',
        'variant_count_requested',
        'lock_rules_json',
        'status',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'generation_dimensions' => 'array',
            'variant_count_requested' => 'integer',
            'lock_rules_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function baseProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'base_project_id');
    }

    public function variants(): HasMany
    {
        return $this->hasMany(Variant::class);
    }
}
