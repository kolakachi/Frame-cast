<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** Duplicate a scene right after itself, picture, voice and all (free; undo removes the copy). */
class DuplicateSceneTool implements CruiseTool
{
    public function name(): string { return 'duplicate_scene'; }

    public function description(): string
    {
        return 'Copy a scene and put the copy right after it (same picture, voice and script). Free. Use for "duplicate / copy / repeat scene N".';
    }

    public function paramsSchema(): array
    {
        return ['scene_id' => ['type' => 'integer', 'required' => true]];
    }

    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'scene'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->where('project_id', $project->getKey())->whereKey($params['scene_id'] ?? null)->first();

        return ['Duplicate scene '.($scene?->scene_order ?? '?').' right after itself'];
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        return DB::transaction(function () use ($project, $params) {
            $scene = Scene::query()->where('project_id', $project->getKey())->whereKey($params['scene_id'] ?? null)->first();
            if (! $scene) throw new RuntimeException('Scene not found in this project.');
            $ids = Scene::query()->where('project_id', $project->getKey())->orderBy('scene_order')->pluck('id')->map(fn ($id) => (int) $id)->all();
            $copy = $scene->replicate();
            $copy->scene_order = (int) Scene::query()->where('project_id', $project->getKey())->max('scene_order') + 1000;
            $copy->status = 'draft';
            $copy->save();
            $at = array_search((int) $scene->getKey(), $ids, true);
            array_splice($ids, $at === false ? count($ids) : $at + 1, 0, [(int) $copy->getKey()]);
            foreach ($ids as $i => $id) Scene::query()->whereKey($id)->update(['scene_order' => 2000 + $i]);
            foreach ($ids as $i => $id) Scene::query()->whereKey($id)->update(['scene_order' => $i + 1]);

            return ['summary' => 'Duplicated scene '.$scene->scene_order, 'credits_spent' => 0, 'affected_scene_id' => (int) $copy->getKey()];
        });
    }
}
