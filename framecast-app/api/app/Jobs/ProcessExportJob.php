<?php

namespace App\Jobs;

use App\Events\ExportProgressed;
use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Variant;
use App\Traits\RendersExportScenes;
use App\Traits\TracksJobFailure;
use Illuminate\Bus\Batch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Bus;

/**
 * Thin orchestrator: validates the export, then fans out one RenderSceneSegmentJob
 * per scene via Bus::batch(). ConcatenateExportJob runs in the batch's then() callback
 * once all scene renders succeed.
 */
class ProcessExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;
    use TracksJobFailure;
    use RendersExportScenes;

    public int $timeout = 120;

    public function __construct(public readonly int $exportJobId)
    {
        $this->onQueue('exports');
    }

    public function handle(): void
    {
        $exportJob = ExportJob::query()->find($this->exportJobId);

        if (! $exportJob) {
            return;
        }

        // Already completed on a prior attempt — nothing to do unless the file vanished.
        if ($exportJob->status === 'completed' && $exportJob->output_asset_id) {
            $outputAsset = Asset::query()->find((int) $exportJob->output_asset_id);

            if ($outputAsset && $this->storageUrlExists((string) $outputAsset->storage_url)) {
                return;
            }

            $exportJob->forceFill([
                'status'           => 'queued',
                'progress_percent' => 0,
                'failure_reason'   => null,
                'output_asset_id'  => null,
                'completed_at'     => null,
            ])->save();
        }

        $exportJob->forceFill([
            'status'           => 'processing',
            'progress_percent' => 5,
            'failure_reason'   => null,
            'started_at'       => now(),
            'completed_at'     => null,
        ])->save();

        $this->syncBatchJob($exportJob);
        $this->dispatchProgress($exportJob, 'processing', 5, 'Export processing started.');

        $project = Project::query()->find($exportJob->project_id);

        if (! $project) {
            throw new \RuntimeException('Project not found for export.');
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        if ($scenes->isEmpty()) {
            throw new \RuntimeException('Project has no scenes to export.');
        }

        $totalDurationSeconds = max(
            1.0,
            (float) $scenes->sum(fn (Scene $s): float => (float) ($s->duration_seconds ?: 0))
        );

        $sceneJobs      = [];
        $elapsedSeconds = 0.0;

        foreach ($scenes->values() as $index => $scene) {
            $sceneJobs[] = new RenderSceneSegmentJob(
                exportJobId: $this->exportJobId,
                sceneId: $scene->getKey(),
                sceneIndex: $index,
                totalScenes: $scenes->count(),
                elapsedSeconds: $elapsedSeconds,
                totalDurationSeconds: $totalDurationSeconds,
            );

            $elapsedSeconds += max(1.0, (float) ($scene->duration_seconds ?: 3.0));
        }

        // Capture primitives only — closures are serialized by Laravel.
        $exportJobId = $this->exportJobId;
        $sceneCount  = $scenes->count();

        Bus::batch($sceneJobs)
            ->then(function () use ($exportJobId, $sceneCount): void {
                ConcatenateExportJob::dispatch($exportJobId, $sceneCount);
            })
            ->catch(function (Batch $batch, \Throwable $e) use ($exportJobId): void {
                rescue(function () use ($exportJobId): void {
                    $exportJob = ExportJob::query()->find($exportJobId);

                    if (! $exportJob || $exportJob->status === 'failed') {
                        return;
                    }

                    $exportJob->forceFill([
                        'status'         => 'failed',
                        'failure_reason' => 'One or more scenes failed to render. Please retry.',
                    ])->save();

                    if ($exportJob->variant_id) {
                        Variant::query()
                            ->whereKey((int) $exportJob->variant_id)
                            ->update(['status' => 'failed']);
                    }

                    ExportProgressed::dispatch(
                        (int) $exportJob->project_id,
                        (int) $exportJob->getKey(),
                        'failed',
                        (int) $exportJob->progress_percent,
                        'One or more scenes failed to render. Please retry.',
                        (string) $exportJob->file_name,
                        $exportJob->failure_reason
                    );
                }, false);
            })
            ->onQueue('exports')
            ->dispatch();
    }

    public function failed(\Throwable $exception): void
    {
        report($exception);

        $exportJob = ExportJob::query()->find($this->exportJobId);

        $this->recordFailureTrace(
            $exception,
            'export',
            $this->exportJobId,
            $exportJob?->workspace_id,
            $exportJob?->project_id,
        );

        if (! $exportJob) {
            return;
        }

        $userSafeFailure = $this->summarizeFailureForUser($exception);

        $exportJob->forceFill([
            'status'         => 'failed',
            'failure_reason' => $userSafeFailure,
        ])->save();

        if ($exportJob->variant_id) {
            Variant::query()
                ->whereKey((int) $exportJob->variant_id)
                ->update(['status' => 'failed']);
        }

        $this->syncBatchJob($exportJob->fresh());

        $this->dispatchProgress(
            $exportJob,
            'failed',
            (int) $exportJob->progress_percent,
            $userSafeFailure
        );
    }
}
