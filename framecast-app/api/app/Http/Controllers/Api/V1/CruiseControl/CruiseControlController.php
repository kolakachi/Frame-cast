<?php

namespace App\Http\Controllers\Api\V1\CruiseControl;

use App\Http\Controllers\Controller;
use App\Models\CruiseAuditLog;
use App\Models\CruiseConversation;
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
use Illuminate\Support\Str;

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

        // Persist both turns to the conversation so the editor can
        // hydrate from history on refresh.
        $userMsg = [
            'id'         => 'u-' . Str::uuid()->toString(),
            'role'       => 'user',
            'text'       => $validated['intent'],
            'created_at' => now()->toIso8601String(),
        ];
        $assistantMsg = [
            'id'         => 'a-' . Str::uuid()->toString(),
            'role'       => 'assistant',
            'text'       => $result['reply_to_user'],
            'action'     => $result['action'],
            'action_status' => $result['action'] ? 'proposed' : null,
            'created_at' => now()->toIso8601String(),
        ];
        $this->appendMessages($user, $project, [$userMsg, $assistantMsg]);

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

        return response()->json([
            'data' => [
                ...$result,
                'user_message_id'      => $userMsg['id'],
                'assistant_message_id' => $assistantMsg['id'],
            ],
            'meta' => [],
        ]);
    }

    public function apply(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'tool'       => ['required', 'string'],
            'params'     => ['required', 'array'],
            'message_id' => ['nullable', 'string', 'max:64'],
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

        // Stamp the message in the persisted conversation as applied so
        // the chat history shows ✓ on refresh, not the Apply button.
        if (! empty($validated['message_id'])) {
            $this->updateMessageStatus(
                $user,
                $project,
                $validated['message_id'],
                'applied',
                (int) ($result['credits_spent'] ?? 0),
            );
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
                'success'           => true,
                'summary'           => $result['summary'],
                'credits_spent'     => $result['credits_spent'] ?? 0,
                'affected_section'  => $tool->affectedSection(),
                'affected_scene_id' => $result['affected_scene_id'] ?? null,
            ],
            'meta' => [],
        ]);
    }

    /**
     * Persist that the user dismissed a proposed action. Flips the
     * message's action_status to 'skipped' so refresh / re-open shows the
     * Skipped pill instead of the Apply button again.
     */
    public function skip(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'project_id' => ['required', 'integer'],
            'message_id' => ['required', 'string', 'max:64'],
        ]);

        $project = Project::query()
            ->whereKey($validated['project_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $this->updateMessageStatus($user, $project, $validated['message_id'], 'skipped');

        CruiseAuditLog::create([
            'workspace_id'  => $user->workspace_id,
            'user_id'       => $user->getKey(),
            'project_id'    => $project->getKey(),
            'phase'         => 'apply',
            'applied'       => false,
            'outcome'       => 'skipped',
        ]);

        return response()->json(['data' => ['success' => true], 'meta' => []]);
    }

    /**
     * Update workspace-level Cruise prefs (auto-apply for now).
     */
    public function updateSettings(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspace = Workspace::query()->whereKey($user->workspace_id)->first();
        if (! $workspace) {
            return $this->error('no_workspace', 'No workspace.', 422);
        }
        $validated = $request->validate([
            'auto_apply' => ['required', 'boolean'],
        ]);
        $workspace->forceFill(['cruise_auto_apply' => $validated['auto_apply']])->save();

        return response()->json([
            'data' => ['auto_apply' => (bool) $workspace->cruise_auto_apply],
            'meta' => [],
        ]);
    }

    /**
     * Hydrate the chat history for a project. Frontend loads this on
     * editor mount so the conversation survives refresh / re-open.
     */
    public function conversation(Request $request, int $projectId): JsonResponse
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

        $conv = CruiseConversation::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('project_id', $projectId)
            ->first();

        return response()->json([
            'data' => [
                'messages'         => $conv?->messages ?? [],
                'message_count'    => $conv?->message_count ?? 0,
                'last_activity_at' => $conv?->last_activity_at?->toIso8601String(),
            ],
            'meta' => [],
        ]);
    }

    /**
     * Append turns to the conversation. Uses a row-level lock so two
     * parallel resolves (user opens chat in two tabs and sends quickly)
     * don't clobber each other's history.
     */
    private function appendMessages(User $user, Project $project, array $newMessages): void
    {
        DB::transaction(function () use ($user, $project, $newMessages) {
            $conv = CruiseConversation::query()
                ->where('workspace_id', $user->workspace_id)
                ->where('project_id', $project->getKey())
                ->lockForUpdate()
                ->first();

            if (! $conv) {
                $conv = new CruiseConversation([
                    'workspace_id' => $user->workspace_id,
                    'project_id'   => $project->getKey(),
                    'user_id'      => $user->getKey(),
                    'messages'     => [],
                ]);
            }

            $messages = is_array($conv->messages) ? $conv->messages : [];
            $messages = array_merge($messages, $newMessages);

            // Cap at the last 200 entries so the JSON column doesn't grow
            // unbounded. Older messages still live in cruise_audit_logs.
            if (count($messages) > 200) {
                $messages = array_slice($messages, -200);
            }

            $conv->forceFill([
                'messages'         => $messages,
                'message_count'    => count($messages),
                'last_activity_at' => now(),
            ])->save();
        });
    }

    /**
     * Flip a message's action_status in place. Used by apply() so the
     * chat-history view shows ✓ applied (or ✕ failed) on refresh.
     */
    private function updateMessageStatus(User $user, Project $project, string $messageId, string $status, int $creditsSpent = 0): void
    {
        DB::transaction(function () use ($user, $project, $messageId, $status, $creditsSpent) {
            $conv = CruiseConversation::query()
                ->where('workspace_id', $user->workspace_id)
                ->where('project_id', $project->getKey())
                ->lockForUpdate()
                ->first();
            if (! $conv || ! is_array($conv->messages)) return;

            $messages = $conv->messages;
            foreach ($messages as $i => $m) {
                if (($m['id'] ?? null) === $messageId) {
                    $messages[$i]['action_status']  = $status;
                    if ($creditsSpent > 0) $messages[$i]['action_credits'] = $creditsSpent;
                    break;
                }
            }

            $conv->forceFill(['messages' => $messages, 'last_activity_at' => now()])->save();
        });
    }

}
