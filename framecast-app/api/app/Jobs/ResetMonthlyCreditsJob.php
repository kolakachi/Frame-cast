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
        $due = Workspace::query()
            ->whereNotNull('billing_renews_at')
            ->where('billing_renews_at', '<=', now())
            ->where('plan_status', 'active')
            ->where('plan_tier', '!=', 'free')
            ->get();

        $resetCount = 0;
        foreach ($due as $workspace) {
            $credits->resetMonthly($workspace);
            $resetCount++;
        }

        if ($resetCount > 0) {
            Log::info('ResetMonthlyCreditsJob: reset workspaces', ['count' => $resetCount]);
        }
    }
}
