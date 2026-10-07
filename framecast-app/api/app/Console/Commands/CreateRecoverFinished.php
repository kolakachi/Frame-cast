<?php

namespace App\Console\Commands;

use App\Services\Create\{AttemptService, RunService};
use Illuminate\Console\Command;
use Illuminate\Support\Facades\{DB, Storage};
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;

/**
 * Operator recovery. A worker finished a build and rendered the video, but the app
 * failed to record it, so the run was closed as failed. This records the
 * saved result as the version it should have been. No model call or render is
 * repeated and nothing is charged: every call was already settled.
 */
class CreateRecoverFinished extends Command
{
    protected $signature = 'create:recover-finished {run : Run id} {completion : Path to the worker completion.json} {artifact : Path to the rendered video.mp4}';

    protected $description = 'Record a saved finished Create build after stop and settlement checks, without regenerating';

    public function handle(RunService $runs): int
    {
        abort_unless(config('create.enabled'), 403, 'Create is not enabled here.');
        $id = (string) $this->argument('run');
        $run = DB::table('composition_runs')->where('id', $id)->firstOrFail();
        if ($run->status !== 'failed') { $this->error('Only a failed run can be recovered; this one is '.$run->status.'.'); return self::FAILURE; }
        app(\App\Services\Create\WorkerOwnership::class)->requireStopped($run);
        if (AttemptService::unresolved($id)) { $this->error('Some calls are unresolved; reconcile them first.'); return self::FAILURE; }
        $this->requireSettled($run);
        $completion = json_decode((string) file_get_contents((string) $this->argument('completion')), true);
        $result = $completion['result'] ?? null;
        if (! is_array($result) || ($result['status'] ?? '') !== 'preview_ready' || ! isset($result['bundle']['index.html'])) { $this->error('The completion file has no finished result.'); return self::FAILURE; }
        $result['summary'] = mb_substr((string) $result['summary'], 0, 2000);

        $file = (string) $this->argument('artifact');
        $settings = json_decode($run->input_json, true)['settings'];
        $probe = new Process(['ffprobe', '-v', 'error', '-show_streams', '-show_format', '-of', 'json', $file]);
        $probe->setTimeout(20); $probe->mustRun();
        $info = json_decode($probe->getOutput(), true);
        $video = collect($info['streams'] ?? [])->firstWhere('codec_type', 'video');
        $duration = (float) ($info['format']['duration'] ?? 0);
        if (! $video || abs($duration - $settings['duration_seconds']) > .3) { $this->error('The video does not match the approved length.'); return self::FAILURE; }
        $result['media'] = ['kind' => 'video', 'mime_type' => 'video/mp4', 'width' => $video['width'], 'height' => $video['height'], 'duration_seconds' => $duration];
        $hash = hash_file('sha256', $file);
        $path = 'create/previews/'.$id.'/'.$hash.'.mp4';
        if (! app(\App\Services\Create\CreateStorage::class)->exists($path)) {
            $stream = fopen($file, 'rb');
            try { app(\App\Services\Create\CreateStorage::class)->put($path, $stream, ['visibility' => 'private']); }
            finally { if (is_resource($stream)) fclose($stream); }
        }

        // Reopen under a fresh one-minute lease (the old worker's token stays revoked),
        // then record through the normal finish path.
        $lease = Str::random(64);
        $out = DB::transaction(function () use ($id, $run, $lease, $runs, $result, $path, $hash) {
            // Same conversation/run order as normal delivery. A competing recovery must recheck terminal state.
            DB::table('create_conversations')->where('id', $run->conversation_id)->lockForUpdate()->firstOrFail();
            $current = DB::table('composition_runs')->where('id', $id)->lockForUpdate()->firstOrFail();
            abort_unless($current->status === 'failed' && ! AttemptService::unresolved($id), 409, 'Run changed during recovery. No result was replaced.');
            app(\App\Services\Create\WorkerOwnership::class)->requireStopped($current);
            $this->requireSettled($current);
            DB::table('composition_runs')->where('id', $id)->update(['status' => 'running', 'lease_hash' => hash('sha256', $lease),
                'lease_expires_at' => now()->addMinute(), 'result_hash' => null, 'updated_at' => now()]);
            DB::table('api_operations')->where('id', $run->operation_id)->update(['status' => 'running']);
            $out = $runs->finish($id, $lease, $result, $path, $hash);
            DB::table('composition_runs')->where('id', $id)->update(['lease_hash' => null, 'lease_expires_at' => null]);
            return $out;
        });
        $rev = DB::table('composition_revisions')->where('run_id', $id)->first();
        $this->info('Recovered: run '.$out['status'].', version '.($rev->number ?? '?').'. No calls were repeated.');
        return self::SUCCESS;
    }

    private function requireSettled(object $run): void
    {
        abort_if(DB::table('composition_attempts')->where('run_id', $run->id)->where(fn ($q) => $q
            ->whereNotIn('status', ['succeeded', 'failed'])->orWhereNull('result_hash')->orWhereNull('cost_microusd'))->exists(),
            409, 'Every call needs a settlement receipt before recovering saved output.');
        abort_if(DB::table('api_operation_jobs')->where('operation_id', $run->operation_id)->whereIn('status', ['pending', 'running', 'released'])->exists(),
            409, 'Operation jobs still need reconciliation before recovering saved output.');
    }
}
