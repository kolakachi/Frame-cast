<?php

namespace App\Services\CruiseControl\Tools;

use App\Jobs\GenerateAIImageJob;
use App\Jobs\GenerateTTSJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageStyleDescriptors;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Insert a new scene into the project. Push subsequent scenes' order
 * down by one. Dispatch image + tts so the new scene is rendered out of
 * the gate (animate is opt-in via the chained_animate_tier param).
 *
 * Structural change → confirmation_class = 'always_prompt'. Cost is the
 * sum of image + tts + (optional) animate at the chosen tier.
 */
class AddSceneTool implements CruiseTool
{
    public function name(): string { return 'add_scene'; }

    public function description(): string
    {
        return 'Add a new scene to the project. Provide the spoken script (1–2 sentences, first/second person — what the voice ACTUALLY says, not a description), the visual prompt (what the image shows), and optionally style + voice_id. Position defaults to the end of the project; pass an explicit position to insert in the middle. Optionally chain an animation by setting animate_tier.';
    }

    public function paramsSchema(): array
    {
        return [
            'script_text' => [
                'type' => 'string',
                'required' => true,
                'description' => 'What the voice says. 1–2 sentences max, ~50–180 chars.',
            ],
            'visual_prompt' => [
                'type' => 'string',
                'required' => true,
                'description' => 'A DETAILED image-generation prompt (target 500–1000 chars, hard cap 1500). Expand the user\'s intent into a vivid scene: subject + pose, environment + setting, time of day + lighting, camera angle + framing, mood + emotion, style references, colour palette, and concrete textural details. Do NOT just echo the user\'s words — paint the picture the model will draw.',
            ],
            'position' => [
                'type' => 'integer',
                'required' => false,
                'description' => '1-based insertion position. Omit to append at the end.',
            ],
            'style' => [
                'type' => 'string',
                'required' => false,
                'enum' => array_keys(ImageStyleDescriptors::META),
                'description' => 'Defaults to the project\'s ai_broll_style.',
            ],
            'voice_id' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer', 'ash', 'coral', 'sage', 'ballad', 'verse'],
                'description' => 'Defaults to the project\'s default voice or alloy.',
            ],
            'animate_tier' => [
                'type' => 'string',
                'required' => false,
                'enum' => ['quick', 'seedance_lite', 'balanced', 'seedance_pro', 'premium'],
                'description' => 'If set, the image is animated after generation. Adds the tier\'s VIDEO_* cost.',
            ],
        ];
    }

    public function confirmationClass(): string { return 'always_prompt'; }
    public function affectedSection(): string { return 'scene'; }

    public function diffLines(Project $project, array $params): array
    {
        $position = $params['position'] ?? null;
        $posText = $position === null ? 'end of project' : "at position {$position}";
        $lines = [
            "Add new scene: {$posText}",
            'Script: ' . mb_substr((string) ($params['script_text'] ?? ''), 0, 60) . '…',
            'Visual: ' . mb_substr((string) ($params['visual_prompt'] ?? ''), 0, 60) . '…',
        ];
        if (! empty($params['animate_tier'])) {
            $lines[] = 'Animate: ' . $params['animate_tier'];
        }
        return $lines;
    }

    public function estimateCost(Project $project, array $params): int
    {
        // Image (AI_MEDIUM = gpt-image-1 default) + TTS + optional animate.
        $cost = CreditService::AI_MEDIUM + CreditService::TTS;
        if (! empty($params['animate_tier'])) {
            $cost += match ($params['animate_tier']) {
                'premium'       => CreditService::VIDEO_PREMIUM,
                'balanced'      => CreditService::VIDEO_BALANCED,
                'seedance_pro'  => CreditService::VIDEO_SEEDANCE_PRO,
                'seedance_lite' => CreditService::VIDEO_SEEDANCE_LITE,
                default         => CreditService::VIDEO_QUICK,
            };
        }
        return $cost;
    }

    public function execute(Workspace $workspace, Project $project, array $params): array
    {
        $scriptText = trim((string) ($params['script_text'] ?? ''));
        $visualPrompt = trim((string) ($params['visual_prompt'] ?? ''));
        if ($scriptText === '' || $visualPrompt === '') {
            throw new RuntimeException('Script and visual prompt are both required.');
        }

        $style = $params['style'] ?? ($project->ai_broll_style ?? 'photorealistic');
        $voiceId = $params['voice_id'] ?? data_get($project->default_voice_settings_json, 'voice_id', 'alloy');
        $animateTier = $params['animate_tier'] ?? null;
        // Balanced (Hailuo) needs 6 or 10; the others use 5 or 10.
        $animateDuration = $animateTier === 'balanced' ? 6 : 5;

        return DB::transaction(function () use ($project, $scriptText, $visualPrompt, $style, $voiceId, $animateTier, $animateDuration, $params) {
            $maxOrder = (int) Scene::query()->where('project_id', $project->getKey())->max('scene_order');
            $position = (int) ($params['position'] ?? ($maxOrder + 1));
            $position = max(1, min($maxOrder + 1, $position));

            // Shift existing scenes at or after the position down by one.
            // Done in a single UPDATE so we never violate (project_id,
            // scene_order) ordering invariants mid-way.
            if ($position <= $maxOrder) {
                Scene::query()
                    ->where('project_id', $project->getKey())
                    ->where('scene_order', '>=', $position)
                    ->orderByDesc('scene_order')   // bump from the back to avoid collisions
                    ->update(['scene_order' => DB::raw('scene_order + 1')]);

                // Update labels that still reference the OLD scene_order
                // as "Scene N". Without this, inserting at position 5
                // when a "Scene 5" labelled row already existed left two
                // rows both labelled "Scene 5" — confusing the editor.
                // Only touch labels that match the default "Scene N"
                // pattern; custom labels stay as the user set them.
                $bumped = Scene::query()
                    ->where('project_id', $project->getKey())
                    ->where('scene_order', '>', $position)   // already shifted to N+1
                    ->orderBy('scene_order')
                    ->get(['id', 'scene_order', 'label']);
                foreach ($bumped as $b) {
                    $expectedOld = 'Scene ' . ($b->scene_order - 1);
                    if (trim((string) $b->label) === $expectedOld) {
                        Scene::query()->whereKey($b->id)->update(['label' => 'Scene ' . $b->scene_order]);
                    }
                }
            }

            $imageToken = (string) Str::uuid();
            $scene = Scene::query()->create([
                'project_id'        => $project->getKey(),
                'scene_order'       => $position,
                'scene_type'        => 'narration',
                'label'             => "Scene {$position}",
                'script_text'       => $scriptText,
                'duration_seconds'  => 8,
                'voice_settings_json' => [
                    'voice_id' => $voiceId,
                    'speed'    => 1.0,
                    'is_outdated' => false,   // newly created — not outdated
                    'last_error' => null,
                ],
                'caption_settings_json' => $project->default_caption_settings_json ?? null,
                'visual_type'   => 'ai_image',
                'visual_prompt' => $visualPrompt,
                'visual_style'  => $style,
                'status'        => 'draft',
                'image_generation_settings_json' => [
                    'in_progress'           => true,
                    'last_error'            => null,
                    'needs_visual'          => false,
                    'generation_token'      => $imageToken,
                    'generation_started_at' => now()->toIso8601String(),
                ],
            ]);

            GenerateAIImageJob::dispatch(
                $scene->getKey(),
                $project->getKey(),
                $style,
                null,
                $style,
                $imageToken,
                $animateTier ? $animateDuration : null,
                null,                  // motion prompt — let the user re-animate later if they want
                $animateTier,
                null,
                [],
            )->afterCommit();

            GenerateTTSJob::dispatch($project->getKey(), [$scene->getKey()], false)->afterCommit();

            return [
                'summary'       => "Added Scene {$position} (\"" . mb_substr($scriptText, 0, 30) . "\")",
                'credits_spent' => $this->estimateCost($project, $params),
                // Frontend needs this to (a) start polling against the new
                // scene and (b) drop it into scenes.value so the editor
                // shows the pending placeholder while jobs run.
                'affected_scene_id' => (int) $scene->getKey(),
            ];
        });
    }
}
