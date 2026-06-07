<?php

namespace App\Services\CruiseControl;

use App\Models\CruiseActionRun;
use App\Models\Project;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CruiseActionRunService
{
    public function startRun(
        Workspace $workspace,
        User $user,
        Project $project,
        string $messageId,
        int $actionIndex,
        string $tool,
        array $params,
        array $expectedStages,
        int $estimatedCredits,
        ?int $affectedSceneId,
        bool $runsAsync,
    ): CruiseActionRun {
        return DB::transaction(function () use (
            $workspace,
            $user,
            $project,
            $messageId,
            $actionIndex,
            $tool,
            $params,
            $expectedStages,
            $estimatedCredits,
            $affectedSceneId,
            $runsAsync,
        ) {
            $run = CruiseActionRun::query()->firstOrNew([
                'workspace_id' => (int) $workspace->getKey(),
                'project_id' => (int) $project->getKey(),
                'message_id' => $messageId,
                'action_index' => $actionIndex,
            ]);

            $run->forceFill([
                'user_id' => (int) $user->getKey(),
                'tool' => $tool,
                'params_json' => $params,
                'expected_stages' => array_values($expectedStages),
                'completed_stages' => $runsAsync ? [] : array_values($expectedStages),
                'status' => $runsAsync ? 'running' : 'completed',
                'estimated_credits' => $estimatedCredits,
                'actual_credits' => $runsAsync ? 0 : $estimatedCredits,
                'affected_scene_id' => $affectedSceneId,
                'error_message' => null,
            ])->save();

            return $run->fresh();
        });
    }

    public function markSkipped(int $workspaceId, int $projectId, string $messageId, int $actionIndex): void
    {
        CruiseActionRun::query()
            ->where('workspace_id', $workspaceId)
            ->where('project_id', $projectId)
            ->where('message_id', $messageId)
            ->where('action_index', $actionIndex)
            ->update([
                'status' => 'skipped',
                'error_message' => null,
                'updated_at' => now(),
            ]);
    }

    public function markStageCompleted(int $projectId, string $stage, ?int $sceneId = null): void
    {
        $run = $this->findRunForStage($projectId, $stage, $sceneId);
        if (! $run) {
            return;
        }

        $completed = is_array($run->completed_stages) ? $run->completed_stages : [];
        if (! in_array($stage, $completed, true)) {
            $completed[] = $stage;
        }
        $expected = is_array($run->expected_stages) ? $run->expected_stages : [];
        $allDone = $expected === [] || collect($expected)->every(fn ($s): bool => in_array($s, $completed, true));

        $run->forceFill([
            'completed_stages' => array_values(array_unique($completed)),
            'status' => $allDone ? 'completed' : 'running',
            'actual_credits' => $allDone ? (int) $run->estimated_credits : (int) $run->actual_credits,
            'error_message' => null,
        ])->save();
    }

    public function markStageFailed(int $projectId, string $stage, string $errorMessage, ?int $sceneId = null): void
    {
        $run = $this->findRunForStage($projectId, $stage, $sceneId);
        if (! $run) {
            return;
        }

        $run->forceFill([
            'status' => 'failed',
            'error_message' => mb_substr($errorMessage, 0, 1000),
        ])->save();
    }

    /**
     * @param array<int, array<string, mixed>> $messages
     * @return array<int, array<string, mixed>>
     */
    public function mergeRunsIntoMessages(array $messages, int $workspaceId, int $projectId): array
    {
        $runs = CruiseActionRun::query()
            ->where('workspace_id', $workspaceId)
            ->where('project_id', $projectId)
            ->get()
            ->keyBy(fn (CruiseActionRun $run): string => $this->runKey($run->message_id, (int) $run->action_index));

        foreach ($messages as $i => $message) {
            if (! is_array($message['actions'] ?? null)) {
                continue;
            }

            foreach ($message['actions'] as $actionIndex => $action) {
                $run = $runs->get($this->runKey((string) ($message['id'] ?? ''), (int) $actionIndex));
                if (! $run) {
                    continue;
                }

                $messages[$i]['actions'][$actionIndex]['status'] = $this->conversationStatusForRun($run->status);
                $messages[$i]['actions'][$actionIndex]['credits'] = $run->actual_credits > 0
                    ? (int) $run->actual_credits
                    : (int) $run->estimated_credits;
                $messages[$i]['actions'][$actionIndex]['affected_scene_id'] = $run->affected_scene_id;
                $messages[$i]['actions'][$actionIndex]['expected_stages'] = $run->expected_stages ?? [];
                $messages[$i]['actions'][$actionIndex]['completed_stages'] = $run->completed_stages ?? [];
                $messages[$i]['actions'][$actionIndex]['error'] = $run->error_message;
            }

            $messages[$i]['action_status'] = $this->aggregateConversationStatuses($messages[$i]['actions']);
        }

        return $messages;
    }

    private function findRunForStage(int $projectId, string $stage, ?int $sceneId): ?CruiseActionRun
    {
        return CruiseActionRun::query()
            ->where('project_id', $projectId)
            ->where('status', 'running')
            ->when($sceneId, fn ($query) => $query->where('affected_scene_id', $sceneId))
            ->orderByDesc('id')
            ->get()
            ->first(function (CruiseActionRun $run) use ($stage): bool {
                $expected = is_array($run->expected_stages) ? $run->expected_stages : [];
                $completed = is_array($run->completed_stages) ? $run->completed_stages : [];

                return in_array($stage, $expected, true) && ! in_array($stage, $completed, true);
            });
    }

    private function runKey(string $messageId, int $actionIndex): string
    {
        return $messageId . ':' . $actionIndex;
    }

    private function conversationStatusForRun(string $runStatus): string
    {
        return match ($runStatus) {
            'completed' => 'applied',
            'failed' => 'failed',
            'skipped' => 'skipped',
            default => 'running',
        };
    }

    /**
     * @param array<int, array<string, mixed>> $actions
     */
    private function aggregateConversationStatuses(array $actions): ?string
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
}
