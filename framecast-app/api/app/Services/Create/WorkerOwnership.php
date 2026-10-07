<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Log, Schema};
use Illuminate\Support\Str;

/** Assignment and stop attestations. Worker names are operational labels, not separate authentication. */
class WorkerOwnership
{
    public function find(string $runId): ?object
    {
        if (! Schema::hasTable('create_worker_assignments')) {
            abort_if(config('create.worker_ownership_required'), 503, 'Worker ownership storage is not ready.');
            return null;
        }
        return DB::table('create_worker_assignments')->where('run_id', $runId)->first();
    }

    public function validate(?array $identity): void
    {
        abort_if($identity === null && config('create.worker_ownership_required'), 422, 'This worker must send its configured identity before claiming.');
        if ($identity === null) return;
        validator($identity, [
            'worker_id' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,79}$/D'],
            'instance_id' => ['required', 'uuid'],
            'slot' => ['required', 'string', 'regex:/^[A-Za-z0-9][A-Za-z0-9_.-]{0,39}$/D'],
        ])->validate();
        abort_unless(Schema::hasTable('create_worker_assignments'), 503, 'Worker ownership storage is not ready.');
    }

    /** Called inside claim's transaction. One run is never reassigned or automatically replayed. */
    public function claimed(string $runId, string $lease, ?array $identity): ?array
    {
        if ($identity === null) return null;
        $identity = array_intersect_key($identity, array_flip(['worker_id', 'instance_id', 'slot']));
        $id = (string) Str::uuid();
        DB::table('create_worker_assignments')->insert([
            'id' => $id, 'run_id' => $runId, ...$identity, 'lease_fingerprint' => hash('sha256', $lease),
            'claimed_at' => now(), 'last_seen_at' => now(),
        ]);
        return ['id' => $id, ...$identity];
    }

    public function seen(string $runId): void
    {
        if ($this->find($runId)) DB::table('create_worker_assignments')->where('run_id', $runId)->whereNull('stopped_at')->update(['last_seen_at' => now()]);
    }

    /** Run must be locked by the caller. Only the first acknowledgement supplies the audit record. */
    public function acknowledge(object $run): void
    {
        $owner = $this->find($run->id);
        if (! $owner) return;
        abort_unless($run->lease_hash && hash_equals($owner->lease_fingerprint, $run->lease_hash), 409, 'Worker assignment no longer matches this lease.');
        if (! $owner->stopped_at) DB::table('create_worker_assignments')->where('id', $owner->id)->update([
            'stopped_at' => now(), 'stop_source' => 'worker', 'stop_evidence' => 'Authenticated lease holder acknowledged sandbox termination.',
        ]);
    }

    /** Lost acknowledgement may be retried with the old token, but it grants no new work or settlement. */
    public function replayedStop(object $run, string $lease): bool
    {
        $owner = $this->find($run->id);
        return $owner && $owner->stopped_at && $run->worker_stopped_at && ! $run->lease_hash
            && hash_equals($owner->lease_fingerprint, hash('sha256', $lease));
    }

    /** Assigned runs require a durable stop record even if a CLI caller passes --worker-stopped. */
    public function requireStopped(object $run): void
    {
        $owner = $this->find($run->id);
        if (! $owner) return; // Legacy runs retain explicit operator-confirmation recovery.
        abort_unless($owner->stopped_at && $run->worker_stopped_at && ! $run->lease_hash, 409,
            'Record stop evidence for this worker assignment with create:worker-recovery before reconciliation.');
    }

    public function inspect(string $runId): array
    {
        $run = DB::table('composition_runs')->where('id', $runId)->firstOrFail();
        $owner = $this->find($runId);
        $op = DB::table('api_operations')->where('id', $run->operation_id)->first();
        return [
            'run_id' => $runId, 'status' => $run->status, 'operation_id' => $run->operation_id,
            'assignment' => $owner ? array_diff_key((array) $owner, ['lease_fingerprint' => true]) : null,
            'containers' => ['wyv-create-'.$runId, 'wyv-create-'.$runId.'-delivery'],
            'worker_stopped_at' => $run->worker_stopped_at, 'lease_expires_at' => $run->lease_expires_at,
            'reserved_credits' => (int) ($op->reserved_credits ?? 0), 'spent_credits' => (int) ($op->spent_credits ?? 0),
            'unresolved_attempts' => DB::table('composition_attempts')->where('run_id', $runId)->whereIn('status', ['started', 'unknown'])
                ->get(['id', 'provider', 'status', 'prediction_id'])->all(),
            'note' => 'Verify the original coordinator process and named containers on the assigned host. An expired lease or a new instance ID is not proof of termination. Stop evidence does not establish provider billing.',
        ];
    }

    /** Operator has independently stopped/fenced the original process and containers; this records that attestation. */
    public function confirmStopped(string $runId, string $assignmentId, string $evidence): array
    {
        abort_unless(config('create.enabled'), 403);
        abort_unless(strlen(trim($evidence)) >= 8 && mb_strlen($evidence) <= 500 && ! preg_match('/[\x00-\x1F]/', $evidence), 422,
            'Supply a short incident or host-check evidence reference (8–500 characters, no secrets).');
        return DB::transaction(function () use ($runId, $assignmentId, $evidence) {
            $run = DB::table('composition_runs')->where('id', $runId)->lockForUpdate()->firstOrFail();
            abort_unless(ConversationService::workspaceAllowed((int) $run->workspace_id), 403);
            $owner = $this->find($runId);
            abort_unless($owner && hash_equals($owner->id, $assignmentId), 409, 'Worker assignment changed or is not recorded. No capacity or hold was changed.');
            if ($owner->stopped_at) {
                $this->requireStopped($run);
                return ['replayed' => true, 'assignment_id' => $owner->id, 'holds_changed' => false];
            }
            abort_unless(in_array($run->status, ['needs_attention', 'failed'], true), 409, 'Inspect or drain active work first. Only interrupted/failed runs can be confirmed stopped.');
            abort_unless($run->lease_hash && hash_equals($owner->lease_fingerprint, $run->lease_hash), 409, 'Worker lease changed. Stop evidence must match the recorded assignment.');
            DB::table('create_worker_assignments')->where('id', $owner->id)->update([
                'stopped_at' => now(), 'stop_source' => 'operator', 'stop_evidence' => trim($evidence),
            ]);
            DB::table('composition_runs')->where('id', $runId)->update([
                'worker_stopped_at' => now(), 'lease_hash' => null, 'lease_expires_at' => null, 'updated_at' => now(),
            ]);
            // Do not reopen a terminal operation or change any credit/attempt field.
            DB::table('api_operations')->where('id', $run->operation_id)->update(['capacity_slots' => 0, 'updated_at' => now()]);
            Log::notice('create.worker_stop_recorded', ['run_id' => $runId, 'assignment_id' => $owner->id, 'source' => 'operator']);
            return ['replayed' => false, 'assignment_id' => $owner->id, 'holds_changed' => false];
        });
    }
}
