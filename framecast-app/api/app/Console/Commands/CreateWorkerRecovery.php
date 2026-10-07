<?php

namespace App\Console\Commands;

use App\Services\Create\WorkerOwnership;
use Illuminate\Console\Command;

class CreateWorkerRecovery extends Command
{
    protected $signature = 'create:worker-recovery {run} {--confirm-stopped= : Exact assignment id from inspection} {--worker-stopped : Operator verified original process and sandboxes stopped} {--evidence= : Incident/host-check evidence reference, no credentials} {--close-settled : Close an already stopped run only if all provider attempts have settlement receipts}';
    protected $description = 'Inspect a worker assignment; optionally record operator-verified termination without changing billing';

    public function handle(WorkerOwnership $ownership): int
    {
        if ($this->option('close-settled')) {
            if ($this->option('confirm-stopped') || $this->option('evidence') || $this->option('worker-stopped')) {
                $this->error('Record and inspect stop evidence first; use --close-settled as a separate action.');
                return self::FAILURE;
            }
            abort_unless($ownership->find($this->argument('run')), 409, 'This command requires a recorded worker assignment. Use the legacy recovery procedure for older runs.');
            $this->line(json_encode(app(\App\Services\Create\ReconciliationService::class)->closeSettled($this->argument('run'), true)));
            return self::SUCCESS;
        }
        if ($assignment = $this->option('confirm-stopped')) {
            if (! $this->option('worker-stopped')) {
                $this->error('Verify the original coordinator and containers stopped, then pass --worker-stopped and --evidence.');
                return self::FAILURE;
            }
            $this->line(json_encode($ownership->confirmStopped($this->argument('run'), $assignment, (string) $this->option('evidence'))));
        } elseif ($this->option('worker-stopped') || $this->option('evidence')) {
            $this->error('Inspection is read-only. To record evidence, supply the exact --confirm-stopped assignment id.');
            return self::FAILURE;
        }
        $this->line(json_encode($ownership->inspect($this->argument('run')), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        return self::SUCCESS;
    }
}
