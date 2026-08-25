<?php

namespace Database\Seeders;

use App\Models\Workspace;
use App\Services\Onboarding\WorkspaceDefaults;
use Illuminate\Database\Seeder;

/**
 * Backfills the default music library into EXISTING workspaces.
 *
 * New workspaces are provisioned automatically by
 * ProvisionWorkspaceDefaultsJob at signup — this seeder is only for catching up
 * workspaces created before that wiring existed. The track list itself lives in
 * WorkspaceDefaults so both paths stay in sync.
 */
class MusicTrackSeeder extends Seeder
{
    public function run(WorkspaceDefaults $defaults): void
    {
        $workspaceIds = Workspace::query()->pluck('id');

        if ($workspaceIds->isEmpty()) {
            $this->command?->info('MusicTrackSeeder: no workspaces found — skipping.');

            return;
        }

        foreach ($workspaceIds as $workspaceId) {
            $defaults->provisionMusic((int) $workspaceId);
        }

        $this->command?->info(sprintf(
            'MusicTrackSeeder: provisioned %d track(s) across %d workspace(s).',
            count(WorkspaceDefaults::MUSIC_TRACKS),
            $workspaceIds->count(),
        ));
    }
}
