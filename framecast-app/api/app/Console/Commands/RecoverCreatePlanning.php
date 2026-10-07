<?php

namespace App\Console\Commands;

use App\Services\Create\PlanningJobService;
use Illuminate\Console\Command;

class RecoverCreatePlanning extends Command
{
    protected $signature = 'create:recover-planning';
    protected $description = 'Publish queued plans and flag interrupted planning without repeating provider calls';

    public function handle(PlanningJobService $plans): int
    {
        $plans->recover();
        $this->info('Planning request journal checked. Uncertain work was not replayed.');
        return self::SUCCESS;
    }
}
