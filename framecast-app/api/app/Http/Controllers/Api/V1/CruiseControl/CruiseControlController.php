<?php

namespace App\Http\Controllers\Api\V1\CruiseControl;

use App\Http\Controllers\Controller;
use App\Models\CruiseAuditLog;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\CruiseControl\CruiseControlService;
use App\Services\CruiseControl\CruiseToolRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Two endpoints. resolve() takes user intent + scope, returns a structured
 * action proposal (or a clarifying question). apply() runs the proposed
 * action atomically.
 *
 * Audit logging on both phases — every resolve/apply gets a row in
 * cruise_audit_logs for forensics + future fine-tuning data.
 */
class CruiseControlController extends Controller
{
    public function __construct(
        private CruiseControlService $service,
        private CruiseToolRegistry $registry,
        private CreditService $credits,
    ) {
    }

    public function resolve(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // 30 resolve calls / 10 minutes per user (plan §5.1).
        $rateKey = 'cruise:resolve:' . $user->getKey();
        if (RateLimiter::tooManyAttempts($rateKey, 30)) {
            return $this->error(
                'rate_limited',
                'You\'re sending requests too fast. Try again in a minute.',
                429,
            );
        }
        RateLimiter::hit($rateKey, 600);

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'intent'     => ['required', 'string', 'min:2', 'max:1000'],
            'scope_scene_id' => ['nullable', 'integer'],
            'history'    => ['nullable', 'array', 'max:12'],
            'history.*.role' => ['required_with:history', 'string', 'in:user,assistant'],
            'history.*.text' => ['required_with:history', 'string', 'max:800'],
        ]);

        $project = Project::query()
            ->whereKey($validated['project_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $scope = null;
        if (! empty($validated['scope_scene_id'])) {
            $scope = Scene::query()
                ->whereKey($validated['scope_scene_id'])
                ->where('project_id', $project->getKey())
                ->first();
        }

        $result = $this->service->resolve(
            $validated['intent'],
            $project,
            $scope,
            $validated['history'] ?? [],
        );

        CruiseAuditLog::create([
            'workspace_id'    => $user->workspace_id,
            'user_id'         => $user->getKey(),
            'project_id'      => $project->getKey(),
            'scene_id'        => $scope?->getKey(),
            'phase'           => 'resolve',
            'intent_text'     => mb_substr($validated['intent'], 0, 1000),
            'resolved_tool'   => $result['action']['tool'] ?? null,
            'resolved_params' => $result['action']['params'] ?? null,
            'applied'         => false,
            'outcome'         => $result['action'] ? 'ok' : 'unresolved',
        ]);

        return response()->json(['data' => $result, 'meta' => []]);
    }

    public function apply(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'tool'       => ['required', 'string'],
            'params'     => ['required', 'array'],
        ]);

        $tool = $this->registry->get($validated['tool']);
        if (! $tool) {
            return $this->error('unknown_tool', 'That tool is not available.', 422);
        }

        $project = Project::query()
            ->whereKey($validated['project_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $workspace = Workspace::query()->whereKey($user->workspace_id)->first();
        if (! $workspace) {
            return $this->error('no_workspace', 'No workspace.', 422);
        }

        // Pre-flight credit check on the tool's estimate. Defence-in-depth:
        // the resolve already showed the user the cost, but balance may
        // have dropped between resolve and apply.
        $estimate = $tool->estimateCost($project, $validated['params']);
        $balance = $this->credits->balance((int) $workspace->getKey());
        if ($balance < $estimate) {
            return $this->error(
                'insufficient_credits',
                "Need {$estimate} credits to run this. You have {$balance}.",
                402,
            );
        }

        try {
            $result = DB::transaction(function () use ($tool, $workspace, $project, $validated) {
                return $tool->execute($workspace, $project, $validated['params']);
            });
        } catch (\Throwable $e) {
            CruiseAuditLog::create([
                'workspace_id'    => $user->workspace_id,
                'user_id'         => $user->getKey(),
                'project_id'      => $project->getKey(),
                'phase'           => 'apply',
                'resolved_tool'   => $validated['tool'],
                'resolved_params' => $validated['params'],
                'applied'         => false,
                'outcome'         => 'error',
                'error_message'   => mb_substr($e->getMessage(), 0, 500),
            ]);
            return $this->error('apply_failed', $e->getMessage(), 422);
        }

        // Tools deduct credits themselves via the jobs they dispatch
        // (GenerateTTSJob etc.); we don't deduct here. Just stamp the
        // estimated spend for the audit so forensics shows what we
        // told the user vs what landed.
        CruiseAuditLog::create([
            'workspace_id'    => $user->workspace_id,
            'user_id'         => $user->getKey(),
            'project_id'      => $project->getKey(),
            'phase'           => 'apply',
            'resolved_tool'   => $validated['tool'],
            'resolved_params' => $validated['params'],
            'applied'         => true,
            'credits_spent'   => $result['credits_spent'] ?? 0,
            'outcome'         => 'ok',
        ]);

        return response()->json([
            'data' => [
                'success'          => true,
                'summary'          => $result['summary'],
                'credits_spent'    => $result['credits_spent'] ?? 0,
                'affected_section' => $tool->affectedSection(),
            ],
            'meta' => [],
        ]);
    }

}
