<?php
namespace App\Services\Create;

use App\Models\Asset;
use App\Services\Media\MediaTranscriptionService;
use Illuminate\Support\Facades\{RateLimiter, Storage};

/**
 * Word-timed transcripts of a run's supplied speech. The app holds the
 * provider key; the worker asks by asset id and gets times on that file's own
 * timeline. Results are cached on the asset against the exact bytes, so a
 * later version or a free edit never pays or waits twice.
 */
class TranscriptService
{
    public function forRun(string $runId, string $lease, int $assetId): array
    {
        $file = app(RunService::class)->inputFile($runId, $lease, $assetId);
        abort_unless(str_starts_with($file['mime_type'], 'video/') || str_starts_with($file['mime_type'], 'audio/'), 422, 'Only audio or video has speech to transcribe.');
        abort_if(($file['duration_seconds'] ?? 0) > config('create.transcript_max_seconds'), 422, 'Transcripts cover clips up to 10 minutes.');
        // A silent video (a screen recording with no sound track) has nothing to transcribe: say so, not a server error.
        $path = app(\App\Services\Create\CreateStorage::class)->path($file['storage_path']);
        if (str_starts_with($file['mime_type'], 'video/')) {
            $streams = \Illuminate\Support\Facades\Process::timeout(30)->run(['ffprobe', '-v', 'error', '-select_streams', 'a', '-show_entries', 'stream=index', '-of', 'csv=p=0', $path]);
            abort_if($streams->successful() && trim($streams->output()) === '', 422, 'This video has no sound track, so there is no speech to transcribe.');
        }
        $asset = Asset::findOrFail($assetId);
        $cached = ($asset->metadata_json ?? [])['create_transcript'] ?? null;
        if (is_array($cached) && ($cached['sha256'] ?? null) === $file['sha256']) return $this->present($cached, true);

        $key = 'create-transcript:'.$asset->workspace_id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($key, (int) config('create.transcript_daily_limit')), 429, 'Daily transcript limit reached. Try again tomorrow.');
        RateLimiter::hit($key, 86400);
        $result = app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($path, $file['mime_type']);
        // The media service returns a placeholder on failure; that is not speech.
        abort_if(($result['provider_key'] ?? '') === 'local_fallback' || ! $result['words'], 503, 'Transcription is unavailable right now. The video can still be built without word timing.');

        $record = ['sha256' => $file['sha256'], 'provider' => $result['provider_key'], 'model' => $result['model'], 'text' => mb_substr($result['transcript'], 0, 20000),
            'words' => array_slice($result['words'], 0, 3000), 'segments' => array_slice($result['segments'], 0, 600), 'created_at' => now()->toIso8601String()];
        $asset->forceFill(['metadata_json' => array_merge($asset->metadata_json ?? [], ['create_transcript' => $record])]
            + ((string) $asset->transcript_text === '' ? ['transcript_text' => $record['text']] : []))->save();
        return $this->present($record, false);
    }

    /**
     * Listening to sound the worker made: the export's soundtrack, or narration it edited itself. Returns the words
     * with times, and the approved script both as written and as the voice was asked to say it (pronunciations).
     */
    public function listen(string $runId, string $lease, \Illuminate\Http\UploadedFile $file): array
    {
        $run = app(RunService::class)->currentRun($runId, $lease);
        $path = $file->getRealPath();
        $seconds = (float) trim(\Illuminate\Support\Facades\Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'format=duration', '-of', 'csv=p=0', $path])->output());
        abort_unless($seconds > 0, 422, 'That file has no sound to listen to.');
        abort_if($seconds > config('create.transcript_max_seconds'), 422, 'Listening covers up to 10 minutes.');
        // No daily limit here: this is the build's own check of a video someone already paid for, bounded by that
        // build. The daily limit protects the free transcripts users ask for (transcribe above).
        $result = app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($path, (string) ($file->getMimeType() ?: 'audio/wav'));
        // A provider hiccup is retried once before the check is reported as unavailable (it then shows as unverified).
        if (($result['provider_key'] ?? '') === 'local_fallback') { sleep(3); $result = app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps($path, (string) ($file->getMimeType() ?: 'audio/wav')); }
        abort_if(($result['provider_key'] ?? '') === 'local_fallback', 503, 'Listening is unavailable right now.');
        $record = ['provider' => $result['provider_key'], 'model' => $result['model'] ?? null, 'text' => mb_substr((string) ($result['transcript'] ?? ''), 0, 20000),
            'words' => array_slice($result['words'] ?? [], 0, 3000), 'segments' => array_slice($result['segments'] ?? [], 0, 600)];
        $lines = array_values(array_filter(array_map('strval', (array) data_get(json_decode($run->input_json, true), 'plan.narration', []))));
        return $this->present($record, false) + ['seconds' => round($seconds, 2), 'script' => ['written' => $lines,
            'spoken' => array_map(fn ($l) => PlanMediaExecutor::pronounce($l, (int) $run->workspace_id), $lines),
            'names' => PlanMediaExecutor::pronunciationsIn(implode("\n", $lines), (int) $run->workspace_id)]];
    }

    private function present(array $r, bool $cached): array
    {
        $round = fn ($items) => array_map(fn ($w) => ['text' => $w['text'], 'start' => round((float) $w['start'], 2), 'end' => round((float) $w['end'], 2)], $items);
        return ['text' => $r['text'], 'words' => $round($r['words']), 'segments' => $round($r['segments']), 'provider' => $r['provider'], 'model' => $r['model'], 'cached' => $cached];
    }
}
