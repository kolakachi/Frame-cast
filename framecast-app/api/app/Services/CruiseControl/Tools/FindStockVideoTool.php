<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\Generation\Visual\VisualProviderAdapter;
use RuntimeException;

/**
 * Search the stock-video provider (Pexels via VisualProviderAdapter), grab
 * the best match for the query, materialise an Asset row from the result,
 * and attach it to the scene. Free (no upstream API cost charged to user)
 * — Pexels API is unmetered for us at the volume we'll hit.
 */
class FindStockVideoTool implements CruiseTool
{
    public function name(): string { return 'find_stock_video'; }

    public function description(): string
    {
        return 'Search stock video and attach the best match to a scene. Use when the user wants real footage: "find a clip of a city skyline at sunset", "use stock video of a marathon runner". For looping ambient backgrounds (under speech, abstract motion) use type=bg_loop instead of type=clip.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'query' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Concrete Pexels-style search query (3-8 words). Compose from the user\'s described subject + setting + mood. E.g. "drone shot city skyline sunset golden hour".',
            ],
            'type' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['clip', 'bg_loop'],
                'description' => 'Default clip. Use bg_loop when user says "looping background", "ambient", "behind the voice", or describes seamless / abstract motion.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'visual'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        $type = $params['type'] ?? 'clip';
        return [
            "Visual: stock video ({$type})",
            "Query: " . mb_substr((string) ($params['query'] ?? ''), 0, 60),
            "Scene: {$scene?->scene_order}",
        ];
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scene = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereKey($params['scene_id'] ?? null)
            ->first();
        if (! $scene) {
            throw new RuntimeException('Scene not found in this project.');
        }
        $query = trim((string) ($params['query'] ?? ''));
        if ($query === '') {
            throw new RuntimeException('Empty search query.');
        }
        $visualType = ($params['type'] ?? 'clip') === 'bg_loop' ? 'background_loop' : 'stock_clip';

        $orientation = $project->aspect_ratio === '16:9' ? 'landscape' : 'portrait';
        $match = app(VisualProviderAdapter::class)->match($query, $orientation, $visualType);

        $asset = Asset::query()->create([
            'workspace_id'     => $project->workspace_id,
            'channel_id'       => $project->channel_id,
            'asset_type'       => $match['asset_type'] ?? 'video',
            'title'            => 'Stock visual · ' . mb_substr($query, 0, 50),
            'description'      => $query,
            'storage_url'      => $match['asset_url'] ?? null,
            'thumbnail_url'    => $match['thumbnail_url'] ?? null,
            'duration_seconds' => $match['duration_seconds'] ?? null,
            'tags'             => ['stock', 'pexels', $params['type'] ?? 'clip'],
            'source'           => 'pexels',
            'status'           => 'active',
            'created_by_user_id' => $project->created_by_user_id,
        ]);

        $scene->forceFill([
            'visual_asset_id' => $asset->getKey(),
            'visual_type'     => $visualType,
            'status'          => 'edited',
        ])->save();

        return [
            'summary'       => "Attached stock video to Scene {$scene->scene_order}",
            'credits_spent' => 0,
        ];
    }
}
