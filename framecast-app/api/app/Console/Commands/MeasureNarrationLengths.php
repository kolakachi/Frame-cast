<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Scene;
use App\Services\Generation\TTS\OpenAITTSAdapter;
use App\Services\Media\StorageService;
use Illuminate\Console\Command;

/**
 * Narration from the OpenAI voice was stored with a word-count estimate of its length, not its real one (fixed
 * 2026-11-17). The export always measured the file, but the editor's timeline, preview pacing and transition timing
 * use the stored length, so they drifted (project 59: 95.6 s stored, 86 s spoken). This measures each such file and
 * stores its real length on the asset and on the scenes it narrates.
 *
 * A changed scene length also marks that project's earlier exports as out of date (ExportFreshnessService).
 */
class MeasureNarrationLengths extends Command
{
    protected $signature = 'tts:measure-lengths {--project=* : Only these projects} {--dry-run : Report, change nothing}';

    protected $description = 'Store the real length of OpenAI-voice narration on its asset and scenes';

    public function handle(StorageService $storage): int
    {
        $scenes = Scene::query()->whereNotNull('voice_settings_json')
            ->when($this->option('project'), fn ($q, $ids) => $q->whereIn('project_id', array_map('intval', $ids)))
            ->orderBy('project_id')->orderBy('scene_order')->get();
        $dry = (bool) $this->option('dry-run');
        $seen = [];
        $changed = 0;
        $before = 0.0;
        $after = 0.0;

        foreach ($scenes as $scene) {
            $id = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
            $asset = $id ? Asset::query()->find($id) : null;
            if (! $asset || ! in_array('tts', (array) $asset->tags, true) || ! in_array('openai', (array) $asset->tags, true)) continue;

            if (! array_key_exists($id, $seen)) {
                $bytes = rescue(fn () => $storage->get((string) $asset->storage_url), null, false);
                $seen[$id] = is_string($bytes) && $bytes !== '' ? OpenAITTSAdapter::measure($bytes) : null;
                if ($seen[$id] !== null && ! $dry && abs((float) $asset->duration_seconds - $seen[$id]) > 0.01) {
                    $asset->forceFill(['duration_seconds' => $seen[$id]])->save();
                }
            }
            $real = $seen[$id];
            if ($real === null) {
                $this->warn("scene {$scene->id}: narration {$id} could not be read; left as it is");
                continue;
            }
            // A scene playing only its clip's sound runs as long as the clip, not the narration.
            if (data_get($scene->voice_settings_json, 'clip_sound') === 'only') continue;
            $before += (float) $scene->duration_seconds;
            $after += $real;
            if (abs((float) $scene->duration_seconds - $real) > 0.01) {
                $changed++;
                if (! $dry) $scene->forceFill(['duration_seconds' => $real])->save();
            }
        }

        $this->info(sprintf('%s%d scene(s) %s; their total length %.1f s → %.1f s (%d narration file(s) read).',
            $dry ? '[dry run] ' : '', $changed, $dry ? 'would change' : 'changed', $before, $after, count($seen)));

        return self::SUCCESS;
    }
}
