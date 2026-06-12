<?php

namespace App\Http\Controllers\Api\V1\CruiseControl;

use App\Http\Controllers\Controller;
use App\Models\CruiseActionRun;
use App\Models\CruiseAuditLog;
use App\Models\CruiseConversation;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\CruiseControl\CruiseActionRunService;
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
        private CruiseActionRunService $actionRuns,
        private \App\Services\CruiseControl\ProjectBriefService $briefs,
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
            // Larger than the reply text alone: assistant turns now carry an
            // "[actions already taken: …]" note so the resolver remembers
            // what it did to which scene.
            'history.*.text' => ['required_with:history', 'string', 'max:2000'],
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
        // Persist actions[] (plural). Each entry carries its own status so
        // multi-action turns can show per-card progress on refresh. action
        // (singular) is kept on the message as a back-compat read for old
        // clients but new clients should iterate actions[].
        $actionsForPersist = [];
        foreach (($result['actions'] ?? []) as $a) {
            $actionsForPersist[] = $a + ['status' => 'proposed'];
        }
        $assistantMsg = [
            'id'         => 'a-' . Str::uuid()->toString(),
            'role'       => 'assistant',
            'text'       => $result['reply_to_user'],
            'action'     => $result['action'] ?? null,
            'actions'    => $actionsForPersist,
            'action_status' => !empty($actionsForPersist) ? 'proposed' : null,
            'created_at' => now()->toIso8601String(),
        ];
        $this->appendMessages($user, $project, [$userMsg, $assistantMsg]);

        // One audit row per proposed action so forensics + fine-tuning data
        // captures the whole plan, not just the first card.
        $actionsForAudit = $result['actions'] ?? ($result['action'] ? [$result['action']] : []);
        if (empty($actionsForAudit)) {
            CruiseAuditLog::create([
                'workspace_id'    => $user->workspace_id,
                'user_id'         => $user->getKey(),
                'project_id'      => $project->getKey(),
                'scene_id'        => $scope?->getKey(),
                'phase'           => 'resolve',
                'intent_text'     => mb_substr($validated['intent'], 0, 1000),
                'applied'         => false,
                'outcome'         => 'unresolved',
            ]);
        } else {
            foreach ($actionsForAudit as $a) {
                CruiseAuditLog::create([
                    'workspace_id'    => $user->workspace_id,
                    'user_id'         => $user->getKey(),
                    'project_id'      => $project->getKey(),
                    'scene_id'        => $scope?->getKey(),
                    'phase'           => 'resolve',
                    'intent_text'     => mb_substr($validated['intent'], 0, 1000),
                    'resolved_tool'   => $a['tool'] ?? null,
                    'resolved_params' => $a['params'] ?? null,
                    'applied'         => false,
                    'outcome'         => 'ok',
                ]);
            }
        }

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
            'project_id'   => ['required', 'integer'],
            'tool'         => ['required', 'string'],
            'params'       => ['required', 'array'],
            'message_id'   => ['nullable', 'string', 'max:64'],
            // Index into the assistant message's actions[] array. When the
            // message has multiple proposed actions, this tells us which one
            // to stamp as applied.
            'action_index' => ['nullable', 'integer', 'min:0', 'max:10'],
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

        // Snapshot the state this action is about to overwrite, BEFORE it
        // runs, so the user can undo it later. add_scene's snapshot is
        // finalised after execute (we need the created scene id).
        $revert = $this->buildRevertSnapshot((string) $validated['tool'], $project, $validated['params']);

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
        // For multi-action turns we stamp ONLY the indexed action, not
        // the whole message — sibling actions stay in 'proposed'.
        // Finalise add_scene's revert now that we know the created scene id:
        // undo = delete that scene and restore the pre-insert orders/labels.
        if ($validated['tool'] === 'add_scene' && ! empty($result['affected_scene_id'])) {
            $revert = [
                'kind'         => 'delete_scene',
                'scene_id'     => (int) $result['affected_scene_id'],
                'scene_states' => $revert['scene_states'] ?? [],
            ];
        }

        if (! empty($validated['message_id'])) {
            $messageStatus = $this->toolRunsAsync($validated['tool']) ? 'running' : 'applied';
            $this->updateMessageStatus(
                $user,
                $project,
                $validated['message_id'],
                $messageStatus,
                (int) ($result['credits_spent'] ?? 0),
                $validated['action_index'] ?? null,
                $result['affected_scene_id'] ?? null,
            );

            $this->actionRuns->startRun(
                $workspace,
                $user,
                $project,
                (string) $validated['message_id'],
                (int) ($validated['action_index'] ?? 0),
                (string) $validated['tool'],
                $validated['params'],
                $this->toolExpectedStages((string) $validated['tool'], $validated['params']),
                (int) ($result['credits_spent'] ?? 0),
                isset($result['affected_scene_id']) ? (int) $result['affected_scene_id'] : null,
                $this->toolRunsAsync((string) $validated['tool']),
                $revert,
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
                // Output tools (export/schedule) hand off to a UI surface —
                // the editor opens export progress or the schedule composer.
                'navigate'          => $result['navigate'] ?? null,
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
            'project_id'   => ['required', 'integer'],
            'message_id'   => ['required', 'string', 'max:64'],
            'action_index' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        $project = Project::query()
            ->whereKey($validated['project_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $this->updateMessageStatus($user, $project, $validated['message_id'], 'skipped', 0, $validated['action_index'] ?? null);
        $this->actionRuns->markSkipped(
            (int) $user->workspace_id,
            (int) $project->getKey(),
            (string) $validated['message_id'],
            (int) ($validated['action_index'] ?? 0),
        );

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
     * Update workspace-level Cruise prefs. Each field is independently
     * optional — frontend PATCHes only what changed (no merge bugs from
     * stale values clobbering current ones).
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
            'auto_apply'      => ['nullable', 'boolean'],
            'image_model'     => ['nullable', 'string', 'in:gpt-image-1,gpt-image-2,nano-banana,flux-schnell,sdxl-lightning'],
            'animation_tier'  => ['nullable', 'string', 'in:quick,seedance_lite,balanced,seedance_pro,premium'],
            // 'auto' explicitly clears the bias; a value locks the default.
            'visual_source'   => ['nullable', 'string', 'in:auto,ai_image,stock_video,stock_image,audiogram'],
        ]);

        $updates = [];
        if (array_key_exists('auto_apply', $validated))     $updates['cruise_auto_apply']     = (bool) $validated['auto_apply'];
        if (array_key_exists('image_model', $validated))    $updates['cruise_image_model']    = $validated['image_model'];
        if (array_key_exists('animation_tier', $validated)) $updates['cruise_animation_tier'] = $validated['animation_tier'];
        if (array_key_exists('visual_source', $validated)) {
            $updates['cruise_visual_source'] = $validated['visual_source'] === 'auto' ? null : $validated['visual_source'];
        }
        if (! empty($updates)) $workspace->forceFill($updates)->save();

        return response()->json([
            'data' => [
                'auto_apply'     => (bool) $workspace->cruise_auto_apply,
                'image_model'    => $workspace->cruise_image_model,
                'animation_tier' => $workspace->cruise_animation_tier,
                'visual_source'  => $workspace->cruise_visual_source ?? 'auto',
            ],
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
        $messages = $this->actionRuns->mergeRunsIntoMessages(
            $conv?->messages ?? [],
            (int) $user->workspace_id,
            (int) $projectId,
        );

        // Lazily synthesise a brief the first time the assistant opens on a
        // project that never got one (non-one-shot projects). Seeded one-shot
        // projects already have one; we don't re-spend on them here.
        if (! is_array($project->assistant_brief_json) || empty($project->assistant_brief_json)) {
            $brief = $this->briefs->synthesize($project);
            if (! empty($brief)) {
                $project->forceFill(['assistant_brief_json' => $brief])->save();
            }
        }

        return response()->json([
            'data' => [
                'messages'         => $messages,
                'message_count'    => $conv?->message_count ?? 0,
                'last_activity_at' => $conv?->last_activity_at?->toIso8601String(),
                'brief'            => $project->assistant_brief_json,
                'brief_locked'     => (bool) $project->assistant_brief_locked,
            ],
            'meta' => [],
        ]);
    }

    /**
     * Update the brief from the editor. Any field the user touches locks the
     * brief so auto-refresh won't overwrite their wording.
     */
    public function updateBrief(Request $request, int $projectId): JsonResponse
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
            'theme'             => ['nullable', 'string', 'max:200'],
            'topic'             => ['nullable', 'string', 'max:200'],
            'visual_style'      => ['nullable', 'string', 'max:200'],
            'tone'              => ['nullable', 'string', 'max:200'],
            'recurring_subject' => ['nullable', 'string', 'max:200'],
            'locked'            => ['nullable', 'boolean'],
        ]);

        $brief = is_array($project->assistant_brief_json) ? $project->assistant_brief_json : [];
        foreach (\App\Services\CruiseControl\ProjectBriefService::FIELDS as $f) {
            if (array_key_exists($f, $validated)) {
                $brief[$f] = $validated[$f] !== null ? trim((string) $validated[$f]) : null;
            }
        }
        $brief['source'] = 'user';

        $project->forceFill([
            'assistant_brief_json'   => $brief,
            // Editing locks by default; an explicit locked=false unlocks.
            'assistant_brief_locked' => $validated['locked'] ?? true,
        ])->save();

        return response()->json([
            'data' => ['brief' => $project->assistant_brief_json, 'brief_locked' => (bool) $project->assistant_brief_locked],
            'meta' => [],
        ]);
    }

    /**
     * Re-synthesise the brief from the current scenes. Manual + explicit, so
     * it runs even if locked (the user asked for it); leaves the lock as-is.
     */
    public function refreshBrief(Request $request, int $projectId): JsonResponse
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

        $brief = $this->briefs->synthesize($project);
        $project->forceFill(['assistant_brief_json' => $brief])->save();

        return response()->json([
            'data' => ['brief' => $project->assistant_brief_json, 'brief_locked' => (bool) $project->assistant_brief_locked],
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
     * Flip a message's per-action status in place. Used by apply() and
     * skip() so the chat-history view shows ✓ / ⏭ on refresh.
     *
     * When $actionIndex is provided we stamp ONLY that entry in actions[]
     * (multi-action turns). When it's null we stamp the legacy singular
     * action_status on the message itself (old single-action messages).
     */
    private function updateMessageStatus(User $user, Project $project, string $messageId, string $status, int $creditsSpent = 0, ?int $actionIndex = null, ?int $affectedSceneId = null): void
    {
        DB::transaction(function () use ($user, $project, $messageId, $status, $creditsSpent, $actionIndex, $affectedSceneId) {
            $conv = CruiseConversation::query()
                ->where('workspace_id', $user->workspace_id)
                ->where('project_id', $project->getKey())
                ->lockForUpdate()
                ->first();
            if (! $conv || ! is_array($conv->messages)) return;

            $messages = $conv->messages;
            foreach ($messages as $i => $m) {
                if (($m['id'] ?? null) !== $messageId) continue;

                if ($actionIndex !== null && is_array($m['actions'] ?? null) && isset($m['actions'][$actionIndex])) {
                    $messages[$i]['actions'][$actionIndex]['status'] = $status;
                    if ($creditsSpent > 0) $messages[$i]['actions'][$actionIndex]['credits'] = $creditsSpent;
                    // Persist affected_scene_id so the frontend can
                    // re-verify on refresh whether the work is still
                    // in flight and resume polling if so.
                    if ($affectedSceneId) $messages[$i]['actions'][$actionIndex]['affected_scene_id'] = $affectedSceneId;

                    $messages[$i]['action_status'] = $this->aggregateActionStatuses($messages[$i]['actions']);
                } else {
                    $messages[$i]['action_status'] = $status;
                    if ($creditsSpent > 0) $messages[$i]['action_credits'] = $creditsSpent;
                    if ($affectedSceneId) $messages[$i]['affected_scene_id'] = $affectedSceneId;
                }
                break;
            }

            $conv->forceFill(['messages' => $messages, 'last_activity_at' => now()])->save();
        });
    }

    /**
     * Keep the persisted message-level state aligned with the frontend's
     * card aggregator so refresh / second-client hydrate stays truthful.
     *
     * @param array<int, array<string, mixed>> $actions
     */
    private function aggregateActionStatuses(array $actions): ?string
    {
        $statuses = array_values(array_filter(array_map(
            static fn (array $action): ?string => isset($action['status']) ? (string) $action['status'] : null,
            $actions,
        )));

        if ($statuses === []) {
            return null;
        }

        if (in_array('running', $statuses, true)) {
            return 'running';
        }

        if (in_array('failed', $statuses, true)) {
            return 'failed';
        }

        if (in_array('proposed', $statuses, true)) {
            return 'proposed';
        }

        return 'applied';
    }

    private function toolRunsAsync(string $tool): bool
    {
        return in_array($tool, [
            'regenerate_image',
            'animate_scene',
            'make_spokesperson',
            'rerecord_voice',
            'update_scene_script',
            'change_music',
            'add_scene',
        ], true);
    }

    /**
     * @return string[]
     */
    private function toolExpectedStages(string $tool, array $params): array
    {
        return match ($tool) {
            'regenerate_image' => ! empty($params['chain_animate_tier']) ? ['ai_image', 'animation'] : ['ai_image'],
            'animate_scene' => ['animation'],
            'make_spokesperson' => ['animation'],
            'rerecord_voice', 'update_scene_script' => ['tts'],
            'change_music' => ['ai_music'],
            'add_scene' => ! empty($params['animate_tier']) ? ['ai_image', 'tts', 'animation'] : ['ai_image', 'tts'],
            default => [],
        };
    }

    /**
     * Wipe the conversation thread + its action runs so the user can start
     * the chat over. Does NOT touch project content — applied changes stand
     * (use undo for those).
     */
    public function resetConversation(Request $request, int $projectId): JsonResponse
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

        DB::transaction(function () use ($user, $projectId): void {
            // Use the model (not a raw query update) so the `messages` array
            // cast encodes to JSON — query-builder update() writes the raw
            // PHP array and throws on the json column (the 500 on reset).
            $conv = CruiseConversation::query()
                ->where('workspace_id', $user->workspace_id)
                ->where('project_id', $projectId)
                ->first();
            if ($conv) {
                $conv->forceFill(['messages' => [], 'message_count' => 0, 'last_activity_at' => now()])->save();
            }
            CruiseActionRun::query()
                ->where('workspace_id', $user->workspace_id)
                ->where('project_id', $projectId)
                ->delete();
        });

        return response()->json(['data' => ['success' => true, 'messages' => []], 'meta' => []]);
    }

    /**
     * Undo a previously-applied action by restoring the snapshot captured at
     * apply time. Credits are NOT refunded — the compute was already spent;
     * undo restores state, like an editor undo. Gated to completed actions.
     */
    public function undo(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $validated = $request->validate([
            'project_id'   => ['required', 'integer'],
            'message_id'   => ['required', 'string', 'max:64'],
            'action_index' => ['nullable', 'integer', 'min:0', 'max:10'],
        ]);

        $project = Project::query()
            ->whereKey($validated['project_id'])
            ->where('workspace_id', $user->workspace_id)
            ->first();
        if (! $project) {
            return $this->error('not_found', 'Project not found.', 404);
        }

        $actionIndex = (int) ($validated['action_index'] ?? 0);
        $run = CruiseActionRun::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('project_id', $project->getKey())
            ->where('message_id', $validated['message_id'])
            ->where('action_index', $actionIndex)
            ->first();

        if (! $run) {
            return $this->error('not_found', 'Nothing to undo for that action.', 404);
        }
        if ($run->status === 'running') {
            return $this->error('not_undoable', 'This action is still finishing — wait for it to complete, then undo.', 422);
        }
        if ($run->status === 'undone') {
            return $this->error('already_undone', 'That action was already undone.', 422);
        }
        if (! is_array($run->revert_json) || empty($run->revert_json)) {
            return $this->error('not_undoable', "This action can't be undone.", 422);
        }

        try {
            $affectedSceneId = DB::transaction(fn () => $this->applyRevert($run->revert_json, $project));
        } catch (\Throwable $e) {
            return $this->error('undo_failed', $e->getMessage(), 422);
        }

        $this->actionRuns->markUndone((int) $user->workspace_id, (int) $project->getKey(), (string) $validated['message_id'], $actionIndex);
        $this->updateMessageStatus($user, $project, (string) $validated['message_id'], 'undone', 0, $actionIndex);

        CruiseAuditLog::create([
            'workspace_id'  => $user->workspace_id,
            'user_id'       => $user->getKey(),
            'project_id'    => $project->getKey(),
            'phase'         => 'apply',
            'resolved_tool' => $run->tool,
            'applied'       => false,
            'outcome'       => 'undone',
        ]);

        return response()->json([
            'data' => ['success' => true, 'affected_scene_id' => $affectedSceneId],
            'meta' => [],
        ]);
    }

    /**
     * Restorable scene columns captured for undo. Covers every scene-scoped
     * tool (visual swaps, regen, animate, voice, script, captions, sounds).
     */
    private const REVERTIBLE_SCENE_FIELDS = [
        'visual_asset_id', 'visual_type', 'visual_prompt', 'visual_style',
        'script_text', 'voice_settings_json', 'caption_settings_json',
        'sound_asset_id', 'character_id', 'image_generation_settings_json',
        'motion_settings_json', 'status',
    ];

    /**
     * Capture the prior state an action will overwrite, BEFORE it runs.
     * Returns null for tools we don't support undoing (e.g. apply_brand_kit).
     *
     * @return array<string, mixed>|null
     */
    private function buildRevertSnapshot(string $tool, Project $project, array $params): ?array
    {
        // Project-level music.
        if (in_array($tool, ['change_music', 'pick_library_music'], true)) {
            return [
                'kind'   => 'project_fields',
                'fields' => [
                    'music_asset_id'      => $project->music_asset_id,
                    'music_settings_json' => $project->music_settings_json,
                ],
            ];
        }

        // Order-shuffling tools: snapshot every scene's order + label.
        if (in_array($tool, ['reorder_scene', 'add_scene'], true)) {
            $states = Scene::query()
                ->where('project_id', $project->getKey())
                ->get(['id', 'scene_order', 'label'])
                ->map(fn ($s) => ['id' => (int) $s->id, 'scene_order' => (int) $s->scene_order, 'label' => $s->label])
                ->all();
            // reorder restores orders directly; add_scene's kind is finalised
            // post-execute once we know the created scene id.
            return ['kind' => $tool === 'reorder_scene' ? 'scene_orders' : 'pending_add', 'scene_states' => $states];
        }

        // Scene-field tools: snapshot the scene's restorable columns.
        $sceneId = $params['scene_id'] ?? null;
        if ($sceneId) {
            $scene = Scene::query()->where('project_id', $project->getKey())->whereKey($sceneId)->first();
            if ($scene) {
                $fields = [];
                foreach (self::REVERTIBLE_SCENE_FIELDS as $f) {
                    $fields[$f] = $scene->{$f};
                }
                return ['kind' => 'scene_fields', 'scene_id' => (int) $scene->getKey(), 'fields' => $fields];
            }
        }

        return null;
    }

    /**
     * Restore a revert snapshot. Returns the affected scene id (or null for
     * project-level reverts) so the frontend knows what to refresh.
     */
    private function applyRevert(array $revert, Project $project): ?int
    {
        $kind = $revert['kind'] ?? null;

        if ($kind === 'project_fields') {
            $project->forceFill($revert['fields'] ?? [])->save();
            return null;
        }

        if ($kind === 'scene_orders' || $kind === 'delete_scene') {
            if ($kind === 'delete_scene') {
                Scene::query()
                    ->where('project_id', $project->getKey())
                    ->whereKey((int) ($revert['scene_id'] ?? 0))
                    ->delete();
            }
            foreach ($revert['scene_states'] ?? [] as $st) {
                Scene::query()
                    ->where('project_id', $project->getKey())
                    ->whereKey($st['id'] ?? 0)
                    ->update(['scene_order' => $st['scene_order'] ?? 1, 'label' => $st['label'] ?? null]);
            }
            return $kind === 'delete_scene' ? (int) ($revert['scene_id'] ?? 0) : null;
        }

        if ($kind === 'scene_fields') {
            $sceneId = (int) ($revert['scene_id'] ?? 0);
            $scene = Scene::query()->where('project_id', $project->getKey())->whereKey($sceneId)->first();
            if (! $scene) {
                throw new \RuntimeException('The scene no longer exists.');
            }
            $scene->forceFill($revert['fields'] ?? [])->save();
            return $sceneId;
        }

        throw new \RuntimeException("This action can't be undone.");
    }

}
