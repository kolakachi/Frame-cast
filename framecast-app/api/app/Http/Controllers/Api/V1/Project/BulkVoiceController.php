<?php

namespace App\Http\Controllers\Api\V1\Project;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateTTSJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\TTS\RoutingTTSAdapter;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Re-record every scene's voiceover in one action.
 *
 * Always returns a costed preview; only records when `confirm` is true.
 *
 * Each scene re-records its OWN script with its OWN voice settings — this
 * re-runs TTS, it does not reassign voices. (Changing the voice everywhere is
 * the separate "apply this voice to all scenes" action in the editor; the two
 * compose: set the voice, then re-record.)
 *
 * Cost is summed per scene because the three TTS engines bill differently
 * (OpenAI 1cr, cloned 2cr, Gemini 3cr) and a project can mix them freely — a
 * scene on a cloned voice next to one on Gemini has no single per-scene price.
 */
class BulkVoiceController extends Controller
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
            // Restrict to chosen scenes. Absent = every eligible scene.
            'scene_ids'   => ['sometimes', 'array'],
            'scene_ids.*' => ['integer'],
            'confirm'     => ['sometimes', 'boolean'],
        ]);

        $selection = array_key_exists('scene_ids', $validated)
            ? array_map('intval', $validated['scene_ids'])
            : null;

        $usage = app(WorkspaceUsageService::class);

        // Both guards the single-scene endpoint applies. Checked before the
        // quote so we never show a price for something that can't run.
        if ($usage->hasExceededApiBudget($user)) {
            $ctx = $usage->apiBudgetContext($user);

            return $this->limitError(
                'api_budget_exceeded',
                "Your workspace has reached its \${$ctx['budget_usd']} AI budget for the {$ctx['plan']} plan this month.",
                $ctx,
            );
        }

        if ($usage->hasReachedVoiceLimit($user)) {
            $ctx = $usage->voiceLimitContext($user);

            return $this->limitError(
                'voice_limit_reached',
                "Your workspace has used {$ctx['used']} of {$ctx['limit']} voice minutes on the {$ctx['plan']} plan.",
                $ctx,
            );
        }

        $scenes = Scene::query()
            ->where('project_id', $project->getKey())
            ->orderBy('scene_order')
            ->get();

        $eligible = [];
        $skipped  = [];

        foreach ($scenes as $scene) {
            if (trim((string) $scene->script_text) === '') {
                $skipped[] = [
                    'scene_id'   => $scene->getKey(),
                    'order'      => (int) $scene->scene_order,
                    'reason'     => 'No script to record yet',
                    'selectable' => false,
                ];
                continue;
            }

            $settings = $scene->voice_settings_json ?? [];
            $voiceId  = (string) data_get($settings, 'voice_id', \App\Services\Generation\TTS\GeminiVoices::DEFAULT_VOICE);
            $provider = (string) data_get($settings, 'provider', '');

            // Resolved through the SAME method the router uses at synthesis
            // time, so the quote can't drift from the charge.
            $engine = RoutingTTSAdapter::engineFor($voiceId, ['provider' => $provider]);

            if ($selection !== null && ! in_array((int) $scene->getKey(), $selection, true)) {
                $skipped[] = [
                    'scene_id'   => $scene->getKey(),
                    'order'      => (int) $scene->scene_order,
                    'reason'     => 'Not selected',
                    'selectable' => true,
                    'cost'       => CreditService::ttsCostForEngine($engine),
                ];
                continue;
            }

            $eligible[] = [
                'scene_id' => $scene->getKey(),
                'order'    => (int) $scene->scene_order,
                'engine'   => $engine,
                'cost'     => CreditService::ttsCostForEngine($engine),
            ];
        }

        $total   = array_sum(array_column($eligible, 'cost'));
        $balance = $this->credits->balance((int) $user->workspace_id);

        $payload = [
            'eligible_count' => count($eligible),
            'skipped'        => $skipped,
            'total_cost'     => $total,
            'balance'        => $balance,
            'affordable'     => $total <= $balance,
            'shortage'       => max(0, $total - $balance),
            'scenes'         => $eligible,
            'started'        => false,
        ];

        if (! $request->boolean('confirm')) {
            return response()->json(['data' => $payload, 'meta' => []]);
        }

        if ($eligible === []) {
            return $this->error('nothing_to_record', 'No scene has a script to record yet.', 422);
        }

        // All or nothing — a half re-recorded project mixes old and new audio
        // across scenes, and the credits are spent either way.
        if ($total > $balance) {
            return response()->json([
                'error' => [
                    'code'    => 'insufficient_credits',
                    'message' => "Re-recording all {$payload['eligible_count']} scenes costs {$total} credits. Your balance is {$balance}.",
                    'context' => $payload,
                ],
            ], 402);
        }

        $sceneIds = array_column($eligible, 'scene_id');

        // LOAD-BEARING: GenerateTTSJob skips any scene that already has an
        // audio asset unless is_outdated is set. Every scene here has audio by
        // definition — that's what "re-record" means — so without this flag the
        // job would run, report success, and change nothing.
        Scene::query()
            ->whereIn('id', $sceneIds)
            ->each(function (Scene $scene): void {
                $scene->forceFill([
                    'voice_settings_json' => array_merge($scene->voice_settings_json ?? [], [
                        'is_outdated' => true,
                    ]),
                ])->save();
            });

        // One job for the whole set — it already loops scenes internally and
        // owns the per-scene deduct. shouldFinalizeProject stays false so a
        // re-record on a finished project doesn't reset its status.
        GenerateTTSJob::dispatch($project->getKey(), $sceneIds, false)->afterCommit();

        $payload['started']       = true;
        $payload['started_count'] = count($sceneIds);

        return response()->json(['data' => $payload, 'meta' => []]);
    }
}
