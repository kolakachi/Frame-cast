<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\Generation\Visual\VisualProviderAdapter;
use RuntimeException;

/**
 * Stock-image variant of the visual search. Same Pexels adapter, but the
 * visual_type is image_montage (the editor's "Stock Image" mode).
 */
class FindStockImageTool implements CruiseTool
{
    public function name(): string { return 'find_stock_image'; }

    public function description(): string
    {
        return 'Search stock images and attach the best match to a scene. Use when the user wants editorial photography: "find a photo of a marble countertop", "use stock image of a city at night". Different from regenerate_image — this picks an EXISTING photo from Pexels, not an AI-generated one. If the user wants a NEW image to be generated, use regenerate_image instead.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'query' => [
                'type' => 'string',
                'required' => true,
                'description' => 'Concrete Pexels-style query (3-8 words).',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'visual'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        return [
            "Visual: stock image",
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

        $orientation = $project->aspect_ratio === '16:9' ? 'landscape' : 'portrait';
        $match = app(VisualProviderAdapter::class)->match($query, $orientation, 'image_montage');

        $asset = Asset::query()->create([
            'workspace_id'     => $project->workspace_id,
            'channel_id'       => $project->channel_id,
            'asset_type'       => 'image',
            'title'            => 'Stock image · ' . mb_substr($query, 0, 50),
            'description'      => $query,
            'storage_url'      => $match['asset_url'] ?? null,
            'thumbnail_url'    => $match['thumbnail_url'] ?? null,
            'tags'             => ['stock', 'pexels'],
            'source'           => 'pexels',
            'status'           => 'active',
            'created_by_user_id' => $project->created_by_user_id,
        ]);

        $scene->forceFill([
            'visual_asset_id' => $asset->getKey(),
            'visual_type'     => 'image_montage',
            'status'          => 'edited',
        ])->save();

        return [
            'summary'       => "Attached stock image to Scene {$scene->scene_order}",
            'credits_spent' => 0,
        ];
    }
}
