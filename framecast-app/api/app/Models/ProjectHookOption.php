<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectHookOption extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'project_id',
        'sort_order',
        'hook_text',
        'hook_score',
        'hook_score_reason',
    ];

    protected function casts(): array
    {
        return [
            'hook_score' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
