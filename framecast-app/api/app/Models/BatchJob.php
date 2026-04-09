<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class BatchJob extends Model
{
    use HasFactory;

    protected $fillable = [
        'workspace_id',
        'job_type',
        'source_entity_type',
        'source_entity_id',
        'requested_count',
        'completed_count',
        'failed_count',
        'status',
        'failure_summary_json',
        'created_by_user_id',
    ];

    protected function casts(): array
    {
        return [
            'requested_count' => 'integer',
            'completed_count' => 'integer',
            'failed_count' => 'integer',
            'failure_summary_json' => 'array',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }

    public function exportJobs(): HasMany
    {
        return $this->hasMany(ExportJob::class);
    }
}
