<?php

namespace App\Jobs;

use App\Models\Workspace;
use App\Services\Billing\SubscriptionRenewal;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ReconcileSubscriptionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 120;

    public int $uniqueFor = 600;

    public function __construct(public int $workspaceId)
    {
        $this->onQueue('default');
    }

    public function uniqueId(): string
    {
        return (string) $this->workspaceId;
    }

    public function backoff(): array
    {
        return [60, 300];
    }

    public function handle(SubscriptionRenewal $renewal): void
    {
        $workspace = Workspace::find($this->workspaceId);
        if ($workspace?->kelviq_subscription_id) {
            $renewal->reconcile($workspace, $workspace->kelviq_subscription_id);
        }
    }
}
