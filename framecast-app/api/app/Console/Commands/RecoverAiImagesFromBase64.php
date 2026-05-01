<?php

namespace App\Console\Commands;

use App\Models\Asset;
use App\Models\Scene;
use App\Services\Media\StorageService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RecoverAiImagesFromBase64 extends Command
{
    protected $signature = 'images:recover-base64 {--project= : Limit to a specific project ID} {--dry-run : Preview without writing}';

    protected $description = 'Recover AI images whose base64 payload was stranded in last_error due to the data-URI cURL bug';

    public function handle(StorageService $storage): int
    {
        $query = Scene::query()
            ->whereNull('visual_asset_id')
            ->whereNotNull('image_generation_settings_json')
            ->whereRaw("length(image_generation_settings_json->>'last_error') > 5000");

        if ($projectId = $this->option('project')) {
            $query->where('project_id', (int) $projectId);
        }

        $scenes = $query->with('project')->get();

        if ($scenes->isEmpty()) {
            $this->info('No recoverable scenes found.');
            return 0;
        }

        $this->info("Found {$scenes->count()} scene(s) with stranded base64 data.");

        $recovered = 0;
        $failed    = 0;

        foreach ($scenes as $scene) {
            $error = $scene->image_generation_settings_json['last_error'] ?? '';

            // Extract base64 payload from the cURL error string
            if (preg_match('/data:image\/[^;]+;base64,([A-Za-z0-9+\/=]+)/', $error, $m)) {
                $b64 = $m[1];
            } else {
                // Might be raw base64 without the data-URI prefix
                $stripped = preg_replace('/.*for\s+/s', '', $error);
                $b64 = trim((string) $stripped);
            }

            $contents = base64_decode($b64, true);

            if ($contents === false || strlen($contents) < 1000) {
                $this->warn("  Scene {$scene->id}: could not decode base64 — skipping.");
                $failed++;
                continue;
            }

            $this->line("  Scene {$scene->id} (project {$scene->project_id}): " . number_format(strlen($contents)) . ' bytes');

            if ($this->option('dry-run')) {
                $recovered++;
                continue;
            }

            DB::transaction(function () use ($scene, $contents, $storage, &$recovered): void {
                $path = sprintf(
                    'workspaces/%s/assets/ai-images/%s.png',
                    $scene->project->workspace_id,
                    Str::uuid(),
                );

                $storagePath = $storage->put($path, $contents);

                $settings = $scene->image_generation_settings_json ?? [];
                $style = $settings['style'] ?? $scene->visual_style ?? 'cinematic';

                $asset = Asset::query()->create([
                    'workspace_id'       => $scene->project->workspace_id,
                    'channel_id'         => $scene->project->channel_id,
                    'asset_type'         => 'image',
                    'title'              => "AI Image (recovered) — {$style} — Scene {$scene->scene_order}",
                    'storage_url'        => $storagePath,
                    'thumbnail_url'      => $storagePath,
                    'mime_type'          => 'image/png',
                    'tags'               => ['ai_generated', 'recovered', $style],
                    'source'             => 'ai_generated',
                    'usage_count'        => 1,
                    'status'             => 'active',
                    'created_by_user_id' => $scene->project->created_by_user_id,
                ]);

                $scene->forceFill([
                    'visual_type'     => 'ai_image',
                    'visual_asset_id' => $asset->getKey(),
                    'image_generation_settings_json' => array_merge($settings, [
                        'in_progress' => false,
                        'needs_visual' => false,
                        'last_error'  => null,
                        'recovered'   => true,
                    ]),
                ])->save();

                $recovered++;
            });
        }

        $this->info("Done — recovered: {$recovered}, failed: {$failed}.");

        return $failed > 0 ? 1 : 0;
    }
}
