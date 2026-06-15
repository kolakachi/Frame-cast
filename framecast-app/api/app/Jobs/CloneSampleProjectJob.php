<?php

namespace App\Jobs;

use App\Models\Project;
use App\Services\Onboarding\SampleProjectCloner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Clones the configured sample project into a freshly-created workspace on
 * signup (B7). Queued so a slow clone never delays registration; guarded so a
 * failure can never break a signup or leave a half-cloned project.
 */
class CloneSampleProjectJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;       // a missed starter project is not worth retry-spam
    public int $timeout = 120;

    public function __construct(
        public readonly int $workspaceId,
        public readonly int $userId,
    ) {
        $this->onQueue('default');
    }

    public function handle(SampleProjectCloner $cloner): void
    {
        $sourceId = (int) config('onboarding.sample_project_id', 0);
        if ($sourceId <= 0) {
            return; // feature disabled
        }

        // Idempotent: only seed a workspace that has no projects yet.
        if (Project::query()->where('workspace_id', $this->workspaceId)->exists()) {
            return;
        }

        try {
            $cloner->clone($sourceId, $this->workspaceId, $this->userId);
        } catch (Throwable $e) {
            // Never let onboarding seeding surface to the user — just log it.
            Log::warning('CloneSampleProjectJob failed', [
                'workspace_id' => $this->workspaceId,
                'source_id'    => $sourceId,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
