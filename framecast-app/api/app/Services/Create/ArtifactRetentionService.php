<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Storage};

/** Private Create artifacts. Unknown runs and all saved revisions retain bytes. */
class ArtifactRetentionService
{
    public function sweep(): int
    {
        abort_unless(config('create.enabled'), 404);
        $keep = [];
        foreach (DB::table('composition_runs')->select('input_json')->cursor() as $run) {
            foreach (json_decode($run->input_json, true)['input_files'] ?? [] as $f) $keep[$f['storage_path']] = true;
        }
        foreach (DB::table('api_quotes')->where('expires_at', '>', now())->select('payload_json')->cursor() as $quote) {
            foreach (json_decode($quote->payload_json, true)['input_files'] ?? [] as $f) $keep[$f['storage_path']] = true;
        }
        foreach (DB::table('composition_revisions')->select('artifact_path')->cursor() as $revision) $keep[$revision->artifact_path] = true;
        // A worker may have uploaded a result but not yet committed its callback.
        $activeRuns = DB::table('composition_runs')->whereIn('status', ConversationService::ACTIVE)->pluck('id')->all();
        $disk = app(\App\Services\Create\CreateStorage::class); $count = 0;
        foreach (['create/inputs', 'create/previews'] as $prefix) {
            foreach ($disk->allFiles($prefix) as $path) {
                if (isset($keep[$path]) || $disk->lastModified($path) >= now()->subDay()->timestamp) continue;
                if ($prefix === 'create/previews' && in_array(explode('/', $path)[2] ?? '', $activeRuns, true)) continue;
                if ($disk->delete($path)) $count++;
            }
        }
        return $count;
    }
}
