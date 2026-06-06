<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateAIImageJob;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
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
    private const CHAIN_TIERS = [
        'quick'         => ['model' => 'Wan 2.5',       'cost_const' => CreditService::VIDEO_QUICK],
        'seedance_lite' => ['model' => 'Seedance Lite', 'cost_const' => CreditService::VIDEO_SEEDANCE_LITE],
        'balanced'      => ['model' => 'Hailuo 2.3',    'cost_const' => CreditService::VIDEO_BALANCED],
        'seedance_pro'  => ['model' => 'Seedance Pro',  'cost_const' => CreditService::VIDEO_SEEDANCE_PRO],
        'premium'       => ['model' => 'Kling 2.1',     'cost_const' => CreditService::VIDEO_PREMIUM],
    ];

    public function name(): string { return 'regenerate_image'; }

    public function description(): string
    {
        return 'Generate a NEW AI image for a scene. THIS IS THE CORRECT TOOL when the user wants to "generate", "create", "make", "replace with AI", or describes the content of a new visual ("a man holding a woman under a tree", "a 3D image of...", "swap to anime style"). Pass prompt_override with the user\'s described content. Use style to match what they ask for: "3D" or "Pixar" -> 3d_animated, "anime" -> anime, "watercolor" -> watercolor, etc. Models: gpt-image-1 (15 cr, default photoreal), gpt-image-2 (35 cr, OpenAI newer), nano-banana (15 cr, Google fast), flux-schnell (3 cr, fastest+cheapest), sdxl-lightning (3 cr, stylish). CHAINED ANIMATION: if the user asks for "image AND animation", "make it move", "and animate it", set chain_animate_tier so the same turn produces both — do NOT propose a separate animate_scene action.';
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
                'description' => 'A DETAILED image-generation prompt (target 500–1000 chars, hard cap 1500). Expand the user\'s short description into a vivid scene: subject + pose, environment + setting, time of day + lighting, camera angle + framing, mood + emotion, style references, colour palette, and small concrete details (fabric, weather, surface textures). Do NOT just echo the user\'s words — paint the picture the model will draw.',
            ],
            'model_key' => [
                'type' => 'string',
                'required' => false,
                'enum' => array_keys(ImageAdapterFactory::AVAILABLE),
            ],
            'character_id' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Saved character id to use as the reference face. If user says "use my [character name]" or "with my [character]", look up the character in context and pass the id.',
            ],
            'custom_style_descriptor' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Free-text style descriptor when no preset style fits. E.g. "baroque oil painting, Caravaggio lighting". When set, the tool routes to style=custom and uses this descriptor instead of a preset. Up to 500 chars.',
            ],
            'chain_animate_tier' => [
                'type' => 'string',
                'required' => false,
                'enum' => array_keys(self::CHAIN_TIERS),
                'description' => 'Optional: chain an animation after the image lands. Set this (default "quick") when the user asks for "image AND animation" or "make it move". Use "premium" only if they say "cinematic"/"high quality"/"best".',
            ],
            'chain_animate_duration' => [
                'type' => 'integer',
                'required' => false,
                'description' => '5 or 10 (10 costs 2× the tier). Only used when chain_animate_tier is set.',
            ],
            'chain_animate_motion_prompt' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Optional motion description for the chained animation, e.g. "subtle hair drift, slow camera push-in". Only used when chain_animate_tier is set.',
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
        if (! empty($params['chain_animate_tier']) && isset(self::CHAIN_TIERS[$params['chain_animate_tier']])) {
            $meta = self::CHAIN_TIERS[$params['chain_animate_tier']];
            $dur  = (int) ($params['chain_animate_duration'] ?? 5);
            $lines[] = "Then animate: {$meta['model']} ({$params['chain_animate_tier']}, {$dur}s)";
        }
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int
    {
        $imageCost = app(ImageAdapterFactory::class)->costFor($params['model_key'] ?? null);
        $tier = $params['chain_animate_tier'] ?? null;
        if ($tier && isset(self::CHAIN_TIERS[$tier])) {
            $base = self::CHAIN_TIERS[$tier]['cost_const'];
            $dur  = (int) ($params['chain_animate_duration'] ?? 5);
            $imageCost += ($dur >= 10 ? $base * 2 : $base);
        }
        return $imageCost;
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

        // Resolve style. Custom descriptor wins — when set we route to
        // style='custom' and stash the descriptor on the scene so the
        // adapter picks it up.
        $customDescriptor = trim((string) ($params['custom_style_descriptor'] ?? ''));
        $style = $customDescriptor !== ''
            ? 'custom'
            : ($params['style'] ?? ($scene->visual_style ?? $project->ai_broll_style ?? 'photorealistic'));

        // Resolve character reference — bind to scene + pass the reference
        // through to the image job (it auto-routes to CharacterImageAdapter
        // when a character with a referenceAsset is bound to the scene).
        $characterId = $params['character_id'] ?? null;
        if ($characterId) {
            $character = Character::query()
                ->whereKey($characterId)
                ->where('workspace_id', $workspace->getKey())
                ->first();
            if (! $character) {
                throw new RuntimeException('Character not found in your library.');
            }
        }

        $imageToken = (string) Str::uuid();
        $existing = $scene->image_generation_settings_json ?? [];
        $sceneUpdate = [
            'visual_style' => $style,
            'image_generation_settings_json' => array_merge($existing, [
                'in_progress'           => true,
                'last_error'            => null,
                'needs_visual'          => false,
                'generation_token'      => $imageToken,
                'generation_started_at' => now()->toIso8601String(),
            ]),
        ];
        if ($customDescriptor !== '') {
            $sceneUpdate['custom_visual_style'] = mb_substr($customDescriptor, 0, 500);
        }
        if ($characterId) {
            $sceneUpdate['character_id'] = $characterId;
        }
        $scene->forceFill($sceneUpdate)->save();

        // Resolve optional chained animation. The same GenerateAIImageJob
        // dispatches AnimateSceneJob on success when these are set — same
        // pipeline the one-shot wizard uses for "Animate the image" toggle.
        $chainTier = $params['chain_animate_tier'] ?? null;
        if ($chainTier && ! isset(self::CHAIN_TIERS[$chainTier])) {
            throw new RuntimeException('Unknown animation tier for chain_animate_tier.');
        }
        $chainDuration = null;
        $chainMotion   = null;
        if ($chainTier) {
            $rawDur = (int) ($params['chain_animate_duration'] ?? 5);
            // Snap to the tier's valid value (matches AnimateSceneJob).
            $chainDuration = $rawDur >= 8 ? 10 : ($chainTier === 'balanced' ? 6 : 5);
            $chainMotion   = $params['chain_animate_motion_prompt'] ?? null;
        }

        GenerateAIImageJob::dispatch(
            $scene->getKey(),
            $project->getKey(),
            $style,
            $params['prompt_override'] ?? null,
            $style,
            $imageToken,
            $chainDuration,
            $chainMotion,
            $chainTier,
            $params['model_key'] ?? null,
            [],                            // no reference asset overrides — character path
                                            // auto-routes via scene.character_id
        );

        $summary = "Regenerating Scene {$scene->scene_order} image ({$style})";
        if ($chainTier) {
            $summary .= " + animation ({$chainTier})";
        }
        return [
            'summary'       => $summary,
            'credits_spent' => $this->estimateCost($project, $params),
        ];
    }
}
