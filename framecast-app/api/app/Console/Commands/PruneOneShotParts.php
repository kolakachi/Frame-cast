<?php

namespace App\Console\Commands;

use App\Jobs\GenerateOneShotUgcJob;
use App\Models\Scene;
use Illuminate\Console\Command;

/** Saved one-take segments nobody retried within GenerateOneShotUgcJob::PARTS_DAYS are removed. */
class PruneOneShotParts extends Command
{
    protected $signature = 'ugc:prune-oneshot-parts';

    protected $description = 'Remove one-take segments saved for a retry that never came';

    public function handle(): int
    {
        $pruned = 0;
        Scene::query()->whereNotNull('image_generation_settings_json->oneshot_recipe')
            ->where('updated_at', '<', now()->subDays(GenerateOneShotUgcJob::PARTS_DAYS))
            ->with('project:id,workspace_id')
            ->lazyById(200)->each(function (Scene $scene) use (&$pruned): void {
                // A take still being made keeps its segments whatever their age.
                if (! empty(($scene->image_generation_settings_json ?? [])['in_progress']) || ! $scene->project) return;
                GenerateOneShotUgcJob::forgetParts($scene, (int) $scene->project->workspace_id);
                $pruned++;
            });
        $this->info("Pruned saved segments of {$pruned} take(s).");
        return self::SUCCESS;
    }
}
