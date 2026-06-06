<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use RuntimeException;

/**
 * Set a scene's visual to an asset already in the workspace library.
 * No upstream API calls; free. Used when the user says "swap to my logo"
 * or "use the kitchen photo from my assets".
 */
class SwapVisualFromLibraryTool implements CruiseTool
{
    public function name(): string { return 'swap_visual_from_library'; }

    public function description(): string
    {
        return 'Replace a scene\'s visual with an existing asset from the user\'s library. Use only when you can identify an asset id from context or the user names a specific asset. Otherwise ask for clarification.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'asset_id' => [
                'type' => 'integer',
                'required' => true,
                'description' => 'Asset id from the workspace library.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'auto'; }
    public function affectedSection(): string { return 'visual'; }

    public function diffLines(Project $project, array $params): array
    {
        $asset = Asset::query()->find($params['asset_id'] ?? null);
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        return [
            "Visual: " . ($asset?->title ?? 'asset #' . ($params['asset_id'] ?? '?')),
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

        $asset = Asset::query()
            ->whereKey($params['asset_id'] ?? null)
            ->where('workspace_id', $workspace->getKey())
            ->whereIn('asset_type', ['image', 'video'])
            ->first();
        if (! $asset) {
            throw new RuntimeException('Asset not found in your library, or not an image/video.');
        }

        $scene->forceFill([
            'visual_asset_id' => $asset->getKey(),
            'visual_type'     => $asset->asset_type === 'video' ? 'stock_video' : 'stock_image',
        ])->save();

        return [
            'summary'       => "Swapped Scene {$scene->scene_order} visual to {$asset->title}",
            'credits_spent' => 0,
        ];
    }
}
