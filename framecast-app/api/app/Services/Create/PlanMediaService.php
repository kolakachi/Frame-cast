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
    public function produce(string $runId, string $lease, int $index): array
    {
        $run = DB::table('composition_runs')->where('id', $runId)->firstOrFail();
        $input = json_decode($run->input_json, true);
        $item = $input['plan_media'][$index] ?? null;
        abort_unless(is_array($item) && isset($input['plan']['plan_id']), 404, 'This run has no such plan item.');
        app(RunService::class)->validateResultLease($runId, $lease);
        $planId = $input['plan']['plan_id'];
        $hash = hash('sha256', $item['kind'].'|'.$item['description']);

        $done = DB::table('create_plan_media')->where('plan_id', $planId)->where('item_index', $index)->first();
        if ($done && $done->status === 'succeeded' && $done->description_hash === $hash) {
            $record = json_decode($done->record_json, true);
            if (! empty($record['file'])) $record['file'] = app(RunService::class)->reuseGenerated($runId, $lease, $record['file']);
            return [...$record, 'reused' => true, 'charged_credits' => 0];
        }

        $attempts = app(AttemptService::class);
        $attempt = $attempts->begin($runId, $lease, 'plan-media-'.$index, 'plan_media', $hash);
        abort_unless($attempt['may_execute'], 409, 'This item was already attempted in this run.');
        $dir = sys_get_temp_dir().'/create-pm-'.Str::uuid();
        mkdir($dir, 0700);
        try {
            try {
                $made = app(PlanMediaExecutor::class)->produce($item['kind'], $item['description'], $this->context($run, $input), $dir);
            } catch (\Throwable $e) {
                // Nothing usable was made, so nothing is charged. The build goes on without it.
                $id = 'pm-failed-'.Str::uuid();
                $attempts->bindPrediction($runId, $lease, $attempt['id'], $id);
                $receipt = new VerifiedAttemptReceipt($attempt['id'], 'failed', $id, 0, 'pilot-tariff:catalogue; not produced');
                $attempts->settle($runId, $lease, $attempt['id'], $receipt->result(), $receipt);
                $message = $e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface || $e instanceof \RuntimeException ? $e->getMessage() : 'The provider did not return a result.';
                $this->record($run, $planId, $index, $item, $hash, 'failed', null, 0, mb_substr($message, 0, 280));
                return ['kind' => $item['kind'], 'description' => $item['description'], 'status' => 'failed', 'error' => $message, 'charged_credits' => 0];
            }
            $file = $made['path'] !== '' ? app(RunService::class)->generated($runId, $lease, $made['path'], $made['title'],
                ['plan_media' => ['plan_id' => $planId, 'index' => $index, 'kind' => $item['kind'], 'description' => $item['description']], 'provider_id' => $made['provider_id']]) : null;
            $id = preg_replace('/[^a-zA-Z0-9_-]/', '-', $made['provider_id']);
            $attempts->bindPrediction($runId, $lease, $attempt['id'], substr($id.'-'.substr($attempt['id'], 0, 8), 0, 160));
            $credits = (int) $item['credits'];
            $receipt = new VerifiedAttemptReceipt($attempt['id'], 'succeeded', substr($id.'-'.substr($attempt['id'], 0, 8), 0, 160), $credits * 4000,
                'pilot-tariff:catalogue; '.$item['kind'].' at its listed price of '.$credits.' credits');
            $settled = $attempts->settle($runId, $lease, $attempt['id'], $receipt->result(), $receipt);
            $record = ['kind' => $item['kind'], 'description' => $item['description'], 'status' => 'succeeded', 'file' => $file, 'brand' => $made['brand'] ?? null, 'cues' => $made['cues'] ?? null];
            $this->record($run, $planId, $index, $item, $hash, 'succeeded', $record, (int) $settled['charged_credits'], null);
            return [...$record, 'reused' => false, 'charged_credits' => (int) $settled['charged_credits']];
        } finally {
            foreach (glob($dir.'/*') ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    private function context(object $run, array $input): array
    {
        $images = collect($input['input_files'] ?? [])->where('purpose', 'source')->where('asset_type', 'image')
            ->map(fn ($f) => Storage::disk('local')->path($f['storage_path']))->filter(fn ($p) => is_file($p))->values()->all();
        return ['workspace_id' => (int) $run->workspace_id, 'aspect_ratio' => $input['settings']['aspect_ratio'] ?? '9:16',
            'language' => $input['settings']['language'] ?? 'en', 'approved_copy' => $input['plan']['on_screen_copy'] ?? [], 'source_images' => $images,
            'narration' => $input['plan']['narration'] ?? [], 'voice' => $input['plan']['voice'] ?? null, 'duration_seconds' => (int) ($input['settings']['duration_seconds'] ?? 15)];
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
