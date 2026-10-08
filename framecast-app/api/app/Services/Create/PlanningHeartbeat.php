<?php

namespace App\Services\Create;

/**
 * Shows a plan is still being made while PHP is blocked in a model call or ffmpeg (PLAN-DEAD, 2026-10-08: a planning
 * worker killed mid-plan was only noticed at the 22-minute deadline). A forked watcher touches a file every 20 s for
 * as long as the planning process lives; the every-minute recovery treats a file older than 90 s as a dead plan.
 *
 * The watcher never uses the parent's database or Redis connections (closing a copied Postgres connection would end
 * the parent's session) and ends itself with SIGKILL so no shutdown code runs in the copy. A file on the shared
 * private volume is all it writes. Jobs with no file (older jobs, or no pcntl) keep the deadline rule.
 */
class PlanningHeartbeat
{
    public const EVERY = 20;
    public const STALE = 90;

    public static function path(string $jobId): string
    {
        return storage_path('app/private/create/plan-heartbeats/'.preg_replace('/[^a-zA-Z0-9-]/', '', $jobId));
    }

    /** Start watching; returns the watcher's pid, or null when only the first beat could be written. */
    public static function start(string $jobId): ?int
    {
        $file = self::path($jobId);
        @mkdir(dirname($file), 0700, true);
        @touch($file);
        if (app()->runningUnitTests() || ! function_exists('pcntl_fork') || ! function_exists('posix_kill')) return null;
        $parent = getmypid();
        $pid = pcntl_fork();
        if ($pid !== 0) return $pid > 0 ? $pid : null;
        // The watcher: beat while the planning process lives, then end without running any of the copy's shutdown code.
        while (posix_getppid() === $parent && posix_kill($parent, 0) && is_file($file)) {
            @touch($file);
            sleep(self::EVERY);
        }
        posix_kill(getmypid(), SIGKILL);
        exit(0);
    }

    public static function stop(?int $pid, string $jobId): void
    {
        if ($pid && function_exists('posix_kill')) {
            @posix_kill($pid, SIGKILL);
            if (function_exists('pcntl_waitpid')) @pcntl_waitpid($pid, $status);
        }
        @unlink(self::path($jobId));
    }

    /** true: beating; false: stopped beating (the plan's process is gone); null: never watched (use the deadline). */
    public static function alive(string $jobId): ?bool
    {
        $file = self::path($jobId);
        clearstatcache(true, $file);
        if (! is_file($file)) return null;
        return time() - (int) filemtime($file) <= self::STALE;
    }
}
