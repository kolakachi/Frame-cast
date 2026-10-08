<?php

namespace App\Services\Create;

use App\Jobs\PlanCreateVideo;
use App\Models\User;
use Illuminate\Support\Facades\{Bus, DB};
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** The database is the request journal; Redis delivery is disposable and may be repeated. Paid work is not. */
class PlanningJobService
{
    public const PER_WORKSPACE = 2;
    public const TIMEOUT = 1200;
    // A plan is charged in the same transaction that saves it, so an interrupted plan with nothing saved charged
    // nothing: the user may simply ask again. It is never retried automatically (G-REC drill, 2026-10-08: the old
    // message locked the conversation until an operator stepped in, and no operator tool existed).
    public const UNCERTAIN = 'Planning stopped before it finished. Nothing was charged; send your request again to plan.';

    public function submit(User $user, string $conversationId, int $version, string $key, bool $skip): array
    {
        $job = DB::transaction(function () use ($user, $conversationId, $version, $key, $skip) {
            $service = app(ConversationService::class);
            $service->authorize($user, true);
            // This existing row is also the lock for two different request keys in one conversation.
            $conversation = $service->conversation($user, $conversationId, true);
            $hash = hash('sha256', json_encode([$version, $skip]));
            $existing = DB::table('create_planning_jobs')->where('conversation_id', $conversationId)->where('idempotency_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->request_hash, $hash), 409, 'This request key already belongs to a different planning request.');
                return $existing;
            }
            app(AdmissionControl::class)->assertOpen(true);
            app(DiskSpace::class)->admission();
            abort_if($conversation->archived_at, 409, 'Restore this conversation before planning.');
            abort_unless((int) $conversation->version === $version, 409, 'Conversation changed. Refresh before planning.');
            $active = DB::table('create_planning_jobs')->where('conversation_id', $conversationId)->whereIn('state', ['queued', 'running', 'needs_attention'])->first();
            // An interrupted plan that saved nothing (and so charged nothing) gives way to the user's new request.
            if ($active?->state === 'needs_attention' && ! DB::table('create_plans')->where('conversation_id', $conversationId)->where('idempotency_key', $active->idempotency_key)->exists()) {
                DB::table('create_planning_jobs')->where('id', $active->id)->where('state', 'needs_attention')
                    ->update(['state' => 'failed', 'error' => 'Superseded by a new request after an interruption; nothing was charged.', 'updated_at' => now()]);
                $active = null;
            }
            abort_if($active, 409, $active?->state === 'needs_attention' ? self::UNCERTAIN : 'A plan is already queued or running for this conversation.');
            $id = (string) Str::uuid();
            DB::table('create_planning_jobs')->insert([
                'id' => $id, 'conversation_id' => $conversationId, 'workspace_id' => $user->workspace_id, 'user_id' => $user->id,
                'idempotency_key' => $key, 'request_hash' => $hash, 'expected_version' => $version, 'skip_questions' => $skip,
                'state' => 'queued', 'created_at' => now(), 'updated_at' => now(),
            ]);
            return DB::table('create_planning_jobs')->where('id', $id)->first();
        });
        // A crash or Redis outage here cannot lose admission: the scheduler publishes queued rows again.
        if ($job->state === 'queued') $this->dispatch($job->id);
        return $this->present(DB::table('create_planning_jobs')->where('id', $job->id)->first());
    }

    /** Back in the queue for a moment while the workspace's other plans run (recovery republishes it if Redis is down). */
    private function later(string $id): void
    {
        DB::table('create_planning_jobs')->where('id', $id)->where('state', 'queued')->update(['dispatched_at' => now(), 'updated_at' => now()]);
        rescue(fn () => Bus::dispatch((new PlanCreateVideo($id))->onConnection('redis')->onQueue('create-planning')->delay(now()->addSeconds(20))), report: false);
    }

    public function dispatch(string $id): void
    {
        if (app(AdmissionControl::class)->paused()) return;
        $claimed = DB::table('create_planning_jobs')->where('id', $id)->where('state', 'queued')
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(5)))
            ->update(['dispatched_at' => now(), 'updated_at' => now()]);
        if (! $claimed) return;
        try {
            Bus::dispatch((new PlanCreateVideo($id))->onConnection('redis')->onQueue('create-planning'));
        } catch (\Throwable $e) {
            report($e);
            // Keep it queued. A committed request must not be reported as rejected just because Redis is down.
            DB::table('create_planning_jobs')->where('id', $id)->where('state', 'queued')->update(['dispatched_at' => null]);
        }
    }

    public function execute(string $id): void
    {
        if (! config('create.enabled') || ! config('create.durable_planning')) return;
        if (! DB::table('create_planning_jobs')->where('id', $id)->where('state', 'queued')->exists()) return;
        try { app(DiskSpace::class)->admission(); }
        catch (DiskCapacityException) { return; } // Still queued; scheduler republishes after capacity returns. No paid work started.
        $token = (string) Str::uuid();
        $workspace = (int) DB::table('create_planning_jobs')->where('id', $id)->value('workspace_id');
        // Several planning workers share the queue; one workspace runs at most PER_WORKSPACE plans at once, so a
        // workspace that sends many briefs cannot keep everyone else waiting. Its next plan waits its turn.
        $claimed = \Illuminate\Support\Facades\Cache::lock('create-planning:ws:'.$workspace, 15)->block(10, fn () => DB::transaction(function () use ($id, $token, $workspace) {
            if (app(AdmissionControl::class)->paused(true)) return 0;
            if (DB::table('create_planning_jobs')->where('workspace_id', $workspace)->where('state', 'running')->count() >= (int) config('create.planning_per_workspace', self::PER_WORKSPACE)) return -1;
            return DB::table('create_planning_jobs')->where('id', $id)->where('state', 'queued')->update([
                'state' => 'running', 'execution_token' => $token, 'started_at' => now(),
                'deadline_at' => now()->addSeconds(self::TIMEOUT + 120), 'updated_at' => now(),
            ]);
        }));
        if ($claimed === -1) { $this->later($id); return; }
        if (! $claimed) return; // A duplicated queue delivery never repeats model calls.
        $job = DB::table('create_planning_jobs')->where('id', $id)->first();
        try {
            $user = User::find($job->user_id);
            abort_unless($user && (int) $user->workspace_id === (int) $job->workspace_id, 403, 'Your workspace access changed.');
            $result = app(PlanService::class)->propose($user, $job->conversation_id, (int) $job->expected_version, $job->idempotency_key, (bool) $job->skip_questions, $token);
            $this->complete($job, ['needs_answer' => $result['needs_answer'] ?? null, 'plan_id' => $result['id'] ?? null]);
        } catch (\Throwable $e) {
            $status = $e instanceof HttpExceptionInterface ? $e->getStatusCode() : 500;
            if ($status >= 500) report($e);
            // Known precondition failures are terminal; a provider/internal failure is not safe to replay blindly.
            $uncertain = $status >= 500;
            DB::table('create_planning_jobs')->where('id', $id)->where('execution_token', $token)
                ->whereIn('state', ['running', 'needs_attention'])->update([
                    'state' => $uncertain ? 'needs_attention' : 'failed', 'error_status' => $uncertain ? 409 : $status,
                    'error' => $uncertain ? self::UNCERTAIN : $e->getMessage(), 'finished_at' => now(), 'updated_at' => now(),
                ]);
        } finally {
            // Queue workers are long-lived; never let one customer's activity/cost context leak to the next job.
            app()->forgetInstance(PlanActivity::class);
            PlanningCosts::end();
        }
    }

    private function complete(object $job, array $result): void
    {
        DB::table('create_planning_jobs')->where('id', $job->id)->where('execution_token', $job->execution_token)
            ->whereIn('state', ['running', 'needs_attention'])->update([
                'state' => 'done', 'result_json' => json_encode($result), 'error' => null, 'error_status' => null,
                'finished_at' => now(), 'updated_at' => now(),
            ]);
    }

    public function interrupted(string $id): void
    {
        $job = DB::table('create_planning_jobs')->where('id', $id)->first();
        if (! $job || ! in_array($job->state, ['running', 'needs_attention'], true)) return;
        // The process may have committed the plan and its charge before dying on the journal update.
        $plan = DB::table('create_plans')->where('conversation_id', $job->conversation_id)->where('idempotency_key', $job->idempotency_key)->first();
        if ($plan) {
            $this->complete($job, ['needs_answer' => null, 'plan_id' => $plan->id]);
            return;
        }
        DB::table('create_planning_jobs')->where('id', $id)->where('state', 'running')->update([
            'state' => 'needs_attention', 'error_status' => 409, 'error' => self::UNCERTAIN,
            'finished_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function recover(): void
    {
        if (! config('create.enabled') || ! config('create.durable_planning')) return;
        DB::table('create_planning_jobs')->where('state', 'queued')
            ->where(fn ($q) => $q->whereNull('dispatched_at')->orWhere('dispatched_at', '<', now()->subMinutes(5)))
            ->orderBy('sequence')->limit(100)->pluck('id')->each(fn ($id) => $this->dispatch($id));
        // Older unresolved jobs must not starve detection of newly interrupted execution.
        DB::table('create_planning_jobs')->where('state', 'running')->where('deadline_at', '<', now())
            ->orderBy('deadline_at')->limit(100)->pluck('id')->each(fn ($id) => $this->interrupted($id));
        DB::table('create_planning_jobs')->where('state', 'needs_attention')->whereExists(fn ($q) => $q->selectRaw('1')->from('create_plans')
            ->whereColumn('create_plans.conversation_id', 'create_planning_jobs.conversation_id')
            ->whereColumn('create_plans.idempotency_key', 'create_planning_jobs.idempotency_key'))
            ->limit(100)->pluck('id')->each(fn ($id) => $this->interrupted($id));
    }

    public function latest(string $conversationId, ?string $key = null): ?array
    {
        $job = DB::table('create_planning_jobs')->where('conversation_id', $conversationId)
            ->when($key !== null, fn ($q) => $q->where('idempotency_key', $key))->orderByDesc('sequence')->first();
        return $job ? $this->present($job) : null;
    }

    public function present(object $job): array
    {
        return [
            'id' => $job->id, 'key' => $job->idempotency_key, 'state' => $job->state,
            'started_at' => $job->started_at, 'queued_at' => $job->created_at,
            'status' => $job->error_status, 'error' => $job->error,
            ...json_decode($job->result_json ?: '{}', true),
        ];
    }
}
