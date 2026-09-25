<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\CruiseControl\CruiseControlController;
use App\Models\ApiQuote;
use App\Models\Project;
use App\Models\User;
use App\Services\CreditService;
use App\Services\CruiseControl\CruiseToolRegistry;
use App\Services\Developer\EditOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Phase 5: WyvStudio's in-app assistant (Cruise Control) as a bounded planner
 * for an external assistant.
 *
 * The external assistant sends a plain request ("make this more energetic");
 * Cruise resolves it into concrete actions from its own tool registry, each
 * with a diff and an estimate, and that list is frozen as a plan with the
 * project's revision. Nothing is applied by planning. Applying the plan runs
 * only the frozen actions, through Cruise's own apply (its estimate check,
 * balance check, audit log and revert snapshot), one by one, and reports
 * each result. The client can never name a tool or params itself, so Cruise
 * has no wider reach through the API than it has in the editor, and Cruise
 * never calls back out.
 */
class AssistantController extends DeveloperController
{
    use ClaimsQuotes;

    // Explicit public boundary: new dashboard tools are not automatically API tools.
    private const API_TOOLS = ['regenerate_image', 'animate_scene', 'make_spokesperson', 'add_scene', 'lock_subject',
        'change_music', 'update_scene_script', 'reorder_scene', 'rerecord_voice', 'set_audiogram_visual',
        'update_captions', 'apply_brand_kit', 'find_stock_video', 'find_stock_image', 'pick_library_music',
        'swap_visual_from_library', 'add_sound_effect', 'export_video', 'schedule_post'];

    public function __construct(private readonly CreditService $credits, private readonly CruiseToolRegistry $registry)
    {
    }

    /** What Cruise can do, for the docs and the schema. */
    public function tools(): JsonResponse
    {
        $tools = [];
        foreach ($this->registry->all() as $tool) {
            if (! in_array($tool->name(), self::API_TOOLS, true)) continue;
            $tools[] = ['name' => $tool->name(), 'description' => $tool->description(), 'section' => $tool->affectedSection(), 'confirmation' => $tool->confirmationClass(), 'execution' => $tool->name() === 'schedule_post' ? 'app_handoff' : 'api_action'];
        }

        return response()->json(['data' => ['tools' => $tools], 'meta' => ['count' => count($tools)]]);
    }

    /** Free: ask Cruise to turn a request into concrete, priced actions. */
    public function plan(Request $request, int $videoId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = Project::query()->whereKey($videoId)->where('workspace_id', $user->workspace_id)->first();
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $input = $this->validated($request, [
            'request' => ['required', 'string', 'min:2', 'max:1000'],
            'scene_id' => ['nullable', 'integer'],
            'history' => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required_with:history', 'in:user,assistant'],
            'history.*.text' => ['required_with:history', 'string', 'max:2000'],
        ]);
        $revision = EditorController::revision($project);

        $out = EditOperations::run(fn () => app(CruiseControlController::class)->resolve(EditOperations::inner($request, array_filter([
            'project_id' => $project->getKey(), 'intent' => $input['request'], 'scope_scene_id' => $input['scene_id'] ?? null, 'history' => $input['history'] ?? null,
        ], fn ($v) => $v !== null))));
        if ($out instanceof JsonResponse) {
            return $out;
        }

        $actions = [];
        $total = 0;
        foreach ((array) ($out['actions'] ?? []) as $i => $a) {
            if (! in_array($a['tool'], self::API_TOOLS, true)) return $this->fail('app_only_action', 'This assistant action is available only in the app.', 422);
            $cost = (int) ($a['estimated_cost'] ?? 0);
            if ($cost > 0 && $actions !== []) {
                return $this->fail('dependent_changes_require_staging', 'Apply one assistant action at a time when later actions spend credits. Replan against the completed result.', 422);
            }
            $total += $cost;
            $actions[] = ['index' => $i, 'tool' => $a['tool'], 'params' => $a['params'] ?? [], 'what_changes' => $a['tool'] === 'schedule_post' ? ['Open the app scheduler; confirm export, account and time there. No post is scheduled by this action.'] : ($a['diff_lines'] ?? []),
                'section' => $a['affected_section'] ?? null, 'credits_max' => $cost,
                'execution' => $a['tool'] === 'schedule_post' ? 'app_handoff' : 'api_action'];
        }

        if ($actions === []) {
            return response()->json(['data' => ['plan_id' => null, 'reply' => $out['reply_to_user'] ?? 'Nothing to do.', 'actions' => [], 'credits' => ['max' => 0]], 'meta' => []]);
        }

        $quote = ApiQuote::query()->create([
            'id' => ApiQuote::newId(), 'workspace_id' => (int) $user->workspace_id, 'api_key_id' => $request->attributes->get('api_key_id'),
            'created_by_user_id' => $user->getKey(),
            'payload_json' => ['__kind' => 'assistant_plan', 'project_id' => $project->getKey(), 'revision' => $revision, 'request' => $input['request'],
                'actions' => $actions, 'message_id' => $out['assistant_message_id'] ?? null],
            'credits_min' => $total, 'credits_max' => $total, 'expires_at' => now()->addMinutes(ApiQuote::TTL_MINUTES),
        ]);
        $balance = $this->credits->balance((int) $user->workspace_id);

        return response()->json(['data' => [
            'plan_id' => $quote->getKey(), 'revision' => $revision, 'reply' => in_array('schedule_post', array_column($actions, 'tool'), true) ? 'This plan includes a scheduling handoff. Nothing has been scheduled; choose the destination and confirm in the app.' : ($out['reply_to_user'] ?? ''), 'actions' => $actions,
            'credits' => ['max' => $total], 'balance' => $balance, 'can_afford' => $balance >= $total,
            'expires_at' => $quote->expires_at->toIso8601String(),
        ], 'meta' => []], 201);
    }

    /** Spends credits: run the frozen actions through Cruise's own apply. */
    public function apply(Request $request, int $videoId, string $planId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;
        $project = Project::query()->whereKey($videoId)->where('workspace_id', $workspaceId)->first();
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $input = $this->validated($request, ['idempotency_key' => ['nullable', 'string', 'max:128'], 'only' => ['nullable', 'array'], 'only.*' => ['integer', 'min:0']]);
        $idempotencyKey = $this->idempotencyKeyFrom($request, $input) ?? $planId;

        $plan = ApiQuote::query()->whereKey($planId)->where('workspace_id', $workspaceId)->first();
        $selection = isset($input['only']) ? $input['only'] : array_column($plan?->payload_json['actions'] ?? [], 'index');

        $claim = $this->claimQuote($planId, $workspaceId, $idempotencyKey, $request->attributes->get('api_key_id'), 'assistant_plan', $this->credits, ['project_id' => $videoId], $selection);
        if ($claim instanceof JsonResponse) {
            return $claim;
        }
        /** @var ApiQuote $quote */
        $quote = $claim['quote'];
        $f = $quote->payload_json;
        if (array_key_exists('replay', $claim)) {
            return response()->json(['data' => $f['result'] ?? ['applied' => []], 'meta' => []], 200);
        }
        if ($f['revision'] !== EditorController::revision($project)) {
            $this->releaseQuote($quote);

            return $this->fail('revision_conflict', 'The project changed after this plan was made. Plan again.', 409, ['current_revision' => EditorController::revision($project)]);
        }

        $only = isset($input['only']) ? array_map('intval', $input['only']) : null;
        $results = [];
        foreach ($f['actions'] as $a) {
            if ($only !== null && ! in_array((int) $a['index'], $only, true)) {
                $results[] = ['index' => $a['index'], 'tool' => $a['tool'], 'ok' => null, 'skipped' => true];
                continue;
            }
            if (! in_array($a['tool'], self::API_TOOLS, true)) {
                $results[] = ['index' => $a['index'], 'tool' => $a['tool'], 'ok' => false, 'error' => ['code' => 'app_only_action', 'message' => 'Use the app for this action.']];
                continue;
            }
            if ($a['tool'] === 'schedule_post' && ! $this->credits->limitFor($workspaceId, 'social_publishing')) {
                $results[] = ['index' => $a['index'], 'tool' => $a['tool'], 'ok' => false, 'error' => ['code' => 'upgrade_required', 'message' => 'Social publishing is not available on this plan.']];
                continue;
            }
            $this->checkpoint($quote, $results, (int) $a['index']);
            $out = EditOperations::run(fn () => app(CruiseControlController::class)->apply(EditOperations::inner($request, [
                'project_id' => $project->getKey(), 'tool' => $a['tool'], 'params' => $a['params'], 'expected_credits' => (int) $a['credits_max'],
                'message_id' => $f['message_id'] ?? null, 'action_index' => (int) $a['index'],
            ])));
            if ($out instanceof JsonResponse) {
                $err = $out->getData(true)['error'] ?? [];
                $results[] = ['index' => $a['index'], 'tool' => $a['tool'], 'ok' => false, 'status' => $out->getStatusCode(), 'error' => ['code' => $err['code'] ?? 'refused', 'message' => $err['message'] ?? '']];
            } else {
                if (($out['navigate']['type'] ?? null) === 'schedule' || $a['tool'] === 'schedule_post') {
                    $handoff = DeliveryController::describe($project, 'schedule') + ['version_checked' => false];
                    $results[] = ['index' => $a['index'], 'tool' => $a['tool'], 'ok' => true,
                        'outcome' => 'handoff_required', 'external_action_completed' => false,
                        'summary' => 'Open the app to select a destination and confirm scheduling. Nothing was scheduled.',
                        'credits_spent' => 0, 'navigate' => $out['navigate'] ?? ['type' => 'schedule'], 'handoff' => $handoff];
                } else {
                    $results[] = ['index' => $a['index'], 'tool' => $a['tool'], 'ok' => true,
                        'summary' => $out['summary'] ?? null, 'credits_spent' => $out['credits_spent'] ?? 0, 'affected_scene_id' => $out['affected_scene_id'] ?? null];
                }
            }
            $this->checkpoint($quote, $results, null);
        }
        $this->checkpoint($quote, $results, null);
        $project = $project->fresh();
        $result = ['plan_id' => $quote->getKey(), 'revision' => EditorController::revision($project), 'applied' => $results,
            'handoffs_required' => count(array_filter($results, fn ($r) => ($r['outcome'] ?? null) === 'handoff_required')),
            'failed' => count(array_filter($results, fn ($r) => $r['ok'] === false)), 'credits_spent' => array_sum(array_map(fn ($r) => (int) ($r['credits_spent'] ?? 0), $results))];
        $quote->forceFill(['project_id' => $project->getKey(), 'payload_json' => $quote->payload_json + ['result' => $result]])->save();

        return response()->json(['data' => $result, 'meta' => []]);
    }
}
