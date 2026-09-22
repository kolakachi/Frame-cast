<?php
namespace App\Services\Export;

use App\Models\{Asset, ExportJob, Project, Scene};
use Illuminate\Support\Collection;

final class ExportSnapshot
{
    public static function capture(Project $project): array
    {
        $scenes = $project->scenes()->orderBy('scene_order')->orderBy('id')->get();
        $ids = collect([$project->music_asset_id]);
        foreach ($scenes as $scene) $ids = $ids->merge([$scene->visual_asset_id, $scene->sound_asset_id, data_get($scene->voice_settings_json, 'audio_asset_id')]);
        $assets = Asset::query()->where('workspace_id', $project->workspace_id)->whereIn('id', $ids->filter()->unique())->get();
        return ['project' => $project->getAttributes(), 'scenes' => $scenes->map->getAttributes()->all(), 'assets' => $assets->map->getAttributes()->all()];
    }
    public static function project(ExportJob $job): ?Project
    {
        return $job->render_snapshot ? (new Project)->setRawAttributes($job->render_snapshot['project'], true) : Project::find($job->project_id);
    }
    public static function scenes(ExportJob $job): Collection
    {
        return $job->render_snapshot ? collect($job->render_snapshot['scenes'])->map(fn ($row) => (new Scene)->setRawAttributes($row, true))
            : Scene::where('project_id', $job->project_id)->orderBy('scene_order')->get();
    }
    public static function asset(ExportJob $job, ?int $id): ?Asset
    {
        if (! $id) return null;
        if ($job->render_snapshot) {
            $row = collect($job->render_snapshot['assets'])->first(fn ($row) => (int) $row['id'] === $id && (int) $row['workspace_id'] === (int) $job->workspace_id);
            $asset = $row ? (new Asset)->setRawAttributes($row, true) : null;
        } else $asset = Asset::where('workspace_id', $job->workspace_id)->find($id);
        if (! $asset) throw new \RuntimeException('An export asset is missing or unavailable in this workspace.');
        return $asset;
    }
}
