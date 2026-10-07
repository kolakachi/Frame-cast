<?php

namespace App\Console\Commands;

use App\Services\Create\CreateHealth;
use Illuminate\Console\Command;

class CreateHealthCheck extends Command
{
    protected $signature = 'create:health';
    protected $description = 'Check Create planning, builds, workers, holds and disk; email the team about new problems';

    public function handle(CreateHealth $health): int
    {
        $this->line(json_encode(['problems' => array_keys($health->check())]));
        return self::SUCCESS;
    }
}
