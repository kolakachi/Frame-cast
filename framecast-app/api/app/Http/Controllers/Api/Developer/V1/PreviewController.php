<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\Asset;
use App\Models\Character;
use App\Models\CharacterImageGeneration;
use App\Models\Project;
use App\Models\Scene;
use App\Services\Media\PreviewRenderer;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response as BaseResponse;

/**
 * JPEG previews so an assistant can show the user what a scene, animation,
 * character or library asset looks like without a trip to the app. The body
 * is the image; the headers say which asset it came from.
 */
class PreviewController extends DeveloperController
{
    public function __construct(private readonly PreviewRenderer $previews)
    {
    }

    public function asset(Request $request, int $assetId): BaseResponse
    {
        $asset = Asset::query()->whereKey($assetId)->where('workspace_id', (int) $request->user()->workspace_id)->first();
        if (! $asset) {
            return $this->fail('not_found', 'Asset not found in this workspace.', 404);
        }

        return $this->render($request, $asset, ['asset_id' => $asset->getKey()]);
    }

    public function scene(Request $request, int $videoId, int $sceneId): BaseResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $project = Project::query()->whereKey($videoId)->where('workspace_id', $workspaceId)->first();
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $scene = Scene::query()->whereKey($sceneId)->where('project_id', $project->getKey())->first();
        if (! $scene) {
            return $this->fail('not_found', 'Scene not found in this video.', 404);
        }
        $input = $this->validated($request, ['kind' => ['nullable', Rule::in(['visual', 'animation'])], 'width' => ['nullable', 'integer', 'min:64', 'max:1024']]);
        $igs = is_array($scene->image_generation_settings_json) ? $scene->image_generation_settings_json : [];
        $animationId = (int) ($igs['animation_video_asset_id'] ?? 0);
        $kind = $input['kind'] ?? ($animationId ? 'animation' : 'visual');
        $assetId = $kind === 'animation' ? $animationId : (int) $scene->visual_asset_id;
        if (! $assetId) {
            return $this->fail('no_visual', $kind === 'animation' ? 'This scene has no animation yet.' : 'This scene has no visual yet.', 404);
        }
        $asset = Asset::query()->whereKey($assetId)->where('workspace_id', $workspaceId)->first();
        if (! $asset) {
            return $this->fail('no_visual', 'The scene\'s visual is missing.', 404);
        }

        return $this->render($request, $asset, ['asset_id' => $asset->getKey(), 'scene_id' => $scene->getKey(), 'kind' => $kind]);
    }

    public function character(Request $request, int $characterId): BaseResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $character = Character::query()->whereKey($characterId)->where('workspace_id', $workspaceId)->first();
        if (! $character) {
            return $this->fail('not_found', 'Character not found in this workspace.', 404);
        }
        $assetId = (int) $character->reference_asset_id;
        $source = 'reference';
        if (! $assetId) {
            $assetId = (int) CharacterImageGeneration::query()->where('character_id', $character->getKey())->where('status', 'succeeded')
                ->whereNotNull('result_asset_id')->latest('id')->value('result_asset_id');
            $source = 'generated';
        }
        if (! $assetId) {
            return $this->fail('no_visual', 'This character has no reference photo or generated image yet.', 404);
        }
        $asset = Asset::query()->whereKey($assetId)->where('workspace_id', $workspaceId)->first();
        if (! $asset) {
            return $this->fail('no_visual', 'The character\'s image is missing.', 404);
        }

        return $this->render($request, $asset, ['asset_id' => $asset->getKey(), 'character_id' => $character->getKey(), 'source' => $source]);
    }

    private function render(Request $request, Asset $asset, array $meta): BaseResponse
    {
        $width = (int) ($request->query('width') ?: PreviewRenderer::WIDTH);
        $bytes = $this->previews->jpeg($asset, $width);
        if ($bytes === null) {
            return $this->fail('preview_unavailable', 'A preview could not be rendered for this asset.', 422, ['asset_type' => $asset->asset_type]);
        }
        $headers = ['Content-Type' => 'image/jpeg', 'Cache-Control' => 'private, max-age=300', 'X-Wyv-Asset-Type' => $asset->asset_type];
        foreach ($meta as $k => $v) {
            $headers['X-Wyv-'.str_replace('_', '-', ucwords($k, '_'))] = (string) $v;
        }

        return new Response($bytes, 200, $headers);
    }
}
