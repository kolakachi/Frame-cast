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
                $settings = $scene->image_generation_settings_json ?? [];
                if (! empty($settings['in_progress']) || ! $scene->project) return;
                // A delivered take keeps its clips for section remakes until sections_until (SECTION_DAYS).
                if (! empty($settings['sections_until']) && now()->lt(\Carbon\CarbonImmutable::parse($settings['sections_until']))) return;
                GenerateOneShotUgcJob::forgetParts($scene, (int) $scene->project->workspace_id);
                $pruned++;
            });
        // Segments whose take no longer exists at all (removed by a delete that skipped the cleanup, ORPHAN-PARTS).
        $disk = \Illuminate\Support\Facades\Storage::disk('local');
        $orphans = 0;
        foreach ($disk->directories('ugc-oneshot-parts') as $workspaceDir) {
            foreach ($disk->directories($workspaceDir) as $sceneDir) {
                $sceneId = (int) basename($sceneDir);
                if ($sceneId > 0 && ! Scene::query()->whereKey($sceneId)->exists()) {
                    $disk->deleteDirectory($sceneDir);
                    $orphans++;
                }
            }
        }
        $this->info("Pruned saved segments of {$pruned} take(s) and {$orphans} deleted take(s).");
        return self::SUCCESS;
    }
}
