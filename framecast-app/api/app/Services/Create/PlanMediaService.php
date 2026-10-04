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
    public const PRODUCTION_ONLY = ['voiceover', 'cloned_voiceover', 'music', 'sfx', 'talking_shot', 'talking_take', 'animate_image', 'character_variants', 'generated_shot', 'ugc_take'];

    public function produce(string $runId, string $lease, int $index): array
    {
        $run = DB::table('composition_runs')->where('id', $runId)->firstOrFail();
        $input = json_decode($run->input_json, true);
        $item = $input['plan_media'][$index] ?? null;
        abort_unless(is_array($item) && isset($input['plan']['plan_id']), 404, 'This run has no such plan item.');
        app(RunService::class)->validateResultLease($runId, $lease);
        abort_if(! empty($input['look_first']) && in_array($item['kind'], self::PRODUCTION_ONLY, true), 422, 'Audio and motion are deferred until the full video build.');
        if (empty($input['look_first']) && collect($input['plan_media'])->contains(fn ($m) => in_array($m['kind'], ['character_poses', 'character_variants', 'talking_shot', 'talking_take', 'reference_sheet'], true) || ShotRoute::usesSheet($m))) CharacterApproval::requireApproved($input['plan'], $input['settings'], (int) $run->workspace_id);
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
            $approved = CharacterApproval::requireApproved($input['plan'], $input['settings'], (int) $run->workspace_id);
            if ($item['kind'] === 'character_variants' || $item['kind'] === 'animate_image') abort_unless(hash_equals($approved['files'][0]['sha256'], (string) ($item['master_sha256'] ?? '')), 409, 'The approved character changed. Review a fresh quote.');
            $context['animation_subject'] = $item['subject'] ?? 'source';
            $context['approved_character_media_id'] = $approved['media_id'];
            $context['approved_character_files'] = $approved['files'];
        }
        // Generated video: the approved sheet's files and the user's avatar are its references.
        if (in_array($item['kind'], ['generated_shot', 'ugc_take'], true)) {
            if (ShotRoute::usesSheet($item)) {
                $approved = CharacterApproval::requireApproved($input['plan'], $input['settings'], (int) $run->workspace_id);
                abort_unless(hash_equals(hash('sha256', implode('|', array_column($approved['files'], 'sha256'))), (string) ($item['sheet_sha256'] ?? '')), 409, 'The approved sheet changed. Review a fresh quote.');
                $context['sheet_files'] = array_map(fn ($f, $k) => $f + ['name' => $approved['names'][$k] ?? null], $approved['files'], array_keys($approved['files']));
            }
        }
        if (in_array($item['kind'], ShotRoute::KINDS, true)) $context['shot'] = array_intersect_key($item, array_flip(ShotRoute::ROUTE_KEYS));
        $hash = CharacterApproval::mediaHash($item, $context);

        $done = DB::table('create_plan_media')->where('plan_id', $planId)->where('item_index', $cacheIndex)->first();
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
                $made = app(PlanMediaExecutor::class)->produce($item['kind'], $item['description'], $context, $dir);
            } catch (\Throwable $e) {
                report($e);
                // Lack of usable output is not evidence that a generation was unbilled.
                // Keep the reservation and stop; a new run must not repurchase this item blindly.
                if ($providerStarted && ! in_array($item['kind'], ['stock_video', 'stock_image', 'brand_kit'], true)) {
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
            $credits = (int) $item['credits'];
            $receipt = new VerifiedAttemptReceipt($attempt['id'], 'succeeded', substr($id.'-'.substr($attempt['id'], 0, 8), 0, 160), $credits * 4000,
                'pilot-tariff:catalogue; '.$item['kind'].' at its listed price of '.$credits.' credits');
            $settled = $attempts->settle($runId, $lease, $attempt['id'], $receipt->result(), $receipt);
            $record = ['task_id' => $item['id'] ?? null, 'requirement_ids' => $item['requirement_ids'] ?? [], 'kind' => $item['kind'], 'description' => $item['description'], 'status' => 'succeeded', 'file' => $file, 'brand' => $made['brand'] ?? null, 'cues' => $made['cues'] ?? null,
                'more_files' => $more ?: null, 'poses' => $made['poses'] ?? null, 'line' => $made['line'] ?? null, 'speech_mode' => $made['speech_mode'] ?? 'audio_driven', 'engine' => $made['engine'] ?? null,
                'character_contract' => $made['character_contract'] ?? null, 'master_sha256' => $made['master_sha256'] ?? null];
            $this->record($run, $planId, $cacheIndex, $item, $hash, 'succeeded', $record, (int) $settled['charged_credits'], null);
            $recorded = true;
            return [...$record, 'reused' => false, 'charged_credits' => (int) $settled['charged_credits']];
        } finally {
            // Uncertain attempts retain their local files for offline recovery; no automatic re-purchase.
            if ($recorded) { foreach (glob($dir.'/*') ?: [] as $f) @unlink($f); @rmdir($dir); }
        }
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
        abort_if(! empty($input['look_first']) && in_array($kind, self::PRODUCTION_ONLY, true), 422, 'Audio and motion are deferred until the full video build.');
        $credits = $kind === 'music' ? CapabilityCatalogue::musicCredits((int) ($input['settings']['duration_seconds'] ?? 15)) : CapabilityCatalogue::credits($kind, (int) $run->workspace_id);
        abort_unless(is_int($credits) && $credits > 0, 422, 'That item is not for sale here.');
        abort_if($kind === 'cloned_voiceover' && ($input['plan']['voice'] ?? null) !== 'clone', 422, 'Select a cloned voice explicitly before generating cloned speech.');
        if (in_array($kind, ['talking_shot', 'talking_take'], true)) CharacterApproval::requireApproved($input['plan'], $input['settings'], (int) $run->workspace_id);
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

    private function context(object $run, array $input): array
    {
        // A layered rig SVG is placed by the build, not sent to an image model as a photo reference.
        $images = collect($input['input_files'] ?? [])->where('purpose', 'source')->where('asset_type', 'image')->filter(fn ($f) => ($f['mime_type'] ?? '') !== 'image/svg+xml')
            ->map(fn ($f) => Storage::disk('local')->path($f['storage_path']))->filter(fn ($p) => is_file($p))->values()->all();
        return ['character_style' => $input['plan']['character_style'] ?? '', 'workspace_id' => (int) $run->workspace_id, 'aspect_ratio' => $input['settings']['aspect_ratio'] ?? '9:16',
            'language' => $input['settings']['language'] ?? 'en', 'approved_copy' => $input['plan']['on_screen_copy'] ?? [], 'source_images' => $images,
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
