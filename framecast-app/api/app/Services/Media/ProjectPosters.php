<?php

namespace App\Services\Media;

use App\Http\Controllers\Api\V1\Asset\AssetController;
use App\Jobs\MakeCreatePoster;
use App\Models\Asset;
use App\Services\Create\CreateStorage;
use Illuminate\Support\Facades\{Cache, DB, URL};

/** The picture on a video's card: a Create video's latest frame; a classic video's export frame, else its first scene's picture. */
final class ProjectPosters
{
    /** @param list<int> $projectIds @return array<int, string> project id => poster URL, only for projects that have one */
    public function forProjects(int $workspaceId, array $projectIds): array
    {
        if (! $projectIds) return [];
        $out = [];
        $made = DB::table('create_conversations')->join('composition_revisions', 'composition_revisions.id', '=', 'create_conversations.head_revision_id')
            ->where('create_conversations.workspace_id', $workspaceId)->whereIn('create_conversations.project_id', $projectIds)
            ->where('composition_revisions.artifact_path', 'like', '%.mp4')
            ->pluck('composition_revisions.id', 'create_conversations.project_id');
        foreach ($made as $projectId => $revisionId) {
            if ($url = self::createPoster((string) $revisionId)) $out[(int) $projectId] = $url;
        }

        $assets = app(AssetController::class);
        $rest = array_values(array_diff($projectIds, $made->keys()->map(fn ($id) => (int) $id)->all()));
        $exported = $rest ? DB::table('export_jobs')->whereIn('project_id', $rest)->where('status', 'completed')->whereNotNull('output_asset_id')
            ->orderByDesc('completed_at')->get(['project_id', 'output_asset_id'])->unique('project_id')->pluck('output_asset_id', 'project_id') : collect();
        foreach ($rest as $projectId) {
            $assetId = $exported[$projectId] ?? null;
            $asset = $assetId ? Asset::query()->where('workspace_id', $workspaceId)->find($assetId) : null;
            $url = $asset ? $assets->posterUrl($asset) : null;
            if (! $url) {
                $assetId = DB::table('scenes')->where('project_id', $projectId)->whereNotNull('visual_asset_id')->orderBy('scene_order')->value('visual_asset_id');
                $asset = $assetId ? Asset::query()->where('workspace_id', $workspaceId)->find($assetId) : null;
                $url = $asset ? $assets->posterUrl($asset) : null;
            }
            if ($url) $out[$projectId] = $url;
        }
        return $out;
    }

    /** A signed URL for a Create version's frame, or null while it is missing (an older version gets one made for the next visit). */
    public static function createPoster(string $revisionId): ?string
    {
        if (app(CreateStorage::class)->exists(MakeCreatePoster::path($revisionId))) {
            return URL::temporarySignedRoute('media.create.poster', now()->addHours(2), ['revisionId' => $revisionId]);
        }
        if (Cache::add('create-poster:'.$revisionId, 1, 600)) MakeCreatePoster::dispatch($revisionId);
        return null;
    }
}
