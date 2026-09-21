<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Export\ProjectExportService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/** Finish the initial download without requiring the user to keep a tab open. */
class FinishGeneratedVideoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 240;
    public int $timeout = 60;

    public function __construct(public int $projectId)
    {
        $this->onQueue('generation');
    }

    public function handle(ProjectExportService $exports): void
    {
        $project = Project::query()->find($this->projectId);
        if (! $project || ! $project->usesAutomaticFinish() || in_array($project->status, ['failed', 'draft'], true) || data_get($project->visual_brief, 'ugc_revision_at')) return;
        if (! $exports->finishInitial($project)) $this->release(30);
    }

    public function failed(?\Throwable $exception): void
    {
        \Illuminate\Support\Facades\DB::transaction(function () {
            $project = Project::query()->lockForUpdate()->find($this->projectId);
            if (! $project || ! $project->usesAutomaticFinish() || $project->status !== 'ready_for_review'
                || \App\Models\ExportJob::query()->where('project_id', $this->projectId)->exists()) return;
            \App\Models\ExportJob::query()->create([
                'workspace_id' => $project->workspace_id, 'project_id' => $project->id,
                'aspect_ratio' => $project->aspect_ratio ?: '9:16',
                'language' => $project->primary_language ?: 'en', 'file_name' => 'video.mp4',
                'status' => 'failed', 'progress_percent' => 0, 'priority' => 0,
                'watermark_enabled' => true, 'queued_at' => now(),
                'failure_reason' => 'Finishing took longer than expected. Review your scenes or retry finishing the video.',
            ]);
        });
    }
}
