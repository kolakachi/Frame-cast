<?php

namespace App\Console\Commands;

use App\Services\Create\RunService;
use Illuminate\Console\Command;

class CheckCreateLeases extends Command
{
    protected $signature = 'create:check-leases';
    protected $description = 'Flag expired build leases without retrying work or releasing uncertain holds';

    public function handle(RunService $runs): int
    {
        $this->line(json_encode(['expired' => $runs->expireLeases(), 'replayed' => 0, 'holds_released' => 0]));
        return self::SUCCESS;
    }
}
