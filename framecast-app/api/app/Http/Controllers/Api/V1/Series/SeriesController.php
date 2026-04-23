<?php

namespace App\Http\Controllers\Api\V1\Series;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Series;
use App\Models\SeriesCharacter;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SeriesController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $series = Series::query()
            ->where('workspace_id', $user->workspace_id)
            ->withCount('episodes')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'data' => [
                'series' => $series->map(fn (Series $s): array => $this->serializeSeries($s))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate($this->rules());

        $series = Series::query()->create([
            ...$validated,
            'workspace_id' => $user->workspace_id,
            'created_by_user_id' => $user->getKey(),
            'status' => 'active',
        ]);

        return response()->json([
            'data' => [
                'series' => $this->serializeSeries($series->loadCount('episodes')),
            ],
            'meta' => [],
        ], 201);
    }

    public function show(Request $request, int $seriesId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $series = $this->findForUser($user, $seriesId);

        if (! $series) {
            return $this->notFound();
        }

        return response()->json([
            'data' => [
                'series' => $this->serializeSeries($series->loadCount('episodes')->load('characters')),
            ],
            'meta' => [],
        ]);
    }

    public function update(Request $request, int $seriesId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $series = $this->findForUser($user, $seriesId);

        if (! $series) {
            return $this->notFound();
        }

        $validated = $request->validate($this->rules(true));
        $series->fill($validated)->save();

        return response()->json([
            'data' => [
                'series' => $this->serializeSeries($series->fresh()->loadCount('episodes')),
            ],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $seriesId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $series = $this->findForUser($user, $seriesId);

        if (! $series) {
            return $this->notFound();
        }

        $series->forceFill(['status' => 'archived'])->save();

        return response()->json([
            'data' => ['archived' => true],
            'meta' => [],
        ]);
    }

    public function episodes(Request $request, int $seriesId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $series = $this->findForUser($user, $seriesId);

        if (! $series) {
            return $this->notFound();
        }

        $episodes = Project::query()
            ->where('series_id', $series->getKey())
            ->orderBy('series_episode_number')
            ->get(['id', 'title', 'status', 'series_episode_number', 'series_episode_summary', 'created_at', 'updated_at']);

        return response()->json([
            'data' => [
                'episodes' => $episodes->map(fn (Project $p): array => [
                    'id' => $p->getKey(),
                    'title' => $p->title,
                    'status' => $p->status,
                    'episode_number' => $p->series_episode_number,
                    'has_summary' => ! empty($p->series_episode_summary),
                    'created_at' => $p->created_at?->toIso8601String(),
                    'updated_at' => $p->updated_at?->toIso8601String(),
                ])->all(),
            ],
            'meta' => ['total' => $episodes->count()],
        ]);
    }

    public function characters(Request $request, int $seriesId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $series = $this->findForUser($user, $seriesId);

        if (! $series) {
            return $this->notFound();
        }

        return response()->json([
            'data' => [
                'characters' => $series->characters->map(fn (SeriesCharacter $c): array => $this->serializeCharacter($c))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function storeCharacter(Request $request, int $seriesId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $series = $this->findForUser($user, $seriesId);

        if (! $series) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'visual_description' => ['nullable', 'string'],
            'personality_notes' => ['nullable', 'string'],
            'appearance_json' => ['nullable', 'array'],
        ]);

        $character = SeriesCharacter::query()->create([
            ...$validated,
            'series_id' => $series->getKey(),
            'status' => 'active',
        ]);

        return response()->json([
            'data' => ['character' => $this->serializeCharacter($character)],
            'meta' => [],
        ], 201);
    }

    public function updateCharacter(Request $request, int $seriesId, int $characterId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $series = $this->findForUser($user, $seriesId);

        if (! $series) {
            return $this->notFound();
        }

        $character = SeriesCharacter::query()
            ->where('series_id', $series->getKey())
            ->find($characterId);

        if (! $character) {
            return $this->notFound();
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'visual_description' => ['sometimes', 'nullable', 'string'],
            'personality_notes' => ['sometimes', 'nullable', 'string'],
            'appearance_json' => ['sometimes', 'nullable', 'array'],
            'status' => ['sometimes', 'in:active,archived'],
        ]);

        $character->fill($validated)->save();

        return response()->json([
            'data' => ['character' => $this->serializeCharacter($character->fresh())],
            'meta' => [],
        ]);
    }

    public function destroyCharacter(Request $request, int $seriesId, int $characterId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $series = $this->findForUser($user, $seriesId);

        if (! $series) {
            return $this->notFound();
        }

        $character = SeriesCharacter::query()
            ->where('series_id', $series->getKey())
            ->find($characterId);

        if (! $character) {
            return $this->notFound();
        }

        $character->delete();

        return response()->json([
            'data' => ['deleted' => true],
            'meta' => [],
        ]);
    }

    private function findForUser(User $user, int $seriesId): ?Series
    {
        return Series::query()
            ->whereKey($seriesId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';
        $nullable = $partial ? 'sometimes' : 'nullable';

        return [
            'name' => [$required, 'string', 'max:255'],
            'channel_id' => [$nullable, 'nullable', 'integer'],
            'description' => [$nullable, 'nullable', 'string'],
            'platform_targets' => [$nullable, 'nullable', 'array'],
            'platform_targets.*' => ['string', 'max:64'],
            'aspect_ratio' => [$nullable, 'nullable', 'in:9:16,16:9,1:1'],
            'duration_target_seconds' => [$nullable, 'nullable', 'integer', 'min:5', 'max:600'],
            'posting_cadence' => [$nullable, 'nullable', 'string', 'max:32'],
            'concept_text' => [$nullable, 'nullable', 'string'],
            'audience_text' => [$nullable, 'nullable', 'string'],
            'tone' => [$nullable, 'nullable', 'string', 'max:64'],
            'episode_format_template' => [$nullable, 'nullable', 'string'],
            'always_include_tags' => [$nullable, 'nullable', 'array'],
            'always_include_tags.*' => ['string', 'max:100'],
            'never_include_tags' => [$nullable, 'nullable', 'array'],
            'never_include_tags.*' => ['string', 'max:100'],
            'memory_window' => [$nullable, 'nullable', 'integer', 'min:0', 'max:10'],
            'auto_summarise' => [$nullable, 'nullable', 'boolean'],
            'visual_mode' => [$nullable, 'nullable', 'in:stock,ai,mixed'],
            'visual_style' => [$nullable, 'nullable', 'string', 'max:64'],
            'visual_palette' => [$nullable, 'nullable', 'string', 'max:64'],
            'visual_description' => [$nullable, 'nullable', 'string'],
            'default_voice_profile_id' => [$nullable, 'nullable', 'integer'],
            'default_caption_preset_id' => [$nullable, 'nullable', 'integer'],
            'default_music_setting' => [$nullable, 'nullable', 'in:none,auto,instrumental,library'],
            'default_music_volume' => [$nullable, 'nullable', 'integer', 'min:0', 'max:100'],
            'default_language' => [$nullable, 'nullable', 'string', 'max:16'],
            'status' => [$nullable, 'nullable', 'in:active,archived'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeSeries(Series $series): array
    {
        $characters = $series->relationLoaded('characters')
            ? $series->characters->map(fn (SeriesCharacter $c): array => $this->serializeCharacter($c))->all()
            : null;

        return [
            'id' => $series->getKey(),
            'workspace_id' => $series->workspace_id,
            'channel_id' => $series->channel_id,
            'name' => $series->name,
            'description' => $series->description,
            'platform_targets' => $series->platform_targets ?? [],
            'aspect_ratio' => $series->aspect_ratio,
            'duration_target_seconds' => $series->duration_target_seconds,
            'posting_cadence' => $series->posting_cadence,
            'concept_text' => $series->concept_text,
            'audience_text' => $series->audience_text,
            'tone' => $series->tone,
            'episode_format_template' => $series->episode_format_template,
            'always_include_tags' => $series->always_include_tags ?? [],
            'never_include_tags' => $series->never_include_tags ?? [],
            'memory_window' => $series->memory_window,
            'auto_summarise' => $series->auto_summarise,
            'visual_mode' => $series->visual_mode,
            'visual_style' => $series->visual_style,
            'visual_palette' => $series->visual_palette,
            'visual_description' => $series->visual_description,
            'default_voice_profile_id' => $series->default_voice_profile_id,
            'default_caption_preset_id' => $series->default_caption_preset_id,
            'default_music_setting' => $series->default_music_setting,
            'default_music_volume' => $series->default_music_volume,
            'default_language' => $series->default_language,
            'status' => $series->status,
            'episodes_count' => (int) ($series->episodes_count ?? 0),
            'characters' => $characters,
            'created_at' => $series->created_at?->toIso8601String(),
            'updated_at' => $series->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function serializeCharacter(SeriesCharacter $character): array
    {
        return [
            'id' => $character->getKey(),
            'series_id' => $character->series_id,
            'name' => $character->name,
            'visual_description' => $character->visual_description,
            'personality_notes' => $character->personality_notes,
            'appearance_json' => $character->appearance_json,
            'status' => $character->status,
            'created_at' => $character->created_at?->toIso8601String(),
            'updated_at' => $character->updated_at?->toIso8601String(),
        ];
    }

    private function notFound(): JsonResponse
    {
        return response()->json([
            'error' => ['code' => 'not_found', 'message' => 'Not found.'],
        ], 404);
    }
}
