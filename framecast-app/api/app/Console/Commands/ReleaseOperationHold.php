<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Releases the credits an API operation still holds after it was fenced (needs_attention) and every one of its jobs
 * has stopped. What it already charged stays charged; only the unused hold goes back to the workspace. Weave runs
 * have their own recovery (create:worker-recovery), so their operations are refused here.
 */
class ReleaseOperationHold extends Command
{
    protected $signature = 'api:release-hold {operation} {--evidence= : What was checked, e.g. "scene 3 refused by Gemini E005; scenes 1-2 delivered"} {--admin= : Email of the admin releasing it} {--confirm : Release it; without this the command only reports}';

    protected $description = 'Release the unused credits a fenced API operation holds once its jobs have stopped';

    public function handle(): int
    {
        $id = (string) $this->argument('operation');
        $op = DB::table('api_operations')->where('id', $id)->first();
        if (! $op) { $this->error('No such operation.'); return self::FAILURE; }
        $jobs = DB::table('api_operation_jobs')->where('operation_id', $id)->pluck('status')->countBy()->all();
        $this->line(json_encode(['status' => $op->status, 'spent' => (int) $op->spent_credits, 'reserved' => (int) $op->reserved_credits, 'jobs' => $jobs]));

        if ($op->status !== 'needs_attention') { $this->error('Only a needs_attention operation can be released; this one is '.$op->status.'.'); return self::FAILURE; }
        if (\Illuminate\Support\Facades\Schema::hasTable('composition_runs') && DB::table('composition_runs')->where('operation_id', $id)->exists()) { $this->error('This is a Weave run\'s operation: use create:worker-recovery.'); return self::FAILURE; }
        if (array_intersect(array_keys($jobs), ['pending', 'running', 'released'])) { $this->error('A job of this operation may still run; wait for it to stop.'); return self::FAILURE; }
        $evidence = trim((string) $this->option('evidence'));
        if (mb_strlen($evidence) < 10) { $this->error('Say what was checked with --evidence.'); return self::FAILURE; }
        $admin = \App\Models\User::query()->where('email', (string) $this->option('admin'))->first();
        if (! $admin) { $this->error('Name the admin releasing it with --admin=<email>.'); return self::FAILURE; }
        if (! $this->option('confirm')) { $this->info('Would release '.(int) $op->reserved_credits.' held credits. Run again with --confirm.'); return self::SUCCESS; }

        DB::transaction(function () use ($id, $op, $evidence, $admin) {
            $n = DB::table('api_operations')->where('id', $id)->where('status', 'needs_attention')->update(['status' => 'failed', 'reserved_credits' => 0, 'updated_at' => now()]);
            if ($n !== 1) throw new \RuntimeException('The operation changed while releasing it; nothing was released.');
            \App\Models\AdminAuditLog::query()->create(['admin_user_id' => $admin->getKey(), 'action' => 'api_operation.release_hold', 'target_type' => 'api_operation', 'target_id' => null,
                // target_id is numeric; an operation id is text, so it is kept in the payload.
                'payload_json' => ['operation_id' => $id, 'released_credits' => (int) $op->reserved_credits, 'spent_credits' => (int) $op->spent_credits, 'evidence' => $evidence]]);
        });
        $this->info('Released '.(int) $op->reserved_credits.' credits; '.(int) $op->spent_credits.' stay charged.');
        return self::SUCCESS;
    }
}
