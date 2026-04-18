<?php

namespace App\Jobs;

use App\Events\GenerationProgressed;
use App\Jobs\GenerateHooksJob;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;

class BreakdownScenesJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $projectId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(AIGenerationAdapter $aiGeneration): void
    {
        GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'processing');

        $project = Project::query()->find($this->projectId);

        if (! $project || ! $project->script_text) {
            return;
        }

        $result = $aiGeneration->generate('scene_breakdown', [
            'script_text' => $project->script_text,
            'language' => $project->primary_language ?: 'en',
        ], 1100, 0.2, [
            'usage_context' => [
                'workspace_id' => $project->workspace_id,
                'project_id' => $project->getKey(),
                'user_id' => $project->created_by_user_id,
                'template' => 'scene_breakdown',
            ],
        ]);

        $scenes = $this->extractScenes($result['content'], $project->script_text);
        $sourceImageAssetIds = $this->sourceImageAssetIds($project);

        DB::transaction(function () use ($project, $scenes, $sourceImageAssetIds): void {
            Scene::query()->where('project_id', $project->getKey())->delete();

            foreach ($scenes as $index => $scene) {
                $sourceImageAssetId = $sourceImageAssetIds[$index] ?? null;

                Scene::query()->create([
                    'project_id' => $project->getKey(),
                    'scene_order' => $index + 1,
                    'scene_type' => $scene['scene_type'],
                    'label' => $scene['label'],
                    'script_text' => $scene['script_text'],
                    'duration_seconds' => $scene['duration_seconds'],
                    'visual_type' => $sourceImageAssetId ? 'upload' : null,
                    'visual_asset_id' => $sourceImageAssetId,
                    'visual_prompt' => $sourceImageAssetId ? 'Uploaded source image #'.($index + 1) : null,
                    'status' => 'draft',
                ]);
            }

        });

        GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'completed');
        GenerateHooksJob::dispatch($project->getKey());
    }

    public function failed(\Throwable $exception): void
    {
        Project::query()
            ->whereKey($this->projectId)
            ->update([
                'status' => 'failed',
            ]);

        GenerationProgressed::dispatch($this->projectId, 'scene_breakdown', 'failed', $exception->getMessage());
    }

    /**
     * @return list<array{scene_type:string,label:string,script_text:string,duration_seconds:float}>
     */
    private function extractScenes(string $content, string $scriptText): array
    {
        $decoded = json_decode($content, true);

        if (is_array($decoded)) {
            $sceneRows = $decoded['scenes'] ?? $decoded;

            if (is_array($sceneRows) && $sceneRows !== []) {
                $normalized = [];

                foreach ($sceneRows as $row) {
                    if (! is_array($row)) {
                        continue;
                    }

                    $text = trim((string) ($row['script_text'] ?? $row['text'] ?? ''));

                    if ($text === '') {
                        continue;
                    }

                    $normalized[] = [
                        'scene_type' => $this->normalizeSceneType((string) ($row['scene_type'] ?? 'narration')),
                        'label' => $this->normalizeLabel((string) ($row['label'] ?? 'Scene')),
                        'script_text' => $text,
                        'duration_seconds' => $this->normalizeDuration($row['duration_seconds'] ?? null),
                    ];
                }

                if ($normalized !== []) {
                    return array_slice($normalized, 0, 20);
                }
            }
        }

        return $this->fallbackScenes($scriptText);
    }

    private function normalizeSceneType(string $sceneType): string
    {
        $allowed = ['hook', 'narration', 'transition', 'text_card', 'quote'];

        return in_array($sceneType, $allowed, true) ? $sceneType : 'narration';
    }

    private function normalizeLabel(string $label): string
    {
        $trimmed = trim($label);

        return $trimmed !== '' ? mb_substr($trimmed, 0, 255) : 'Scene';
    }

    /**
     * @param  mixed  $duration
     */
    private function normalizeDuration(mixed $duration): float
    {
        $value = (float) $duration;

        if ($value <= 0) {
            return 6.0;
        }

        return min(max($value, 2.0), 20.0);
    }

    /**
     * @return list<array{scene_type:string,label:string,script_text:string,duration_seconds:float}>
     */
    private function fallbackScenes(string $scriptText): array
    {
        $chunks = preg_split('/\n{2,}/', trim($scriptText)) ?: [];

        if ($chunks === []) {
            return [[
                'scene_type' => 'narration',
                'label' => 'Scene 1',
                'script_text' => trim($scriptText),
                'duration_seconds' => 6.0,
            ]];
        }

        $scenes = [];

        foreach (array_slice($chunks, 0, 20) as $index => $chunk) {
            $text = trim($chunk);

            if ($text === '') {
                continue;
            }

            $scenes[] = [
                'scene_type' => $index === 0 ? 'hook' : 'narration',
                'label' => 'Scene '.($index + 1),
                'script_text' => $text,
                'duration_seconds' => 6.0,
            ];
        }

        return $scenes === [] ? [[
            'scene_type' => 'narration',
            'label' => 'Scene 1',
            'script_text' => trim($scriptText),
            'duration_seconds' => 6.0,
        ]] : $scenes;
    }

    /**
     * @return list<int>
     */
    private function sourceImageAssetIds(Project $project): array
    {
        if ($project->source_type !== 'images') {
            return [];
        }

        return array_values(array_filter(array_map(
            static fn (mixed $id): int => (int) $id,
            $project->source_image_asset_ids ?? [],
        )));
    }
}
