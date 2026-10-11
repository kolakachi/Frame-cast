<?php

namespace App\Support;

use App\Models\Scene;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * When a scene's work really started (QUEUE-REAP, 2026-10-12). Requests stamp generation_started_at (or
 * animation_started_at) when they queue the work, and one worker runs it in turn, so a job waiting behind a long
 * one-take looked stuck and was reaped as "timed out" before it had begun. Each job now marks running_since (or
 * animation_running_since) the moment it starts; the reaper times only real runs, and gives work that never started
 * a much longer wait. A fresh request stamps a newer started_at than an earlier run's mark, so an old mark never makes
 * new, still-queued work look running.
 */
final class SceneRun
{
    public const IMAGE = 'image';
    public const ANIMATION = 'animation';

    /**
     * Mark the scene's work as running from now, on the loaded model and in the row. $token: the request this job
     * serves; a job a newer request replaced marks nothing, or the newer, still-queued work would look running.
     */
    public static function started(Scene $scene, string $kind = self::IMAGE, ?string $token = null): void
    {
        [$queued, $running] = self::keys($kind);
        $now = now()->toIso8601String();
        $cfg = $scene->image_generation_settings_json ?? [];
        if ($token !== null && (string) ($cfg['generation_token'] ?? '') !== $token) return;
        // The run's own clock: a request queued long ago starts now.
        $cfg[$running] = $now;
        $cfg[$queued] = $now;
        $scene->image_generation_settings_json = $cfg;
        $scene->saveQuietly();
        DB::table('scenes')->where('id', $scene->id)->update(['image_generation_settings_json' => json_encode($cfg), 'updated_at' => now()]);
    }

    /** A running job's heartbeat (a one-take between segments): both clocks move together. */
    public static function beat(string $kind = self::IMAGE): array
    {
        [$queued, $running] = self::keys($kind);
        $now = now()->toIso8601String();

        return [$queued => $now, $running => $now];
    }

    /** When the current run started, or null while its job is still waiting in the queue. */
    public static function runningSince(array $cfg, string $kind = self::IMAGE): ?CarbonImmutable
    {
        [$queued, $running] = self::keys($kind);
        $r = self::time($cfg[$running] ?? null);
        $q = self::time($cfg[$queued] ?? null);
        if (! $r) return null;
        // A mark older than the latest request belongs to an earlier run.
        return $q && $r->lt($q) ? null : $r;
    }

    private static function keys(string $kind): array
    {
        return $kind === self::ANIMATION ? ['animation_started_at', 'animation_running_since'] : ['generation_started_at', 'running_since'];
    }

    private static function time(mixed $v): ?CarbonImmutable
    {
        if (! is_string($v) || $v === '') return null;
        try { return CarbonImmutable::parse($v); } catch (\Throwable) { return null; }
    }
}
