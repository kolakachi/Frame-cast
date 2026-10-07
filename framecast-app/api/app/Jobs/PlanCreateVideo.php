<?php

namespace App\Jobs;

use App\Services\Create\PlanningJobService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class PlanCreateVideo implements ShouldQueue
{
    use Queueable;

    public int $timeout = PlanningJobService::TIMEOUT;
    public int $tries = 1;
    public bool $failOnTimeout = true;

    public function __construct(public string $planningJobId) {}

    public function handle(PlanningJobService $plans): void { $plans->execute($this->planningJobId); }

    public function failed(?\Throwable $error): void
    {
        app(PlanningJobService::class)->interrupted($this->planningJobId);
    }
}
