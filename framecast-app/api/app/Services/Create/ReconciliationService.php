<?php
namespace App\Services\Create;

use App\Models\Workspace;
use App\Services\Developer\OperationAccounting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ReconciliationService
{
    /** Caller has verified external terminal status AND confirmed the host stopped.
     * Resolve a late receipt without issuing another provider request/render.
     */
    public function reconcile(VerifiedAttemptReceipt $receipt, bool $workerStopped): array
    {
        abort_unless(app()->environment(['local','testing']) && config('create.enabled') && $workerStopped, 403);
        $hash = hash('sha256',json_encode([$receipt->result(),$receipt->evidence]));
        return DB::transaction(function () use ($receipt,$hash) {
            $attempt = DB::table('composition_attempts')->where('id',$receipt->attemptId)->firstOrFail();
            $unlocked = DB::table('composition_runs')->where('id',$attempt->run_id)->firstOrFail();
            $workspace = Workspace::findOrFail($unlocked->workspace_id);
            Workspace::whereIn('id',array_unique([$workspace->id,$workspace->parent_workspace_id ?: $workspace->id]))->orderBy('id')->lockForUpdate()->get();
            $run = DB::table('composition_runs')->where('id',$unlocked->id)->lockForUpdate()->firstOrFail();
            $attempt = DB::table('composition_attempts')->where('id',$receipt->attemptId)->lockForUpdate()->firstOrFail();
            $old = DB::table('composition_reconciliations')->where('attempt_id',$attempt->id)->first();
            if ($old) { abort_unless(hash_equals($old->receipt_hash,$hash),409,'A different reconciliation is already recorded.');return ['replayed'=>true,'charged_credits'=>(int)$attempt->charged_credits]; }
            abort_unless($run->status === 'needs_attention' && in_array($attempt->status,['started','unknown'],true),409,'Only unresolved interrupted work can be reconciled.');
            abort_unless(in_array((int)$run->workspace_id,config('create.workspaces',[]),true),403);
            $lease = Str::random(64);
            // All temporary state is inside this transaction; other workers never
            // see an executable lease and the old worker's token is revoked.
            DB::table('composition_runs')->where('id',$run->id)->update(['status'=>'running','lease_hash'=>hash('sha256',$lease),'lease_expires_at'=>now()->addMinute()]);
            DB::table('api_operations')->where('id',$run->operation_id)->update(['status'=>'running']);
            DB::table('composition_attempts')->where('id',$attempt->id)->update(['result_hash'=>null]);
            $result=app(AttemptService::class)->settle($run->id,$lease,$attempt->id,$receipt->result(),$receipt);
            DB::table('composition_reconciliations')->insert(['attempt_id'=>$attempt->id,'receipt_hash'=>$hash,
                'previous_status'=>$attempt->status,'previous_result_hash'=>$attempt->result_hash,'evidence'=>$receipt->evidence,'created_at'=>now()]);
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
        abort_unless(app()->environment(['local','testing']) && config('create.enabled') && $workerStopped, 403);
        return DB::transaction(function () use ($runId) {
            $run = DB::table('composition_runs')->where('id',$runId)->lockForUpdate()->firstOrFail();
            abort_unless($run->status === 'needs_attention', 409, 'Only a held run can be closed.');
            abort_if(AttemptService::unresolved($run->id), 409, 'Some calls are still unresolved; reconcile them with a verified receipt.');
            // Same order as reconcile(): reopen, then close, so the hold is released and the operation settles.
            DB::table('api_operations')->where('id',$run->operation_id)->update(['status'=>'running']);
            OperationAccounting::close($run->operation_id);
            DB::table('composition_runs')->where('id',$run->id)->update(['status'=>'failed','lease_hash'=>null,'lease_expires_at'=>null,
                'stage'=>'The worker stopped after every paid call was settled; nothing was repeated.','error'=>'Start a new approved run to continue.','updated_at'=>now()]);
            return ['status'=>'failed','charged_credits'=>(int) DB::table('composition_attempts')->where('run_id',$run->id)->sum('charged_credits')];
        });
    }
}
