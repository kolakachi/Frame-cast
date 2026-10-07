<?php
namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class QuarantineCreateRun extends Command
{
    protected $signature = 'create:quarantine-run {run} {--worker-stopped : Operator verified the original host and named sandbox have stopped} {--release-unstarted : Release only unstarted work; retain full uncertain-call credit ceilings}';
    protected $description = 'Release the local host after a stopped run while preserving every unresolved billing hold';

    public function handle(): int
    {
        abort_unless(config('create.enabled') && $this->option('worker-stopped'), 403);
        if ($this->option('release-unstarted')) {
            $result = app(\App\Services\Create\ReconciliationService::class)->releaseUnstarted($this->argument('run'), true);
            $this->info("Released {$result['released_credits']} unused credits; retained {$result['retained_credits']} for uncertain calls. No balance change or generation.");
            return self::SUCCESS;
        }
        DB::transaction(function () {
            $run = DB::table('composition_runs')->where('id', $this->argument('run'))->lockForUpdate()->firstOrFail();
            app(\App\Services\Create\WorkerOwnership::class)->requireStopped($run);
            abort_unless($run->status === 'needs_attention' && \App\Services\Create\ConversationService::workspaceAllowed((int) $run->workspace_id), 409);
            DB::table('composition_runs')->where('id', $run->id)->update(['worker_stopped_at' => now(), 'lease_hash' => null,
                'lease_expires_at' => null, 'stage' => 'Worker stopped; external work needs reconciliation', 'updated_at' => now()]);
            DB::table('api_operations')->where('id', $run->operation_id)->update([
                'status' => 'needs_attention', 'capacity_slots' => 0, 'updated_at' => now(),
            ]);
        });
        $this->info('Host released. The conversation stays paused and billing holds are unchanged. No generation repeated.');
        return self::SUCCESS;
    }
}
