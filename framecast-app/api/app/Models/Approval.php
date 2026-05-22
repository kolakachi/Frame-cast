<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Approval extends Model
{
    protected $fillable = [
        'token',
        'workspace_id',
        'project_id',
        'export_job_id',
        'requested_by_user_id',
        'reviewer_email',
        'reviewer_name',
        'reviewed_by_user_id',
        'status',
        'comment',
        'reviewed_at',
        'expires_at',
        'metadata_json',
    ];

    protected $casts = [
        'reviewed_at'  => 'datetime',
        'expires_at'   => 'datetime',
        'metadata_json'=> 'array',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function exportJob(): BelongsTo
    {
        return $this->belongsTo(ExportJob::class);
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
