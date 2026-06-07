<?php

namespace App\Services\CruiseControl\Tools;

use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Move a scene to a new position in the project. Non-destructive — only
 * shuffles scene_order, no regeneration, no credit cost. Structural change
 * so confirmation_class = 'always_prompt'.
 *
 * "Swap scenes 2 and 4" is expressed by the LLM as two reorder_scene
 * actions; this tool does a single move-to-position which composes cleanly.
 */
class ReorderSceneTool implements CruiseTool
{
    public function name(): string { return 'reorder_scene'; }

    public function description(): string
    {
        return 'Move a scene to a new position in the project (reorder / rearrange / "make this the intro or outro"). Non-destructive and free — it only changes the order, nothing is re-generated. position is 1-based (1 = first scene). To swap two scenes, emit two reorder_scene actions.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'position' => [
                'type' => 'integer',
                'required' => true,
                'description' => '1-based target position. 1 moves the scene to the front; pass the scene count to move it to the end.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'always_prompt'; }
    public function affectedSection(): string { return 'scene'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        $to = (int) ($params['position'] ?? 0);
        return [
            "Move Scene {$scene?->scene_order}",
            "To position {$to}",
            'No regeneration · free',
        ];
    }

    public function estimateCost(Project $project, array $params): int { return 0; }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        return DB::transaction(function () use ($project, $params) {
            $scene = Scene::query()
                ->where('project_id', $project->getKey())
                ->whereKey($params['scene_id'] ?? null)
                ->lockForUpdate()
                ->first();
            if (! $scene) {
                throw new RuntimeException('Scene not found in this project.');
            }

            $maxOrder = (int) Scene::query()->where('project_id', $project->getKey())->max('scene_order');
            $from = (int) $scene->scene_order;
            $to   = max(1, min($maxOrder, (int) ($params['position'] ?? $from)));

            if ($from === $to) {
                return [
                    'summary'           => "Scene {$from} is already at position {$to}",
                    'credits_spent'     => 0,
                    'affected_scene_id' => (int) $scene->getKey(),
                ];
            }

            // Park the moving scene out of the unique (project_id, scene_order)
            // range so the block shift below can't collide with it.
            $scene->forceFill(['scene_order' => 0])->save();

            if ($to < $from) {
                // Moving up: scenes in [to, from-1] shift down by one.
                // Process from the back so each slot is vacated before filled.
                Scene::query()
                    ->where('project_id', $project->getKey())
                    ->whereBetween('scene_order', [$to, $from - 1])
                    ->orderByDesc('scene_order')
                    ->update(['scene_order' => DB::raw('scene_order + 1')]);
            } else {
                // Moving down: scenes in (from, to] shift up by one.
                Scene::query()
                    ->where('project_id', $project->getKey())
                    ->whereBetween('scene_order', [$from + 1, $to])
                    ->orderBy('scene_order')
                    ->update(['scene_order' => DB::raw('scene_order - 1')]);
            }

            $scene->forceFill(['scene_order' => $to])->save();

            $this->relabelDefaults($project);

            return [
                'summary'           => "Moved scene to position {$to}",
                'credits_spent'     => 0,
                'affected_scene_id' => (int) $scene->getKey(),
            ];
        });
    }

    /**
     * Re-number default "Scene N" labels to match the new order. Custom
     * labels the user set are left untouched. Mirrors AddSceneTool's relabel
     * so the editor never shows two "Scene 3"s after a move.
     */
    private function relabelDefaults(Project $project): void
    {
        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get(['id', 'scene_order', 'label']);

        foreach ($scenes as $s) {
            if (preg_match('/^Scene \d+$/', trim((string) $s->label))) {
                $expected = 'Scene ' . $s->scene_order;
                if (trim((string) $s->label) !== $expected) {
                    Scene::query()->whereKey($s->id)->update(['label' => $expected]);
                }
            }
        }
    }
}
