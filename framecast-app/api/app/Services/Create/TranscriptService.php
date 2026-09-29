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
        $asset = Asset::findOrFail($assetId);
        $cached = ($asset->metadata_json ?? [])['create_transcript'] ?? null;
        if (is_array($cached) && ($cached['sha256'] ?? null) === $file['sha256']) return $this->present($cached, true);

        $key = 'create-transcript:'.$asset->workspace_id.':'.now()->toDateString();
        abort_if(RateLimiter::tooManyAttempts($key, (int) config('create.transcript_daily_limit')), 429, 'Daily transcript limit reached. Try again tomorrow.');
        RateLimiter::hit($key, 86400);
        $result = app(MediaTranscriptionService::class)->transcribeLocalMediaWithTimestamps(Storage::disk('local')->path($file['storage_path']), $file['mime_type']);
        // The media service returns a placeholder on failure; that is not speech.
        abort_if(($result['provider_key'] ?? '') === 'local_fallback' || ! $result['words'], 503, 'Transcription is unavailable right now. The video can still be built without word timing.');

        $record = ['sha256' => $file['sha256'], 'provider' => $result['provider_key'], 'model' => $result['model'], 'text' => mb_substr($result['transcript'], 0, 20000),
            'words' => array_slice($result['words'], 0, 3000), 'segments' => array_slice($result['segments'], 0, 600), 'created_at' => now()->toIso8601String()];
        $asset->forceFill(['metadata_json' => array_merge($asset->metadata_json ?? [], ['create_transcript' => $record])]
            + ((string) $asset->transcript_text === '' ? ['transcript_text' => $record['text']] : []))->save();
        return $this->present($record, false);
    }

    private function present(array $r, bool $cached): array
    {
        $round = fn ($items) => array_map(fn ($w) => ['text' => $w['text'], 'start' => round((float) $w['start'], 2), 'end' => round((float) $w['end'], 2)], $items);
        return ['text' => $r['text'], 'words' => $round($r['words']), 'segments' => $round($r['segments']), 'provider' => $r['provider'], 'model' => $r['model'], 'cached' => $cached];
    }
}
