<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Log, Schema};

/** A shared admission pause. It never disables callbacks, cancels work or releases credit holds. */
class AdmissionControl
{
    public const MESSAGE = 'Create is temporarily paused for maintenance. Your saved work is safe. Please try again later.';

    public function paused(bool $lock = false): bool
    {
        if (! config('create.runtime_controls_enabled')) return false;
        abort_unless(Schema::hasTable('create_runtime_controls'), 503, 'Create admission controls are not ready.');
        $query = DB::table('create_runtime_controls')->where('id', 1);
        $row = ($lock ? $query->lockForUpdate() : $query)->first();
        abort_unless($row, 503, 'Create admission controls are not ready.');
        return (bool) $row->draining;
    }

    public function assertOpen(bool $lock = false): void
    {
        abort_if($this->paused($lock), 503, self::MESSAGE);
    }

    public function setPaused(bool $paused, ?string $reason): void
    {
        abort_unless(config('create.runtime_controls_enabled'), 503, 'Enable CREATE_RUNTIME_CONTROLS_ENABLED on all Create processes first.');
        DB::transaction(function () use ($paused, $reason) {
            $this->paused(true);
            DB::table('create_runtime_controls')->where('id', 1)->update([
                'draining' => $paused, 'reason' => $reason, 'updated_at' => now(),
            ]);
        });
        Log::notice('create.admission_control', ['draining' => $paused]);
    }

    /** A journal inventory, not proof that HTTP requests, sandboxes or provider work have stopped. */
    public function status(): array
    {
        $counts = fn (string $table, string $column) => DB::table($table)->select($column)
            ->selectRaw('count(*) as total')->groupBy($column)->pluck('total', $column)->map(fn ($v) => (int) $v)->all();
        return [
            'controls_enabled' => (bool) config('create.runtime_controls_enabled'),
            'draining' => $this->paused(),
            'create_enabled' => (bool) config('create.enabled'),
            'durable_planning' => (bool) config('create.durable_planning'),
            'planning' => $counts('create_planning_jobs', 'state'),
            'builds' => $counts('composition_runs', 'status'),
            'unconfirmed_workers' => DB::table('composition_runs')->where('status', 'needs_attention')->whereNull('worker_stopped_at')->count(),
            'unresolved_attempts' => DB::table('composition_attempts')->whereIn('status', ['started', 'unknown'])->count(),
            'pending_media' => DB::table('create_plan_media')->where('status', 'pending')->count(),
            'note' => 'Queued work is preserved. Check running and uncertain work, API requests and actual host processes before restarting. This inventory does not certify a safe shutdown.',
        ];
    }

    /** A necessary journal check only; host processes and in-flight HTTP still need inspection. */
    public static function drainBlockers(array $status): array
    {
        $blocked = [];
        foreach (['controls_enabled', 'draining', 'durable_planning'] as $key) {
            if (($status[$key] ?? null) !== true) $blocked[] = $key;
        }
        foreach (['planning' => ['running', 'needs_attention'], 'builds' => ['running', 'cancel_requested', 'needs_attention']] as $group => $states) {
            if (! isset($status[$group]) || ! is_array($status[$group])) { $blocked[] = $group; continue; }
            foreach ($states as $state) if (($status[$group][$state] ?? 0) > 0) $blocked[] = $group.'.'.$state;
        }
        foreach (['unconfirmed_workers', 'unresolved_attempts', 'pending_media'] as $key) {
            if (! isset($status[$key]) || $status[$key] !== 0) $blocked[] = $key;
        }
        return $blocked;
    }
}
