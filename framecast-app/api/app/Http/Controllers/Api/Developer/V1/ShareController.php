<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\Project\PublicShareController as AppPublicShareController;
use App\Models\ExportJob;
use App\Models\Project;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The app's public watch link (/sample/<token>): anyone holding it can watch
 * the video's latest completed export without logging in. Turning it on is
 * outward-facing but reversible, so it needs no quote and no confirm; the
 * link stops working the moment it is turned off and comes back unchanged
 * when turned on again.
 */
class ShareController extends DeveloperController
{
    public function toggle(Request $request, int $videoId): JsonResponse
    {
        $workspaceId = (int) $request->user()->workspace_id;
        $project = Project::query()->whereKey($videoId)->where('workspace_id', $workspaceId)->first();
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $input = $this->validated($request, ['enabled' => ['nullable', 'boolean']]);
        $enabled = (bool) ($input['enabled'] ?? true);
        $latest = ExportJob::query()->where('project_id', $project->getKey())->where('status', 'completed')->latest('id')->first();
        if ($enabled && ! $latest) {
            return $this->fail('not_ready', 'Nothing to share yet: the video has no completed export. Export it first.', 409);
        }

        $inner = Request::create('/internal/share', 'POST', ['enabled' => $enabled]);
        $inner->headers->set('Accept', 'application/json');
        $inner->setUserResolver(fn () => $request->user());
        $response = app(AppPublicShareController::class)->toggle($inner, $videoId);
        if ($response->getStatusCode() >= 400) {
            $err = $response->getData(true)['error'] ?? [];

            return $this->fail($err['code'] ?? 'refused', $err['message'] ?? 'Refused.', $response->getStatusCode());
        }
        $d = $response->getData(true)['data'];

        return response()->json(['data' => [
            'video_id' => $project->getKey(), 'shared' => (bool) $d['is_shared'],
            'share_url' => $d['is_shared'] ? $d['share_url'] : null,
            'shows' => $latest ? ['export_id' => $latest->getKey(), 'aspect_ratio' => $latest->aspect_ratio, 'completed_at' => $latest->completed_at?->toIso8601String(), 'note' => 'The link always plays the latest completed export, including ones made later.'] : null,
            'next' => $d['is_shared'] ? 'Give the user share_url. Anyone with it can watch without logging in; call share_video with enabled=false to turn it off.' : 'The link is off. It returns unchanged when turned on again.',
        ], 'meta' => []], $enabled ? 201 : 200);
    }
}
