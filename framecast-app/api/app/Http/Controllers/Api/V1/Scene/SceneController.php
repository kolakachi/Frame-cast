<?php

namespace App\Http\Controllers\Api\V1\Scene;

use App\Http\Controllers\Controller;
use App\Models\Scene;
use App\Models\User;
use App\Services\Generation\AI\AIGenerationAdapter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

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
        return [
            'id' => $scene->getKey(),
            'project_id' => $scene->project_id,
            'scene_order' => $scene->scene_order,
            'scene_type' => $scene->scene_type,
            'label' => $scene->label,
            'script_text' => $scene->script_text,
            'duration_seconds' => $scene->duration_seconds,
            'voice_profile_id' => $scene->voice_profile_id,
            'voice_settings_json' => $scene->voice_settings_json,
            'caption_settings_json' => $scene->caption_settings_json,
            'visual_type' => $scene->visual_type,
            'visual_asset_id' => $scene->visual_asset_id,
            'visual_prompt' => $scene->visual_prompt,
            'transition_rule' => $scene->transition_rule,
            'status' => $scene->status,
            'locked_fields_json' => $scene->locked_fields_json,
            'created_at' => $scene->created_at?->toIso8601String(),
            'updated_at' => $scene->updated_at?->toIso8601String(),
        ];
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
