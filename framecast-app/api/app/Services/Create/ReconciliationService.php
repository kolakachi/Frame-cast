<?php
namespace App\Services\Create;

use App\Models\Workspace;
use App\Services\Developer\OperationAccounting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class ReconciliationService
{
    /** Retain the full ceiling of every uncertain call, but free work never started.
     * Operator must first stop the original host/container. No provider is called.
     */
    public function releaseUnstarted(string $runId, bool $workerStopped): array
    {
        abort_unless(config('create.enabled') && $workerStopped, 403);
        return DB::transaction(function () use ($runId) {
            $unlocked = DB::table('composition_runs')->where('id', $runId)->firstOrFail();
            $workspace = Workspace::findOrFail($unlocked->workspace_id);
            Workspace::whereIn('id', array_unique([$workspace->id, $workspace->creditRootId()]))->orderBy('id')->lockForUpdate()->get();
            $run = DB::table('composition_runs')->where('id', $runId)->lockForUpdate()->firstOrFail();
            app(WorkerOwnership::class)->requireStopped($run);
            abort_unless(in_array($run->status, ['needs_attention', 'failed'], true)
                && (\App\Services\Create\ConversationService::workspaceAllowed((int) $run->workspace_id)), 409);
            $op = DB::table('api_operations')->where('id', $run->operation_id)->lockForUpdate()->firstOrFail();
            abort_unless(in_array($op->status, ['running', 'needs_attention'], true), 409);
            $attempts = DB::table('composition_attempts')->where('run_id', $runId)->lockForUpdate()->get();
            $jobs = DB::table('api_operation_jobs')->where('operation_id', $op->id)->lockForUpdate()->get();
            // Unknown dependencies must never be assumed to have no remaining cost.
            abort_if($jobs->contains(fn ($job) => !$attempts->contains(fn ($a) => $job->id === 'create-call-'.$a->id)), 409, 'Unmapped operation jobs require review; hold unchanged.');
            $remaining = 0;
            foreach ($attempts as $a) {
                abort_unless($a->operation_id === $op->id, 409);
                if (!self::hasSettlement($a)) $remaining += max(0, (int) $a->credit_limit - (int) $a->charged_credits);
            }
            abort_unless($remaining > 0 && $remaining <= (int) $op->reserved_credits, 409, 'Use verified settlement recovery, or review the hold mismatch.');
            $released = (int) $op->reserved_credits - $remaining;
            DB::table('composition_runs')->where('id', $runId)->update(['status' => 'needs_attention', 'worker_stopped_at' => now(),
                'lease_hash' => null, 'lease_expires_at' => null, 'stage' => 'Worker stopped; external work needs reconciliation', 'updated_at' => now()]);
            DB::table('api_operations')->where('id', $op->id)->update(['status' => 'needs_attention', 'producer_closed' => true,
                'capacity_slots' => 0, 'reserved_credits' => $remaining, 'updated_at' => now()]);
            Log::warning('create.reservation_recovery', ['run_id' => $runId, 'operation_id' => $op->id, 'released_credits' => $released,
                'retained_credits' => $remaining, 'reason' => 'Stopped worker; full uncertain-call ceilings retained; unstarted work released']);
            return ['released_credits' => $released, 'retained_credits' => $remaining];
        });
    }

    private static function hasSettlement(object $attempt): bool
    {
        return in_array($attempt->status, ['succeeded', 'failed'], true) && $attempt->result_hash
            && $attempt->cost_microusd !== null;
    }

    /** Caller has verified external terminal status AND confirmed the host stopped.
     * Resolve a late receipt without issuing another provider request/render.
     */
    public function reconcile(VerifiedAttemptReceipt $receipt, bool $workerStopped): array
    {
        abort_unless(config('create.enabled') && $workerStopped, 403);
        $hash = hash('sha256',json_encode([$receipt->result(),$receipt->evidence]));
        return DB::transaction(function () use ($receipt,$hash) {
            $attempt = DB::table('composition_attempts')->where('id',$receipt->attemptId)->firstOrFail();
            $unlocked = DB::table('composition_runs')->where('id',$attempt->run_id)->firstOrFail();
            $workspace = Workspace::findOrFail($unlocked->workspace_id);
            Workspace::whereIn('id',array_unique([$workspace->id,$workspace->parent_workspace_id ?: $workspace->id]))->orderBy('id')->lockForUpdate()->get();
            $run = DB::table('composition_runs')->where('id',$unlocked->id)->lockForUpdate()->firstOrFail();
            app(WorkerOwnership::class)->requireStopped($run);
            $attempt = DB::table('composition_attempts')->where('id',$receipt->attemptId)->lockForUpdate()->firstOrFail();
            $old = DB::table('composition_reconciliations')->where('attempt_id',$attempt->id)->first();
            if ($old) { abort_unless(hash_equals($old->receipt_hash,$hash),409,'A different reconciliation is already recorded.');return ['replayed'=>true,'charged_credits'=>(int)$attempt->charged_credits]; }
            abort_unless($run->status === 'needs_attention' && in_array($attempt->status,['started','unknown'],true),409,'Only unresolved interrupted work can be reconciled.');
            abort_unless(\App\Services\Create\ConversationService::workspaceAllowed((int) $run->workspace_id),403);
            $lease = Str::random(64);
            // All temporary state is inside this transaction; other workers never
            // see an executable lease and the old worker's token is revoked.
            DB::table('composition_runs')->where('id',$run->id)->update(['status'=>'running','lease_hash'=>hash('sha256',$lease),'lease_expires_at'=>now()->addMinute()]);
            DB::table('api_operations')->where('id',$run->operation_id)->update(['status'=>'running']);
            DB::table('composition_attempts')->where('id',$attempt->id)->update(['result_hash'=>null]);
            $result=app(AttemptService::class)->settle($run->id,$lease,$attempt->id,$receipt->result(),$receipt);
            DB::table('composition_reconciliations')->insert(['attempt_id'=>$attempt->id,'receipt_hash'=>$hash,
                'previous_status'=>$attempt->status,'previous_result_hash'=>$attempt->result_hash,'evidence'=>$receipt->evidence,'created_at'=>now()]);
            // A bought plan item's record follows its attempt (plan-media-<item index>): settled as failed, the item can be
            // bought again by a new run; left "unknown", every later run would refuse it.
            if ($attempt->kind==='plan_media' && preg_match('/^plan-media-(\d+)$/',(string)$attempt->attempt_key,$m) && ($receipt->result()['status'] ?? null)==='failed')
                DB::table('create_plan_media')->where('run_id',$run->id)->where('item_index',(int)$m[1])->where('status','unknown')
                    ->update(['status'=>'failed','error'=>'Reconciled: '.mb_substr($receipt->evidence,0,240),'updated_at'=>now()]);
            $unresolved=AttemptService::unresolved($run->id);
            if ($unresolved) DB::table('api_operations')->where('id',$run->operation_id)->update(['status'=>'needs_attention']);
            else OperationAccounting::close($run->operation_id);
            DB::table('composition_runs')->where('id',$run->id)->update(['status'=>$unresolved?'needs_attention':'failed',
                'lease_hash'=>null,'lease_expires_at'=>null,'stage'=>$unresolved?'Other attempts still need reconciliation':'Interrupted run reconciled; previous versions preserved',
                'error'=>'No generation was repeated. Start a new approved run to continue.','updated_at'=>now()]);
            return $result;
        });
    }

    /**
     * Close a held run whose worker stopped after every paid call was already
     * settled: nothing is in doubt, so no receipt is needed. Releases the
     * unused credit hold and repeats nothing.
     */
    public function closeSettled(string $runId, bool $workerStopped): array
    {
        abort_unless(config('create.enabled') && $workerStopped, 403);
        return DB::transaction(function () use ($runId) {
            $unlocked = DB::table('composition_runs')->where('id', $runId)->firstOrFail();
            $workspace = Workspace::findOrFail($unlocked->workspace_id);
            Workspace::whereIn('id', array_unique([$workspace->id, $workspace->creditRootId()]))->orderBy('id')->lockForUpdate()->get();
            $run = DB::table('composition_runs')->where('id',$runId)->lockForUpdate()->firstOrFail();
            app(WorkerOwnership::class)->requireStopped($run);
            abort_unless($run->status === 'needs_attention', 409, 'Only a held run can be closed.');
            abort_unless(\App\Services\Create\ConversationService::workspaceAllowed((int) $run->workspace_id), 403);
            abort_if(AttemptService::unresolved($run->id), 409, 'Some calls are still unresolved; reconcile them with a verified receipt.');
            $attempts = DB::table('composition_attempts')->where('run_id', $runId)->lockForUpdate()->get();
            abort_if($attempts->contains(fn ($a) => !self::hasSettlement($a)), 409, 'A terminal label without a settlement receipt is not proof of billing. Hold retained.');
            // Repair stranded jobs only from settled attempts, never from a status label alone.
            foreach ($attempts as $a) {
                abort_unless($a->operation_id === $run->operation_id, 409);
                DB::table('api_operation_jobs')->where('operation_id', $run->operation_id)->where('id', 'create-call-'.$a->id)
                    ->whereIn('status', ['pending', 'running', 'released'])->update(['status' => $a->status === 'succeeded' ? 'completed' : 'failed', 'updated_at' => now()]);
            }
            abort_if(DB::table('api_operation_jobs')->where('operation_id', $run->operation_id)->whereIn('status', ['pending', 'running', 'released'])->exists(), 409, 'Unresolved operation jobs remain; hold retained.');
            // Same order as reconcile(): reopen, then close, so the hold is released and the operation settles.
            DB::table('api_operations')->where('id',$run->operation_id)->update(['status'=>'running']);
            OperationAccounting::close($run->operation_id);
            DB::table('composition_runs')->where('id',$run->id)->update(['status'=>'failed','lease_hash'=>null,'lease_expires_at'=>null,
                'stage'=>'The worker stopped after every paid call was settled; nothing was repeated.','error'=>'Start a new approved run to continue.','updated_at'=>now()]);
            return ['status'=>'failed','charged_credits'=>(int) DB::table('composition_attempts')->where('run_id',$run->id)->sum('charged_credits')];
        });
    }
}
