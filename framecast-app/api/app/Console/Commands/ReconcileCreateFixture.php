<?php

namespace App\Console\Commands;

use App\Services\Developer\OperationAccounting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/** Recovery is explicit: expiry alone is never evidence that external work stopped. */
class ReconcileCreateFixture extends Command
{
    protected $signature = 'create:reconcile-fixture {run} {--worker-stopped : Operator verified the named Docker render container has stopped}';
    protected $description = 'Close an interrupted local fixture run after verifying its worker has stopped';

    public function handle(): int
    {
        if (! app()->environment(['local', 'testing']) || ! config('create.enabled') || ! $this->option('worker-stopped')) {
            $this->error('Local fixture only. Verify the render container stopped, then pass --worker-stopped.');
            return self::FAILURE;
        }
        $ok = DB::transaction(function () {
            $run = DB::table('composition_runs')->where('id', $this->argument('run'))->lockForUpdate()->first();
            if (! $run || $run->status !== 'needs_attention' || (json_decode($run->input_json, true)['mode'] ?? '') !== 'fixture') return false;
            if (! OperationAccounting::cancel($run->operation_id, $run->workspace_id)) return false;
            DB::table('composition_runs')->where('id', $run->id)->update([
                'status' => 'cancelled', 'stage' => 'Local fixture reconciled', 'error' => 'Operator confirmed the worker stopped. A new run can be approved.',
                'lease_hash' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]);
            return true;
        });
        if (! $ok) { $this->error('Run is not an interrupted fixture, or its operation is still fenced.'); return self::FAILURE; }
        $this->info('Fixture cancelled; capacity released. Previous revisions were preserved.');
        return self::SUCCESS;
    }
}
