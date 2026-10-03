<?php

namespace App\Services\Create;

use App\Models\Workspace;
use App\Services\CreditService;
use App\Services\Developer\OperationAccounting;
use Illuminate\Support\Facades\{Context, DB};
use Illuminate\Support\Str;

/** Durable start before any external call. Replayed starts never grant execution. */
class AttemptService
{
    public function begin(string $runId, string $lease, string $key, string $kind, string $requestHash): array
    {
        return DB::transaction(function () use ($runId, $lease, $key, $kind, $requestHash) {
            $run = $this->leased($runId, $lease, false);
            abort_unless(Workspace::whereKey($run->workspace_id)->where('status', 'active')->exists(), 403);
            $old = DB::table('composition_attempts')->where('run_id', $runId)->where('attempt_key', $key)->first();
            if ($old) {
                abort_unless($old->kind === $kind && hash_equals($old->request_hash, $requestHash), 409, 'Attempt key already used for another request.');
                return ['id' => $old->id, 'may_execute' => false, 'status' => $old->status];
            }
            abort_unless(OperationAccounting::enabled(), 503, 'Operation accounting is required.');
            $input = json_decode($run->input_json, true);
            $policy = $input['execution_policy'][$kind] ?? null;
            abort_unless(is_array($policy), 422, 'This operation was not quoted.');
            $credits = $policy['credits']; $cost = $policy['cost_limit_microusd'];
            abort_unless(is_int($credits) && $credits >= 0 && is_int($cost) && $cost >= 0, 422);
            abort_unless(($input['mode'] === 'fixture' && $credits === 0 && $cost === 0 && $policy['provider'] === 'offline')
                || ($input['mode'] === 'agent' && PilotPolicy::enabled()), 503, 'Paid execution remains disabled.');
            abort_if(DB::table('composition_attempts')->where('run_id', $runId)->where('kind', $kind)->count() >= $policy['max_calls'], 409, 'Quoted attempt limit reached.');
            $pending = DB::table('composition_attempts')->where('operation_id', $run->operation_id)->whereIn('status', ['started', 'unknown'])->sum('credit_limit');
            $op = DB::table('api_operations')->where('id', $run->operation_id)->lockForUpdate()->firstOrFail();
            abort_unless($op->status === 'running' && $op->workspace_id == $run->workspace_id
                && $op->spent_credits + $pending + $credits <= $op->authorized_credits, 409, 'Operation allowance exhausted.');
            $id = (string) Str::uuid();
            DB::table('composition_attempts')->insert(['id' => $id, 'run_id' => $runId, 'operation_id' => $run->operation_id,
                'attempt_key' => $key, 'request_hash' => $requestHash, 'kind' => $kind, 'provider' => $policy['provider'], 'model' => $policy['model'],
                'status' => 'started', 'credit_limit' => $credits, 'cost_limit_microusd' => $cost, 'created_at' => now(), 'updated_at' => now()]);
            // Existing accounting recovery must see this external dependency too.
            OperationAccounting::queued($run->operation_id, 'create-call-'.$id);
            return ['id' => $id, 'may_execute' => true, 'status' => 'started'];
        });
    }

    public function bindPrediction(string $runId, string $lease, string $id, string $prediction): void
    {
        DB::transaction(function () use ($runId,$lease,$id,$prediction) {
            $this->leased($runId,$lease,false);
            $a=DB::table('composition_attempts')->where('run_id',$runId)->where('id',$id)->lockForUpdate()->firstOrFail();
            abort_unless($a->status==='started' && preg_match('/^[a-zA-Z0-9_-]{1,160}$/D',$prediction),409);
            abort_if($a->prediction_id && $a->prediction_id!==$prediction,409,'Prediction already bound.');
            DB::table('composition_attempts')->where('id',$id)->update(['prediction_id'=>$prediction,'updated_at'=>now()]);
        });
    }

    public function settle(string $runId, string $lease, string $id, array $result, ?VerifiedAttemptReceipt $verified = null): array
    {
        $hash = hash('sha256', json_encode([$result['status'], $result['prediction_id'] ?? null, $result['cost_microusd'] ?? null]));
        return DB::transaction(function () use ($runId, $lease, $id, $result, $hash, $verified) {
            // Match CreditService's pool-before-operation lock order.
            $unlocked = DB::table('composition_runs')->where('id', $runId)->firstOrFail();
            $workspace = Workspace::findOrFail($unlocked->workspace_id);
            Workspace::whereIn('id', array_unique([$workspace->id, $workspace->parent_workspace_id ?: $workspace->id]))->orderBy('id')->lockForUpdate()->get();
            $run = $this->leased($runId, $lease, true);
            $attempt = DB::table('composition_attempts')->where('run_id', $runId)->where('id', $id)->lockForUpdate()->firstOrFail();
            if ($attempt->result_hash) {
                abort_unless(hash_equals($attempt->result_hash, $hash), 409, 'A different receipt is already recorded.');
                return ['status' => $attempt->status, 'charged_credits' => (int) $attempt->charged_credits, 'cost_microusd'=>$attempt->cost_microusd===null?null:(int)$attempt->cost_microusd, 'prediction_id'=>$attempt->prediction_id, 'replayed' => true];
            }
            abort_unless(in_array($run->status, ['running', 'cancel_requested'], true) && now()->lessThan($run->lease_expires_at), 409, 'Settlement requires a current lease.');
            $status = $result['status'];
            $cost = $result['cost_microusd'] ?? null;
            abort_unless(in_array($status, ['succeeded', 'failed', 'unknown'], true), 422);
            abort_unless($status === 'unknown' || (is_int($cost) && $cost >= 0 && $cost <= $attempt->cost_limit_microusd), 422, 'Known usage must fit the approved ceiling; otherwise report unknown.');
            $prediction = $result['prediction_id'] ?? $attempt->prediction_id;
            abort_if($attempt->prediction_id && $prediction !== $attempt->prediction_id,409,'Receipt prediction does not match the bound attempt.');
            if ($attempt->provider !== 'offline' && $status !== 'unknown') {
                abort_unless($verified && $verified->attemptId === $id && $verified->status === $status
                    && $verified->predictionId === $prediction && $verified->costMicrousd === $cost, 409, 'Verified provider state and billing evidence are required. Hold retained.');
            }
            abort_unless($attempt->provider === 'offline' || $status === 'unknown' || (is_string($prediction) && strlen($prediction) > 0), 422, 'Provider receipt ID is required.');
            $credits = $verified && str_starts_with($verified->evidence,'pilot-tariff:') && in_array($attempt->kind,['agent','critic','plan_media'],true) ? min((int)$attempt->credit_limit,(int)ceil($cost/4000)) : ($status === 'unknown' ? 0 : (($status === 'succeeded' || $cost > 0) ? (int) $attempt->credit_limit : 0));
            $previous = Context::getHidden(OperationAccounting::CONTEXT);
            try {
                Context::addHidden(OperationAccounting::CONTEXT, $run->operation_id);
                abort_unless(OperationAccounting::enabled() && app(CreditService::class)->deductQuietly((int) $run->workspace_id, $credits, 'create_'.$attempt->kind,
                    ['upstream_cost_usd' => $cost === null ? null : $cost / 1000000,
                        'metadata' => ['composition_run_id' => $runId, 'composition_attempt_id' => $id,'cost_evidence'=>$verified?->evidence]]), 409, 'Usage could not be settled. Hold retained.');
            } finally {
                Context::forgetHidden(OperationAccounting::CONTEXT);
                if ($previous !== null) Context::addHidden(OperationAccounting::CONTEXT, $previous);
            }
            DB::table('composition_attempts')->where('id', $id)->update(['status' => $status, 'cost_microusd' => $status === 'unknown' ? null : $cost,
                'charged_credits' => $credits, 'prediction_id' => $prediction, 'result_hash' => $hash, 'updated_at' => now()]);
            if ($status === 'unknown') {
                DB::table('composition_runs')->where('id', $runId)->update(['status' => 'needs_attention', 'stage' => 'Reconciling external work', 'updated_at' => now()]);
                DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => 'needs_attention', 'updated_at' => now()]);
            } else {
                DB::table('api_operation_jobs')->where('id', 'create-call-'.$id)->update(['status' => $status === 'succeeded' ? 'completed' : 'failed', 'updated_at' => now()]);
            }
            return ['status' => $status, 'charged_credits' => $credits, 'cost_microusd'=>$status==='unknown'?null:$cost, 'prediction_id'=>$prediction, 'replayed' => false];
        });
    }

    private function leased(string $id, string $token, bool $settling): object
    {
        $run = DB::table('composition_runs')->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless($run->lease_hash && hash_equals($run->lease_hash, hash('sha256', $token)), 403);
        abort_unless(($settling || ($run->status === 'running' && now()->lessThan($run->lease_expires_at)))
            && in_array((int) $run->workspace_id, config('create.workspaces', []), true), 409, 'Attempt lease is no longer current.');
        return $run;
    }

    public static function unresolved(string $runId): bool
    {
        return DB::table('composition_attempts')->where('run_id', $runId)->whereIn('status', ['started', 'unknown'])->exists();
    }
}
