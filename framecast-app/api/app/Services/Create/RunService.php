<?php

namespace App\Services\Create;

use App\Services\Developer\OperationAccounting;
use Illuminate\Support\Facades\{DB, Storage};
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
            if (DB::table('composition_runs')->whereIn('status', ['running', 'cancel_requested'])->exists()
                || DB::table('composition_runs')->where('status', 'needs_attention')->whereNull('worker_stopped_at')->exists()) return null;
            $query = DB::table('composition_runs')->where('status', 'queued')->whereIn('workspace_id', config('create.workspaces', []))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('composition_runs as held')->whereColumn('held.conversation_id', 'composition_runs.conversation_id')->where('held.status', 'needs_attention'))->orderBy('created_at');
            $run = $query->lockForUpdate()->first();
            if (! $run) return null;
            $token = Str::random(64);
            DB::table('composition_runs')->where('id', $run->id)->update([
                'status' => 'running', 'stage' => 'Preparing local render', 'lease_hash' => hash('sha256', $token),
                'lease_expires_at' => now()->addSeconds(config('create.lease_seconds')), 'updated_at' => now(),
            ]);
            $input = json_decode($run->input_json, true);
            $input['input_files'] = array_map(function ($file) { unset($file['storage_path']); return $file; }, $input['input_files'] ?? []);
            return ['id' => $run->id, 'lease_token' => $token, 'input' => $input];
        });
    }

    /** Called by the authenticated host only after its sandbox has stopped. Billing remains held. */
    public function workerStopped(string $id, string $token): array
    {
        $handed = 0;
        $result = DB::transaction(function () use ($id, $token, &$handed) {
            $run = $this->leased($id, $token);
            abort_unless(in_array($run->status, ['needs_attention', 'running', 'cancel_requested'], true), 409);
            // Clips still rendering are handed to the next run while the lease is still valid (C2).
            if ($run->status !== 'needs_attention') $handed = (int) rescue(fn () => app(PlanMediaService::class)->handOverPending($run, $token), 0);
            DB::table('composition_runs')->where('id', $id)->update([
                'status' => 'needs_attention', 'worker_stopped_at' => now(), 'lease_hash' => null, 'lease_expires_at' => null,
                'stage' => 'Worker stopped; external work needs reconciliation', 'updated_at' => now(),
            ]);
            // A stopped host consumes no execution slot, but uncertain spend stays reserved.
            DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => 'needs_attention', 'capacity_slots' => 0, 'updated_at' => now()]);
            return ['status' => 'needs_attention', 'hold_retained' => true];
        });
        // Nothing left in doubt (every paid call settled, clips handed over): close the run now instead of holding it.
        if (! AttemptService::unresolved($id)) {
            $closed = rescue(fn () => app(ReconciliationService::class)->closeSettled($id, true), null, false);
            if ($closed && $handed > 0) DB::table('composition_runs')->where('id', $id)->update(['stage' => 'Your video clips are still being made by the video model', 'error' => 'Your video clips are still being made by the video model. They are kept and only charged when done: press Try again to collect them and finish the video. Nothing is bought twice.', 'updated_at' => now()]);
            if ($closed) return ['status' => 'failed', 'hold_retained' => false, 'handed_over' => $handed];
        }
        return $result;
    }

    private function leased(string $id, string $token): object
    {
        $run = DB::table('composition_runs')->where('id', $id)->lockForUpdate()->firstOrFail();
        abort_unless($run->lease_hash && hash_equals($run->lease_hash, hash('sha256', $token)), 403);
        return $run;
    }

    /** The run behind a current lease, for work done while it runs or finishes (the listening check on its export). */
    public function currentRun(string $id, string $token): object
    {
        return DB::transaction(function () use ($id, $token) {
            $run = $this->leased($id, $token);
            abort_unless(in_array($run->status, ['running', 'cancel_requested'], true) && now()->lessThan($run->lease_expires_at), 409, 'Worker lease expired.');
            return $run;
        });
    }

    public function validateResultLease(string $id, string $token): void
    {
        DB::transaction(function () use ($id, $token) {
            $run = $this->leased($id, $token);
            abort_unless($run->result_hash || (in_array($run->status, ['running', 'cancel_requested'], true)
                && now()->lessThan($run->lease_expires_at)), 409, 'Worker lease expired.');
        });
    }

    public function inputFile(string $id, string $token, int $assetId): array
    {
        $file = DB::transaction(function () use ($id, $token, $assetId) {
            $run = $this->leased($id, $token);
            abort_unless($run->status === 'running' && now()->lessThan($run->lease_expires_at)
                && in_array((int) $run->workspace_id, config('create.workspaces', []), true), 409, 'Input lease is no longer current.');
            abort_unless(\App\Models\Workspace::whereKey($run->workspace_id)->where('status', 'active')->exists(), 403);
            $input = json_decode($run->input_json, true);
            $file = collect(array_merge($input['input_files'] ?? [], $input['derived_files'] ?? []))->first(fn ($f) => (int) $f['asset_id'] === $assetId);
            abort_unless($file, 404);
            abort_unless(\App\Models\Asset::where('workspace_id', $run->workspace_id)->whereKey($assetId)->where('status', '!=', 'archived')->exists(), 404);
            return $file;
        });
        // Hash the immutable copy outside the transaction, never fetch the mutable original here.
        app(InputSnapshotService::class)->verify([$file]);
        return $file;
    }

    // 'run' is the sandbox program runner: its files may be generated from scratch, so they can have no parent.
    public const DERIVED_OPS = ['trim', 'cut', 'remove_silence', 'clean_audio', 'loudness', 'stabilize', 'speed', 'crop', 'frame', 'grade', 'duck', 'fade', 'space', 'run'];
    private const DERIVED_TYPES = ['video/mp4' => ['video', 'mp4'], 'audio/mpeg' => ['audio', 'mp3'], 'audio/x-wav' => ['audio', 'wav'], 'audio/wav' => ['audio', 'wav'], 'image/png' => ['image', 'png'], 'image/jpeg' => ['image', 'jpg'], 'image/webp' => ['image', 'webp'], 'image/svg+xml' => ['image', 'svg']];

    /**
     * A file the sandbox made from a source file (stabilised, trimmed, cleaned).
     * Stored like a private upload, listed in the library with where it came
     * from, and recorded on the run so later versions and free edits inherit it.
     */
    public function derived(string $id, string $token, \Illuminate\Http\UploadedFile $file, ?int $fromAssetId, string $op, array $params): array
    {
        abort_unless(in_array($op, self::DERIVED_OPS, true), 422, 'Unknown media operation.');
        abort_unless($fromAssetId !== null || $op === 'run', 422, 'Derived media must name its source file.');
        abort_unless($file->isValid() && $file->getSize() > 0 && $file->getSize() <= (int) config('create.input_file_bytes'), 422, 'Derived file size is not allowed.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        $type = self::DERIVED_TYPES[$mime] ?? null;
        abort_unless($type, 422, 'Derived file type is not allowed.');
        $hash = hash_file('sha256', $file->getRealPath());
        return DB::transaction(function () use ($id, $token, $file, $fromAssetId, $op, $params, $type, $mime, $hash) {
            $run = $this->leased($id, $token);
            abort_unless($run->status === 'running' && now()->lessThan($run->lease_expires_at), 409, 'Stale worker result.');
            $input = json_decode($run->input_json, true);
            $derived = $input['derived_files'] ?? [];
            if ($same = collect($derived)->firstWhere('sha256', $hash)) return $same;
            abort_if(count($derived) >= 20, 422, 'Too many derived files in one run.');
            $sources = collect(array_merge($input['input_files'] ?? [], $derived))->where('purpose', 'source');
            $parent = $fromAssetId === null ? null : $sources->first(fn ($f) => (int) $f['asset_id'] === $fromAssetId);
            abort_unless($fromAssetId === null || $parent, 422, 'Derived media must come from a source file of this run.');
            $suffix = $run->workspace_id.'/'.Str::uuid().'/'.$hash.'.'.$type[1];
            $path = 'create/uploads/'.$suffix;
            abort_unless(Storage::disk('local')->putFileAs(dirname($path), $file, basename($path)), 503, 'Could not store derived media.');
            $origin = $fromAssetId === null ? 'generated' : (\App\Models\Asset::whereKey($fromAssetId)->value('title') ?: 'media');
            $asset = \App\Models\Asset::create(['workspace_id' => $run->workspace_id, 'asset_type' => $type[0],
                'title' => mb_substr(ucfirst(str_replace('_', ' ', $op)).' · '.$origin, 0, 180), 'storage_url' => 'create-upload://'.$suffix,
                'mime_type' => $mime, 'file_size_bytes' => $file->getSize(), 'status' => 'active', 'restriction_scope' => 'workspace',
                'metadata_json' => ['derived_from_asset_id' => $fromAssetId, 'operation' => $op, 'params' => $params,
                    'create_conversation_id' => $run->conversation_id, 'composition_run_id' => $run->id]]);
            $record = ['asset_id' => (int) $asset->id, 'purpose' => 'source', 'name' => 'asset-'.$asset->id.'-'.$hash.'.'.$type[1],
                'sha256' => $hash, 'bytes' => (int) $file->getSize(), 'mime_type' => $mime, 'asset_type' => $type[0],
                'storage_path' => $path, 'duration_seconds' => null, 'transcript' => '', 'derived_from_asset_id' => $fromAssetId, 'operation' => $op];
            $input['derived_files'] = [...$derived, $record];
            DB::table('composition_runs')->where('id', $id)->update(['input_json' => json_encode($input), 'updated_at' => now()]);
            return $record;
        });
    }

    /**
     * A file the app made for this run from the approved plan (stock, AI image,
     * narration). Stored like a private upload and recorded with the run's
     * derived files, so the worker downloads it and later versions inherit it.
     */
    public function generated(string $id, string $token, string $localPath, string $title, array $meta): array
    {
        abort_unless(is_file($localPath) && filesize($localPath) > 0 && filesize($localPath) <= (int) config('create.input_file_bytes'), 422, 'Generated file size is not allowed.');
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($localPath);
        $type = self::DERIVED_TYPES[$mime] ?? null;
        abort_unless($type, 422, 'Generated file type is not allowed.');
        $hash = hash_file('sha256', $localPath);
        return DB::transaction(function () use ($id, $token, $localPath, $title, $meta, $type, $mime, $hash) {
            $run = $this->leased($id, $token);
            abort_unless($run->status === 'running' && now()->lessThan($run->lease_expires_at), 409, 'Stale worker result.');
            $input = json_decode($run->input_json, true);
            $derived = $input['derived_files'] ?? [];
            if ($same = collect($derived)->firstWhere('sha256', $hash)) return $same;
            abort_if(count($derived) >= 20, 422, 'Too many derived files in one run.');
            $suffix = $run->workspace_id.'/'.Str::uuid().'/'.$hash.'.'.$type[1];
            $path = 'create/uploads/'.$suffix;
            $stream = fopen($localPath, 'rb');
            try { abort_unless(Storage::disk('local')->put($path, $stream, ['visibility' => 'private']), 503, 'Could not store generated media.'); }
            finally { if (is_resource($stream)) fclose($stream); }
            $asset = \App\Models\Asset::create(['workspace_id' => $run->workspace_id, 'asset_type' => $type[0], 'title' => mb_substr($title, 0, 180),
                'storage_url' => 'create-upload://'.$suffix, 'mime_type' => $mime, 'file_size_bytes' => filesize($localPath), 'status' => 'active', 'restriction_scope' => 'workspace',
                'metadata_json' => [...$meta, 'create_conversation_id' => $run->conversation_id, 'composition_run_id' => $run->id]]);
            $record = ['asset_id' => (int) $asset->id, 'purpose' => 'source', 'name' => 'asset-'.$asset->id.'-'.$hash.'.'.$type[1],
                'sha256' => $hash, 'bytes' => (int) filesize($localPath), 'mime_type' => $mime, 'asset_type' => $type[0],
                'storage_path' => $path, 'duration_seconds' => null, 'transcript' => '', 'derived_from_asset_id' => null, 'operation' => 'plan_media'];
            $input['derived_files'] = [...$derived, $record];
            DB::table('composition_runs')->where('id', $id)->update(['input_json' => json_encode($input), 'updated_at' => now()]);
            return $record;
        });
    }

    /** Adds an already-stored file (a plan item bought by an earlier run) to this run. */
    public function reuseGenerated(string $id, string $token, array $record): array
    {
        return DB::transaction(function () use ($id, $token, $record) {
            $run = $this->leased($id, $token);
            abort_unless($run->status === 'running' && now()->lessThan($run->lease_expires_at), 409, 'Stale worker result.');
            abort_unless(\App\Models\Asset::where('workspace_id', $run->workspace_id)->whereKey($record['asset_id'])->where('status', '!=', 'archived')->exists(), 404);
            $input = json_decode($run->input_json, true);
            $derived = $input['derived_files'] ?? [];
            if (! collect($derived)->firstWhere('asset_id', $record['asset_id'])) {
                $input['derived_files'] = [...$derived, $record];
                DB::table('composition_runs')->where('id', $id)->update(['input_json' => json_encode($input), 'updated_at' => now()]);
            }
            return $record;
        });
    }

    public static function creativeReview(mixed $value, array $plan = [], bool $lookOnly = false): array
    {
        $value = is_array($value) ? $value : [];
        $findings = array_values(array_slice(array_map(fn ($x) => mb_substr($x, 0, 300), array_filter((array) ($value['findings'] ?? []), 'is_string')), 0, 8));
        $checks = collect(is_array($value['performance_checks'] ?? null) ? $value['performance_checks'] : [])
            ->filter(fn ($r) => is_array($r) && is_string($r['id'] ?? null) && preg_match('/^perf-[a-z0-9-]{1,35}$/', $r['id']))
            ->take(24)->map(fn ($r) => ['id' => $r['id'],
                'status' => in_array($r['status'] ?? '', ['pass', 'fail', 'unverified', 'deferred'], true) ? $r['status'] : 'unverified',
                'evidence' => mb_substr(is_string($r['evidence'] ?? null) ? $r['evidence'] : '', 0, 400),
                'source' => 'critic_interpretation'])->values()->all();
        $requirements = RequirementContract::review($value['requirement_checks'] ?? [], $plan, $lookOnly);
        $unverifiedRequirements = collect($requirements)->contains(fn ($r) => ! in_array($r['status'], ['fulfilled', 'deferred'], true));
        $unverified = collect($checks)->contains(fn ($r) => in_array($r['status'], ['fail', 'unverified'], true) || trim($r['evidence']) === '');
        // passed: a critic pass with nothing open; ready: technical checks passed, for the user's review; issues: specific
        // findings the user should see; incomplete: the checks did not pass.
        $claimed = $value['status'] ?? null;
        $status = match (true) {
            // blocked: a final check found approved content missing or a technical fault; the version needs fixing.
            $claimed === 'blocked' => 'blocked',
            $claimed === 'passed' && ! $findings && ! $unverified && ! $unverifiedRequirements => 'passed',
            $claimed === 'ready' && ! $findings => 'ready',
            in_array($claimed, ['passed', 'ready', 'issues'], true) => 'issues',
            default => 'incomplete',
        };
        return ['status' => $status, 'findings' => $findings,
            ...($requirements ? ['requirement_checks' => $requirements] : []), ...($checks ? ['performance_checks' => $checks] : [])];
    }

    /** The agent's last review scores, one per sampled frame, bounded. */
    public static function reviewScores(mixed $r): array
    {
        if (! is_array($r)) return [];
        return array_values(array_slice(array_map(fn ($x) => ['time' => round((float) ($x['time'] ?? 0), 1), 'score' => max(1, min(10, (int) ($x['score'] ?? 0))),
            'problems' => array_values(array_slice(array_map(fn ($p) => mb_substr((string) $p, 0, 120), array_filter((array) ($x['problems'] ?? []), 'is_string')), 0, 3))], array_filter($r, 'is_array')), 0, 5));
    }

    /** Shots a model refused, each with the next-best engine and its price (C3), for the user to choose. */
    public static function mediaSuggestions(mixed $s): array
    {
        return collect(is_array($s) ? $s : [])->filter(fn ($x) => is_array($x) && in_array($x['engine'] ?? null, ShotRoute::ENGINES, true))->take(8)
            ->map(fn ($x) => ['shot' => max(1, (int) ($x['shot'] ?? 1)), 'engine' => $x['engine'], 'label' => ShotRoute::label($x['engine']), 'credits' => max(0, (int) ($x['credits'] ?? 0)),
                'declined_by' => mb_substr((string) ($x['declined_by'] ?? ''), 0, 40)])->values()->all();
    }

    /** The final checks on the delivered video (todo D), reduced to known fields and bounded text. */
    public static function finalChecks(mixed $c): ?array
    {
        if (! is_array($c) || ! in_array($c['status'] ?? null, ['passed', 'issues', 'blocked', 'unverified'], true)) return null;
        $s = fn ($v, $n) => mb_substr(is_string($v) ? $v : '', 0, $n);
        return ['status' => $c['status'], 'checks' => collect(is_array($c['checks'] ?? null) ? $c['checks'] : [])->filter(fn ($x) => is_array($x))->take(24)->map(fn ($x) => [
            'id' => $s($x['id'] ?? '', 40), 'label' => $s($x['label'] ?? '', 160), 'status' => in_array($x['status'] ?? '', ['pass', 'fail', 'unverified'], true) ? $x['status'] : 'unverified',
            'blocking' => (bool) ($x['blocking'] ?? false), 'message' => $s($x['message'] ?? '', 300),
            'times' => array_values(array_slice(array_map(fn ($t) => round((float) $t, 1), array_filter((array) ($x['times'] ?? []), 'is_numeric')), 0, 6))])->values()->all()];
    }

    /** Worker-reported delivery checks, reduced to known fields and bounded text. */
    public static function deliveryChecks(mixed $c): ?array
    {
        if (! is_array($c)) return null;
        $items = fn ($list) => array_values(array_slice(array_map(fn ($f) => ['selector' => mb_substr((string) ($f['selector'] ?? ''), 0, 120),
            'time' => is_numeric($f['time'] ?? null) ? round((float) $f['time'], 2) : null, 'message' => mb_substr((string) ($f['message'] ?? ''), 0, 200)], is_array($list) ? array_filter($list, 'is_array') : []), 0, 12));
        $l = is_array($c['loudness'] ?? null) ? $c['loudness'] : [];
        $num = fn ($v) => is_numeric($v) ? round((float) $v, 1) : null;
        return ['ok' => (bool) ($c['ok'] ?? false), 'safe_area' => $items($c['safe_area'] ?? []), 'edges' => $items($c['edges'] ?? []), 'contrast' => $items($c['contrast'] ?? []),
            'pacing' => array_map(fn ($f, $raw) => [...$f, 'code' => in_array($raw['code'] ?? '', ['reading_time', 'blank_frames', 'still_stretch', 'small_text', 'mostly_empty'], true) ? $raw['code'] : 'reading_time'], $items($c['pacing'] ?? []), array_slice(array_values(array_filter(is_array($c['pacing'] ?? null) ? $c['pacing'] : [], 'is_array')), 0, 12)),
            'loudness' => ['status' => in_array($l['status'] ?? '', ['ok', 'levelled', 'silent', 'no_audio', 'check_failed'], true) ? $l['status'] : 'unknown', 'lufs' => $num($l['lufs'] ?? null), 'from' => $num($l['from'] ?? null), 'peak' => $num($l['peak'] ?? null)],
            // What the listening check heard, in plain words for Before you post.
            ...(is_array($c['audio'] ?? null) ? ['audio' => ['ok' => (bool) ($c['audio']['ok'] ?? false),
                'problems' => array_values(array_slice(array_map(fn ($p) => mb_substr($p, 0, 200), array_filter((array) ($c['audio']['problems'] ?? []), 'is_string')), 0, 6)),
                'script_coverage' => is_numeric($c['audio']['script_coverage'] ?? null) ? round((float) $c['audio']['script_coverage'], 3) : null]] : [])];
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
            abort_unless($input['mode'] === 'fixture' || ($input['mode']==='agent' && PilotPolicy::enabled()), 503, 'Paid settlement is not enabled.');
            $status = $result['status'];
            abort_if($status !== 'needs_attention' && AttemptService::unresolved($id), 409, 'External attempts require settlement before closing this run.');
            // Stop keeps the last version that passed every check: the worker may deliver it instead of cancelling.
            if ($run->status === 'cancel_requested') abort_unless(in_array($status, ['cancelled', 'needs_attention', 'preview_ready'], true), 409, 'Stop the worker before acknowledging cancellation.');
            if ($status === 'preview_ready') {
                abort_unless($artifactPath && $artifactHash, 422, 'A verified encoded preview is required.');
                $bundle = $result['bundle'];
                abort_unless(isset($bundle['index.html']), 422);
                foreach ($bundle as $name => $contents) {
                    abort_unless(preg_match('/^[a-zA-Z0-9_-]+\.(html|css|js)$/D', $name) && is_string($contents) && strlen($contents) <= (PilotPolicy::unlimited() ? 1000000 : 128000), 422, 'Invalid source bundle.');
                }
                ksort($bundle);
                $revision = (string) Str::uuid();
                $conflict = $c->head_revision_id !== $input['base_revision_id'] || (int) $c->version !== $input['version'];
                DB::table('composition_revisions')->insert([
                    'id' => $revision, 'conversation_id' => $c->id, 'run_id' => $id,
                    'number' => 1 + (int) DB::table('composition_revisions')->where('conversation_id', $c->id)->max('number'), 'parent_revision_id' => $input['base_revision_id'],
                    'metadata_json'=>json_encode(['requirements_schema'=>$input['plan']['requirements_schema'] ?? null,'requirements'=>$input['plan']['requirements'] ?? [],'look'=>(bool)($input['look_first'] ?? false),'creative_review'=>self::creativeReview($result['creative_review'] ?? null, $input['plan'] ?? [], (bool) ($input['look_first'] ?? false)),'review'=>self::reviewScores($result['review'] ?? null),'delivery_checks'=>self::deliveryChecks($result['delivery_checks'] ?? null),'final_checks'=>self::finalChecks($result['final_checks'] ?? null),'media_suggestions'=>self::mediaSuggestions($result['media_suggestions'] ?? null),'settings'=>$input['mode']==='fixture' ? array_merge($input['settings'],['output_kind'=>'video','duration_seconds'=>15,'aspect_ratio'=>'9:16']) : $input['settings'],'requested_settings'=>$input['settings'],'source_version'=>$input['version'],'attachments'=>collect($input['attachments']??[])->map(fn($a)=>(array)$a)->sortBy('asset_id')->values()->all(),'variant_group'=>$input['variant_group']??null,'variant_index'=>$input['variant_index']??null,'fixture'=>$input['mode']==='fixture','media'=>$result['media']??null]),
                    'bundle_json' => json_encode($bundle), 'bundle_hash' => hash('sha256', json_encode($bundle)),
                    'artifact_path' => $artifactPath, 'artifact_hash' => $artifactHash, 'summary' => $result['summary'],
                    'conflict' => $conflict, 'created_at' => now(),
                ]);
                if (! $conflict) DB::table('create_conversations')->where('id', $c->id)->update(['head_revision_id' => $revision, 'version' => $c->version + 1, 'updated_at' => now()]);
            }
            DB::table('composition_runs')->where('id', $id)->update([
                'status' => $status, 'stage' => mb_substr((string) $result['summary'], 0, 250), 'error' => $status === 'preview_ready' ? null : $result['summary'],
                'result_hash' => $fingerprint, 'updated_at' => now(),
            ]);
            if ($status === 'needs_attention') {
                DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => 'needs_attention', 'updated_at' => now()]);
            } else {
                OperationAccounting::close($run->operation_id);
                if (in_array($status, ['failed', 'cancelled','needs_input'], true)) DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => $status]);
            }
            return ['status' => $status, 'replayed' => false];
        });
    }
}
