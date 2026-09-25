<?php
namespace App\Services\Developer;

use Illuminate\Support\Facades\DB;

/** Session locks die with a worker; cancellation takes the exclusive fence. */
class OperationFence
{
    public static function acquire(string $id, bool $exclusive = false): bool
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return true;
        $fn = $exclusive ? 'pg_try_advisory_lock' : 'pg_try_advisory_lock_shared';
        return (bool) DB::selectOne("select {$fn}(hashtextextended(?, 7834)) as acquired", [$id])->acquired;
    }

    public static function release(string $id, bool $exclusive = false): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') return;
        $fn = $exclusive ? 'pg_advisory_unlock' : 'pg_advisory_unlock_shared';
        DB::select("select {$fn}(hashtextextended(?, 7834))", [$id]);
    }
}
