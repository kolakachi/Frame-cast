<?php

namespace App\Jobs;

use App\Models\ApiUsageEvent;
use App\Models\WorkspaceNotification;
use App\Services\Notification\NotificationService;
use App\Services\WorkspaceUsageService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class CheckBudgetAlertJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public function __construct(public readonly int $workspaceId)
    {
        $this->onQueue('default');
    }

    public function handle(NotificationService $notifications): void
    {
        $plans = WorkspaceUsageService::plans();

        $workspace = \App\Models\Workspace::query()->find($this->workspaceId);

        if (! $workspace) {
            return;
        }

        $planTier = (string) ($workspace->plan_tier ?: 'studio');
        $plan = $plans[$planTier] ?? $plans['studio'];
        $budget = (float) $plan['api_budget_usd'];

        if ($budget <= 0) {
            return;
        }

        $monthKey = now()->format('Y-m');

        $spent = (float) ApiUsageEvent::query()
            ->where('workspace_id', $this->workspaceId)
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd');

        $pct = $spent / $budget;

        if ($pct < 0.80) {
            return;
        }

        // Determine which threshold was crossed.
        $threshold = $pct >= 1.0 ? 100 : 80;
        $alertKey = "budget_{$threshold}pct_{$monthKey}";

        $alreadySent = WorkspaceNotification::query()
            ->where('workspace_id', $this->workspaceId)
            ->whereJsonContains('payload_json->alert_key', $alertKey)
            ->exists();

        if ($alreadySent) {
            return;
        }

        $planName = (string) $plan['name'];
        $spentFormatted = number_format($spent, 2);
        $budgetFormatted = number_format($budget, 2);

        if ($threshold === 100) {
            $notifications->create(
                $this->workspaceId,
                'AI budget exhausted',
                "This workspace has spent \${$spentFormatted} of its \${$budgetFormatted} monthly AI budget on the {$planName} plan. New AI generation is blocked until next month or the plan is upgraded.",
                'error',
                null,
                ['alert_key' => $alertKey, 'spent_usd' => round($spent, 4), 'budget_usd' => $budget, 'plan' => $planTier],
            );
        } else {
            $notifications->create(
                $this->workspaceId,
                'AI budget at 80%',
                "This workspace has spent \${$spentFormatted} of its \${$budgetFormatted} monthly AI budget on the {$planName} plan.",
                'warning',
                null,
                ['alert_key' => $alertKey, 'spent_usd' => round($spent, 4), 'budget_usd' => $budget, 'plan' => $planTier],
            );
        }
    }
}
