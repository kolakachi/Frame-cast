<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\CreditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Animate every scene in a project in one action.
 *
 * Always returns a costed preview; only starts work when `confirm` is true.
 * That ordering is the point — animation is the most expensive thing in the
 * product (up to 320 credits for one spokesperson clip), so committing a whole
 * project without showing the bill first is how someone loses their balance in
 * a click.
 *
 * Cost is summed PER SCENE, never multiplied. Spokesperson billing is
 * length-based (Fabric charges per second of voiceover), so a 12-scene project
 * can legitimately range from 130 to 320 credits a scene. A flat
 * count × tier-price estimate would be wrong for exactly the tier most likely
 * to be applied in bulk.
 */
class BulkAnimateController extends Controller
{
    public function __construct(private readonly CreditService $credits) {}

    public function __invoke(Request $request, int $projectId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $project = Project::query()
            ->whereKey($projectId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $validated = $request->validate([
            'tier'             => ['required', 'string', Rule::in(['quick', 'balanced', 'premium', 'seedance_lite', 'seedance_pro', 'spokesperson'])],
            'duration_seconds' => ['sometimes', 'integer', 'min:3', 'max:10'],
            'motion_prompt'    => ['sometimes', 'nullable', 'string', 'max:1000'],
            'quality'          => ['sometimes', 'nullable', 'string', 'max:16'],
            // The still EVERY selected scene animates from. Applying an
            // action to all scenes means applying the whole action, image
            // included — a scene's existing visual is replaced, and only its
            // script and voiceover stay its own. Scenes keep their previous
            // visual as their revert target, so this is undoable per scene.
            'source_asset_id'  => ['sometimes', 'nullable', 'integer'],
            // Restrict to chosen scenes. Absent = every eligible scene.
            'scene_ids'        => ['sometimes', 'array'],
            'scene_ids.*'      => ['integer'],
            // Preview by default. Nothing is charged or queued without this.
            'confirm'          => ['sometimes', 'boolean'],
        ]);

        $isSpokesperson  = $validated['tier'] === 'spokesperson';
        $durationSeconds = ((int) ($validated['duration_seconds'] ?? 5)) >= 8 ? 10 : 5;
        $quality         = $isSpokesperson
            ? null
            : CreditService::videoQuality($validated['tier'], $validated['quality'] ?? null);

        // A picked source image, validated once. Must be an image in this
        // workspace — an override is not a way to reach another tenant's asset.
        $appliedStill = null;
        if (! empty($validated['source_asset_id'])) {
            $appliedStill = Asset::query()
                ->whereKey((int) $validated['source_asset_id'])
                ->where('workspace_id', $user->workspace_id)
                ->first();

            if (! $appliedStill || ! $appliedStill->storage_url) {
                return $this->error('source_not_found', 'That image could not be found.', 404);
            }

            if ($appliedStill->asset_type === 'video' || str_starts_with((string) $appliedStill->mime_type, 'video/')) {
                return $this->error('source_not_image', 'Pick a still image — animation cannot start from a video.', 422);
            }
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        // A subset the user picked, or null for "everything eligible".
        $selection = array_key_exists('scene_ids', $validated)
            ? array_map('intval', $validated['scene_ids'])
            : null;

        $eligible = [];
        $skipped  = [];

        foreach ($scenes as $scene) {
            // The chosen image wins for every scene; only without one does a
            // scene fall back to its own still.
            $ownStill    = $this->sourceStill($scene);
            $sceneSource = $appliedStill ?? $ownStill;
            $reason      = $this->ineligibleReason($scene, $isSpokesperson, $sceneSource);

            if ($reason !== null) {
                $skipped[] = [
                    'scene_id'   => $scene->getKey(),
                    'order'      => (int) $scene->scene_order,
                    'reason'     => $reason,
                    'selectable' => false,
                ];
                continue;
            }

            $cost = $isSpokesperson
                ? CreditService::spokespersonCost($this->voiceoverSeconds($scene))
                : CreditService::animationCost($validated['tier'], $quality, $durationSeconds);

            // Animatable but not picked. Reported separately from "can't" so
            // the UI can offer it as a checkbox rather than an excuse.
            if ($selection !== null && ! in_array((int) $scene->getKey(), $selection, true)) {
                $skipped[] = [
                    'scene_id'   => $scene->getKey(),
                    'order'      => (int) $scene->scene_order,
                    'reason'     => 'Not selected',
                    'selectable' => true,
                    'cost'       => $cost,
                ];
                continue;
            }

            $eligible[] = [
                'scene_id'        => $scene->getKey(),
                'order'           => (int) $scene->scene_order,
                'cost'            => $cost,
                'source_still_id' => (int) $sceneSource->getKey(),
                // This scene had its own image and is losing it to the batch.
                'replaces_own'      => $appliedStill !== null
                    && $ownStill !== null
                    && $ownStill->getKey() !== $appliedStill->getKey(),
                // Had nothing of its own; the chosen image gives it one.
                'from_picked_image' => $ownStill === null,
            ];
        }

        // Animate each distinct image ONCE and share the clip with every other
        // scene built on it. Projects routinely reuse one still across scenes;
        // animating per scene would buy N identical videos at N times the price.
        //
        // Never for spokesperson — that clip is lip-synced to one scene's
        // audio, so two scenes cannot share it even with the same photo.
        $groups = [];

        if (! $isSpokesperson) {
            foreach ($eligible as $i => $row) {
                $groups[$row['source_still_id']][] = $i;
            }
        }

        foreach ($groups as $indexes) {
            // First scene pays and renders; the rest ride along for free.
            foreach (array_slice($indexes, 1) as $i) {
                $eligible[$i]['cost']            = 0;
                $eligible[$i]['shares_with']     = $eligible[$indexes[0]]['order'];
                $eligible[$indexes[0]]['shared_with_count']
                    = ($eligible[$indexes[0]]['shared_with_count'] ?? 0) + 1;
            }
        }

        $total   = array_sum(array_column($eligible, 'cost'));
        $balance = $this->credits->balance((int) $user->workspace_id);

        // What it WOULD have cost animating every scene separately, so the
        // saving is visible rather than just a smaller number.
        $unsharedTotal = 0;
        foreach ($eligible as $row) {
            $unsharedTotal += $isSpokesperson
                ? $row['cost']
                : CreditService::animationCost($validated['tier'], $quality, $durationSeconds);
        }

        $payload = [
            'tier'            => $validated['tier'],
            'eligible_count'  => count($eligible),
            'render_count'    => $isSpokesperson ? count($eligible) : count($groups),
            'source_asset_id' => $appliedStill?->getKey(),
            'replaced_count'  => count(array_filter(array_column($eligible, 'replaces_own'))),
            'unshared_cost'   => $unsharedTotal,
            'saved'           => max(0, $unsharedTotal - array_sum(array_column($eligible, 'cost'))),
            'skipped'         => $skipped,
            'total_cost'      => $total,
            'balance'         => $balance,
            'affordable'      => $total <= $balance,
            'shortage'        => max(0, $total - $balance),
            'scenes'          => $eligible,
            'started'         => false,
        ];

        if (empty($validated['confirm'])) {
            return response()->json(['data' => $payload, 'meta' => []]);
        }

        if ($eligible === []) {
            return $this->error('nothing_to_animate', 'No scenes in this project can be animated yet.', 422);
        }

        // All or nothing. Animating "as many as affordable" would drain the
        // balance AND leave a half-animated project, which is worse than being
        // told the number up front.
        if ($total > $balance) {
            return response()->json([
                'error' => [
                    'code'    => 'insufficient_credits',
                    'message' => "Animating all {$payload['eligible_count']} scenes costs {$total} credits. Your balance is {$balance}.",
                    'context' => $payload,
                ],
            ], 402);
        }

        $started = 0;

        // Only the paying scene of each group gets a job; its siblings receive
        // the finished clip from AnimateSceneJob::shareClipWith.
        $sharersFor = [];
        $renderRows = $eligible;

        if (! $isSpokesperson) {
            $renderRows = [];
            foreach ($groups as $indexes) {
                $lead = $eligible[$indexes[0]];
                $sharersFor[$lead['scene_id']] = array_map(
                    fn (int $i): int => (int) $eligible[$i]['scene_id'],
                    array_slice($indexes, 1),
                );
                $renderRows[] = $lead;
            }
        }

        // Lock the sharers too, so they read as working rather than idle while
        // the one render they depend on is running.
        foreach ($sharersFor as $sharerIds) {
            foreach ($sharerIds as $sharerId) {
                $sharer = $scenes->firstWhere('id', $sharerId);
                if (! $sharer) {
                    continue;
                }
                $sharer->forceFill([
                    'image_generation_settings_json' => array_merge($sharer->image_generation_settings_json ?? [], [
                        'animation_in_progress' => true,
                        'animation_last_error'  => null,
                    ]),
                ])->save();
            }
        }

        foreach ($renderRows as $row) {
            $scene = $scenes->firstWhere('id', $row['scene_id']);
            if (! $scene) {
                continue;
            }

            $token   = (string) Str::uuid();
            $existing = $scene->image_generation_settings_json ?? [];

            $scene->forceFill([
                'image_generation_settings_json' => array_merge($existing, [
                    'animation_in_progress'   => true,
                    'animation_last_error'    => null,
                    'animation_tier'          => $validated['tier'],
                    'animation_duration'      => $durationSeconds,
                    'animation_quality'       => $quality,
                    'animation_motion_prompt' => $validated['motion_prompt'] ?? null,
                    'animation_started_at'    => now()->toIso8601String(),
                    'generation_token'        => $token,
                ]),
            ])->save();

            // Same jobs as the single-scene path — they own the atomic deduct
            // and refund-on-failure, so bulk never becomes a second billing
            // route that can drift from the first.
            if ($isSpokesperson) {
                \App\Jobs\GenerateTalkingVideoJob::dispatch(
                    $scene->getKey(),
                    $scene->project_id,
                    $token,
                    $appliedStill?->getKey() ?? $this->sourceStill($scene)?->getKey(),
                );
            } else {
                \App\Jobs\AnimateSceneJob::dispatch(
                    $scene->getKey(),
                    $scene->project_id,
                    $validated['tier'],
                    $durationSeconds,
                    $validated['motion_prompt'] ?? null,
                    quality: $quality,
                    shareWithSceneIds: $sharersFor[$row['scene_id']] ?? [],
                    sourceAssetId: $appliedStill?->getKey() ?? $this->sourceStill($scene)?->getKey(),
                );
            }

            $started += 1 + count($sharersFor[$row['scene_id']] ?? []);
        }

        $payload['started']       = true;
        $payload['started_count'] = $started;

        return response()->json(['data' => $payload, 'meta' => []]);
    }

    /**
     * Why this scene can't be animated, or null if it can.
     *
     * The reasons are specific on purpose. "No still image" was originally
     * returned for every ineligible scene, including stock-clip scenes that
     * plainly DO have a visual — which reads as the batch miscounting rather
     * than as a scene that is already moving footage.
     */
    private function ineligibleReason(Scene $scene, bool $isSpokesperson, ?Asset $sceneSource): ?string
    {
        $existing = $scene->image_generation_settings_json ?? [];

        if (! empty($existing['animation_in_progress'])) {
            return 'Already animating';
        }

        // Only genuinely unrunnable when there is no still anywhere to use —
        // neither the scene's own nor a fallback.
        if (! $sceneSource) {
            return 'No image anywhere to animate from';
        }

        if ($isSpokesperson && $this->voiceoverSeconds($scene) <= 0.0) {
            return 'No voiceover — the spokesperson lip-syncs to the audio';
        }

        return null;
    }

    /**
     * The still to animate. On a re-animate the scene's visual is already a
     * video, so fall back to the preserved original — mirrors the single-scene
     * endpoint so bulk can't disagree with it about what's animatable.
     */
    private function sourceStill(Scene $scene): ?Asset
    {
        $asset = $scene->visual_asset_id ? Asset::query()->find($scene->visual_asset_id) : null;

        if ($this->isVideo($asset)) {
            $originalId = (int) (data_get($scene->image_generation_settings_json, 'animation_original_image_asset_id') ?? 0);
            $asset = $originalId ? Asset::query()->find($originalId) : null;
        }

        return $this->isVideo($asset) ? null : $asset;
    }

    private function isVideo(?Asset $asset): bool
    {
        return ! $asset
            || str_starts_with((string) $asset->mime_type, 'video/')
            || $asset->asset_type === 'video';
    }

    private function voiceoverSeconds(Scene $scene): float
    {
        $audioId = (int) data_get($scene->voice_settings_json, 'audio_asset_id', 0);

        if (! $audioId) {
            return 0.0;
        }

        $audio = Asset::query()->find($audioId);

        return (float) ($audio?->duration_seconds ?: $scene->duration_seconds ?: 8);
    }
}
