<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/** Admin diagnostics: observable actions and receipts, never model thinking or credentials. */
class TrajectoryService
{
    public static function safe(mixed $value): string
    {
        $s = (string) $value;
        foreach ([
            '~data:[^\s]+~i' => '[media omitted]',
            '~https?://[^\s<>"\']+~i' => '[url omitted]',
            '~\b[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}\b~i' => '[email omitted]',
            '~\b(?:Bearer\s+\S+|sk-[A-Za-z0-9_-]+|r8_[A-Za-z0-9_-]+)~i' => '[credential omitted]',
            '~\b(api[_-]?key|token|secret|password|authorization)\s*["\']?\s*[:=]\s*["\']?[^\s,"\'}]+~i' => '$1=[redacted]',
            '~/+(?:Users|home|output|private|tmp)/[^\s"\']+~' => '[local path omitted]',
        ] as $pattern => $replacement) $s = preg_replace($pattern, $replacement, $s);
        return mb_substr($s, 0, 1200);
    }

    public function append(string $runId, string $lease, array $events): void
    {
        DB::transaction(function () use ($runId, $lease, $events) {
            app(RunService::class)->validateResultLease($runId, $lease);
            foreach ($events as $event) {
                // Explicit projection also excludes arbitrary nested provider payloads.
                $e = array_intersect_key($event, array_flip(['sequence', 'at', 'phase', 'tool', 'status', 'call', 'revision', 'duration_ms', 'summary', 'detail', 'input_hash', 'output_hash']));
                foreach (['summary', 'detail', 'tool'] as $key) if (isset($e[$key])) $e[$key] = self::safe($e[$key]);
                $json = json_encode($e, JSON_THROW_ON_ERROR);
                $prior = DB::table('composition_trace_events')->where('run_id', $runId)->where('sequence', $e['sequence'])->first();
                if ($prior) {
                    abort_unless(json_decode($prior->event_json, true) === $e, 409, 'Trace sequence already records a different event.');
                    continue;
                }
                DB::table('composition_trace_events')->insert(['run_id' => $runId, 'sequence' => $e['sequence'], 'event_json' => $json, 'created_at' => now()]);
            }
        });
    }

    public function show(string $id): array
    {
        $c = DB::table('create_conversations')->where('id', $id)->firstOrFail();
        $rows = DB::table('composition_runs')->where('conversation_id', $id)->orderByDesc('created_at')->limit(21)->get();
        $truncated = $rows->count() > 20; $rows = $rows->take(20)->reverse();
        $timeline = []; $runs = [];
        $add = function ($at, $kind, $id, $summary, array $data = [], ?string $run = null) use (&$timeline) {
            $timeline[] = ['at' => $at, 'kind' => $kind, 'id' => $id, 'run_id' => $run, 'summary' => self::safe($summary), 'data' => $data];
        };
        foreach (DB::table('create_messages')->where('conversation_id', $id)->orderByDesc('sequence')->limit(200)->get()->reverse() as $m) {
            $add($m->created_at, 'message', $m->id, $m->content, ['role' => $m->role, 'sequence' => (int) $m->sequence]);
        }
        foreach (DB::table('create_plans')->where('conversation_id', $id)->orderByDesc('created_at')->limit(100)->get() as $p) {
            $plan = json_decode($p->plan_json, true) ?: [];
            $add($p->created_at, 'plan', $p->id, $plan['summary'] ?? 'Plan prepared', ['message_id' => $p->message_id, 'provider' => $p->provider, 'status' => $p->status]);
        }
        foreach ($rows as $r) {
            $input = json_decode($r->input_json, true) ?: [];
            $messages = array_values(array_filter($input['messages'] ?? [], fn ($m) => ($m['role'] ?? '') === 'user'));
            $requestId = $messages ? end($messages)['id'] ?? null : null;
            $attempts = DB::table('composition_attempts')->where('run_id', $r->id)->orderBy('created_at')->get([
                'id', 'kind', 'provider', 'model', 'request_hash', 'prediction_id', 'status', 'charged_credits', 'cost_microusd', 'dispatched_at', 'created_at', 'updated_at',
            ]);
            $events = DB::table('composition_trace_events')->where('run_id', $r->id)->orderBy('sequence')->get();
            $owner = app(WorkerOwnership::class)->find($r->id);
            $ownership = $owner ? array_intersect_key((array) $owner, array_flip(['id', 'worker_id', 'instance_id', 'slot', 'claimed_at', 'last_seen_at', 'stopped_at', 'stop_source'])) : null;
            $runs[] = ['id' => $r->id, 'message_id' => $requestId, 'plan_id' => $input['plan']['plan_id'] ?? null,
                'worker_assignment' => $ownership, 'quote_id' => $r->quote_id, 'operation_id' => $r->operation_id, 'status' => $r->status, 'stage' => self::safe($r->stage),
                'build_stage' => $input['build_stage'] ?? (! empty($input['look_first']) ? 'storyboard' : 'full_video'),
                'charged_credits' => (int) $attempts->sum('charged_credits'), 'known_cost_microusd' => (int) $attempts->sum('cost_microusd'),
                'unknown_attempts' => $attempts->whereIn('status', ['started', 'unknown'])->count(),
                'trace_events' => $events->count(), 'trace_coverage' => $events->isEmpty() ? 'No detailed trace recorded; database evidence only' : 'Worker events recorded; an unmatched start may indicate interruption',
                'trace_truncated' => $events->count() >= 2000];
            $add($r->created_at, 'run', $r->id, 'Approved run queued', ['message_id' => $requestId, 'plan_id' => $input['plan']['plan_id'] ?? null, 'quote_id' => $r->quote_id,
                'inputs' => array_map(fn ($f) => array_intersect_key($f, array_flip(['asset_id', 'purpose', 'asset_type', 'sha256'])), $input['input_files'] ?? []),
                'approved_plan_summary' => self::safe($input['plan']['summary'] ?? ''),
                'requirements' => array_slice($input['plan']['requirements'] ?? [], 0, 24),
                'requirement_history' => array_slice($input['plan']['requirement_history'] ?? [], -48),
                'character_style' => self::safe($input['plan']['character_style'] ?? ''),
                'execution_policy' => array_map(fn ($p) => array_intersect_key($p, array_flip(['provider', 'model', 'max_calls', 'max_output_tokens', 'credits', 'total_credits', 'cost_limit_microusd'])), $input['execution_policy'] ?? []),
                'planned_media' => array_map(fn ($m) => ['task_id' => $m['id'] ?? null, 'requirement_ids' => $m['requirement_ids'] ?? [], 'kind' => $m['kind'], 'credits' => $m['credits'], 'description' => self::safe($m['description'] ?? ''), 'reused' => isset($m['reuse_media_id'])], $input['plan_media'] ?? [])], $r->id);
            if ($owner) {
                $add($owner->claimed_at, 'worker_assignment', $owner->id, 'Run assigned to '.$owner->worker_id.' / '.$owner->slot, $ownership, $r->id);
                if ($owner->stopped_at) $add($owner->stopped_at, 'worker_stop', $owner->id.'-stop', 'Stop attestation recorded by '.$owner->stop_source, $ownership, $r->id);
            }
            foreach ($attempts as $a) {
                $data = ['attempt_id' => $a->id, 'kind' => $a->kind, 'provider' => $a->provider, 'model' => $a->model, 'request_hash' => $a->request_hash,
                    'prediction_id' => $a->prediction_id, 'status' => $a->status, 'charged_credits' => (int) $a->charged_credits,
                    'cost_microusd' => $a->cost_microusd === null ? null : (int) $a->cost_microusd, 'dispatched_at' => $a->dispatched_at];
                $add($a->created_at, 'attempt_started', $a->id.'-start', $a->kind.' call started', $data, $r->id);
                if ($a->status !== 'started') $add($a->updated_at, 'attempt_outcome', $a->id.'-outcome', $a->kind.' · '.$a->status, $data, $r->id);
            }
            foreach ($events as $e) {
                $v = json_decode($e->event_json, true);
                $add($v['at'], 'worker', 'event-'.$e->id, $v['summary'] ?? $v['tool'] ?? $v['phase'], $v, $r->id);
            }
            $add($r->updated_at, 'run_state', $r->id.'-state', $r->stage, ['status' => $r->status, 'error' => self::safe($r->error)], $r->id);
        }
        foreach (DB::table('create_plan_media')->where('conversation_id', $id)->orderByDesc('updated_at')->limit(200)->get() as $m) {
            $record = json_decode($m->record_json ?? '{}', true) ?: [];
            $files = array_values(array_filter([$record['file'] ?? null, ...($record['more_files'] ?? [])]));
            $add($m->updated_at, 'plan_media', $m->id, $m->kind.' · '.$m->status,
                ['plan_id' => $m->plan_id, 'status' => $m->status, 'description' => self::safe($record['description'] ?? ''),
                    'files' => array_map(fn ($f) => array_intersect_key($f, array_flip(['asset_id', 'sha256'])), $files),
                    'master_sha256' => $record['master_sha256'] ?? null, 'character_contract' => $record['character_contract'] ?? null]);
        }
        foreach (DB::table('composition_revisions')->where('conversation_id', $id)->orderByDesc('number')->limit(100)->get() as $v) {
            $meta = json_decode($v->metadata_json ?? '{}', true) ?: [];
            $review = $meta['creative_review'] ?? [];
            $add($v->created_at, 'output', $v->id, $v->summary, ['number' => (int) $v->number, 'artifact_hash' => $v->artifact_hash,
                'conflict' => (bool) $v->conflict, 'review_status' => $review['verdict'] ?? $review['status'] ?? null,
                'review_findings' => array_map(fn ($x) => self::safe(is_string($x) ? $x : json_encode($x)), array_slice($review['findings'] ?? [], 0, 12))], $v->run_id);
        }
        usort($timeline, fn ($a, $b) => strcmp($this->utc($a['at']), $this->utc($b['at'])) ?: (($a['run_id'] === $b['run_id'] && $a['kind'] === 'worker' && $b['kind'] === 'worker') ? $a['data']['sequence'] <=> $b['data']['sequence'] : strcmp($a['id'], $b['id'])));
        return ['schema' => 1, 'conversation' => ['id' => $c->id, 'title' => self::safe($c->title), 'workspace_id' => (int) $c->workspace_id],
            'coverage' => ['older_runs_omitted' => $truncated, 'run_limit' => 20, 'message_limit' => 200, 'plan_limit' => 100,
                'note' => 'Observable events and stored receipts, not hidden model reasoning. Worker timestamps use the local worker clock. Earlier runs may lack tool history. Recorded costs include app tariffs and exclude planning usage; they are not a provider invoice. Model-reported limitations are unverified claims.'],
            'runs' => $runs, 'timeline' => $timeline];
    }
    private function utc($value): string { return \Carbon\Carbon::parse($value)->utc()->format('Y-m-d H:i:s.u'); }
}
