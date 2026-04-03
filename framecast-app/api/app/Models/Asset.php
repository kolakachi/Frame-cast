<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Asset extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'channel_id',
        'asset_type',
        'title',
        'description',
        'storage_url',
        'thumbnail_url',
        'duration_seconds',
        'dimensions_json',
        'file_size_bytes',
        'mime_type',
        'tags',
        'collection_ids',
        'usage_count',
        'restriction_scope',
        'status',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'duration_seconds' => 'float',
            'dimensions_json' => 'array',
            'tags' => 'array',
            'collection_ids' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }
}
