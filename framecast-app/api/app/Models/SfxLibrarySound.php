<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SfxLibrarySound extends Model
{
    protected $fillable = [
        'name',
        'category',
        'storage_url',
        'duration_seconds',
        'file_size_bytes',
        'mime_type',
        'source',
        'created_by_user_id',
        'status',
    ];

    protected $casts = [
        'duration_seconds' => 'float',
        'file_size_bytes'  => 'integer',
    ];
}
