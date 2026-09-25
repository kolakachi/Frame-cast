<?php

namespace App\Services\Developer;

use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Http\Controllers\Api\V1\Scene\SceneController;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\Generation\TTS\GeminiVoices;
use App\Services\Generation\TTS\RoutingTTSAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The editor operations an assistant may propose, one entry each: the
 * inputs it takes, what it costs (the same arithmetic the dashboard
 * controllers use before they charge), whether it needs a scene, and how
 * it executes — always by delegating to the dashboard's own controller
 * method as the same user, so validation, locking, in-progress guards and
 * accounting are the editor's. This class prices and routes; it never
 * charges.
 */
class EditOperations
{
    public const SCENE_SETTINGS = [
        'label', 'script_text', 'duration_seconds', 'scene_type', 'visual_type', 'visual_asset_id', 'character_id',
        'sound_asset_id', 'sound_settings_json', 'visual_prompt', 'transition_rule', 'voice_profile_id', 'voice_settings_json',
        'caption_settings_json', 'visual_style', 'custom_visual_style', 'motion_settings_json', 'image_generation_settings_json', 'locked_fields_json',
    ];

    public const REWRITE_MODES = ['shorten', 'expand', 'stronger_hook', 'more_punchy', 'more_educational', 'more_salesy', 'simplify', 'scarier', 'more_dramatic', 'more_documentary'];

    public const ANIMATE_TIERS = ['quick', 'balanced', 'premium', 'seedance_lite', 'seedance_pro', 'veo_fast', 'seedance_25', 'spokesperson'];

    /**
     * @return array<string, array{scene: bool, family: string, spends: bool, description: string, inputs: array<string, string>}>
     */
    public static function catalogue(): array
    {
        return [
            'update_scene' => ['scene' => true, 'family' => 'script_and_structure', 'spends' => false, 'description' => 'Change any scene setting: script, label, duration, visual type/asset/prompt/style, character, voice profile and settings, captions, sound, motion, transition.', 'inputs' => ['scene_id' => 'int', 'settings' => 'object of scene settings']],
            'reorder_scenes' => ['scene' => false, 'family' => 'script_and_structure', 'spends' => false, 'description' => 'Set the scene order.', 'inputs' => ['scene_ids' => 'int[] in the new order']],
            'add_scene' => ['scene' => false, 'family' => 'script_and_structure', 'spends' => false, 'description' => 'Insert a scene (after a scene, or at the end).', 'inputs' => ['after_scene_id' => 'int?', 'script_text' => 'string?', 'label' => 'string?', 'visual_type' => 'string?', 'visual_prompt' => 'string?', 'visual_style' => 'string?', 'duration_seconds' => 'int?', 'character_id' => 'int?', 'visual_asset_id' => 'int?']],
            'duplicate_scene' => ['scene' => true, 'family' => 'script_and_structure', 'spends' => false, 'description' => 'Duplicate a scene after itself.', 'inputs' => ['scene_id' => 'int']],
            'rewrite_scene' => ['scene' => true, 'family' => 'script_and_structure', 'spends' => false, 'description' => 'DIRECT APPLY: generate and replace the script when the proposal is approved; no candidate preview. To review first, supply your reviewed text using update_scene.', 'inputs' => ['scene_id' => 'int', 'mode' => implode('|', self::REWRITE_MODES)]],
            'use_animation_history' => ['scene' => true, 'family' => 'visuals', 'spends' => false, 'description' => 'Restore an owned clip from this scene animation history without generation.', 'inputs' => ['scene_id' => 'int', 'asset_id' => 'int from animation_history']],
            'rerecord_all' => ['scene' => false, 'family' => 'bulk', 'spends' => true, 'description' => 'Re-record eligible scenes with their own voices; preview lists all costs and skips.', 'inputs' => ['scene_ids' => 'int[] optional; absent means all eligible']],
            'restyle_all' => ['scene' => false, 'family' => 'bulk', 'spends' => true, 'description' => 'Restyle eligible scenes using their own prompts. No shared prompt override.', 'inputs' => ['scene_ids' => 'int[]?', 'style' => 'visual style', 'model_key' => 'string?', 'custom_visual_style' => 'string?']],
            'animate_all' => ['scene' => false, 'family' => 'bulk', 'spends' => true, 'description' => 'Animate eligible scenes; identical source stills share one paid render except spokesperson.', 'inputs' => ['scene_ids' => 'int[]?', 'tier' => 'animation tier', 'source_asset_id' => 'int? replaces selected stills', 'quality' => 'tier option?', 'duration_seconds' => '3..10?', 'motion_prompt' => 'string?', 'consent' => 'bool?']],
            'use_narration' => ['scene' => true, 'family' => 'narration', 'spends' => false, 'description' => 'Attach workspace audio. audio_only keeps script; audio_and_script requires completed transcription and freezes it in this proposal.', 'inputs' => ['scene_id' => 'int', 'asset_id' => 'int', 'mode' => 'audio_only|audio_and_script']],
            'regenerate_voice' => ['scene' => true, 'family' => 'narration', 'spends' => true, 'description' => 'Re-record the scene\'s narration with its current voice and script.', 'inputs' => ['scene_id' => 'int']],
            'swap_visual' => ['scene' => true, 'family' => 'visuals', 'spends' => false, 'description' => 'Replace the visual with a library asset, or search stock by query.', 'inputs' => ['scene_id' => 'int', 'visual_asset_id' => 'int?', 'query' => 'string?', 'visual_type' => 'string?']],
            'generate_image' => ['scene' => true, 'family' => 'visuals', 'spends' => true, 'description' => 'Generate an AI still from the scene\'s visual prompt (and character, if set).', 'inputs' => ['scene_id' => 'int', 'model_key' => 'string?', 'style' => 'string?', 'prompt_override' => 'string?']],
            'edit_image' => ['scene' => true, 'family' => 'visuals', 'spends' => true, 'description' => 'Edit the scene\'s image with an instruction.', 'inputs' => ['scene_id' => 'int', 'instruction' => 'string', 'model_key' => 'string?']],
            'animate' => ['scene' => true, 'family' => 'visuals', 'spends' => true, 'description' => 'Animate the scene\'s still into a motion clip (or a lip-synced spokesperson).', 'inputs' => ['scene_id' => 'int', 'tier' => implode('|', self::ANIMATE_TIERS), 'duration_seconds' => '3..10?', 'motion_prompt' => 'string?', 'lipsync_engine' => 'string?', 'quality' => 'string?', 'consent' => 'bool?']],
            'cancel_animation' => ['scene' => true, 'family' => 'visuals', 'spends' => false, 'description' => 'Cancel an animation in progress.', 'inputs' => ['scene_id' => 'int']],
            'revert_animation' => ['scene' => true, 'family' => 'visuals', 'spends' => false, 'description' => 'Go back to the still.', 'inputs' => ['scene_id' => 'int']],
            'regenerate_music' => ['scene' => true, 'family' => 'music_and_sound', 'spends' => true, 'description' => 'Generate a new music bed for the scene from a mood.', 'inputs' => ['scene_id' => 'int', 'mood' => 'string', 'duration_seconds' => '3..30?']],
            'update_project' => ['scene' => false, 'family' => 'project_settings', 'spends' => false, 'description' => 'Change title, aspect ratio, channel, brand kit, music track and music settings.', 'inputs' => ['title' => 'string?', 'aspect_ratio' => '9:16|1:1|16:9?', 'channel_id' => 'int?', 'brand_kit_id' => 'int?', 'music_asset_id' => 'int?', 'music_settings_json' => 'object?']],
            'generate_hooks' => ['scene' => false, 'family' => 'project_settings', 'spends' => false, 'description' => 'Re-roll ranked hook options from the script.', 'inputs' => []],
        ];
    }

    /** @return array<string, array<int, mixed>> laravel rules for a change of this op */
    public static function rules(string $op): array
    {
        $sid = ['required', 'integer'];

        return match ($op) {
            'update_scene' => ['scene_id' => $sid, 'settings' => ['required', 'array', 'min:1']],
            'reorder_scenes' => ['scene_ids' => ['required', 'array', 'min:1'], 'scene_ids.*' => ['integer', 'distinct']],
            'add_scene' => ['after_scene_id' => ['nullable', 'integer'], 'script_text' => ['nullable', 'string', 'max:5000'], 'label' => ['nullable', 'string', 'max:120'], 'visual_type' => ['nullable', 'string', 'max:64'], 'visual_prompt' => ['nullable', 'string', 'max:2000'], 'visual_style' => ['nullable', 'string', 'max:64'], 'duration_seconds' => ['nullable', 'integer', 'min:1', 'max:120'], 'character_id' => ['nullable', 'integer'], 'visual_asset_id' => ['nullable', 'integer'], 'scene_type' => ['nullable', 'string', 'max:32']],
            'duplicate_scene', 'regenerate_voice', 'cancel_animation', 'revert_animation' => ['scene_id' => $sid],
            'use_narration' => ['scene_id' => $sid, 'asset_id' => ['required', 'integer'], 'mode' => ['required', 'in:audio_only,audio_and_script']],
            'use_animation_history' => ['scene_id' => $sid, 'asset_id' => ['required', 'integer']],
            'rerecord_all' => ['scene_ids' => ['sometimes', 'array'], 'scene_ids.*' => ['integer', 'distinct']],
            'restyle_all' => ['scene_ids' => ['sometimes', 'array'], 'scene_ids.*' => ['integer', 'distinct'], 'style' => ['required', 'string'], 'model_key' => ['nullable', 'string'], 'custom_visual_style' => ['nullable', 'string', 'max:500']],
            'animate_all' => array_diff_key(self::rules('animate'), ['scene_id' => true, 'lipsync_engine' => true]) + ['scene_ids' => ['sometimes', 'array'], 'scene_ids.*' => ['integer', 'distinct'], 'source_asset_id' => ['nullable', 'integer']],
            'rewrite_scene' => ['scene_id' => $sid, 'mode' => ['required', 'in:'.implode(',', self::REWRITE_MODES)]],
            'swap_visual' => ['scene_id' => $sid, 'visual_asset_id' => ['nullable', 'integer'], 'query' => ['nullable', 'string', 'max:200'], 'visual_type' => ['nullable', 'string', 'max:64']],
            'generate_image' => ['scene_id' => $sid, 'style' => ['nullable', 'string', 'max:64'], 'prompt_override' => ['nullable', 'string', 'max:1000'], 'model_key' => ['nullable', 'string', 'max:40']],
            'edit_image' => ['scene_id' => $sid, 'instruction' => ['required', 'string', 'max:2000'], 'model_key' => ['nullable', 'string', 'max:40']],
            'animate' => ['scene_id' => $sid, 'tier' => ['required', 'in:'.implode(',', self::ANIMATE_TIERS)], 'duration_seconds' => ['nullable', 'integer', 'min:3', 'max:10'], 'motion_prompt' => ['nullable', 'string', 'max:1000'], 'lipsync_engine' => ['nullable', 'string', 'max:32'], 'quality' => ['nullable', 'string', 'max:16'], 'consent' => ['nullable', 'boolean']],
            'regenerate_music' => ['scene_id' => $sid, 'mood' => ['required', 'string', 'min:2', 'max:100'], 'duration_seconds' => ['nullable', 'integer', 'min:3', 'max:30']],
            'update_project' => ['title' => ['nullable', 'string', 'max:255'], 'aspect_ratio' => ['nullable', 'in:9:16,1:1,16:9'], 'channel_id' => ['nullable', 'integer'], 'brand_kit_id' => ['nullable', 'integer'], 'music_asset_id' => ['nullable', 'integer'], 'music_settings_json' => ['nullable', 'array']],
            'generate_hooks' => [],
            default => throw new \InvalidArgumentException("Unknown operation {$op}"),
        };
    }

    /** Credits this change will cost at most, the way the dashboard prices it before charging. */
    public static function price(string $op, Project $project, ?Scene $scene, array $c): int
    {
        return match ($op) {
            'rerecord_all', 'restyle_all', 'animate_all' => (int) ($c['preview']['total_cost'] ?? 0),
            'regenerate_voice' => CreditService::ttsCostForEngine(RoutingTTSAdapter::engineFor((string) data_get($scene?->voice_settings_json, 'voice_id', GeminiVoices::DEFAULT_VOICE), (array) ($scene?->voice_settings_json ?? []))),
            'generate_image' => app(ImageAdapterFactory::class)->generationCost($c['model_key'] ?? null, (bool) ($scene?->character_id && \App\Models\Character::query()->whereKey($scene->character_id)->where('workspace_id', $project->workspace_id)->whereNotNull('reference_asset_id')->exists())),
            'edit_image' => app(ImageAdapterFactory::class)->referenceGenerationCost(($c['model_key'] ?? null) ?: \App\Jobs\EditSceneImageJob::EDIT_MODEL),
            'animate' => (function () use ($c, $scene) {
                $seconds = ((int) ($c['duration_seconds'] ?? 5)) >= 8 ? 10 : 5;
                if ($c['tier'] === 'spokesperson') {
                    $audioId = data_get($scene?->voice_settings_json, 'audio_asset_id');
                    $audio = $audioId ? Asset::query()->find($audioId) : null;
                    $voiceoverSeconds = (float) ($audio?->duration_seconds ?: $scene?->duration_seconds ?: 8);

                    return (int) CreditService::spokespersonCost($voiceoverSeconds, $c['lipsync_engine'] ?? data_get($scene?->image_generation_settings_json, 'lipsync_engine'));
                }

                return CreditService::animationCost($c['tier'], CreditService::videoQuality($c['tier'], $c['quality'] ?? null), $seconds);
            })(),
            'regenerate_music' => CreditService::AI_MUSIC,
            default => 0,
        };
    }

    /**
     * Execute one validated change as this user. Returns the dashboard's
     * decoded `data`, or its error response untouched.
     *
     * @return array<string, mixed>|JsonResponse
     */
    public static function execute(string $op, Request $outer, Project $project, array $c): array|JsonResponse
    {
        $sceneController = fn () => app(SceneController::class);
        $projectController = fn () => app(ProjectController::class);
        $req = fn (array $payload, string $method = 'POST') => self::inner($outer, $payload, $method);
        $sid = (int) ($c['scene_id'] ?? 0);

        if (isset(BulkEdits::CONTROLLERS[$op])) return BulkEdits::execute($op, $outer, $project, $c);
        return self::run(fn () => match ($op) {
            'use_narration' => $sceneController()->update($req(['voice_settings_json' => ['audio_asset_id' => $c['asset_id'], 'custom_audio' => true]] + ($c['mode'] === 'audio_and_script' ? ['script_text' => $c['transcript_text']] : []), 'PATCH'), $sid),
            'update_scene' => $sceneController()->update($req($c['settings'], 'PATCH'), $sid),
            'reorder_scenes' => $sceneController()->reorder($req(['project_id' => $project->getKey(), 'scene_ids' => $c['scene_ids']], 'PATCH')),
            'add_scene' => $sceneController()->store($req(array_filter(['project_id' => $project->getKey(), 'insert_after_scene_id' => $c['after_scene_id'] ?? null] + $c, fn ($v) => $v !== null))),
            'duplicate_scene' => $sceneController()->duplicate($req([]), $sid),
            'rewrite_scene' => $sceneController()->rewrite($req(['mode' => $c['mode'], 'apply' => true]), $sid, app(\App\Services\Generation\AI\AIGenerationAdapter::class)),
            'regenerate_voice' => $sceneController()->regenerateVoice($req([]), $sid, app(\App\Services\Generation\TTS\TTSAdapter::class), app(\App\Services\Media\MediaTranscriptionService::class)),
            'swap_visual' => ! empty($c['visual_asset_id'])
                ? $sceneController()->update($req(array_filter(['visual_asset_id' => $c['visual_asset_id'], 'visual_type' => $c['visual_type'] ?? null], fn ($v) => $v !== null), 'PATCH'), $sid)
                : $sceneController()->swapVisual($req(array_filter(['query' => $c['query'] ?? null, 'visual_type' => $c['visual_type'] ?? null], fn ($v) => $v !== null)), $sid, app(\App\Services\Generation\Visual\VisualProviderAdapter::class)),
            'generate_image' => $sceneController()->generateImage($req(array_filter(array_intersect_key($c, array_flip(['model_key', 'style', 'prompt_override'])), fn ($v) => $v !== null)), $sid),
            'edit_image' => $sceneController()->editImage($req(array_filter(['instruction' => $c['instruction'], 'model_key' => $c['model_key'] ?? null], fn ($v) => $v !== null)), $sid),
            'animate' => $sceneController()->animate($req(array_filter(array_intersect_key($c, array_flip(['tier', 'duration_seconds', 'motion_prompt', 'lipsync_engine', 'quality', 'consent'])), fn ($v) => $v !== null)), $sid),
            'cancel_animation' => $sceneController()->cancelAnimation($req([]), $sid),
            'use_animation_history' => $sceneController()->useAnimationFromHistory($req(['asset_id' => $c['asset_id']]), $sid),
            'revert_animation' => $sceneController()->revertAnimation($req([]), $sid),
            'regenerate_music' => $sceneController()->regenerateMusic($req(array_filter(['mood' => $c['mood'], 'duration_seconds' => $c['duration_seconds'] ?? null], fn ($v) => $v !== null)), $sid),
            'update_project' => $projectController()->update($req(array_intersect_key($c, array_flip(['title', 'aspect_ratio', 'channel_id', 'brand_kit_id', 'music_asset_id', 'music_settings_json'])), 'PATCH'), $project->getKey()),
            'generate_hooks' => $projectController()->generateHooks($req([]), $project->getKey()),
            default => throw new \InvalidArgumentException("Unknown operation {$op}"),
        });
    }

    public static function inner(Request $outer, array $payload, string $method = 'POST'): Request
    {
        $inner = Request::create('/internal/editor', $method, $payload);
        $inner->headers->set('Accept', 'application/json');
        $inner->setUserResolver(fn () => $outer->user());
        $inner->attributes->set('api_key_id', $outer->attributes->get('api_key_id'));
        $inner->attributes->set('auth_session_id', $outer->attributes->get('auth_session_id'));

        return $inner;
    }

    /** @return array<string, mixed>|JsonResponse */
    public static function run(callable $action): array|JsonResponse
    {
        try {
            $response = $action();
        } catch (ValidationException $e) {
            return response()->json(['error' => ['code' => 'validation_failed', 'message' => collect($e->errors())->flatten()->first() ?? 'Invalid request.', 'context' => ['errors' => $e->errors()]]], 422);
        }
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        return $response->getData(true)['data'] ?? [];
    }
}
