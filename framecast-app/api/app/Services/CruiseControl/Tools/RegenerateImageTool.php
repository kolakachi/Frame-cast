<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateAIImageJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\Generation\Image\ImageStyleDescriptors;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Regenerate the AI image on a single scene. Honours optional style swap,
 * prompt override, and model picker — same shape as the editor's AI image
 * panel. Heavier than voice (~15–35 cr depending on model) so
 * confirmation_class is 'prompt'.
 */
class RegenerateImageTool implements CruiseTool
{
    public function name(): string { return 'regenerate_image'; }

    public function description(): string
    {
        return 'Regenerate the AI image on a scene. Optionally swap the visual style or pass a one-shot prompt override. Models: gpt-image-1 (15 cr, default photoreal), gpt-image-2 (35 cr, OpenAI newer), nano-banana (15 cr, Google fast), flux-schnell (3 cr, fastest+cheapest), sdxl-lightning (3 cr, stylish).';
    }

    public function paramsSchema(): array
    {
        return [
            'scene_id' => ['type' => 'integer', 'required' => true],
            'style' => [
                'type' => 'string',
                'required' => false,
                'enum' => array_keys(ImageStyleDescriptors::META),
                'description' => 'New visual style key. Omit to reuse scene\'s current style.',
            ],
            'prompt_override' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional override (≤400 chars). Replaces the scene\'s default prompt for THIS regen only.',
            ],
            'model_key' => [
                'type' => 'string',
                'required' => false,
                'enum' => array_keys(ImageAdapterFactory::AVAILABLE),
            ],
        ];
    }

    public function confirmationClass(): string { return 'prompt'; }
    public function affectedSection(): string { return 'visual'; }

    public function diffLines(Project $project, array $params): array
    {
        $scene = Scene::query()->find($params['scene_id'] ?? null);
        $style = $params['style'] ?? ($scene?->visual_style ?? $project->ai_broll_style ?? 'photorealistic');
        $model = $params['model_key'] ?? 'gpt-image-1';
        $lines = [
            "Visual: regenerate AI image",
            "Scene: {$scene?->scene_order}",
            "Style: {$style}",
            "Model: {$model}",
        ];
        if (! empty($params['prompt_override'])) {
            $lines[] = 'Prompt: ' . mb_substr((string) $params['prompt_override'], 0, 60) . '…';
        }
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int
    {
        return app(ImageAdapterFactory::class)->costFor($params['model_key'] ?? null);
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
        if (empty($scene->script_text) && empty($params['prompt_override']) && empty($scene->visual_prompt)) {
            throw new RuntimeException('Scene needs a script or prompt before we can regenerate.');
        }

        // Lock the scene + stamp a fresh generation token (same pattern the
        // editor's regen button uses, see SceneController::generateImage).
        $imageToken = (string) Str::uuid();
        $style = $params['style'] ?? ($scene->visual_style ?? $project->ai_broll_style ?? 'photorealistic');
        $existing = $scene->image_generation_settings_json ?? [];
        $scene->forceFill([
            'visual_style' => $style,
            'image_generation_settings_json' => array_merge($existing, [
                'in_progress'           => true,
                'last_error'            => null,
                'needs_visual'          => false,
                'generation_token'      => $imageToken,
                'generation_started_at' => now()->toIso8601String(),
            ]),
        ])->save();

        GenerateAIImageJob::dispatch(
            $scene->getKey(),
            $project->getKey(),
            $style,
            $params['prompt_override'] ?? null,
            $style,
            $imageToken,
            null, null, null,             // no chained animate
            $params['model_key'] ?? null,
            [],                            // no reference asset overrides
        );

        return [
            'summary'       => "Regenerating Scene {$scene->scene_order} image ({$style})",
            'credits_spent' => $this->estimateCost($project, $params),
        ];
    }
}
