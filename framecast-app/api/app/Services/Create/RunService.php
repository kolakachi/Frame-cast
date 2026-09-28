<?php

namespace App\Services\Create;

use App\Services\Developer\OperationAccounting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Leases fence callbacks, not external costs. Expiry always requires reconciliation. */
class RunService
{
    public function claim(): ?array
    {
        return DB::transaction(function () {
            // One local render host. Serialize claim decisions without holding locks during rendering.
            if (DB::connection()->getDriverName() === 'pgsql') DB::select('select pg_advisory_xact_lock(783429)');
            // Unknown work is never put back on the queue automatically.
            $expired = DB::table('composition_runs')->whereIn('status', ['running', 'cancel_requested'])
                ->where('lease_expires_at', '<', now())->lockForUpdate()->get();
            foreach ($expired as $run) {
                DB::table('composition_runs')->where('id', $run->id)->update([
                    'status' => 'needs_attention', 'stage' => 'Worker disconnected', 'error' => 'Confirm the worker stopped before retrying.', 'updated_at' => now(),
                ]);
                DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => 'needs_attention', 'updated_at' => now()]);
            }
            if (DB::table('composition_runs')->whereIn('status', ['running', 'cancel_requested', 'needs_attention'])->exists()) return null;
            $query = DB::table('composition_runs')->where('status', 'queued')->whereIn('workspace_id', config('create.workspaces', []))->orderBy('created_at');
            $run = $query->lockForUpdate()->first();
            if (! $run) return null;
            $token = Str::random(64);
            DB::table('composition_runs')->where('id', $run->id)->update([
                'status' => 'running', 'stage' => 'Preparing local render', 'lease_hash' => hash('sha256', $token),
                'lease_expires_at' => now()->addSeconds(config('create.lease_seconds')), 'updated_at' => now(),
            ]);
            return ['id' => $run->id, 'lease_token' => $token, 'input' => json_decode($run->input_json, true)];
        });
    }

    private function leased(string $id, string $token): object
    {
        $run = DB::table('composition_runs')->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless($run->lease_hash && hash_equals($run->lease_hash, hash('sha256', $token)), 403);
        return $run;
    }

    public function validateResultLease(string $id, string $token): void
    {
        DB::transaction(function () use ($id, $token) {
            $run = $this->leased($id, $token);
            abort_unless($run->result_hash || (in_array($run->status, ['running', 'cancel_requested'], true)
                && now()->lessThan($run->lease_expires_at)), 409, 'Worker lease expired.');
        });
    }

    public function heartbeat(string $id, string $token, int $sequence, string $stage): array
    {
        return DB::transaction(function () use ($id, $token, $sequence, $stage) {
            $run = $this->leased($id, $token);
            abort_unless(in_array($run->status, ['running', 'cancel_requested'], true) && now()->lessThan($run->lease_expires_at), 409, 'Lease is no longer current.');
            // Replayed events cannot regress the visible stage.
            DB::table('composition_runs')->where('id', $id)->update([
                'sequence' => max($sequence, $run->sequence), 'stage' => $sequence > $run->sequence ? $stage : $run->stage,
                'lease_expires_at' => now()->addSeconds(config('create.lease_seconds')), 'updated_at' => now(),
            ]);
            return ['cancel_requested' => $run->status === 'cancel_requested'];
        });
    }

    public function cancel(int $workspaceId, string $conversationId, string $id): void
    {
        DB::transaction(function () use ($workspaceId, $conversationId, $id) {
            $run = DB::table('composition_runs')->where('workspace_id', $workspaceId)->where('conversation_id', $conversationId)->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($run->status === 'queued') {
                DB::table('composition_runs')->where('id', $id)->update(['status' => 'cancelled', 'stage' => 'Cancelled before starting', 'updated_at' => now()]);
                OperationAccounting::close($run->operation_id);
                DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => 'cancelled']);
            } elseif ($run->status === 'running') {
                DB::table('composition_runs')->where('id', $id)->update(['status' => 'cancel_requested', 'stage' => 'Stopping safely', 'updated_at' => now()]);
            }
            // Unknown work stays held; clicking cancel is not proof that it stopped.
        });
    }

    public function finish(string $id, string $token, array $result, ?string $artifactPath, ?string $artifactHash): array
    {
        $fingerprint = hash('sha256', json_encode([$result, $artifactHash]));
        return DB::transaction(function () use ($id, $token, $result, $artifactPath, $artifactHash, $fingerprint) {
            // Conversation first, then run: same order as user approval/restore.
            $unlocked = DB::table('composition_runs')->where('id', $id)->firstOrFail();
            $c = DB::table('create_conversations')->where('id', $unlocked->conversation_id)->lockForUpdate()->firstOrFail();
            $run = $this->leased($id, $token);
            if ($run->result_hash) {
                abort_unless(hash_equals($run->result_hash, $fingerprint), 409, 'A different result is already recorded.');
                return ['status' => $run->status, 'replayed' => true];
            }
            abort_unless(in_array($run->status, ['running', 'cancel_requested'], true) && now()->lessThan($run->lease_expires_at), 409, 'Stale worker result. Keep the local files for reconciliation.');
            $input = json_decode($run->input_json, true);
            abort_unless($input['mode'] === 'fixture', 503, 'Paid settlement is not enabled.');
            $status = $result['status'];
            if ($run->status === 'cancel_requested') abort_unless(in_array($status, ['cancelled', 'needs_attention'], true), 409, 'Stop the worker before acknowledging cancellation.');
            if ($status === 'preview_ready') {
                abort_unless($artifactPath && $artifactHash, 422, 'A verified encoded preview is required.');
                $bundle = $result['bundle'];
                abort_unless(isset($bundle['index.html']), 422);
                foreach ($bundle as $name => $contents) {
                    abort_unless(preg_match('/^[a-zA-Z0-9_-]+\.(html|css|js)$/D', $name) && is_string($contents) && strlen($contents) <= 128000, 422, 'Invalid source bundle.');
                }
                ksort($bundle);
                $revision = (string) Str::uuid();
                $conflict = $c->head_revision_id !== $input['base_revision_id'] || (int) $c->version !== $input['version'];
                DB::table('composition_revisions')->insert([
                    'id' => $revision, 'conversation_id' => $c->id, 'run_id' => $id,
                    'number' => 1 + (int) DB::table('composition_revisions')->where('conversation_id', $c->id)->max('number'), 'parent_revision_id' => $input['base_revision_id'],
                    'bundle_json' => json_encode($bundle), 'bundle_hash' => hash('sha256', json_encode($bundle)),
                    'artifact_path' => $artifactPath, 'artifact_hash' => $artifactHash, 'summary' => $result['summary'],
                    'conflict' => $conflict, 'created_at' => now(),
                ]);
                if (! $conflict) DB::table('create_conversations')->where('id', $c->id)->update(['head_revision_id' => $revision, 'version' => $c->version + 1, 'updated_at' => now()]);
            }
            DB::table('composition_runs')->where('id', $id)->update([
                'status' => $status, 'stage' => $result['summary'], 'error' => $status === 'preview_ready' ? null : $result['summary'],
                'result_hash' => $fingerprint, 'updated_at' => now(),
            ]);
            if ($status === 'needs_attention') {
                DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => 'needs_attention', 'updated_at' => now()]);
            } else {
                OperationAccounting::close($run->operation_id);
                if (in_array($status, ['failed', 'cancelled'], true)) DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => $status]);
            }
            return ['status' => $status, 'replayed' => false];
        });
    }
}
