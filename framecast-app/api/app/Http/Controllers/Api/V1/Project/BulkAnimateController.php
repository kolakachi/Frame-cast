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
            // Preview by default. Nothing is charged or queued without this.
            'confirm'          => ['sometimes', 'boolean'],
        ]);

        $isSpokesperson  = $validated['tier'] === 'spokesperson';
        $durationSeconds = ((int) ($validated['duration_seconds'] ?? 5)) >= 8 ? 10 : 5;
        $quality         = $isSpokesperson
            ? null
            : CreditService::videoQuality($validated['tier'], $validated['quality'] ?? null);

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        $eligible = [];
        $skipped  = [];

        foreach ($scenes as $scene) {
            $reason = $this->ineligibleReason($scene, $isSpokesperson);

            if ($reason !== null) {
                $skipped[] = [
                    'scene_id' => $scene->getKey(),
                    'order'    => (int) $scene->scene_order,
                    'reason'   => $reason,
                ];
                continue;
            }

            $cost = $isSpokesperson
                ? CreditService::spokespersonCost($this->voiceoverSeconds($scene))
                : CreditService::animationCost($validated['tier'], $quality, $durationSeconds);

            $eligible[] = [
                'scene_id' => $scene->getKey(),
                'order'    => (int) $scene->scene_order,
                'cost'     => $cost,
            ];
        }

        $total   = array_sum(array_column($eligible, 'cost'));
        $balance = $this->credits->balance((int) $user->workspace_id);

        $payload = [
            'tier'            => $validated['tier'],
            'eligible_count'  => count($eligible),
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

        foreach ($eligible as $row) {
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

            $started++;
        }

        $payload['started']       = true;
        $payload['started_count'] = $started;

        return response()->json(['data' => $payload, 'meta' => []]);
    }

    /** Why this scene can't be animated, or null if it can. */
    private function ineligibleReason(Scene $scene, bool $isSpokesperson): ?string
    {
        $existing = $scene->image_generation_settings_json ?? [];

        if (! empty($existing['animation_in_progress'])) {
            return 'Already animating';
        }

        if (! $this->sourceStill($scene)) {
            return 'No still image to animate yet';
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
