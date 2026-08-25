<?php

namespace App\Jobs;

use App\Services\Onboarding\WorkspaceDefaults;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Provisions a brand-new workspace with its default content (today: the music
 * library). Queued so it never delays registration and guarded so a failure
 * can never break a signup.
 *
 * Dispatch this from EVERY path that creates a workspace. The music library
 * was previously only ever populated by a hand-run seeder, so every workspace
 * created after that one run had an empty music picker.
 */
class ProvisionWorkspaceDefaultsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 2;
    public int $timeout = 60;

    public function __construct(public readonly int $workspaceId)
    {
        $this->onQueue('default');
    }

    public function handle(WorkspaceDefaults $defaults): void
    {
        try {
            $defaults->provisionMusic($this->workspaceId);
        } catch (Throwable $e) {
            Log::warning('ProvisionWorkspaceDefaultsJob failed', [
                'workspace_id' => $this->workspaceId,
                'error'        => $e->getMessage(),
            ]);
        }
    }
}
