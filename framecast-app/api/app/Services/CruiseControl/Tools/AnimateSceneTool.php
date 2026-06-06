<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\AnimateSceneJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use RuntimeException;

/**
 * Animate a scene that already has a still image. Reuses the same
 * AnimateSceneJob the editor's ⚡ Animate button uses. Cost = the tier's
 * VIDEO_* constant; the LLM can pick tier based on the user's mood
 * ("quick demo" -> quick; "cinematic feel" -> premium).
 */
class AnimateSceneTool implements CruiseTool
{
    private const TIERS = [
        'quick'         => ['model' => 'Wan 2.5',       'cost_const' => CreditService::VIDEO_QUICK,         'duration_5' => 5],
        'seedance_lite' => ['model' => 'Seedance Lite', 'cost_const' => CreditService::VIDEO_SEEDANCE_LITE, 'duration_5' => 5],
        'balanced'      => ['model' => 'Hailuo 2.3',    'cost_const' => CreditService::VIDEO_BALANCED,      'duration_5' => 6],
        'seedance_pro'  => ['model' => 'Seedance Pro',  'cost_const' => CreditService::VIDEO_SEEDANCE_PRO,  'duration_5' => 5],
        'premium'       => ['model' => 'Kling 2.1',     'cost_const' => CreditService::VIDEO_PREMIUM,       'duration_5' => 5],
    ];

    public function name(): string { return 'animate_scene'; }

    public function description(): string
    {
        $tiers = [];
        foreach (self::TIERS as $key => $meta) {
            $tiers[] = "{$key}={$meta['model']} ({$meta['cost_const']} cr)";
        }
        return 'Animate the still image on a scene into a short video clip. Tiers: ' . implode(', ', $tiers) . '. Default to quick if user does not specify.';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'tier' => [
                'type' => 'string',
                'required' => true,
                'enum' => array_keys(self::TIERS),
            ],
            'duration_seconds' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Per-tier valid durations: balanced=6 or 10, others=5 or 10. Defaults to the tier\'s 5s baseline.',
            ],
            'motion_prompt' => [
                'type' => 'string',
                'required' => false,
                'description' => 'How the still should animate (e.g. "subtle hair drift, slow camera push-in"). Defaults to scene\'s stashed suggestion.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'motion'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        $tier = $params['tier'] ?? 'quick';
        $meta = self::TIERS[$tier] ?? self::TIERS['quick'];
        $duration = $params['duration_seconds'] ?? $meta['duration_5'];
        $lines = [
            "Animate: {$meta['model']} (tier: {$tier})",
            "Scene: {$scene?->scene_order}",
            "Duration: {$duration}s",
        ];
        if (! empty($params['motion_prompt'])) {
            $lines[] = 'Motion: ' . mb_substr($params['motion_prompt'], 0, 60);
        }
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int
    {
        $tier = $params['tier'] ?? 'quick';
        $base = self::TIERS[$tier]['cost_const'] ?? CreditService::VIDEO_QUICK;
        $duration = (int) ($params['duration_seconds'] ?? self::TIERS[$tier]['duration_5'] ?? 5);
        // 10s clips cost 2× (matches AnimateSceneJob's pricing — see line 58).
        return $duration >= 10 ? $base * 2 : $base;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scene = Scene::query()
            ->where('project_id', $project->getKey())
            ->whereKey($params['scene_id'] ?? null)
            ->first();
        if (! $scene) {
            throw new RuntimeException('Scene not found in this project.');
        }
        if (! $scene->visual_asset_id) {
            throw new RuntimeException('Scene needs a still image before we can animate it.');
        }
        $tier = $params['tier'] ?? 'quick';
        if (! isset(self::TIERS[$tier])) {
            throw new RuntimeException('Unknown animation tier.');
        }

        $duration = (int) ($params['duration_seconds'] ?? self::TIERS[$tier]['duration_5']);
        // Snap to a per-tier valid value (the adapter does this too but we
        // want the audit + diff line to be honest).
        $duration = $duration >= 8 ? 10 : ($tier === 'balanced' ? 6 : 5);

        $motion = $params['motion_prompt']
            ?? data_get($scene->image_generation_settings_json, 'suggested_motion_prompt')
            ?? null;

        AnimateSceneJob::dispatch(
            $scene->getKey(),
            $project->getKey(),
            $tier,
            $duration,
            $motion,
        );

        return [
            'summary'       => "Animating Scene {$scene->scene_order} ({$tier}, {$duration}s)",
            'credits_spent' => $this->estimateCost($project, $params),
        ];
    }
}
