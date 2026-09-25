<?php
namespace App\Services\Developer;

use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

class AccountedJob
{
    private static ?\WeakMap $discarded = null;

    public static function discarded($job): bool
    {
        return isset(self::$discarded[$job]);
    }

    /** Discard redeliveries before Laravel invokes handlers, chains or failed(). */
    public static function before($job): void
    {
        $id = $job->payload()['wyv_api_operation'] ?? null;
        if (! $id || ! OperationAccounting::enabled() || $job instanceof \Illuminate\Queue\Jobs\SyncJob) return;
        $op = DB::table('api_operations')->where('id', $id)->first();
        $record = DB::table('api_operation_jobs')->where('id', $job->uuid())->first();
        if ($op?->status === 'running' && $record && in_array($record->status, ['pending', 'released'], true)) return;
        if ($op?->status === 'running' && $record?->status !== 'completed') {
            DB::table('api_operations')->where('id', $id)->where('status', 'running')
                ->update(['status' => 'needs_attention', 'updated_at' => now()]);
        }
        self::$discarded ??= new \WeakMap;
        self::$discarded[$job] = true;
        $job->delete();
    }

    public function handle($command, Closure $next): mixed
    {
        $id = OperationAccounting::current();
        $uuid = Context::getHidden('wyv_api_job');
        if (! $id || ! $uuid) return $next($command);
        if (! OperationFence::acquire($id)) throw new \RuntimeException('Operation is being reconciled.');
        try {
            $start = DB::transaction(function () use ($id, $uuid) {
                $op = DB::table('api_operations')->where('id', $id)->lockForUpdate()->first();
                if (! $op || $op->status !== 'running') return false;
                $job = DB::table('api_operation_jobs')->where('id', $uuid)->where('operation_id', $id)->first();
                if (! $job || ! in_array($job->status, ['pending', 'released'], true)) {
                    DB::table('api_operations')->where('id', $id)->update(['status' => 'needs_attention', 'updated_at' => now()]);
                    return false;
                }
                DB::table('api_operation_jobs')->where('id', $uuid)->update(['status' => 'running', 'updated_at' => now()]);
                return true;
            });
            if (! $start) throw new \RuntimeException('Operation cannot safely repeat this job. Inspect its status before retrying.');
            return $next($command);
        } catch (\Throwable $e) {
            // An exception can occur after an external side effect. Fence the
            // operation instead of replaying it or declaring its hold unused.
            DB::table('api_operations')->where('id', $id)->where('status', 'running')
                ->update(['status' => 'needs_attention', 'updated_at' => now()]);
            throw $e;
        } finally {
            OperationFence::release($id);
        }
    }
}
