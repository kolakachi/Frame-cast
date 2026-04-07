<?php

namespace App\Jobs;

use App\Events\ExportProgressed;
use App\Models\ExportJob;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ProcessExportJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public string $queue = 'exports';

    public function __construct(public readonly int $exportJobId)
    {
    }

    public function handle(): void
    {
        $exportJob = ExportJob::query()->find($this->exportJobId);

        if (! $exportJob) {
            return;
        }

        $exportJob->forceFill([
            'status' => 'processing',
            'progress_percent' => 5,
            'started_at' => now(),
        ])->save();

        ExportProgressed::dispatch(
            (int) $exportJob->project_id,
            (int) $exportJob->getKey(),
            'processing',
            5,
            'Export processing started.'
        );
    }
}
