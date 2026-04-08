<?php

namespace App\Http\Controllers\Api\V1\Scene;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Scene;
use App\Models\User;
use App\Services\Generation\AI\AIGenerationAdapter;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Generation\Visual\VisualProviderAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\URL;

class SceneController extends Controller
{
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
            'visual_prompt' => ['sometimes', 'nullable', 'string'],
            'transition_rule' => ['sometimes', 'nullable', 'string', 'max:64'],
            'voice_profile_id' => ['sometimes', 'nullable', 'integer'],
            'voice_settings_json' => ['sometimes', 'nullable', 'array'],
            'caption_settings_json' => ['sometimes', 'nullable', 'array'],
            'locked_fields_json' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'nullable', 'string', 'max:64'],
        ]);

        $scene->fill($validated);
        $scene->save();

        return response()->json([
            'data' => [
                'scene' => $this->serializeScene($scene->fresh()),
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
            foreach ($validated['scene_ids'] as $index => $sceneId) {
                Scene::query()
                    ->whereKey($sceneId)
                    ->update([
                        'scene_order' => $index + 1,
                    ]);
            }
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
            Scene::query()
                ->where('project_id', $scene->project_id)
                ->where('scene_order', '>', $scene->scene_order)
                ->increment('scene_order');

            $copy = $scene->replicate();
            $copy->scene_order = $scene->scene_order + 1;
            $copy->status = 'draft';
            $copy->save();

            return $copy;
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
            'mode' => ['required', 'string', 'in:shorten,expand,stronger_hook,more_punchy,more_educational,more_salesy,simplify'],
            'apply' => ['nullable', 'boolean'],
        ]);

        $lockedFields = is_array($scene->locked_fields_json) ? $scene->locked_fields_json : [];

        if (in_array('script_text', $lockedFields, true)) {
            return $this->error('scene_locked', 'Scene script is locked and cannot be rewritten.', 422);
        }

        $generation = $ai->generate(
            promptTemplateKey: 'scene_rewrite',
            variables: [
                'mode' => $validated['mode'],
                'language' => (string) ($scene->project?->primary_language ?: 'en'),
                'script_text' => (string) ($scene->script_text ?: ''),
            ],
            maxTokens: 450,
            temperature: 0.55,
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

    public function regenerateVoice(Request $request, int $sceneId, TTSAdapter $tts): JsonResponse
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

        $voiceId = (string) data_get($scene->voice_settings_json, 'voice_id', 'alloy');
        $speed = (float) data_get($scene->voice_settings_json, 'speed', 1.0);
        $language = (string) data_get($scene->voice_settings_json, 'language', $project->primary_language ?: 'en');
        $sceneText = trim((string) ($scene->script_text ?: ''));

        if ($sceneText === '') {
            return $this->error('invalid_scene_state', 'Scene script is required before regenerating voice.', 422);
        }

        DB::transaction(function () use ($scene, $project, $tts, $voiceId, $speed, $language, $sceneText): void {
            $audio = $tts->synthesize($sceneText, $language, $voiceId, $speed);

            $asset = Asset::query()->create([
                'workspace_id' => $project->workspace_id,
                'channel_id' => $project->channel_id,
                'asset_type' => 'audio',
                'title' => 'TTS audio for project '.$project->getKey().' scene '.$scene->scene_order,
                'description' => mb_substr($sceneText, 0, 180),
                'storage_url' => $audio['audio_url'],
                'duration_seconds' => $audio['duration_seconds'],
                'mime_type' => 'audio/mpeg',
                'tags' => ['tts', $audio['provider_key']],
                'usage_count' => 1,
                'status' => 'active',
                'created_by_user_id' => $project->created_by_user_id,
            ]);

            $voiceSettings = is_array($scene->voice_settings_json) ? $scene->voice_settings_json : [];
            $voiceSettings['provider_key'] = $audio['provider_key'];
            $voiceSettings['voice_id'] = $audio['provider_voice_id'];
            $voiceSettings['speed'] = $speed;
            $voiceSettings['language'] = $language;
            $voiceSettings['audio_asset_id'] = $asset->getKey();
            $voiceSettings['is_outdated'] = false;

            $scene->forceFill([
                'duration_seconds' => $audio['duration_seconds'],
                'voice_settings_json' => $voiceSettings,
                'status' => 'edited',
            ])->save();
        });

        return response()->json([
            'data' => [
                'scene' => $this->serializeScene($scene->fresh()),
            ],
            'meta' => [],
        ]);
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
            $sceneOrder = $scene->scene_order;

            $scene->delete();

            Scene::query()
                ->where('project_id', $projectId)
                ->where('scene_order', '>', $sceneOrder)
                ->decrement('scene_order');
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
            'visual_prompt' => $scene->visual_prompt,
            'transition_rule' => $scene->transition_rule,
            'status' => $scene->status,
            'locked_fields' => $scene->locked_fields_json,
            'locked_fields_json' => $scene->locked_fields_json,
            'visual_asset' => $visualAsset ? $this->serializeAsset($visualAsset) : null,
            'audio_asset' => $audioAsset ? $this->serializeAsset($audioAsset) : null,
            'created_at' => $scene->created_at?->toIso8601String(),
            'updated_at' => $scene->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeAsset(Asset $asset): array
    {
        return [
            'id' => $asset->getKey(),
            'asset_type' => $asset->asset_type,
            'title' => $asset->title,
            'storage_url' => $this->assetUrl($asset),
            'thumbnail_url' => $asset->thumbnail_url,
            'duration_seconds' => $asset->duration_seconds,
            'mime_type' => $asset->mime_type,
        ];
    }

    private function assetUrl(Asset $asset): ?string
    {
        $storageUrl = trim((string) $asset->storage_url);

        if ($storageUrl === '') {
            return null;
        }

        if ($this->isB2Url($storageUrl)) {
            return URL::temporarySignedRoute(
                'media.assets.content',
                now()->addHours(6),
                ['assetId' => $asset->getKey()],
            );
        }

        return $storageUrl;
    }

    private function isB2Url(string $url): bool
    {
        if (str_starts_with($url, 'b2://')) {
            return true;
        }

        $host = strtolower((string) parse_url($url, PHP_URL_HOST));
        $bucket = strtolower((string) config('filesystems.disks.b2.bucket'));

        if ($host !== '' && str_contains($host, 'backblazeb2.com')) {
            return true;
        }

        $path = strtolower(trim((string) parse_url($url, PHP_URL_PATH), '/'));

        return $bucket !== '' && str_starts_with($path, $bucket.'/');
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
