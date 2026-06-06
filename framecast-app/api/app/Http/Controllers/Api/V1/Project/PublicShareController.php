<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\Media\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * Public share endpoints for the /sample/<token> cold-DM motion. Two routes:
 *
 *   POST /api/v1/projects/{id}/share   (auth)  — toggle is_shared on / generate
 *                                                share_token if first time.
 *   GET  /api/v1/public/projects/{token}        — no auth. Returns minimal
 *                                                project state + a signed URL
 *                                                to the latest succeeded
 *                                                export (or 404 if none yet).
 *
 * Designed to be the URL we drop in cold DMs and intro emails — the recipient
 * gets a playable demo without hitting a login wall, without spending any of
 * our credits.
 */
class PublicShareController extends Controller
{
    /**
     * Toggle (or enable + create) a share link for a project. Idempotent —
     * calling twice with enabled=true doesn't rotate the token. Pass
     * enabled=false to disable the share link without deleting the token
     * (token persists so re-enabling keeps the same URL).
     */
    public function toggle(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $enabled = (bool) $request->input('enabled', true);

        if ($enabled && empty($project->share_token)) {
            // 32-char URL-safe token. lowercase + digits keeps URLs friendly
            // for copy-paste in DMs / emails without case-sensitivity bugs.
            $project->share_token = strtolower(Str::random(32));
        }
        $project->is_shared = $enabled;
        $project->save();

        $baseUrl = rtrim((string) config('app.frontend_url'), '/');

        return response()->json([
            'data' => [
                'is_shared'   => $project->is_shared,
                'share_token' => $project->share_token,
                'share_url'   => $project->share_token
                    ? "{$baseUrl}/sample/{$project->share_token}"
                    : null,
            ],
            'meta' => [],
        ]);
    }

    /**
     * Public view of a shared project. Returns a sanitized payload:
     * title, aspect ratio, scene count, latest succeeded export URL (the
     * thing that actually plays in the player). 404s if the token doesn't
     * exist OR the project has is_shared=false (lets the owner disable
     * sharing without changing the URL).
     */
    public function show(string $token, StorageService $storage): JsonResponse
    {
        $project = Project::query()
            ->where('share_token', $token)
            ->where('is_shared', true)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'This share link is unavailable.', 404);
        }

        // Latest succeeded export — what plays in the embedded video tag.
        $export = ExportJob::query()
            ->where('project_id', $project->getKey())
            ->where('status', 'succeeded')
            ->whereNotNull('output_asset_id')
            ->orderByDesc('completed_at')
            ->first();

        $videoUrl = null;
        if ($export) {
            $asset = Asset::query()->find($export->output_asset_id);
            if ($asset?->storage_url) {
                $videoUrl = $storage->url($asset->storage_url);
            }
        }

        // Lightweight scene list for the "what's in this video" tease below
        // the player. Don't leak voice settings / prompts / credit info.
        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get(['scene_order', 'script_text']);

        return response()->json([
            'data' => [
                'project' => [
                    'title'        => $project->title ?? 'Untitled',
                    'aspect_ratio' => $project->aspect_ratio,
                    'scene_count'  => $scenes->count(),
                ],
                'video_url' => $videoUrl,
                'export_ready' => (bool) $videoUrl,
                'scenes' => $scenes->map(fn ($s) => [
                    'order' => $s->scene_order,
                    // Show only a short snippet — keeps the page tease-y
                    // and avoids leaking the full script in the preview.
                    'snippet' => Str::limit((string) $s->script_text, 80, '…'),
                ])->all(),
            ],
            'meta' => [],
        ]);
    }

}
