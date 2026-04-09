<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Variant extends Model
{
    use HasFactory;

    protected $fillable = [
        'variant_set_id',
        'base_project_id',
        'derived_project_id',
        'variant_label',
        'changed_dimensions_json',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'changed_dimensions_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function variantSet(): BelongsTo
    {
        return $this->belongsTo(VariantSet::class);
    }

    public function baseProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'base_project_id');
    }

    public function derivedProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'derived_project_id');
    }
}
