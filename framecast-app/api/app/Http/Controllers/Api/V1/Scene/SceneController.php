<?php

namespace App\Http\Controllers\Api\V1\Scene;

use App\Constants\CaptionFonts;
use App\Http\Controllers\Controller;
use App\Jobs\GenerateAIImageJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Generation\Visual\VisualProviderAdapter;
use App\Services\Media\MediaTranscriptionService;
use App\Services\Media\StorageService;
use App\Services\WorkspaceUsageService;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Throwable;

class SceneController extends Controller
{
    public function __construct(
        private readonly WorkspaceUsageService $usageService,
        private readonly CreditService $credits,
    ) {}

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'insert_after_scene_id' => ['sometimes', 'nullable', 'integer'],
            'scene_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'script_text' => ['sometimes', 'nullable', 'string'],
            'duration_seconds' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:600'],
            'visual_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'visual_prompt' => ['sometimes', 'nullable', 'string'],
            'visual_style' => ['sometimes', 'nullable', 'string', 'in:cinematic,dark,anime,documentary,minimalist,realistic,vintage,neon,photorealistic,cyberpunk_80s,anime_80s,anime_90s,dark_fantasy,fantasy_retro,comic,film_noir,line_drawing,watercolor,paper_cutout,cartoon,3d_animated,custom'],
            'custom_visual_style' => ['sometimes', 'nullable', 'string', 'max:500'],
            // Pre-attached visual + character (used by the add-scene panel's
            // Assets tab to skip the "create then assign" two-step).
            'visual_asset_id' => ['sometimes', 'nullable', 'integer'],
            'character_id' => ['sometimes', 'nullable', 'integer'],
        ]);

        // Sanity-check ownership of any asset id passed.
        if (! empty($validated['visual_asset_id'])) {
            $assetOk = Asset::query()
                ->whereKey($validated['visual_asset_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();
            if (! $assetOk) {
                return $this->error('invalid_asset', 'Selected asset is not in this workspace.', 422);
            }
        }
        if (! empty($validated['character_id'])) {
            $characterOk = Character::query()
                ->whereKey($validated['character_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('status', 'active')
                ->exists();
            if (! $characterOk) {
                return $this->error('invalid_character', 'Selected character is not in this workspace.', 422);
            }
        }

        $project = Project::query()
            ->whereKey($validated['project_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $insertAfterScene = null;
        if (! empty($validated['insert_after_scene_id'])) {
            $insertAfterScene = Scene::query()
                ->whereKey($validated['insert_after_scene_id'])
                ->where('project_id', $project->getKey())
                ->first();

            if (! $insertAfterScene) {
                return $this->error('invalid_scene_scope', 'Insert target scene was not found in this project.', 422);
            }
        }

        $scene = DB::transaction(function () use ($validated, $project, $insertAfterScene): Scene {
            $existingSceneIds = Scene::query()
                ->where('project_id', $project->getKey())
                ->orderBy('scene_order')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $scene = Scene::query()->create([
                'project_id' => $project->getKey(),
                'scene_order' => count($existingSceneIds) + 1,
                'scene_type' => $validated['scene_type'] ?? 'narration',
                'label' => $validated['label'] ?? null,
                'script_text' => $validated['script_text'] ?? null,
                'duration_seconds' => $validated['duration_seconds'] ?? 3.0,
                'voice_profile_id' => null,
                'voice_settings_json' => [
                    'voice_id' => \App\Services\Generation\TTS\GeminiVoices::DEFAULT_VOICE,
                    'provider' => 'google',
                    'speed' => 1.0,
                    'stability' => 'medium',
                    'language' => (string) ($project->primary_language ?: 'en'),
                    'is_outdated' => true,
                ],
                'caption_settings_json' => [
                    'enabled' => true,
                    'style_key' => 'impact',
                    'highlight_mode' => 'keywords',
                    'position' => 'bottom_third',
                    'font' => 'Bebas Neue',
                    'highlight_color' => '#ff6b35',
                    'preset_id' => null,
                ],
                'visual_type' => $validated['visual_type'] ?? 'stock_clip',
                // Pre-attached asset from the add-scene Assets tab (selected
                // via MediaPickerModal). Falls to null when no asset picked.
                'visual_asset_id' => $validated['visual_asset_id'] ?? null,
                'visual_prompt' => $validated['visual_prompt'] ?? null,
                // Scenes added in the editor inherit project defaults so AI
                // image gen on them matches the rest of the project. Order of
                // precedence: explicit request value → project.default_visual_style
                // → project.ai_broll_style → null.
                'visual_style' => $validated['visual_style']
                    ?? $project->default_visual_style
                    ?? $project->ai_broll_style
                    ?? null,
                // Custom descriptor: stored on the scene only when explicitly
                // passed; otherwise null and the adapter falls back to
                // project.custom_visual_style at prompt-build time.
                'custom_visual_style' => $validated['custom_visual_style'] ?? null,
                // Explicit per-scene character override beats project default.
                // Empty request value → inherit project.default_character_id.
                'character_id' => $validated['character_id']
                    ?? $project->default_character_id,
                'transition_rule' => null,
                'status' => 'draft',
                'locked_fields_json' => [],
            ]);

            $insertIndex = count($existingSceneIds);
            if ($insertAfterScene) {
                $existingIndex = array_search((int) $insertAfterScene->getKey(), $existingSceneIds, true);
                $insertIndex = $existingIndex === false ? $insertIndex : $existingIndex + 1;
            }

            array_splice($existingSceneIds, $insertIndex, 0, [(int) $scene->getKey()]);
            $this->syncSceneOrder($project->getKey(), $existingSceneIds);

            return $scene->fresh();
        });

        return response()->json([
            'data' => [
                'scene' => $this->serializeScene($scene),
            ],
            'meta' => [],
        ], 201);
    }

    public function update(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);

        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $validated = $request->validate([
            'label' => ['sometimes', 'nullable', 'string', 'max:255'],
            'script_text' => ['sometimes', 'nullable', 'string'],
            'duration_seconds' => ['sometimes', 'nullable', 'numeric', 'min:0', 'max:600'],
            'scene_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'visual_type' => ['sometimes', 'nullable', 'string', 'max:64'],
            'visual_asset_id' => ['sometimes', 'nullable', 'integer'],
            'character_id' => ['sometimes', 'nullable', 'integer'],
            'sound_asset_id' => ['sometimes', 'nullable', 'integer'],
            'sound_settings_json' => ['sometimes', 'nullable', 'array'],
            'visual_prompt' => ['sometimes', 'nullable', 'string'],
            'transition_rule' => ['sometimes', 'nullable', 'string', 'max:64'],
            'voice_profile_id' => ['sometimes', 'nullable', 'integer'],
            'voice_settings_json' => ['sometimes', 'nullable', 'array'],
            'caption_settings_json' => ['sometimes', 'nullable', 'array'],
            'caption_settings_json.enabled' => ['sometimes', 'boolean'],
            'caption_settings_json.style_key' => ['sometimes', 'string', 'in:impact,editorial,hacker'],
            'caption_settings_json.highlight_mode' => ['sometimes', 'string', 'in:keywords,word_by_word,line_by_line,none'],
            'caption_settings_json.position' => ['sometimes', 'string', 'in:bottom_third,center,top_third'],
            'caption_settings_json.font' => ['sometimes', 'nullable', 'string', \Illuminate\Validation\Rule::in(CaptionFonts::ALL)],
            'caption_settings_json.highlight_color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'caption_settings_json.color' => ['sometimes', 'nullable', 'string', 'max:32'],
            'caption_settings_json.size' => ['sometimes', 'nullable', 'string', 'in:small,medium,large,xlarge'],
            'caption_settings_json.preset_id' => ['sometimes', 'nullable'],
            'visual_style' => ['sometimes', 'nullable', 'string', 'max:64'],
            'custom_visual_style' => ['sometimes', 'nullable', 'string', 'max:500'],
            'motion_settings_json' => ['sometimes', 'nullable', 'array'],
            'motion_settings_json.effect' => ['sometimes', 'string', 'in:zoom_in,zoom_out,pan_left,pan_right,pan_up,pan_down,pan_zoom,static'],
            'motion_settings_json.intensity' => ['sometimes', 'string', 'in:subtle,moderate,dramatic'],
            'motion_settings_json.fit' => ['sometimes', 'string', 'in:fit,crop'],
            'image_generation_settings_json' => ['sometimes', 'nullable', 'array'],
            'locked_fields_json' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        if (array_key_exists('visual_asset_id', $validated) && $validated['visual_asset_id'] !== null) {
            $assetExists = Asset::query()
                ->whereKey($validated['visual_asset_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();

            if (! $assetExists) {
                return $this->error('invalid_asset', 'Asset not found in this workspace.', 422);
            }
        }

        if (array_key_exists('sound_asset_id', $validated) && $validated['sound_asset_id'] !== null) {
            $assetExists = Asset::query()
                ->whereKey($validated['sound_asset_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();

            if (! $assetExists) {
                return $this->error('invalid_asset', 'Sound asset not found in this workspace.', 422);
            }
        }

        if (array_key_exists('character_id', $validated) && $validated['character_id'] !== null) {
            $characterExists = \App\Models\Character::query()
                ->whereKey($validated['character_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('status', 'active')
                ->exists();

            if (! $characterExists) {
                return $this->error('invalid_character', 'Character not found in this workspace.', 422);
            }
        }

        $scene->fill($validated);
        $scene->save();

        return response()->json([
            'data' => [
                'scene' => $this->serializeScene($scene->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function generateDraft(Request $request, AIGenerationAdapter $ai): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'insert_after_scene_id' => ['sometimes', 'nullable', 'integer'],
            'scene_type' => ['required', 'string', 'max:64'],
            'current_text' => ['sometimes', 'nullable', 'string'],
        ]);

        $project = Project::query()
            ->whereKey($validated['project_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        $insertAfterSceneId = ! empty($validated['insert_after_scene_id'])
            ? (int) $validated['insert_after_scene_id']
            : null;

        if ($insertAfterSceneId !== null && ! $scenes->firstWhere('id', $insertAfterSceneId)) {
            return $this->error('invalid_scene_scope', 'Insert target scene was not found in this project.', 422);
        }

        $context = $this->sceneContext($scenes, $insertAfterSceneId);
        $generation = $ai->generate(
            'scene_insert',
            [
                'project_title' => (string) ($project->title ?: 'Untitled project'),
                'language' => (string) ($project->primary_language ?: 'en'),
                'tone' => (string) ($project->tone ?: 'neutral'),
                'scene_type' => (string) $validated['scene_type'],
                'current_text' => trim((string) ($validated['current_text'] ?? '')),
                'previous_scene' => $context['previous'],
                'next_scene' => $context['next'],
                'scene_outline' => $context['outline'],
            ],
            220,
            0.6
        );

        $candidate = trim((string) ($generation['content'] ?? ''));

        if ($candidate === '') {
            return $this->error('draft_generation_failed', 'Scene draft generation returned empty content.', 422);
        }

        return response()->json([
            'data' => [
                'draft' => [
                    'candidate' => $candidate,
                    'provider_key' => $generation['provider_key'],
                    'model' => $generation['model'],
                    'tokens_used' => $generation['tokens_used'],
                ],
            ],
            'meta' => [],
        ]);
    }

    public function reorder(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'scene_ids' => ['required', 'array', 'min:1'],
            'scene_ids.*' => ['required', 'integer', 'distinct'],
        ]);

        $scenes = Scene::query()
            ->where('project_id', $validated['project_id'])
            ->whereIn('id', $validated['scene_ids'])
            ->whereHas('project', function ($query) use ($user): void {
                $query->where('workspace_id', $user->workspace_id);
            })
            ->get();

        if ($scenes->count() !== count($validated['scene_ids'])) {
            return $this->error('invalid_scene_scope', 'One or more scenes are missing or outside the current workspace.', 422);
        }

        DB::transaction(function () use ($validated): void {
            $this->syncSceneOrder((int) $validated['project_id'], array_map('intval', $validated['scene_ids']));
        });

        $reordered = Scene::query()
            ->where('project_id', $validated['project_id'])
            ->orderBy('scene_order')
            ->get();

        return response()->json([
            'data' => [
                'scenes' => $reordered->map(fn (Scene $scene): array => $this->serializeScene($scene))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function duplicate(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);

        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $duplicate = DB::transaction(function () use ($scene): Scene {
            $copy = $scene->replicate();
            $orderedSceneIds = Scene::query()
                ->where('project_id', $scene->project_id)
                ->orderBy('scene_order')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $copy->scene_order = count($orderedSceneIds) + 1;
            $copy->status = 'draft';
            $copy->save();

            $insertIndex = array_search((int) $scene->getKey(), $orderedSceneIds, true);
            $insertIndex = $insertIndex === false ? count($orderedSceneIds) : $insertIndex + 1;
            array_splice($orderedSceneIds, $insertIndex, 0, [(int) $copy->getKey()]);
            $this->syncSceneOrder((int) $scene->project_id, $orderedSceneIds);

            return $copy->fresh();
        });

        return response()->json([
            'data' => [
                'scene' => $this->serializeScene($duplicate),
            ],
            'meta' => [],
        ], 201);
    }

    public function rewrite(Request $request, int $sceneId, AIGenerationAdapter $ai): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);

        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $validated = $request->validate([
            'mode' => ['required', 'string', 'in:shorten,expand,stronger_hook,more_punchy,more_educational,more_salesy,simplify,scarier,more_dramatic,more_documentary'],
            'apply' => ['nullable', 'boolean'],
        ]);

        $lockedFields = is_array($scene->locked_fields_json) ? $scene->locked_fields_json : [];

        if (in_array('script_text', $lockedFields, true)) {
            return $this->error('scene_locked', 'Scene script is locked and cannot be rewritten.', 422);
        }

        $context = $this->sceneContextForScene($scene);
        $generation = $ai->generate(
            'scene_rewrite',
            [
                'mode' => $validated['mode'],
                'project_title' => (string) ($scene->project?->title ?: 'Untitled project'),
                'language' => (string) ($scene->project?->primary_language ?: 'en'),
                'scene_type' => (string) ($scene->scene_type ?: 'narration'),
                'scene_label' => (string) ($scene->label ?: ''),
                'script_text' => (string) ($scene->script_text ?: ''),
                // Project brief (theme/topic/tone/recurring subject) so the
                // rewrite fits the whole video's direction, not just the
                // neighbouring scenes' wording.
                'project_brief' => $this->projectBriefLine($scene->project),
                'previous_scene' => $context['previous'],
                'next_scene' => $context['next'],
                'scene_outline' => $context['outline'],
            ],
            450,
            0.55
        );

        $candidate = trim((string) ($generation['content'] ?? ''));
        $apply = (bool) ($validated['apply'] ?? false);

        if ($candidate === '') {
            return $this->error('rewrite_failed', 'Scene rewrite returned empty content.', 422);
        }

        if ($apply) {
            $voiceSettings = is_array($scene->voice_settings_json) ? $scene->voice_settings_json : [];
            $voiceSettings['is_outdated'] = true;

            $scene->forceFill([
                'script_text' => $candidate,
                'status' => 'edited',
                'voice_settings_json' => $voiceSettings,
            ])->save();
        }

        return response()->json([
            'data' => [
                'rewrite' => [
                    'mode' => $validated['mode'],
                    'candidate' => $candidate,
                    'provider_key' => $generation['provider_key'],
                    'model' => $generation['model'],
                    'tokens_used' => $generation['tokens_used'],
                    'applied' => $apply,
                ],
                'scene' => $this->serializeScene($scene->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function preview(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);

        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $visualAsset = $scene->visual_asset_id
            ? Asset::query()->whereKey($scene->visual_asset_id)->first()
            : null;

        $audioAssetId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
        $audioAsset = $audioAssetId > 0
            ? Asset::query()->whereKey($audioAssetId)->first()
            : null;

        return response()->json([
            'data' => [
                'scene' => $this->serializeScene($scene),
                'preview' => [
                    'visual_url' => $visualAsset ? $this->assetUrl($visualAsset) : null,
                    'audio_url' => $audioAsset ? $this->assetUrl($audioAsset) : null,
                ],
            ],
            'meta' => [],
        ]);
    }

    public function regenerateVoice(
        Request $request,
        int $sceneId,
        TTSAdapter $tts,
        MediaTranscriptionService $transcription
    ): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);

        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        if ($this->usageService->hasExceededApiBudget($user)) {
            $ctx = $this->usageService->apiBudgetContext($user);
            return $this->limitError(
                'api_budget_exceeded',
                "Your workspace has reached its \${$ctx['budget_usd']} AI budget for the {$ctx['plan']} plan this month.",
                $ctx,
            );
        }

        if ($this->usageService->hasReachedVoiceLimit($user)) {
            $ctx = $this->usageService->voiceLimitContext($user);
            return $this->limitError(
                'voice_limit_reached',
                "Your workspace has used {$ctx['used']} of {$ctx['limit']} voice minutes on the {$ctx['plan']} plan.",
                $ctx,
            );
        }

        $project = $scene->project;

        if (! $project) {
            return $this->error('invalid_scene_scope', 'Scene is missing its project context.', 422);
        }

        $voiceId = (string) data_get($scene->voice_settings_json, 'voice_id', \App\Services\Generation\TTS\GeminiVoices::DEFAULT_VOICE);
        $speed = (float) data_get($scene->voice_settings_json, 'speed', 1.0);
        $language = (string) data_get($scene->voice_settings_json, 'language', $project->primary_language ?: 'en');
        $provider = (string) data_get($scene->voice_settings_json, 'provider', '');
        $voicePrompt = (string) data_get($scene->voice_settings_json, 'voice_prompt', '');
        $cloneUrl = $this->cloneAudioUrl((int) $project->workspace_id, $voiceId, $provider);
        $sceneText = trim((string) ($scene->script_text ?: ''));

        if ($sceneText === '') {
            return $this->error('invalid_scene_state', 'Scene script is required before regenerating voice.', 422);
        }

        $asset = null;

        DB::transaction(function () use ($scene, $project, $user, $tts, $voiceId, $speed, $language, $provider, $voicePrompt, $cloneUrl, $sceneText, &$asset): void {
            $audio = $tts->synthesize($sceneText, $language, $voiceId, $speed, [
                'provider'        => $provider,
                'voice_prompt'    => $voicePrompt,
                'clone_audio_url' => $cloneUrl,
                'usage_context' => [
                    'workspace_id' => $project->workspace_id,
                    'project_id' => $project->getKey(),
                    'user_id' => $user->getKey(),
                    'scene_id' => $scene->getKey(),
                    'manual_voice_regeneration' => true,
                ],
            ]);

            $asset = Asset::query()->create([
                'workspace_id' => $project->workspace_id,
                'channel_id' => $project->channel_id,
                'asset_type' => 'audio',
                'title' => 'TTS audio for project '.$project->getKey().' scene '.$scene->scene_order,
                'description' => mb_substr($sceneText, 0, 180),
                'storage_url' => $audio['audio_url'],
                'duration_seconds' => $audio['duration_seconds'],
                'mime_type' => 'audio/mpeg',
                'transcription_status' => 'queued',
                'tags' => ['tts', $audio['provider_key']],
                'metadata_json' => [
                    'caption_timing_status' => 'queued',
                ],
                'usage_count' => 1,
                'status' => 'active',
                'created_by_user_id' => $project->created_by_user_id,
            ]);

            $voiceSettings = is_array($scene->voice_settings_json) ? $scene->voice_settings_json : [];
            $pk = (string) $audio['provider_key'];
            $voiceSettings['provider_key'] = $pk;
            $voiceSettings['provider'] = str_contains($pk, 'chatterbox')
                ? 'replicate:chatterbox'
                : (str_contains($pk, 'gemini') ? 'google' : 'openai');
            $voiceSettings['voice_id'] = $audio['provider_voice_id'];
            $voiceSettings['speed'] = $speed;
            $voiceSettings['language'] = $language;
            $voiceSettings['audio_asset_id'] = $asset->getKey();
            $voiceSettings['is_outdated'] = false;

            // Talking-spokesperson clip was lip-synced to the OLD audio — the new
            // voice no longer matches the lips. Flag it so the editor prompts a
            // re-render (mirrors GenerateTTSJob).
            $imgSettings = is_array($scene->image_generation_settings_json) ? $scene->image_generation_settings_json : [];
            if (
                ($imgSettings['animation_tier'] ?? null) === 'spokesperson'
                && ! empty($imgSettings['animation_video_asset_id'])
                && (int) ($imgSettings['animation_source_audio_asset_id'] ?? 0) !== (int) $asset->getKey()
            ) {
                $imgSettings['animation_outdated'] = true;
            }

            $scene->forceFill([
                'duration_seconds' => $audio['duration_seconds'],
                'voice_settings_json' => $voiceSettings,
                'image_generation_settings_json' => $imgSettings,
                'status' => 'edited',
            ])->save();
        });

        if ($asset) {
            $this->attachCaptionTiming($asset, $transcription);
        }

        $scene->refresh();
        $voiceSettings = is_array($scene->voice_settings_json) ? $scene->voice_settings_json : [];
        $voiceSettings['is_outdated'] = false;
        $scene->forceFill([
            'voice_settings_json' => $voiceSettings,
        ])->save();

        return response()->json([
            'data' => [
                'scene' => $this->serializeScene($scene->fresh()),
            ],
            'meta' => [],
        ]);
    }

    /**
     * Cloned-voice reference sample → a Replicate-fetchable URL (Chatterbox is
     * zero-shot, so synthesis needs the sample every call). Null for non-clones.
     */
    private function cloneAudioUrl(int $workspaceId, string $voiceId, string $provider): ?string
    {
        if (! str_contains($provider, 'chatterbox') && ! str_starts_with($voiceId, 'clone-')) {
            return null;
        }

        $profile = \App\Models\VoiceProfile::query()
            ->where('workspace_id', $workspaceId)
            ->where('provider_voice_key', $voiceId)
            ->where('is_cloned', true)
            ->first();
        if (! $profile || ! $profile->source_asset_id) {
            return null;
        }

        $sample = Asset::query()->find($profile->source_asset_id);
        if (! $sample || ! $sample->storage_url) {
            return null;
        }

        $storage = app(\App\Services\Media\StorageService::class);
        $raw = (string) $sample->storage_url;

        return $storage->extractPath($raw) !== null ? $storage->url($raw) : $raw;
    }

    public function swapVisual(Request $request, int $sceneId, VisualProviderAdapter $visualProvider): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);

        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $project = $scene->project;

        if (! $project) {
            return $this->error('invalid_scene_scope', 'Scene is missing its project context.', 422);
        }

        $validated = $request->validate([
            'query' => ['sometimes', 'nullable', 'string'],
            'visual_type' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $requestedType = trim((string) ($validated['visual_type'] ?? $scene->visual_type ?? 'image_montage'));
        $noMediaTypes = ['text_card', 'waveform'];

        if (in_array($requestedType, $noMediaTypes, true)) {
            $prompt = trim((string) ($validated['query'] ?? $scene->visual_prompt ?? $scene->script_text ?? $scene->label ?? ''));
            $scene->forceFill([
                'visual_type' => $requestedType,
                'visual_asset_id' => null,
                'visual_prompt' => $prompt !== '' ? $prompt : null,
                'status' => 'edited',
            ])->save();

            return response()->json([
                'data' => [
                    'scene' => $this->serializeScene($scene->fresh()),
                ],
                'meta' => [],
            ]);
        }

        $prompt = trim((string) ($validated['query'] ?? $scene->visual_prompt ?? ''));

        if ($prompt === '') {
            $sceneLabel = $scene->label ?: 'scene';
            $sceneText = mb_substr(trim((string) $scene->script_text), 0, 160);
            $tone = $project->tone ?: 'neutral';
            $prompt = trim("{$sceneLabel}, {$tone} style, {$sceneText}");
        }

        $orientation = in_array((string) ($project->aspect_ratio ?? ''), ['16:9'], true) ? 'landscape' : 'portrait';
        $match = $visualProvider->match($prompt, $orientation, $requestedType);

        $asset = Asset::query()->create([
            'workspace_id' => $project->workspace_id,
            'channel_id' => $project->channel_id,
            'asset_type' => $match['asset_type'],
            'title' => 'Matched visual for project '.$project->getKey(),
            'description' => $prompt,
            'storage_url' => $match['asset_url'],
            'thumbnail_url' => $match['thumbnail_url'],
            'duration_seconds' => $match['duration_seconds'],
            'dimensions_json' => [
                'width' => $match['width'],
                'height' => $match['height'],
            ],
            'mime_type' => $match['mime_type'],
            'tags' => ['matched_visual', $match['provider_key']],
            'usage_count' => 1,
            'status' => 'active',
            'created_by_user_id' => $project->created_by_user_id,
        ]);

        $scene->forceFill([
            'visual_type' => $requestedType,
            'visual_asset_id' => $asset->getKey(),
            'visual_prompt' => $prompt,
            'status' => 'edited',
        ])->save();

        return response()->json([
            'data' => [
                'scene' => $this->serializeScene($scene->fresh()),
            ],
            'meta' => [],
        ]);
    }

    private function attachCaptionTiming(Asset $asset, MediaTranscriptionService $transcription): void
    {
        $asset->forceFill([
            'transcription_status' => 'processing',
            'metadata_json' => array_merge($asset->metadata_json ?? [], [
                'caption_timing_status' => 'processing',
            ]),
        ])->save();

        try {
            $result = $transcription->transcribeAssetWithTimestamps($asset);
            $words = $result['words'] ?? [];
            $segments = $result['segments'] ?? [];

            $asset->forceFill([
                'transcript_text' => $result['transcript'],
                'transcription_status' => 'completed',
                'transcription_error' => null,
                'metadata_json' => array_merge($asset->metadata_json ?? [], [
                    'transcription_provider' => $result['provider_key'],
                    'transcription_model' => $result['model'],
                    'transcribed_at' => now()->toIso8601String(),
                    'caption_timing_status' => count($words) > 0 ? 'completed' : 'unavailable',
                    'caption_timing' => [
                        'source' => $result['provider_key'],
                        'model' => $result['model'],
                        'words' => $words,
                        'segments' => $segments,
                        'generated_at' => now()->toIso8601String(),
                    ],
                ]),
            ])->save();
        } catch (Throwable $exception) {
            $asset->forceFill([
                'transcription_status' => 'failed',
                'transcription_error' => $exception->getMessage(),
                'metadata_json' => array_merge($asset->metadata_json ?? [], [
                    'caption_timing_status' => 'failed',
                    'caption_timing_error' => $exception->getMessage(),
                ]),
            ])->save();
        }
    }

    public function generateImage(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);

        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        if ($this->usageService->hasExceededApiBudget($user)) {
            $ctx = $this->usageService->apiBudgetContext($user);
            return $this->limitError(
                'api_budget_exceeded',
                "Your workspace has reached its \${$ctx['budget_usd']} AI budget for the {$ctx['plan']} plan this month.",
                $ctx,
            );
        }

        // Credit guard for AI image regeneration. Character-bound scenes route to
        // gpt-image-2 /edits (CharacterImageAdapter) which runs at high quality and
        // costs more than the DALL-E baseline, so we charge AI_CHARACTER instead.
        $aiQuality = $request->input('quality', 'medium');
        $usesCharacter = $scene->character_id
            && \App\Models\Character::query()
                ->whereKey($scene->character_id)
                ->where('workspace_id', $user->workspace_id)
                ->whereNotNull('reference_asset_id')
                ->exists();
        // Cost = exactly what GenerateAIImageJob will charge (model-driven, or
        // AI_CHARACTER for the reference path) so quote = charge. The job
        // computes the same way (no quality flag — the per-scene picker has no
        // medium/high split), so pass model + character only.
        $cost = app(\App\Services\Generation\Image\ImageAdapterFactory::class)
            ->generationCost($request->input('model_key'), $usesCharacter);
        $balance = $this->credits->balance((int) $user->workspace_id);
        if ($balance < $cost) {
            return response()->json([
                'error' => [
                    'code'    => 'insufficient_credits',
                    'message' => "You need {$cost} credits to regenerate this image. Your balance is {$balance}.",
                    'context' => ['balance' => $balance, 'required' => $cost, 'shortage' => $cost - $balance],
                ],
            ], 402);
        }

        // Guard: reject concurrent requests — only one generation per scene at a time.
        // Allow override if the lock is stale (job crashed > 5 minutes ago).
        $imageSettings = $scene->image_generation_settings_json ?? [];
        if (! empty($imageSettings['in_progress'])) {
            $startedAt = isset($imageSettings['generation_started_at'])
                ? \Carbon\Carbon::parse($imageSettings['generation_started_at'])
                : null;
            $isStale = $startedAt === null || $startedAt->diffInMinutes(now()) >= 5;
            if (! $isStale) {
                return $this->error('generation_in_progress', 'Image generation is already in progress for this scene.', 409);
            }
        }

        $validated = $request->validate([
            'style'           => ['sometimes', 'string', 'in:cinematic,dark,anime,documentary,minimalist,realistic,vintage,neon,photorealistic,cyberpunk_80s,anime_80s,anime_90s,dark_fantasy,fantasy_retro,comic,film_noir,line_drawing,watercolor,paper_cutout,cartoon,3d_animated,custom'],
            'prompt_override' => ['sometimes', 'nullable', 'string', 'max:1000'],
            // Model picker: validate against ImageAdapterFactory's registry so
            // additions there auto-propagate here without editing this list.
            'model_key'       => ['sometimes', 'nullable', 'string', 'in:' . implode(',', array_keys(\App\Services\Generation\Image\ImageAdapterFactory::AVAILABLE))],
        ]);

        // Prefer request style, then scene-level visual_style, then default.
        $style = $validated['style'] ?? $scene->visual_style ?? 'cinematic';
        $modelKey = $validated['model_key'] ?? null;

        // Content-safety: screen the user's prompt up-front so prohibited
        // requests are rejected before we spend anything (see ContentSafetyService).
        if (! empty($validated['prompt_override'])) {
            $block = app(\App\Services\Moderation\ContentSafetyService::class)->screenText(
                (string) $validated['prompt_override'],
                ['workspace_id' => (int) $user->workspace_id, 'user_id' => (int) $user->getKey(), 'scene_id' => (int) $scene->getKey(), 'project_id' => (int) $scene->project_id, 'operation' => 'regenerate_image'],
            );
            if ($block) {
                return $this->error('content_blocked', $block, 422);
            }
        }

        $generationToken = (string) Str::uuid();

        // Lock the scene immediately so rapid re-clicks and pipeline overlap are rejected.
        $scene->forceFill([
            'image_generation_settings_json' => array_merge($imageSettings, [
                'in_progress'           => true,
                'last_error'            => null,
                'needs_visual'          => false,
                'generation_token'      => $generationToken,
                'generation_started_at' => now()->toIso8601String(),
            ]),
        ])->save();

        GenerateAIImageJob::dispatch(
            $scene->getKey(),
            $scene->project_id,
            $style,
            $validated['prompt_override'] ?? null,
            $style,
            $generationToken,
            null, null, null, // no animate chain for editor-driven regens
            $modelKey,
        );

        return response()->json([
            'data' => [
                'status'   => 'queued',
                'scene_id' => $scene->getKey(),
                'style'    => $style,
            ],
            'meta' => [],
        ]);
    }

    public function animate(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);
        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $validated = $request->validate([
            'tier'             => ['required', 'string', \Illuminate\Validation\Rule::in(['quick', 'balanced', 'premium', 'seedance_lite', 'seedance_pro', 'spokesperson'])],
            // Accept any 3–10s from clients; the adapter clamps to 5 or 10 internally
            // (the Wan/Hailuo/Kling/Seedance models only render those two buckets).
            'duration_seconds' => ['sometimes', 'integer', 'min:3', 'max:10'],
            'motion_prompt'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            // User-chosen quality (resolution for i2v / mode for Kling). Resolved
            // against the tier's catalog; an unknown value falls back to default.
            'quality'          => ['sometimes', 'nullable', 'string', 'max:16'],
        ]);
        // Talking spokesperson (Fabric lip-sync) is a separate path: image +
        // the scene's voiceover -> lip-synced clip. Cost is LENGTH-BASED on the
        // voiceover (Fabric bills per second), not a 5/10s i2v bucket.
        $isSpokesperson = $validated['tier'] === 'spokesperson';
        // Normalize: anything ≤ 7 maps to a 5s render, ≥ 8 to a 10s render. Cost follows.
        $requested = (int) ($validated['duration_seconds'] ?? 5);
        $durationSeconds = $requested >= 8 ? 10 : 5;

        // Must have a source visual asset (image) to animate.
        $sourceAsset = $scene->visual_asset_id
            ? \App\Models\Asset::query()->find($scene->visual_asset_id)
            : null;
        if (! $sourceAsset || str_starts_with((string) $sourceAsset->mime_type, 'video/') || $sourceAsset->asset_type === 'video') {
            return $this->error('no_source_image', 'Generate a still image for this scene before animating.', 422);
        }
        // Spokesperson lip-syncs to the scene's voiceover — require it.
        $voiceoverSeconds = 0.0;
        if ($isSpokesperson) {
            $audioId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
            if (! $audioId) {
                return $this->error('no_voice', 'Generate the voiceover first — the talking spokesperson lip-syncs to the audio.', 422);
            }
            $audioAsset = \App\Models\Asset::query()->find($audioId);
            $voiceoverSeconds = (float) ($audioAsset?->duration_seconds ?: $scene->duration_seconds ?: 8);
        }

        $quality = null;
        if ($isSpokesperson) {
            // Length-based: Fabric bills per second of voiceover.
            $cost = CreditService::spokespersonCost($voiceoverSeconds);
        } else {
            // Tier × chosen quality (resolution/mode) × duration (10s = 2×).
            $quality = CreditService::videoQuality($validated['tier'], $validated['quality'] ?? null);
            $cost = CreditService::animationCost($validated['tier'], $quality, $durationSeconds);
        }

        $balance = $this->credits->balance((int) $user->workspace_id);
        if ($balance < $cost) {
            return response()->json([
                'error' => [
                    'code'    => 'insufficient_credits',
                    'message' => "You need {$cost} credits to animate this scene. Your balance is {$balance}.",
                    'context' => ['balance' => $balance, 'required' => $cost, 'shortage' => $cost - $balance],
                ],
            ], 402);
        }

        // Concurrency guard — reject if an animation job is already running on this scene.
        $existing = $scene->image_generation_settings_json ?? [];
        if (! empty($existing['animation_in_progress'])) {
            $startedAt = isset($existing['animation_started_at'])
                ? \Carbon\Carbon::parse($existing['animation_started_at'])
                : null;
            $isStale = $startedAt === null || $startedAt->diffInMinutes(now()) >= 10;
            if (! $isStale) {
                return $this->error('animation_in_progress', 'Animation already running for this scene.', 409);
            }
        }

        // NOTE: credits are charged inside AnimateSceneJob now — the single
        // billing point for every animation path (manual, Cruise, one-shot,
        // chained). The balance check above gives the user instant feedback;
        // the job does the atomic deduct + refund-on-failure. Charging here too
        // would double-bill manual animations.

        // Lock the scene so the editor knows an animation is running.
        // Persist last-used settings too so the modal pre-fills on re-animate.
        $token = (string) \Illuminate\Support\Str::uuid();
        $scene->forceFill([
            'image_generation_settings_json' => array_merge($existing, [
                'animation_in_progress'      => true,
                'animation_last_error'       => null,
                'animation_tier'             => $validated['tier'],
                'animation_duration'         => $durationSeconds,
                'animation_quality'          => $quality,
                'animation_motion_prompt'    => $validated['motion_prompt'] ?? null,
                'animation_started_at'       => now()->toIso8601String(),
                'generation_token'           => $token,
            ]),
        ])->save();

        if ($isSpokesperson) {
            \App\Jobs\GenerateTalkingVideoJob::dispatch($scene->getKey(), $scene->project_id, $token);
        } else {
            \App\Jobs\AnimateSceneJob::dispatch(
                $scene->getKey(),
                $scene->project_id,
                $validated['tier'],
                $durationSeconds,
                $validated['motion_prompt'] ?? null,
                quality: $quality,
            );
        }

        return response()->json([
            'data' => [
                'scene'    => $this->serializeScene($scene->fresh()),
                'cost'     => $cost,
                'tier'     => $validated['tier'],
            ],
            'meta' => [],
        ]);
    }

    /**
     * Re-generate background music for this scene via MusicGen.
     *
     * Same job the one-shot pipeline uses; the user can call this from
     * the editor's music panel to swap out the AI-generated track for
     * one matching a different mood. Pre-checks credits up front so a
     * busted balance gets a clear 402 instead of a half-billed retry.
     */
    public function regenerateMusic(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);
        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $validated = $request->validate([
            // Music mood seed for MusicGen. ~3-7 words, e.g. "calm acoustic"
            // or "upbeat indie pop". The wizard suggestion picker on the
            // frontend hands users a curated set; this endpoint also accepts
            // free-text.
            'mood'    => ['required', 'string', 'min:2', 'max:100'],
            'duration_seconds' => ['sometimes', 'integer', 'min:3', 'max:30'],
        ]);

        $cost = \App\Services\CreditService::AI_MUSIC;
        $balance = (new \App\Services\CreditService())->balance((int) $user->workspace_id);
        if ($balance < $cost) {
            return $this->error(
                'insufficient_credits',
                "Music regen costs {$cost} credits. You have {$balance}.",
                402,
            );
        }

        $duration = (int) ($validated['duration_seconds'] ?? max(3, min(30, (int) ($scene->duration_seconds ?? 8))));

        \App\Jobs\GenerateAIMusicJob::dispatch(
            $scene->getKey(),
            (int) $scene->project_id,
            $validated['mood'],
            $validated['mood'],
            $duration,
        );

        return response()->json([
            'data' => [
                'status'  => 'queued',
                'scene_id' => $scene->getKey(),
                'mood'    => $validated['mood'],
                'estimated_cost' => $cost,
            ],
            'meta' => [],
        ]);
    }

    public function useAnimationFromHistory(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);
        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $validated = $request->validate([
            'asset_id' => ['required', 'integer'],
        ]);

        // Asset must exist in this workspace AND appear in this scene's history.
        $settings = $scene->image_generation_settings_json ?? [];
        $history = is_array($settings['animation_history'] ?? null) ? $settings['animation_history'] : [];
        $inHistory = collect($history)->contains(fn ($h) => (int) ($h['asset_id'] ?? 0) === (int) $validated['asset_id']);
        if (! $inHistory) {
            return $this->error('not_in_history', 'That animation is no longer in this scene\'s history.', 422);
        }

        $asset = \App\Models\Asset::query()
            ->whereKey($validated['asset_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $asset) {
            return $this->error('asset_missing', 'The animation video is no longer available.', 410);
        }

        $scene->forceFill([
            'visual_asset_id' => $asset->getKey(),
            'image_generation_settings_json' => array_merge($settings, [
                'animation_video_asset_id' => $asset->getKey(),
            ]),
        ])->save();

        return response()->json([
            'data' => ['scene' => $this->serializeScene($scene->fresh())],
            'meta' => [],
        ]);
    }

    public function cancelAnimation(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);
        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $settings = $scene->image_generation_settings_json ?? [];
        if (empty($settings['animation_in_progress'])) {
            return $this->error('no_active_animation', 'No animation is currently running for this scene.', 422);
        }

        $refund = (int) ($settings['animation_cost'] ?? 0);
        if ($refund > 0) {
            $this->credits->grant((int) $user->workspace_id, $refund, "animation_cancelled:scene_{$sceneId}");
        }

        // Flip the cancel flag; AnimateSceneJob honours it when Replicate eventually
        // returns (we don't kill Replicate's prediction, just discard the result).
        $scene->forceFill([
            'image_generation_settings_json' => array_merge($settings, [
                'animation_in_progress'    => false,
                'animation_cancel_requested' => true,
                'animation_cancelled_at'   => now()->toIso8601String(),
                'animation_last_error'     => 'Cancelled by user. Credits refunded.',
            ]),
        ])->save();

        return response()->json([
            'data' => [
                'scene'           => $this->serializeScene($scene->fresh()),
                'refunded_credits' => $refund,
            ],
            'meta' => [],
        ]);
    }

    public function revertAnimation(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);
        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        $settings = $scene->image_generation_settings_json ?? [];
        $originalId = $settings['animation_original_image_asset_id'] ?? null;
        if (! $originalId) {
            return $this->error('nothing_to_revert', 'This scene has no preserved original image to revert to.', 422);
        }

        // Confirm the asset still exists and belongs to this workspace.
        $original = \App\Models\Asset::query()
            ->whereKey($originalId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $original) {
            return $this->error('original_missing', 'The original image is no longer available.', 410);
        }

        $scene->forceFill([
            'visual_asset_id' => $original->getKey(),
            'image_generation_settings_json' => array_merge($settings, [
                // Switch the active visual back to the still, but KEEP the
                // original-still id + the clip history so the editor can show
                // "still + every clip" as switchable cards. Nothing is deleted.
                'animation_video_asset_id' => null,
                'animation_reverted_at'    => now()->toIso8601String(),
            ]),
        ])->save();

        return response()->json([
            'data' => ['scene' => $this->serializeScene($scene->fresh())],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $sceneId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $scene = $this->resolveScene($sceneId, $user);

        if (! $scene) {
            return $this->error('not_found', 'Scene not found.', 404);
        }

        DB::transaction(function () use ($scene): void {
            $projectId = $scene->project_id;
            $remainingSceneIds = Scene::query()
                ->where('project_id', $projectId)
                ->whereKeyNot($scene->getKey())
                ->orderBy('scene_order')
                ->pluck('id')
                ->map(static fn (mixed $id): int => (int) $id)
                ->all();

            $scene->delete();
            $this->syncSceneOrder((int) $projectId, $remainingSceneIds);
        });

        return response()->json([
            'data' => [
                'deleted' => true,
            ],
            'meta' => [],
        ]);
    }

    private function resolveScene(int $sceneId, User $user): ?Scene
    {
        return Scene::query()
            ->with('project')
            ->whereKey($sceneId)
            ->whereHas('project', function ($query) use ($user): void {
                $query->where('workspace_id', $user->workspace_id);
            })
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeScene(Scene $scene): array
    {
        $visualAsset = $scene->visual_asset_id
            ? Asset::query()->whereKey($scene->visual_asset_id)->first()
            : null;

        $audioAssetId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);
        $audioAsset = $audioAssetId > 0
            ? Asset::query()->whereKey($audioAssetId)->first()
            : null;

        $soundAsset = $scene->sound_asset_id
            ? Asset::query()->whereKey($scene->sound_asset_id)->first()
            : null;

        return [
            'id' => $scene->getKey(),
            'project_id' => $scene->project_id,
            'scene_order' => $scene->scene_order,
            'scene_type' => $scene->scene_type,
            'label' => $scene->label,
            'script_text' => $scene->script_text,
            'duration_seconds' => $scene->duration_seconds,
            'voice_profile_id' => $scene->voice_profile_id,
            'voice_settings' => $scene->voice_settings_json,
            'voice_settings_json' => $scene->voice_settings_json,
            'caption_settings' => $scene->caption_settings_json,
            'caption_settings_json' => $scene->caption_settings_json,
            'visual_type' => $scene->visual_type,
            'visual_asset_id' => $scene->visual_asset_id,
            'character_id' => $scene->character_id,
            'visual_prompt' => $scene->visual_prompt,
            'visual_style' => $scene->visual_style,
            'custom_visual_style' => $scene->custom_visual_style,
            'image_generation_settings' => $this->normalizeImageGenerationSettings($scene),
            'motion_settings' => $scene->motion_settings_json,
            'transition_rule' => $scene->transition_rule,
            'status' => $scene->status,
            'locked_fields' => $scene->locked_fields_json,
            'locked_fields_json' => $scene->locked_fields_json,
            'sound_asset_id' => $scene->sound_asset_id,
            'sound_settings_json' => $scene->sound_settings_json,
            'visual_asset' => $visualAsset ? $this->serializeAsset($visualAsset) : null,
            'audio_asset' => $audioAsset ? $this->serializeAsset($audioAsset) : null,
            'sound_asset' => $soundAsset ? $this->serializeAsset($soundAsset) : null,
            'created_at' => $scene->created_at?->toIso8601String(),
            'updated_at' => $scene->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAsset(Asset $asset): array
    {
        $thumbnailUrl = trim((string) $asset->thumbnail_url);
        return [
            'id' => $asset->getKey(),
            'asset_type' => $asset->asset_type,
            'title' => $asset->title,
            'storage_url' => $this->assetUrl($asset),
            'thumbnail_url' => ($thumbnailUrl !== '' && (str_starts_with($thumbnailUrl, 'data:') || ! $this->isB2Url($thumbnailUrl))) ? $thumbnailUrl : null,
            'duration_seconds' => $asset->duration_seconds,
            'mime_type' => $asset->mime_type,
            'tags' => $asset->tags ?? [],
            'transcript_text' => $asset->transcript_text,
            'transcription_status' => $asset->transcription_status,
            'metadata_json' => $asset->metadata_json,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function normalizeImageGenerationSettings(Scene $scene): ?array
    {
        $settings = is_array($scene->image_generation_settings_json) ? $scene->image_generation_settings_json : null;

        if (! $settings) {
            return $settings;
        }

        if (
            $scene->visual_type === 'ai_image' &&
            $scene->visual_asset_id !== null &&
            (int) ($settings['asset_id'] ?? 0) === (int) $scene->visual_asset_id &&
            empty($settings['in_progress'])
        ) {
            $settings['needs_visual'] = false;
            $settings['last_error'] = null;
        }

        // Enrich animation_history entries with signed video + thumbnail URLs so the
        // editor can render previews without an extra round-trip per item.
        if (! empty($settings['animation_history']) && is_array($settings['animation_history'])) {
            $assetIds = collect($settings['animation_history'])->pluck('asset_id')->filter()->all();
            $assets = Asset::query()->whereIn('id', $assetIds)->get()->keyBy('id');
            $settings['animation_history'] = array_map(function (array $h) use ($assets) {
                $asset = $assets->get((int) ($h['asset_id'] ?? 0));
                if ($asset) {
                    $h['video_url']     = $this->assetUrl($asset);
                    $h['thumbnail_url'] = $asset->thumbnail_url ? $this->assetUrlFromPath($asset, (string) $asset->thumbnail_url) : null;
                }
                return $h;
            }, $settings['animation_history']);
        }

        // The preserved original still, enriched, so the editor renders it as
        // the first switchable card alongside the animation clips.
        $stillId = (int) ($settings['animation_original_image_asset_id'] ?? 0);
        if ($stillId > 0) {
            $still = Asset::query()->whereKey($stillId)->first();
            if ($still) {
                $settings['animation_original_image'] = [
                    'asset_id'      => $still->getKey(),
                    'thumbnail_url' => $still->thumbnail_url
                        ? $this->assetUrlFromPath($still, (string) $still->thumbnail_url)
                        : $this->assetUrl($still),
                    'image_url'     => $this->assetUrl($still),
                ];
            }
        }

        return $settings;
    }

    private function assetUrlFromPath(Asset $asset, string $path): ?string
    {
        $storage = app(\App\Services\Media\StorageService::class);
        $isStored = $storage->extractPath($path) !== null;
        if (! $isStored) return $path;
        return \Illuminate\Support\Facades\URL::temporarySignedRoute(
            'media.assets.content', now()->addMinutes(30), ['assetId' => $asset->getKey()]
        );
    }

    private function assetUrl(Asset $asset): ?string
    {
        $storageUrl = trim((string) $asset->storage_url);

        if ($storageUrl === '') {
            return null;
        }

        if ($this->isB2Url($storageUrl) || $this->shouldProxyAudio($asset)) {
            return URL::temporarySignedRoute(
                'media.assets.content',
                now()->addHours(6),
                ['assetId' => $asset->getKey()]
            );
        }

        return $storageUrl;
    }

    private function isB2Url(string $url): bool
    {
        return app(StorageService::class)->isManagedUrl($url);
    }

    private function shouldProxyAudio(Asset $asset): bool
    {
        return (string) $asset->asset_type === 'audio'
            || str_starts_with((string) ($asset->mime_type ?? ''), 'audio/');
    }

    protected function error(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }

    /**
     * @param  \Illuminate\Support\Collection<int, Scene>  $scenes
     * @return array{previous:string,next:string,outline:string}
     */
    private function sceneContext($scenes, ?int $insertAfterSceneId = null): array
    {
        $ordered = $scenes->values();
        $previous = '';
        $next = '';

        if ($insertAfterSceneId !== null) {
            $index = $ordered->search(fn (Scene $scene): bool => (int) $scene->getKey() === $insertAfterSceneId);

            if ($index !== false) {
                $previous = trim((string) ($ordered->get($index)?->script_text ?: ''));
                $next = trim((string) ($ordered->get($index + 1)?->script_text ?: ''));
            }
        }

        $outline = $ordered
            ->map(function (Scene $scene): string {
                $label = trim((string) ($scene->label ?: 'Scene '.$scene->scene_order));
                $text = mb_substr(trim((string) ($scene->script_text ?: '')), 0, 90);

                return "{$label}: {$text}";
            })
            ->implode("\n");

        return [
            'previous' => $previous,
            'next' => $next,
            'outline' => $outline,
        ];
    }

    /**
     * @return array{previous:string,next:string,outline:string}
     */
    /**
     * One-line creative brief from the project's assistant_brief_json, fed to
     * the rewrite LLM so edits stay on-theme. 'n/a' when no brief exists yet.
     */
    private function projectBriefLine(?\App\Models\Project $project): string
    {
        $brief = is_array($project?->assistant_brief_json) ? $project->assistant_brief_json : [];
        $labels = ['theme' => 'Theme', 'topic' => 'Topic', 'tone' => 'Tone', 'recurring_subject' => 'Recurring subject'];
        $parts = [];
        foreach ($labels as $key => $label) {
            $v = trim((string) ($brief[$key] ?? ''));
            if ($v !== '') $parts[] = "{$label}: {$v}";
        }
        return $parts ? implode(' · ', $parts) : 'n/a';
    }

    private function sceneContextForScene(Scene $scene): array
    {
        $scenes = Scene::query()
            ->where('project_id', $scene->project_id)
            ->orderBy('scene_order')
            ->get();
        $ordered = $scenes->values();
        $index = $ordered->search(fn (Scene $row): bool => (int) $row->getKey() === (int) $scene->getKey());

        return [
            'previous' => $index !== false ? trim((string) ($ordered->get($index - 1)?->script_text ?: '')) : '',
            'next' => $index !== false ? trim((string) ($ordered->get($index + 1)?->script_text ?: '')) : '',
            'outline' => $scenes
                ->map(function (Scene $row): string {
                    $label = trim((string) ($row->label ?: 'Scene '.$row->scene_order));
                    $text = mb_substr(trim((string) ($row->script_text ?: '')), 0, 90);

                    return "{$label}: {$text}";
                })
                ->implode("\n"),
        ];
    }

    /**
     * @param  list<int>  $orderedSceneIds
     */
    private function syncSceneOrder(int $projectId, array $orderedSceneIds): void
    {
        if ($orderedSceneIds === []) {
            return;
        }

        $baseOrder = (int) Scene::query()
            ->where('project_id', $projectId)
            ->max('scene_order');
        $tempOffset = max($baseOrder, count($orderedSceneIds)) + 1000;

        foreach (array_values($orderedSceneIds) as $index => $sceneId) {
            Scene::query()
                ->where('project_id', $projectId)
                ->whereKey($sceneId)
                ->update([
                    'scene_order' => $tempOffset + $index,
                ]);
        }

        foreach (array_values($orderedSceneIds) as $index => $sceneId) {
            Scene::query()
                ->where('project_id', $projectId)
                ->whereKey($sceneId)
                ->update([
                    'scene_order' => $index + 1,
                ]);
        }
    }
}
