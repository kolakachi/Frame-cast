<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ScheduledPost extends Model
{
    protected static function booted(): void
    {
        static::updated(function (ScheduledPost $post): void {
            if (! $post->wasChanged('status') || $post->status !== 'published') {
                return;
            }
            $ownerId = \App\Models\User::where('workspace_id', $post->workspace_id)->orderBy('id')->value('id');
            \App\Services\Analytics\PostHogService::capture(
                $ownerId ? (int) $ownerId : null,
                'post_published',
                ['platform' => $post->platform ?? null, 'post_id' => $post->getKey()],
                $post->workspace_id,
            );
        });
    }

    protected $fillable = [
        'workspace_id',
        'project_id',
        'export_job_id',
        'social_account_id',
        'platform',
        'status',
        'scheduled_at',
        'published_at',
        'platform_post_id',
        'platform_post_url',
        'caption',
        'title',
        'description',
        'category',
        'visibility',
        'hashtags',
        'failure_reason',
        'attempt_count',
    ];

    protected function casts(): array
    {
        return [
            'hashtags'     => 'array',
            'scheduled_at' => 'datetime',
            'published_at' => 'datetime',
            'created_at'   => 'datetime',
            'updated_at'   => 'datetime',
        ];
    }

    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function exportJob(): BelongsTo
    {
        return $this->belongsTo(ExportJob::class);
    }

    public function socialAccount(): BelongsTo
    {
        return $this->belongsTo(SocialAccount::class);
    }
}
