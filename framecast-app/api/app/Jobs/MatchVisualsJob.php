<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
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
                $prompt = $this->buildPrompt($scene, $project);
                $match = $visualProvider->match($prompt, 'portrait');

                $asset = Asset::query()->create([
                    'workspace_id' => $project->workspace_id,
                    'channel_id' => $project->channel_id,
                    'asset_type' => 'image',
                    'title' => 'Matched visual for project '.$project->getKey(),
                    'description' => $prompt,
                    'storage_url' => $match['asset_url'],
                    'thumbnail_url' => $match['thumbnail_url'],
                    'duration_seconds' => $match['duration_seconds'],
                    'dimensions_json' => [
                        'width' => $match['width'],
                        'height' => $match['height'],
                    ],
                    'mime_type' => 'image/jpeg',
                    'tags' => ['matched_visual', $match['provider_key']],
                    'usage_count' => 1,
                    'status' => 'active',
                    'created_by_user_id' => $project->created_by_user_id,
                ]);

                $scene->forceFill([
                    'visual_type' => 'image_montage',
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
        $sceneLabel = $scene->label ?: 'scene';
        $sceneText = mb_substr(trim((string) $scene->script_text), 0, 160);
        $tone = $project->tone ?: 'neutral';

        return trim("{$sceneLabel}, {$tone} style, {$sceneText}");
    }
}
