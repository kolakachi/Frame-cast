<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Series;
use App\Services\Generation\Visual\VisualProviderAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class MatchVisualsJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(VisualProviderAdapter $visualProvider): void
    {
        GenerationProgressed::dispatch($this->projectId, 'visual_match', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project) {
            return;
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        if ($scenes->isEmpty()) {
            return;
        }

        DB::transaction(function () use ($project, $scenes, $visualProvider): void {
            foreach ($scenes as $scene) {
                if ($scene->visual_asset_id) {
                    continue;
                }

                $prompt = $this->buildPrompt($scene, $project);
                $orientation = in_array((string) ($project->aspect_ratio ?? ''), ['16:9'], true) ? 'landscape' : 'portrait';
                $match = $visualProvider->match($prompt, $orientation, 'stock_clip');

                $asset = Asset::query()->create([
                    'workspace_id' => $project->workspace_id,
                    'channel_id' => $project->channel_id,
                    'asset_type' => $match['asset_type'],
                    'title' => 'Matched visual for project '.$project->getKey(),
                    'description' => $prompt,
                    'storage_url' => $match['asset_url'],
                    'thumbnail_url' => $match['thumbnail_url'],
                    'duration_seconds' => $match['duration_seconds'],
                    'dimensions_json' => [
                        'width' => $match['width'],
                        'height' => $match['height'],
                    ],
                    'mime_type' => $match['mime_type'],
                    'tags' => ['matched_visual', $match['provider_key']],
                    'usage_count' => 1,
                    'status' => 'active',
                    'created_by_user_id' => $project->created_by_user_id,
                ]);

                $scene->forceFill([
                    'visual_type' => 'stock_clip',
                    'visual_asset_id' => $asset->getKey(),
                    'visual_prompt' => $prompt,
                ])->save();
            }
        });

        GenerationProgressed::dispatch($this->projectId, 'visual_match', 'completed');
        GenerateTTSJob::dispatch($project->getKey());
    }

    public function failed(\Throwable $exception): void
    {
        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);

        GenerationProgressed::dispatch($this->projectId, 'visual_match', 'failed', $exception->getMessage());
    }

    private function buildPrompt(Scene $scene, Project $project): string
    {
        $sceneText = mb_substr(trim((string) $scene->script_text), 0, 120);
        $tone = $project->tone ?: 'neutral';
        $stylePart = $scene->visual_style ? ", {$scene->visual_style}" : '';

        $brief = is_array($project->visual_brief) ? $project->visual_brief : [];
        $briefAnchor = '';

        if ($brief !== []) {
            $parts = [];

            if (! empty($brief['subject'])) {
                $parts[] = (string) $brief['subject'];
            }

            if (! empty($brief['setting'])) {
                $parts[] = (string) $brief['setting'];
            }

            $keywords = array_filter(
                array_slice((array) ($brief['keywords'] ?? []), 0, 3),
                static fn (mixed $k): bool => is_string($k) && $k !== '',
            );

            if ($keywords !== []) {
                $parts[] = implode(', ', $keywords);
            }

            if ($parts !== []) {
                $briefAnchor = implode(', ', $parts).', ';
            }
        }

        // Prepend series visual identity description when set.
        $seriesPrefix = '';
        if ($project->series_id) {
            $series = Series::query()->find($project->series_id);
            if ($series && $series->visual_description) {
                $seriesPrefix = trim((string) $series->visual_description).', ';
            }
        }

        return trim("{$seriesPrefix}{$briefAnchor}{$tone} style{$stylePart}, {$sceneText}");
    }
}
