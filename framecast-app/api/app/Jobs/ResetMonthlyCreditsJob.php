<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Services\CreditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ResetMonthlyCreditsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct()
    {
        $this->onQueue('default');
    }

    public function handle(CreditService $credits): void
    {
        // Reconcile subscriptions even after cancellation/failure, so a recovered
        // payment or a missed webhook can restore access. One failure cannot stop others.
        Workspace::query()->whereNotNull('kelviq_subscription_id')->chunkById(100, function ($workspaces) {
            foreach ($workspaces as $workspace) {
                try {
                    ReconcileSubscriptionJob::dispatch((int) $workspace->id);
                } catch (\Throwable $error) {
                    report($error);
                    Log::warning('Subscription reconciliation failed', ['workspace_id' => $workspace->id]);
                }
            }
        });
        Workspace::query()->whereNull('kelviq_subscription_id')
            ->whereIn('plan_tier', config('billing.manual_monthly_tiers', ['enterprise']))
            ->where('plan_status', 'active')->where('billing_renews_at', '<=', now())
            ->chunkById(100, function ($workspaces) use ($credits) {
                foreach ($workspaces as $workspace) {
                    $credits->resetMonthly($workspace);
                }
            });
    }
}
