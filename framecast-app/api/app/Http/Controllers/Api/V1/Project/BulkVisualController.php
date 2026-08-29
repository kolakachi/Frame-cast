<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAIImageJob;
use App\Models\Character;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Restyle every scene's image in one action — same look across the video.
 *
 * Always returns a costed preview; only regenerates when `confirm` is true.
 *
 * There is deliberately NO prompt override here. Each scene regenerates from
 * its OWN established visual_prompt (see GenerateAIImageJob::buildPrompt), so
 * scene 4 still depicts what scene 4 is about — only the style and model
 * change. Pasting one description across every scene would produce a video of
 * the same picture N times, which is not a bulk edit, it's damage.
 */
class BulkVisualController extends Controller
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

        $usage = app(WorkspaceUsageService::class);

        if ($usage->hasExceededApiBudget($user)) {
            $ctx = $usage->apiBudgetContext($user);

            return $this->limitError(
                'api_budget_exceeded',
                "Your workspace has reached its \${$ctx['budget_usd']} AI budget for the {$ctx['plan']} plan this month.",
                $ctx,
            );
        }

        $styles = 'cinematic,dark,anime,documentary,minimalist,realistic,vintage,neon,photorealistic,cyberpunk_80s,anime_80s,anime_90s,dark_fantasy,fantasy_retro,comic,film_noir,line_drawing,watercolor,paper_cutout,cartoon,3d_animated,custom';

        $validated = $request->validate([
            'style'               => ['required', 'string', 'in:'.$styles],
            'custom_visual_style' => ['sometimes', 'nullable', 'string', 'max:500'],
            'model_key'           => ['sometimes', 'nullable', 'string', Rule::in(array_keys(ImageAdapterFactory::AVAILABLE))],
            // Restrict to chosen scenes. Absent = every eligible scene.
            'scene_ids'           => ['sometimes', 'array'],
            'scene_ids.*'         => ['integer'],
            // Preview by default. Nothing is charged or queued without this.
            'confirm'             => ['sometimes', 'boolean'],
        ]);

        $modelKey = $validated['model_key'] ?? null;
        $factory  = app(ImageAdapterFactory::class);

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        // One query for every character-bound scene in the project, rather than
        // an exists() per scene — those route to the reference-image path and
        // are charged at the higher AI_CHARACTER rate.
        $characterIds = $scenes->pluck('character_id')->filter()->unique()->values();
        $withReference = $characterIds->isEmpty()
            ? collect()
            : Character::query()
                ->whereIn('id', $characterIds)
                ->where('workspace_id', $user->workspace_id)
                ->whereNotNull('reference_asset_id')
                ->pluck('id')
                ->flip();

        $selection = array_key_exists('scene_ids', $validated)
            ? array_map('intval', $validated['scene_ids'])
            : null;

        $eligible = [];
        $skipped  = [];

        foreach ($scenes as $scene) {
            if ($this->isGenerating($scene)) {
                $skipped[] = [
                    'scene_id'   => $scene->getKey(),
                    'order'      => (int) $scene->scene_order,
                    'reason'     => 'Already generating an image',
                    'selectable' => false,
                ];
                continue;
            }

            $usesCharacter = $scene->character_id && $withReference->has($scene->character_id);

            if ($selection !== null && ! in_array((int) $scene->getKey(), $selection, true)) {
                $skipped[] = [
                    'scene_id'   => $scene->getKey(),
                    'order'      => (int) $scene->scene_order,
                    'reason'     => 'Not selected',
                    'selectable' => true,
                    'cost'       => $factory->generationCost($modelKey, (bool) $usesCharacter),
                ];
                continue;
            }

            $eligible[] = [
                'scene_id'       => $scene->getKey(),
                'order'          => (int) $scene->scene_order,
                'uses_character' => (bool) $usesCharacter,
                // Same call the single-scene endpoint makes, so quote = charge.
                'cost'           => $factory->generationCost($modelKey, (bool) $usesCharacter),
            ];
        }

        $total   = array_sum(array_column($eligible, 'cost'));
        $balance = $this->credits->balance((int) $user->workspace_id);

        $payload = [
            'style'           => $validated['style'],
            'model_key'       => $modelKey,
            'eligible_count'  => count($eligible),
            'character_count' => count(array_filter(array_column($eligible, 'uses_character'))),
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
            return $this->error('nothing_to_restyle', 'Every scene is already generating an image.', 422);
        }

        // All or nothing. A partial restyle is worse than none — it leaves the
        // video half in one look and half in another, and the credits are gone
        // either way.
        if ($total > $balance) {
            return response()->json([
                'error' => [
                    'code'    => 'insufficient_credits',
                    'message' => "Restyling all {$payload['eligible_count']} scenes costs {$total} credits. Your balance is {$balance}.",
                    'context' => $payload,
                ],
            ], 402);
        }

        // Remember the choice at project level so scenes added later inherit
        // the look instead of reverting to the old style. Only when the WHOLE
        // project was restyled — after a deliberate subset, the project has no
        // single look, and adopting the subset's style as the default would
        // silently apply it to every scene added later.
        if ($selection === null) {
            $project->forceFill([
                'default_visual_style' => $validated['style'],
                'custom_visual_style'  => $validated['style'] === 'custom'
                    ? ($validated['custom_visual_style'] ?? $project->custom_visual_style)
                    : $project->custom_visual_style,
            ])->save();
        }

        $started = 0;

        foreach ($eligible as $row) {
            $scene = $scenes->firstWhere('id', $row['scene_id']);
            if (! $scene) {
                continue;
            }

            $token    = (string) Str::uuid();
            $existing = $scene->image_generation_settings_json ?? [];

            $scene->forceFill([
                'image_generation_settings_json' => array_merge($existing, [
                    'in_progress'           => true,
                    'last_error'            => null,
                    'needs_visual'          => false,
                    'generation_token'      => $token,
                    'generation_started_at' => now()->toIso8601String(),
                ]),
            ])->save();

            // Same job as the single-scene path — it owns the atomic deduct and
            // refund-on-failure, so bulk never becomes a second billing route.
            // promptOverride stays null on purpose: each scene rebuilds from
            // its own visual_prompt.
            GenerateAIImageJob::dispatch(
                $scene->getKey(),
                $scene->project_id,
                $validated['style'],
                null,
                $validated['style'],
                $token,
                null, null, null, // no animate chain
                $modelKey,
            );

            $started++;
        }

        $payload['started']       = true;
        $payload['started_count'] = $started;

        return response()->json(['data' => $payload, 'meta' => []]);
    }

    /** Mirrors SceneController::regenerateImage — a >5min lock is stale. */
    private function isGenerating(Scene $scene): bool
    {
        $settings = $scene->image_generation_settings_json ?? [];

        if (empty($settings['in_progress'])) {
            return false;
        }

        $startedAt = isset($settings['generation_started_at'])
            ? \Carbon\Carbon::parse($settings['generation_started_at'])
            : null;

        return $startedAt !== null && $startedAt->diffInMinutes(now()) < 5;
    }
}
