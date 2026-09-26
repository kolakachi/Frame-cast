<?php
namespace App\Services\Developer;

use Closure;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;

/**
 * Bus pipe + queue hooks that tie a queued job to its API operation.
 *
 * Job record states: pending (queued) → running (an attempt is executing) →
 * completed | failed. 'released' means the job may be delivered again safely:
 * it released itself, or its attempt threw before any charge was recorded.
 * 'failed' means an attempt threw after a charge, so the operation is fenced
 * (needs_attention) and a redelivery is failed through Laravel rather than
 * run again, which lets the job's own failed() mark the product failed
 * instead of leaving it stranded at "generating".
 */
class AccountedJob
{
    private static ?\WeakMap $discarded = null;

    /**
     * The queued job this process is executing under accounting. Bus pipes
     * run for every dispatchNow in the process, including synchronous work
     * the job itself triggers (ShouldBroadcastNow events, dispatchSync), and
     * those must pass through rather than be treated as a second attempt.
     */
    private static ?string $active = null;

    public static function discarded($job): bool
    {
        return isset(self::$discarded[$job]);
    }

    /** Decide redeliveries before Laravel invokes handlers, chains or failed(). */
    public static function before($job): void
    {
        $id = $job->payload()['wyv_api_operation'] ?? null;
        if (! $id || ! OperationAccounting::enabled() || $job instanceof \Illuminate\Queue\Jobs\SyncJob) return;
        $op = DB::table('api_operations')->where('id', $id)->first();
        $record = DB::table('api_operation_jobs')->where('id', $job->uuid())->first();
        if ($op?->status === 'running' && $record && in_array($record->status, ['pending', 'released'], true)) return;
        self::$discarded ??= new \WeakMap;
        self::$discarded[$job] = true;
        if ($op?->status === 'running' && $record?->status !== 'completed') {
            // A second delivery while the first attempt may still be running
            // (retry_after elapsed, or the worker died mid-job). Fence the
            // operation for review and drop this copy; the first attempt, if
            // alive, still closes its own record.
            DB::table('api_operations')->where('id', $id)->where('status', 'running')
                ->update(['status' => 'needs_attention', 'updated_at' => now()]);
            $job->delete();
            return;
        }
        if ($record && $record->status !== 'completed' && $record->status !== 'cancelled') {
            // The operation is already fenced or settled: surface this as the
            // job's failure so the product is marked failed, not left waiting.
            self::$active = 'fencing';
            try {
                $job->fail(new \RuntimeException('API operation '.$id.' is '.($op?->status ?? 'missing').'; this job is not retried.'));
            } finally {
                self::$active = null;
            }
            return;
        }
        $job->delete();
    }

    public function handle($command, Closure $next): mixed
    {
        $id = OperationAccounting::current();
        $uuid = Context::getHidden('wyv_api_job');
        if (! $id || ! $uuid || self::$active !== null) return $next($command);
        if (! OperationFence::acquire($id)) throw new \RuntimeException('Operation is being reconciled.');
        self::$active = $uuid;
        $spentBefore = null;
        $start = false;
        try {
            $start = DB::transaction(function () use ($id, $uuid, &$spentBefore) {
                $op = DB::table('api_operations')->where('id', $id)->lockForUpdate()->first();
                if (! $op || $op->status !== 'running') return false;
                $job = DB::table('api_operation_jobs')->where('id', $uuid)->where('operation_id', $id)->first();
                if (! $job || ! in_array($job->status, ['pending', 'released'], true)) {
                    DB::table('api_operations')->where('id', $id)->update(['status' => 'needs_attention', 'updated_at' => now()]);
                    return false;
                }
                $spentBefore = (int) $op->spent_credits;
                DB::table('api_operation_jobs')->where('id', $uuid)->update(['status' => 'running', 'updated_at' => now()]);
                return true;
            });
            if (! $start) throw new \RuntimeException('Operation cannot safely repeat this job. Inspect its status before retrying.');
            return $next($command);
        } catch (\Throwable $e) {
            if ($start) {
                $op = DB::table('api_operations')->where('id', $id)->first();
                if ($op && (int) $op->spent_credits === $spentBefore) {
                    // Nothing was charged in this attempt: an ordinary retry is
                    // as safe here as it is for the dashboard's own jobs.
                    DB::table('api_operation_jobs')->where('id', $uuid)->where('status', 'running')
                        ->update(['status' => 'released', 'updated_at' => now()]);
                } else {
                    // A charge was recorded and then the job threw: repeating it
                    // could pay twice. Fence the operation for review.
                    DB::table('api_operation_jobs')->where('id', $uuid)->where('status', 'running')
                        ->update(['status' => 'failed', 'updated_at' => now()]);
                    DB::table('api_operations')->where('id', $id)->where('status', 'running')
                        ->update(['status' => 'needs_attention', 'updated_at' => now()]);
                }
            }
            throw $e;
        } finally {
            self::$active = null;
            OperationFence::release($id);
        }
    }
}
