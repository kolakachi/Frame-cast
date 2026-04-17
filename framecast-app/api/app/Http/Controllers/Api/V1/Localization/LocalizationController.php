<?php

namespace App\Http\Controllers\Api\V1\Localization;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateLocalizationLinkJob;
use App\Models\LocalizationGroup;
use App\Models\LocalizationLink;
use App\Models\Project;
use App\Models\User;
use App\Models\VoiceProfile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LocalizationController extends Controller
{
    public function index(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = $this->resolveProject($projectId, $user);

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $groups = LocalizationGroup::query()
            ->where('source_project_id', $project->getKey())
            ->with(['links.localizedProject', 'links.voiceProfile'])
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'localization_groups' => $groups->map(fn (LocalizationGroup $group): array => $this->serializeGroup($group))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function store(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = $this->resolveProject($projectId, $user);

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $validated = $request->validate([
            'target_languages' => ['required', 'array', 'min:1', 'max:6'],
            'target_languages.*' => ['required', 'string', 'max:16', 'distinct'],
            'voice_profile_id' => ['nullable', 'integer'],
            'voice_profile_ids' => ['sometimes', 'array'],
            'voice_profile_ids.*' => ['nullable', 'integer'],
        ]);

        $sourceLanguage = (string) ($project->primary_language ?: 'en');
        $targetLanguages = collect($validated['target_languages'])
            ->map(fn (mixed $language): string => strtolower(trim((string) $language)))
            ->filter(fn (string $language): bool => $language !== '' && $language !== strtolower($sourceLanguage))
            ->unique()
            ->values()
            ->all();

        if ($targetLanguages === []) {
            return $this->error('invalid_target_languages', 'Select at least one target language different from the source language.', 422);
        }

        $voiceProfileIds = $validated['voice_profile_ids'] ?? [];
        $defaultVoiceProfileId = $validated['voice_profile_id'] ?? null;

        $candidateVoiceIds = collect($voiceProfileIds)
            ->filter()
            ->push($defaultVoiceProfileId)
            ->filter()
            ->map(fn (mixed $id): int => (int) $id)
            ->unique()
            ->values();

        if ($candidateVoiceIds->isNotEmpty()) {
            $foundVoiceCount = VoiceProfile::query()
                ->whereIn('id', $candidateVoiceIds->all())
                ->where(function ($query) use ($user): void {
                    $query->whereNull('workspace_id')
                        ->orWhere('workspace_id', $user->workspace_id);
                })
                ->count();

            if ($foundVoiceCount !== $candidateVoiceIds->count()) {
                return $this->error('invalid_voice_profile', 'One or more selected voice profiles are not available in this workspace.', 422);
            }
        }

        $group = DB::transaction(function () use ($project, $sourceLanguage, $targetLanguages, $voiceProfileIds, $defaultVoiceProfileId): LocalizationGroup {
            $group = LocalizationGroup::query()->create([
                'source_project_id' => $project->getKey(),
                'source_language' => $sourceLanguage,
                'target_languages' => $targetLanguages,
                'status' => 'pending',
            ]);

            foreach ($targetLanguages as $language) {
                LocalizationLink::query()->create([
                    'localization_group_id' => $group->getKey(),
                    'target_language' => $language,
                    'localized_project_id' => null,
                    'voice_profile_id' => $voiceProfileIds[$language] ?? $defaultVoiceProfileId,
                    'status' => 'pending',
                ]);
            }

            return $group->fresh('links');
        });

        foreach ($group->links as $link) {
            GenerateLocalizationLinkJob::dispatch((int) $link->getKey());
        }

        return response()->json([
            'data' => [
                'localization_group' => $this->serializeGroup($group->load(['links.localizedProject', 'links.voiceProfile'])),
            ],
            'meta' => [],
        ], 201);
    }

    public function retry(Request $request, int $localizationLinkId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $link = LocalizationLink::query()
            ->whereKey($localizationLinkId)
            ->whereHas('localizationGroup.sourceProject', function ($query) use ($user): void {
                $query->where('workspace_id', $user->workspace_id);
            })
            ->with('localizationGroup.links')
            ->first();

        if (! $link) {
            return $this->error('not_found', 'Localization target not found.', 404);
        }

        if ($link->status !== 'failed') {
            return $this->error('localization_not_failed', 'Only failed localization targets can be retried.', 422);
        }

        $link->forceFill(['status' => 'pending'])->save();
        $link->localizationGroup?->forceFill(['status' => 'pending'])->save();
        GenerateLocalizationLinkJob::dispatch((int) $link->getKey());

        return response()->json([
            'data' => [
                'localization_link' => $this->serializeLink($link->fresh(['localizedProject', 'voiceProfile'])),
            ],
            'meta' => [],
        ]);
    }

    private function resolveProject(int $projectId, User $user): ?Project
    {
        return Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
    }

    private function serializeGroup(LocalizationGroup $group): array
    {
        return [
            'id' => $group->getKey(),
            'source_project_id' => $group->source_project_id,
            'source_language' => $group->source_language,
            'target_languages' => $group->target_languages ?? [],
            'status' => $group->status,
            'links' => $group->links->map(fn (LocalizationLink $link): array => $this->serializeLink($link))->all(),
            'created_at' => $group->created_at?->toIso8601String(),
            'updated_at' => $group->updated_at?->toIso8601String(),
        ];
    }

    private function serializeLink(LocalizationLink $link): array
    {
        return [
            'id' => $link->getKey(),
            'localization_group_id' => $link->localization_group_id,
            'target_language' => $link->target_language,
            'localized_project_id' => $link->localized_project_id,
            'voice_profile_id' => $link->voice_profile_id,
            'voice_profile_name' => $link->voiceProfile?->name,
            'status' => $link->status,
            'localized_project' => $link->localizedProject ? [
                'id' => $link->localizedProject->getKey(),
                'title' => $link->localizedProject->title,
                'primary_language' => $link->localizedProject->primary_language,
                'status' => $link->localizedProject->status,
                'aspect_ratio' => $link->localizedProject->aspect_ratio,
            ] : null,
            'created_at' => $link->created_at?->toIso8601String(),
            'updated_at' => $link->updated_at?->toIso8601String(),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
            'meta' => [],
        ], $status);
    }
}
