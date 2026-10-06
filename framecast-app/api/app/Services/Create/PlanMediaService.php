<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;

/**
 * Buys the items an approved plan listed, one at a time, for the worker.
 * The quote already priced them from the catalogue and the user approved the
 * total. Each item is charged at that price only when it succeeds, recorded
 * against the plan, and reused by any later run of the same plan, so a failed
 * build never pays for the same image twice.
 */
class PlanMediaService
{
    /** Media bound to one plan's approval (a character master and what is made from it) is never carried to another plan. */
    public const NEVER_CARRIED = ['character_poses', 'character_variants', 'talking_shot', 'talking_take'];

    public const PRODUCTION_ONLY = ['voiceover', 'cloned_voiceover', 'music', 'sfx', 'talking_shot', 'talking_take', 'animate_image', 'character_variants', 'generated_shot', 'ugc_take'];

    public function produce(string $runId, string $lease, int $index): array
    {
        $run = DB::table('composition_runs')->where('id', $runId)->firstOrFail();
        $input = json_decode($run->input_json, true);
        $item = $input['plan_media'][$index] ?? null;
        abort_unless(is_array($item) && isset($input['plan']['plan_id']), 404, 'This run has no such plan item.');
        app(RunService::class)->validateResultLease($runId, $lease);
        abort_if(! empty($input['look_first']) && in_array($item['kind'], self::PRODUCTION_ONLY, true), 422, 'Audio and motion are deferred until the full video build.');
        if (empty($input['look_first']) && collect($input['plan_media'])->contains(fn ($m) => in_array($m['kind'], ['character_poses', 'character_variants', 'talking_shot', 'talking_take', 'reference_sheet'], true) || ShotRoute::usesSheet($m))) CharacterApproval::requireApproved($input['plan'], $input['settings'], (int) $run->workspace_id, $input['input_files'] ?? []);
        abort_if($item['kind'] === 'character_poses' && ($item['character_contract'] ?? '') !== CharacterApproval::CONTRACT, 409, 'The character workflow changed. Review a fresh storyboard quote.');
        $planId = $input['plan']['plan_id'];
        $cacheIndex = (int) ($item['plan_item_index'] ?? $index);
        $context = $this->context($run, $input);
        $context['task_requirements'] = $item['requirements'] ?? [];
        // Legacy quotes keep their pre-approved lip-sync route and price.
        if (in_array($item['kind'], ['talking_shot', 'talking_take'], true)) $context['talking_route'] = isset($item['speech_mode'])
            ? $item : ['speech_mode' => 'legacy_lipsync', 'seconds' => $item['kind'] === 'talking_take' ? 15 : 4];
        if ($item['kind'] === 'voiceover' && collect($input['plan_media'] ?? [])->contains(fn ($m) => $m['kind'] === 'talking_shot' && ($m['speech_mode'] ?? '') === 'native')) {
            $context['narration'] = array_slice($context['narration'], 1);
        }
        if (in_array($item['kind'], ['character_variants', 'talking_shot', 'talking_take'], true) || ($item['kind'] === 'animate_image' && ($item['subject'] ?? '') === 'approved_character')) {
            $approved = CharacterApproval::requireApproved($input['plan'], $input['settings'], (int) $run->workspace_id, $input['input_files'] ?? []);
            if ($item['kind'] === 'character_variants' || $item['kind'] === 'animate_image') abort_unless(hash_equals($approved['files'][0]['sha256'], (string) ($item['master_sha256'] ?? '')), 409, 'The approved character changed. Review a fresh quote.');
            $context['animation_subject'] = $item['subject'] ?? 'source';
            $context['approved_character_media_id'] = $approved['media_id'];
            $context['approved_character_files'] = $approved['files'];
        }
        // Generated video: the approved sheet's files and the user's avatar are its references.
        if (in_array($item['kind'], ['generated_shot', 'ugc_take'], true)) {
            if (ShotRoute::usesSheet($item)) {
                $approved = CharacterApproval::requireApproved($input['plan'], $input['settings'], (int) $run->workspace_id, $input['input_files'] ?? []);
                abort_unless(hash_equals(ShotRoute::inputsSha($item, $approved['files'], $approved['names']), (string) ($item['sheet_sha256'] ?? '')), 409, 'The approved images this clip uses changed. Review a fresh quote.');
                // Each approved image with its label (a cast subject, or "Panel n"); a file's own "name" is its stored file name.
                $context['sheet_files'] = array_map(fn ($f, $k) => ['label' => $approved['names'][$k] ?? null] + $f, $approved['files'], array_keys($approved['files']));
            }
        }
        // Each person or place on the sheet is its own drawing: one already drawn the same way in this creation is kept,
        // so changing one redraws only that one.
        if ($item['kind'] === 'reference_sheet') $context['prior_subjects'] = Storyboard::priorSubjects((string) $run->conversation_id);
        if ($item['kind'] === 'storyboard') {
            // Panels are drawn only from the character the user approved (its own step); their identity includes it.
            if (($input['step'] ?? null) === 'storyboard') CharacterApproval::requireCharacter($input['plan'], $input['settings'], (int) $run->workspace_id, $input['input_files'] ?? []);
            $cast = Storyboard::cast($planId);
            abort_unless($cast, 409, 'The cast is not ready, so the storyboard cannot be drawn yet.');
            $item['cast_sha256'] = Storyboard::castSha($cast);
            $context['cast'] = $cast;
            $context['prior_panels'] = Storyboard::prior((string) $run->conversation_id);
            $context['agreement'] = $input['plan']['agreement'] ?? [];
        }
        if (in_array($item['kind'], ShotRoute::KINDS, true)) $context['shot'] = array_intersect_key($item, array_flip(ShotRoute::ROUTE_KEYS));
        $hash = CharacterApproval::mediaHash($item, $context);

        $done = DB::table('create_plan_media')->where('plan_id', $planId)->where('item_index', $cacheIndex)->first();
        // Unchanged work carries across plans of the same creation: identical inputs (the hash) mean the same media, so a
        // new plan reuses it instead of buying it again. A cast change changes the panels' hash, so they are redrawn.
        if ((! $done || $done->status !== 'succeeded' || $done->description_hash !== $hash) && ! in_array($item['kind'], self::NEVER_CARRIED, true)
            && ($prior = DB::table('create_plan_media')->where('conversation_id', $run->conversation_id)->where('kind', $item['kind'])->where('description_hash', $hash)->where('status', 'succeeded')->where('plan_id', '!=', $planId)->orderByDesc('updated_at')->first())) {
            $this->record($run, $planId, $cacheIndex, $item, $hash, 'succeeded', json_decode((string) $prior->record_json, true), 0, null);
            $done = DB::table('create_plan_media')->where('plan_id', $planId)->where('item_index', $cacheIndex)->first();
        }
        if ($done && $done->status === 'succeeded' && $done->description_hash === $hash) {
            $record = json_decode($done->record_json, true);
            $record['task_id'] = $item['id'] ?? null;
            $record['requirement_ids'] = $item['requirement_ids'] ?? [];
            if (! empty($record['file'])) $record['file'] = app(RunService::class)->reuseGenerated($runId, $lease, $record['file']);
            foreach ($record['more_files'] ?? [] as $k => $f) $record['more_files'][$k] = app(RunService::class)->reuseGenerated($runId, $lease, $f);
            return [...$record, 'reused' => true, 'charged_credits' => 0];
        }

        abort_if(isset($item['reuse_media_id']), 409, 'Previously purchased media changed. Review a fresh quote instead of purchasing it again.');
        abort_if($done && $done->status === 'unknown', 409, 'This plan item has an unresolved provider outcome. Reconcile it before purchasing again.');
        if (in_array($item['kind'], ['generated_shot', 'ugc_take'], true)) return $this->produceGenerated($run, $runId, $lease, $index, $item, $planId, $cacheIndex, $context, $hash, $done);
        $attempts = app(AttemptService::class);
        $attempt = $attempts->begin($runId, $lease, 'plan-media-'.$index, 'plan_media', $hash);
        abort_unless($attempt['may_execute'], 409, 'This item was already attempted in this run.');
        $dir = Storage::disk('local')->path('create/media-attempts/'.$attempt['id']);
        mkdir($dir, 0700, true);
        $recorded = false;
        $providerStarted = false;
        try {
            try {
                if (in_array($item['kind'], ['character_poses', 'reference_sheet'], true)) $context['character_style_images'] = app(References\ReferenceSheets::class)->characterStyleImages($input['input_files'] ?? [], $dir);
                $providerStarted = true;
                // A request that never connected (the host did not resolve, the connection was refused) reached no
                // provider: try once more, and if it still cannot connect it is a plain failure, not an unknown outcome.
                for ($try = 1; ; $try++) {
                    try { $made = app(PlanMediaExecutor::class)->produce($item['kind'], $item['description'], $context, $dir); break; }
                    catch (\Illuminate\Http\Client\ConnectionException $e) {
                        if (! self::neverConnected($e, $item['kind']) || $try > count(NetRetry::WAITS)) throw $e;
                        \Illuminate\Support\Sleep::for(NetRetry::WAITS[$try - 1])->seconds();
                    }
                }
            } catch (\Throwable $e) {
                report($e);
                if (self::neverConnected($e, $item['kind'])) $providerStarted = false;
                // Lack of usable output is not evidence that a generation was unbilled.
                // Keep the reservation and stop; a new run must not repurchase this item blindly.
                // A sheet is a few cheap images: one that fails partway is a plain failure (the user pays only for a
                // delivered sheet; images already made are our cost), not a hold that blocks the build.
                // Output made but lost on the way to us is a known outcome: a plain failure, never a hold.
                if ($providerStarted && ! $e instanceof OutputUnavailable && ! in_array($item['kind'], ['stock_video', 'stock_image', 'brand_kit', 'reference_sheet', 'storyboard'], true)) {
                    $attempts->settle($runId, $lease, $attempt['id'], ['status' => 'unknown']);
                    $this->record($run, $planId, $cacheIndex, $item, $hash, 'unknown', null, 0, 'Provider outcome requires reconciliation.');
                    abort(409, 'Media outcome needs reconciliation; no automatic retry or replacement purchase.');
                }
                $id = 'pm-failed-'.Str::uuid();
                $attempts->bindPrediction($runId, $lease, $attempt['id'], $id);
                $receipt = new VerifiedAttemptReceipt($attempt['id'], 'failed', $id, 0, 'pilot-tariff:catalogue; not produced');
                $attempts->settle($runId, $lease, $attempt['id'], $receipt->result(), $receipt);
                $message = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface || $e instanceof \RuntimeException ? $e->getMessage() : 'The provider did not return a result.';
                $this->record($run, $planId, $cacheIndex, $item, $hash, 'failed', null, 0, mb_substr($message, 0, 280));
                $recorded = true;
                return ['task_id' => $item['id'] ?? null, 'requirement_ids' => $item['requirement_ids'] ?? [], 'kind' => $item['kind'], 'description' => $item['description'], 'status' => 'failed', 'error' => $message, 'charged_credits' => 0];
            }
            // Keep completed output across a crash between provider completion and asset/ledger writes.
            file_put_contents($dir.'/completed.json', json_encode($made, JSON_THROW_ON_ERROR));
            $file = $made['path'] !== '' ? app(RunService::class)->generated($runId, $lease, $made['path'], $made['title'],
                ['plan_media' => ['plan_id' => $planId, 'index' => $index, 'kind' => $item['kind'], 'description' => $item['description']], 'provider_id' => $made['provider_id']]) : null;
            // Items that make several files (a pose sheet) store each one.
            $more = [];
            foreach ($made['extra'] ?? [] as $x) $more[] = app(RunService::class)->generated($runId, $lease, $x['path'], $x['title'],
                ['plan_media' => ['plan_id' => $planId, 'index' => $index, 'kind' => $item['kind'], 'pose' => $x['pose'] ?? null], 'provider_id' => $made['provider_id']]);
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '-', $made['provider_id']);
            $attempts->bindPrediction($runId, $lease, $attempt['id'], substr($id.'-'.substr($attempt['id'], 0, 8), 0, 160));
            // An item that reports what it actually made (a storyboard reusing unchanged panels) is charged for that, never above the quote.
            $credits = isset($made['credits']) ? min((int) $item['credits'], (int) $made['credits']) : (int) $item['credits'];
            $receipt = new VerifiedAttemptReceipt($attempt['id'], 'succeeded', substr($id.'-'.substr($attempt['id'], 0, 8), 0, 160), $credits * 4000,
                'pilot-tariff:catalogue; '.$item['kind'].' at its listed price of '.$credits.' credits');
            $settled = $attempts->settle($runId, $lease, $attempt['id'], $receipt->result(), $receipt);
            $record = ['task_id' => $item['id'] ?? null, 'requirement_ids' => $item['requirement_ids'] ?? [], 'kind' => $item['kind'], 'description' => $item['description'], 'status' => 'succeeded', 'file' => $file, 'brand' => $made['brand'] ?? null, 'cues' => $made['cues'] ?? null,
                'more_files' => $more ?: null, 'poses' => $made['poses'] ?? null, 'line' => $made['line'] ?? null, 'speech_mode' => $made['speech_mode'] ?? 'audio_driven', 'engine' => $made['engine'] ?? null,
                'character_contract' => $made['character_contract'] ?? null, 'master_sha256' => $made['master_sha256'] ?? null,
                ...array_intersect_key($made, array_flip(['panel_hashes', 'panel_checks', 'subject_keys']))];
            $this->record($run, $planId, $cacheIndex, $item, $hash, 'succeeded', $record, (int) $settled['charged_credits'], null);
            $recorded = true;
            return [...$record, 'reused' => false, 'charged_credits' => (int) $settled['charged_credits']];
        } finally {
            // Uncertain attempts retain their local files for offline recovery; no automatic re-purchase.
            if ($recorded) { foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
        }
    }

    /**
     * Generated shots and takes run for minutes at the provider, longer than any one request may wait. Every
     * provider job (a shot, or each segment of a take) is recorded the moment it is submitted; each later call
     * starts any job not yet running, checks the rest once, restarts a failed job once, and when all are done stores
     * the clip and charges it like any other item. A pending item from a stopped run is collected, not bought again.
     */
    private function produceGenerated(object $run, string $runId, string $lease, int $index, array $item, string $planId, int $cacheIndex, array $context, string $hash, ?object $done): array
    {
        $attempts = app(AttemptService::class);
        $executor = app(PlanMediaExecutor::class);
        $base = ['task_id' => $item['id'] ?? null, 'requirement_ids' => $item['requirement_ids'] ?? [], 'kind' => $item['kind'], 'description' => $item['description'], 'engine' => $item['engine'] ?? null];
        $pending = $done && $done->status === 'pending' && $done->description_hash === $hash ? json_decode((string) $done->record_json, true) : null;
        // Records from before per-job tracking listed every started prediction under "predictions".
        if ($pending && ! isset($pending['jobs'])) $pending['jobs'] = array_values($pending['predictions'] ?? []);
        $fresh = false;
        if (! $pending || ($pending['run_id'] ?? null) !== $runId) {
            $attempt = $attempts->begin($runId, $lease, 'plan-media-'.$index, 'plan_media', $hash);
            abort_unless($attempt['may_execute'], 409, 'This item was already attempted in this run.');
            // Adopt the jobs a stopped run started (collected here, not bought again), or start this item's own.
            $pending = $pending ? [...$pending, 'attempt_id' => $attempt['id'], 'run_id' => $runId]
                : ['jobs' => array_fill(0, $executor->jobCount($item['kind'], $context), null), 'restarts' => [], 'start_failures' => [], 'engine' => $item['engine'] ?? null,
                    'attempt_id' => $attempt['id'], 'run_id' => $runId, 'started_at' => now()->toIso8601String()];
            $this->record($run, $planId, $cacheIndex, $item, $hash, 'pending', $pending, 0, null);
            $fresh = true;
        }
        // By reference: each save writes the job list as it stands now, not as it was when the closure was made.
        $save = function () use (&$pending, $run, $planId, $cacheIndex, $item, $hash) { $this->record($run, $planId, $cacheIndex, $item, $hash, 'pending', $pending, 0, null); };
        $waiting = [...$base, 'status' => 'pending', 'charged_credits' => 0, 'started_at' => $pending['started_at'] ?? null];
        $fail = function (string $message) use (&$pending, $executor, $run, $runId, $lease, $planId, $cacheIndex, $item, $hash, $base) {
            // Jobs still running are stopped: nothing more is billed for an item that will not be delivered.
            foreach ($pending['jobs'] as $id) if ($id) $executor->cancelJob($id);
            return $this->failGenerated($run, $runId, $lease, $pending['attempt_id'], $planId, $cacheIndex, $item, $hash, $message, $base);
        };

        // Start every job not yet running, recording each id the moment the provider returns it.
        foreach ($pending['jobs'] as $k => $id) {
            if ($id) continue;
            try { $pending['jobs'][$k] = $executor->startJob($item['kind'], $item['description'], $context, $k); $save(); }
            catch (\Throwable $e) {
                report($e);
                // A refused start made nothing; a start that may have reached the provider cannot be repeated blindly.
                $unsent = ($e instanceof \RuntimeException && ! $e instanceof \Illuminate\Http\Client\ConnectionException) || self::neverConnected($e, 'generated_shot');
                if (! $unsent) { $pending['uncertain'][] = $k; $save(); abort(409, 'A provider job may have started without its id being recorded. Reconcile it before continuing.'); }
                $pending['start_failures'][$k] = ($pending['start_failures'][$k] ?? 0) + 1;
                $save();
                if ($pending['start_failures'][$k] >= 2) return $fail($e->getMessage());
            }
        }
        if ($fresh) return $waiting;

        // Check each job once; a job that failed (not a moderation refusal) is restarted once.
        $urls = []; $running = false;
        foreach ($pending['jobs'] as $k => $id) {
            if (! $id) { $running = true; continue; }
            try { $state = $executor->pollJob($id); }
            catch (\Illuminate\Http\Client\ConnectionException) { $running = true; continue; }
            if ($state['status'] === 'running') { $running = true; continue; }
            if ($state['status'] === 'failed') {
                // Refused by the model's moderation: offer the next-best engine with its price (C3), never retry silently.
                if ($state['declined'] && $item['kind'] === 'generated_shot') {
                    // The shot's own inputs are known to exist (they were approved); only the engine changes.
                    $suggest = ShotRoute::fallback($item, ['has_avatar' => in_array('avatar', (array) ($item['refs'] ?? []), true) || ($item['first_frame'] ?? '') === 'avatar', 'has_sheet' => true,
                        'aspect_ratio' => json_decode((string) $run->input_json, true)['settings']['aspect_ratio'] ?? '9:16',
                        'subjects' => array_values(array_diff((array) ($item['refs'] ?? []), ['avatar', 'sheet'])), 'panels' => [(string) ($item['first_frame'] ?? '')]]);
                    $result = $fail($state['error']);
                    if ($suggest) DB::table('create_plan_media')->where('plan_id', $planId)->where('item_index', $cacheIndex)->update(['record_json' => json_encode(['declined' => true, 'engine' => $item['engine'] ?? null, 'suggest' => $suggest])]);
                    return $suggest ? [...$result, 'declined' => true, 'suggest' => $suggest] : $result;
                }
                if ($state['declined'] || ($pending['restarts'][$k] ?? 0) >= 1) return $fail($state['error']);
                $pending['restarts'][$k] = ($pending['restarts'][$k] ?? 0) + 1;
                $pending['failed_jobs'][] = $id;
                $pending['jobs'][$k] = null;
                $save();
                $running = true;
                continue;
            }
            $urls[$k] = $state['url'];
            // What this job cost us, from the provider's own metrics; recorded beside the credit price, never charged.
            $seg = ($context['shot']['segments'] ?? [])[$k]['seconds'] ?? ($context['shot']['seconds'] ?? 0);
            $pending['costs'][$k] = ShotRoute::providerUsd((string) ($pending['engine'] ?? $item['engine'] ?? ''), $state['metrics'] ?? [], (float) $seg);
            // Whether the provider reported the output's length, or the requested length stands in for it.
            $pending['measured'][$k] = isset(($state['metrics'] ?? [])['video_output_duration_seconds']);
        }
        if ($running) return $waiting;

        $dir = Storage::disk('local')->path('create/media-attempts/'.$pending['attempt_id']);
        if (! is_dir($dir)) mkdir($dir, 0700, true);
        try { ksort($urls); $made = $executor->finishGenerated($item['kind'], $item['description'], $context, $dir, $urls, $pending['jobs'], (string) ($pending['engine'] ?? $item['engine'] ?? '')); }
        catch (\Throwable $e) { report($e); return $fail($e->getMessage()); }
        $file = app(RunService::class)->generated($runId, $lease, $made['path'], $made['title'],
            ['plan_media' => ['plan_id' => $planId, 'index' => $index, 'kind' => $item['kind'], 'description' => $item['description']], 'provider_id' => $made['provider_id']]);
        $id = substr(preg_replace('/[^a-zA-Z0-9_-]/', '-', $made['provider_id']).'-'.substr($pending['attempt_id'], 0, 8), 0, 160);
        $attempts->bindPrediction($runId, $lease, $pending['attempt_id'], $id);
        $credits = (int) $item['credits'];
        $receipt = new VerifiedAttemptReceipt($pending['attempt_id'], 'succeeded', $id, $credits * 4000, 'pilot-tariff:catalogue; '.$item['kind'].' on '.($made['engine'] ?? '?').' at its listed price of '.$credits.' credits');
        $settled = $attempts->settle($runId, $lease, $pending['attempt_id'], $receipt->result(), $receipt);
        $record = [...$base, 'status' => 'succeeded', 'file' => $file, 'line' => $made['line'] ?? null, 'speech_mode' => $made['speech_mode'] ?? 'audio_driven', 'engine' => $made['engine'] ?? null,
            'jobs' => $pending['jobs'], 'failed_jobs' => $pending['failed_jobs'] ?? [], ...(isset($made['speech_check']) ? ['speech_check' => $made['speech_check']] : []),
            'provider_cost_usd' => in_array(null, $pending['costs'] ?? [null], true) ? null : round(array_sum($pending['costs']), 4), 'cost_basis' => in_array(null, $pending['costs'] ?? [null], true) ? 'unknown'
                : (in_array(false, $pending['measured'] ?? [false], true) ? 'estimate: requested seconds × list price' : 'estimate: measured output seconds × list price')];
        $this->record($run, $planId, $cacheIndex, $item, $hash, 'succeeded', $record, (int) $settled['charged_credits'], null);
        foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir);
        return [...$record, 'reused' => false, 'charged_credits' => (int) $settled['charged_credits']];
    }

    /**
     * A run stopping while its clips render (C2): each generated item whose jobs are all on record is settled as
     * handed over (nothing charged here) and stays pending, so the next run collects it instead of buying it again.
     * An item with a job that may have started unrecorded keeps its hold. Returns how many were handed over.
     */
    public function handOverPending(object $run, string $lease): int
    {
        $attempts = app(AttemptService::class); $n = 0;
        $pending = DB::table('create_plan_media')->where('conversation_id', $run->conversation_id)->where('status', 'pending')->get()
            ->keyBy(fn ($r) => (string) (json_decode((string) $r->record_json, true)['attempt_id'] ?? ''));
        foreach (DB::table('composition_attempts')->where('run_id', $run->id)->where('kind', 'plan_media')->where('status', 'started')->get() as $a) {
            $row = $pending[$a->id] ?? null;
            $p = $row ? json_decode((string) $row->record_json, true) : null;
            if (! $p || ! empty($p['uncertain'])) continue;
            $id = 'pm-handover-'.substr($a->id, 0, 8);
            $attempts->bindPrediction($run->id, $lease, $a->id, $id);
            $receipt = new VerifiedAttemptReceipt($a->id, 'failed', $id, 0, 'pilot-tariff:catalogue; handed over: jobs '.implode(',', array_filter((array) ($p['jobs'] ?? []))).' are recorded on the pending plan item; the next run collects them');
            $attempts->settle($run->id, $lease, $a->id, $receipt->result(), $receipt);
            $n++;
        }
        return $n;
    }

    private function failGenerated(object $run, string $runId, string $lease, string $attemptId, string $planId, int $cacheIndex, array $item, string $hash, string $message, array $base): array
    {
        $attempts = app(AttemptService::class);
        $id = 'pm-failed-'.Str::uuid();
        $attempts->bindPrediction($runId, $lease, $attemptId, $id);
        $receipt = new VerifiedAttemptReceipt($attemptId, 'failed', $id, 0, 'pilot-tariff:catalogue; not produced');
        $attempts->settle($runId, $lease, $attemptId, $receipt->result(), $receipt);
        $this->record($run, $planId, $cacheIndex, $item, $hash, 'failed', null, 0, mb_substr($message, 0, 280));
        return [...$base, 'status' => 'failed', 'error' => $message, 'charged_credits' => 0];
    }

    /**
     * A purchase the agent decided on mid-build, within the approved media ceiling.
     * The item is appended to the run's plan list and bought like any other.
     */
    public function produceAdHoc(string $runId, string $lease, string $kind, string $description, array $requirementIds = []): array
    {
        $run = DB::table('composition_runs')->where('id', $runId)->firstOrFail();
        app(RunService::class)->validateResultLease($runId, $lease);
        $input = json_decode($run->input_json, true);
        abort_if(count($requirementIds) > 24 || count(array_filter($requirementIds, 'is_string')) !== count($requirementIds) || array_diff($requirementIds, array_column($input['plan']['requirements'] ?? [], 'id')) !== [], 422, 'Only requirements in the approved plan may be linked to a purchase.');
        abort_unless(in_array($kind, PlanMediaExecutor::KINDS, true), 422, 'That kind of media cannot be bought.');
        abort_if(in_array($kind, ['character_poses', 'character_variants'], true), 422, 'Character design changes need a storyboard plan and approval. Do not purchase a replacement character during a build.');
        abort_if(in_array($kind, ShotRoute::KINDS, true), 422, 'Generated shots, takes and sheets are planned and approved with the plan, not bought during a build. Use what was bought, or report the gap.');
        abort_if(! empty($input['look_first']) && in_array($kind, self::PRODUCTION_ONLY, true), 422, 'Audio and motion are deferred until the full video build.');
        $credits = $kind === 'music' ? CapabilityCatalogue::musicCredits((int) ($input['settings']['duration_seconds'] ?? 15)) : CapabilityCatalogue::credits($kind, (int) $run->workspace_id);
        abort_unless(is_int($credits) && $credits > 0, 422, 'That item is not for sale here.');
        abort_if($kind === 'cloned_voiceover' && ($input['plan']['voice'] ?? null) !== 'clone', 422, 'Select a cloned voice explicitly before generating cloned speech.');
        if (in_array($kind, ['talking_shot', 'talking_take'], true)) CharacterApproval::requireApproved($input['plan'], $input['settings'], (int) $run->workspace_id, $input['input_files'] ?? []);
        $talkingRoute = in_array($kind, ['talking_shot', 'talking_take'], true) ? TalkingPresenter::route($kind, $input['plan']['voice'] ?? null, (int) ($input['settings']['duration_seconds'] ?? 15)) : [];
        $credits = $talkingRoute['credits'] ?? $credits;
        $ceiling = (int) ($input['execution_policy']['plan_media']['total_credits'] ?? 0);
        $spent = (int) DB::table('composition_attempts')->where('run_id', $runId)->where('kind', 'plan_media')->sum('charged_credits');
        abort_if($spent + $credits > $ceiling, 402, 'Over the approved media ceiling ('.$spent.' of '.$ceiling.' credits used; this item is '.$credits.'). Propose it to the user instead.');
        $items = $input['plan_media'] ?? [];
        $nextIndex = max(count($input['plan']['media'] ?? []), max(array_merge([-1], array_column($items, 'plan_item_index'))) + 1);
        $items[] = ['id' => 'task-'.substr(hash('sha256', $runId.'|'.$nextIndex), 0, 20), 'requirement_ids' => array_values(array_unique($requirementIds)), 'requirements' => RequirementContract::targets(['requirement_ids' => $requirementIds], $input['plan'] ?? []), 'plan_item_index' => $nextIndex, 'kind' => $kind, 'description' => mb_substr(trim($description), 0, 200), 'credits' => $credits, 'ad_hoc' => true, ...$talkingRoute];
        $input['plan_media'] = $items;
        DB::table('composition_runs')->where('id', $runId)->update(['input_json' => json_encode($input), 'updated_at' => now()]);
        return $this->produce($runId, $lease, count($items) - 1);
    }

    /** Items that make one prediction: if creating it never connected, nothing billable exists. */
    private const SINGLE_PREDICTION = ['music', 'generated_shot', 'animate_image', 'ai_image', 'talking_shot', 'talking_take'];

    /**
     * cURL could not resolve or connect while CREATING a prediction (or uploading its input), so no prediction exists.
     * A failure while polling (/predictions/{id}) means one does; items making several predictions may have made some.
     */
    public static function neverConnected(\Throwable $e, string $kind): bool
    {
        return $e instanceof \Illuminate\Http\Client\ConnectionException && in_array($kind, self::SINGLE_PREDICTION, true)
            && (bool) preg_match('/cURL error (6|7):.* for https:\/\/api\.replicate\.com\/v1\/(models\/[^\s\/]+\/[^\s\/]+\/predictions|predictions|files)\s*$/s', $e->getMessage());
    }

    private function context(object $run, array $input): array
    {
        // A layered rig SVG is placed by the build, not sent to an image model as a photo reference.
        // The user's own images only: media an earlier run generated (a cast, panels) is inherited as a source too, and
        // must never stand in for "your photo".
        $images = collect($input['input_files'] ?? [])->where('purpose', 'source')->where('asset_type', 'image')->filter(fn ($f) => ($f['mime_type'] ?? '') !== 'image/svg+xml' && empty($f['operation']))
            ->map(fn ($f) => Storage::disk('local')->path($f['storage_path']))->filter(fn ($p) => is_file($p))->values()->all();
        return ['character_style' => $input['plan']['character_style'] ?? '', 'workspace_id' => (int) $run->workspace_id, 'aspect_ratio' => $input['settings']['aspect_ratio'] ?? '9:16',
            'language' => $input['settings']['language'] ?? 'en', 'approved_copy' => $input['plan']['on_screen_copy'] ?? [], 'source_images' => $images, 'source_files' => $input['input_files'] ?? [],
            'narration' => $input['plan']['narration'] ?? [], 'voice' => $input['plan']['voice'] ?? null, 'duration_seconds' => (int) ($input['settings']['duration_seconds'] ?? 15),
            // Items made from earlier items (the talking shot) find them by the plan.
            'plan_id' => $input['plan']['plan_id'] ?? null];
    }

    private function record(object $run, string $planId, int $index, array $item, string $hash, string $status, ?array $record, int $credits, ?string $error): void
    {
        DB::table('create_plan_media')->updateOrInsert(['plan_id' => $planId, 'item_index' => $index], [
            'id' => DB::table('create_plan_media')->where('plan_id', $planId)->where('item_index', $index)->value('id') ?? (string) Str::uuid(),
            'conversation_id' => $run->conversation_id, 'kind' => $item['kind'], 'description_hash' => $hash, 'status' => $status,
            'asset_id' => $record['file']['asset_id'] ?? null, 'record_json' => $record ? json_encode($record) : null, 'run_id' => $run->id,
            'charged_credits' => $credits, 'error' => $error, 'created_at' => now(), 'updated_at' => now()]);
    }
}
