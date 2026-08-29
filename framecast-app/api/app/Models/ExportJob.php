<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ExportJob extends Model
{
    /** Export outcome analytics — see Project::booted for the rationale. */
    protected static function booted(): void
    {
        static::updated(function (ExportJob $job): void {
            if (! $job->wasChanged('status') || ! in_array($job->status, ['completed', 'failed'], true)) {
                return;
            }
            \App\Services\Analytics\PostHogService::capture(
                $job->created_by_user_id,
                $job->status === 'completed' ? 'export_completed' : 'export_failed',
                array_filter([
                    'project_id'   => $job->project_id,
                    'aspect_ratio' => $job->aspect_ratio,
                    'reason'       => $job->status === 'failed' ? mb_substr((string) $job->failure_reason, 0, 120) : null,
                ]),
                $job->workspace_id,
            );
        });
    }

    use HasFactory;

    public $timestamps = false;

    protected $fillable = [
        'workspace_id',
        'project_id',
        'variant_id',
        'batch_job_id',
        'aspect_ratio',
        'language',
        'file_name',
        'watermark_enabled',
        'status',
        'progress_percent',
        'failure_reason',
        'output_asset_id',
        'priority',
        'queued_at',
        'started_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'watermark_enabled' => 'boolean',
            'progress_percent' => 'integer',
            'priority' => 'integer',
            'queued_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(Variant::class);
    }

    public function batchJob(): BelongsTo
    {
        return $this->belongsTo(BatchJob::class);
    }
}
