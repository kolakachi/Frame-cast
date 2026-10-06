<?php

namespace App\Services\Media;

use App\Models\Asset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;
use RuntimeException;

class MediaTranscriptionService
{
    /**
     * @return array{transcript:string,provider_key:string,model:string,words?:array<int, array{text:string,start:float,end:float}>,segments?:array<int, array{text:string,start:float,end:float}>}
     */
    public function transcribeAsset(Asset $asset): array
    {
        $localPath = $this->downloadAsset($asset);
        $transcriptionPath = $this->prepareTranscriptionFile($asset, $localPath);

        try {
            return $this->transcribeLocalFile($transcriptionPath, (string) $asset->title);
        } finally {
            @unlink($localPath);

            if ($transcriptionPath !== $localPath) {
                @unlink($transcriptionPath);
            }
        }
    }

    /**
     * @return array{transcript:string,provider_key:string,model:string,words?:array<int, array{text:string,start:float,end:float}>,segments?:array<int, array{text:string,start:float,end:float}>}
     */
    public function transcribeLocalFile(string $path, string $fallbackTitle = 'media file'): array
    {
        return $this->transcribeLocalFileWithOptions($path, $fallbackTitle, false);
    }

    /**
     * @return array{transcript:string,provider_key:string,model:string,words:array<int, array{text:string,start:float,end:float}>,segments:array<int, array{text:string,start:float,end:float}>}
     */
    public function transcribeAssetWithTimestamps(Asset $asset): array
    {
        $localPath = $this->downloadAsset($asset);
        $transcriptionPath = $this->prepareTranscriptionFile($asset, $localPath);

        try {
            $result = $this->transcribeLocalFileWithOptions($transcriptionPath, (string) $asset->title, true);

            return [
                ...$result,
                'words' => $result['words'] ?? [],
                'segments' => $result['segments'] ?? [],
            ];
        } finally {
            @unlink($localPath);

            if ($transcriptionPath !== $localPath) {
                @unlink($transcriptionPath);
            }
        }
    }

    /**
     * Word-timed transcript of a local audio or video file the caller owns.
     * The caller must check provider_key: on any provider failure this class
     * returns a placeholder marked 'local_fallback', never real speech.
     */
    public function transcribeLocalMediaWithTimestamps(string $path, string $mimeType): array
    {
        $audio = $path;
        if (str_starts_with($mimeType, 'video/')) {
            $audio = sys_get_temp_dir().'/framecast-transcribe-'.Str::uuid().'.mp3';
            $result = Process::timeout(120)->run(['ffmpeg', '-y', '-i', $path, '-vn', '-acodec', 'libmp3lame', '-ar', '44100', '-ac', '1', $audio]);
            if (! $result->successful() || ! file_exists($audio)) throw new RuntimeException('Could not extract audio from video for transcription.');
        }
        try {
            $result = $this->transcribeLocalFileWithOptions($audio, 'media file', true);
            return [...$result, 'words' => $result['words'] ?? [], 'segments' => $result['segments'] ?? []];
        } finally {
            if ($audio !== $path) @unlink($audio);
        }
    }

    /**
     * @return array{transcript:string,provider_key:string,model:string,words?:array<int, array{text:string,start:float,end:float}>,segments?:array<int, array{text:string,start:float,end:float}>}
     */
    private function transcribeLocalFileWithOptions(string $path, string $fallbackTitle, bool $withTimestamps): array
    {
        $apiKey = (string) config('services.openai.api_key');
        $model = $withTimestamps
            ? (string) config('services.openai.timestamp_transcription_model', 'whisper-1')
            : (string) config('services.openai.transcription_model', 'whisper-1');

        if ($apiKey === '') {
            return $this->fallbackTranscript($fallbackTitle);
        }

        try {
            $payload = [
                'model' => $model,
                'response_format' => $withTimestamps ? 'verbose_json' : 'json',
            ];

            if ($withTimestamps) {
                $payload['timestamp_granularities[]'] = 'word';
            }

            // A network drop before the request reaches the provider is waited out (up to about a minute); a busy or
            // failed answer is asked once more after a short wait. Every failure is logged and classified
            // (VendorAlerts: our account out of credit or a bad key alerts the team), never swallowed silently.
            $send = fn () => \App\Services\Create\NetRetry::run(fn () => Http::timeout(120)
                ->withToken($apiKey)
                ->attach('file', file_get_contents($path), self::uploadName($path))
                ->post('https://api.openai.com/v1/audio/transcriptions', $payload));
            $response = $send();
            if (! $response->ok() && ! in_array(\App\Services\Vendors\VendorError::classify($response->body(), $response->status()), ['vendor_credit', 'vendor_config', 'content_refused'], true)) {
                \Illuminate\Support\Sleep::for(5)->seconds();
                $response = $send();
            }

            if (! $response->ok()) {
                \App\Services\Vendors\VendorAlerts::observe('openai', $response->body(), $response->status(), ['kind' => 'transcription']);
                throw new RuntimeException('Transcription provider request failed ('.$response->status().'): '.mb_substr($response->body(), 0, 200));
            }

            $json = $response->json();
            $text = trim((string) data_get($json, 'text', ''));

            if ($text === '') {
                throw new RuntimeException('Transcription provider returned empty text.');
            }

            $result = [
                'transcript' => $text,
                'provider_key' => 'openai',
                'model' => $model,
            ];

            if ($withTimestamps) {
                $result['words'] = $this->normalizeTimedItems((array) data_get($json, 'words', []), 'word');
                $result['segments'] = $this->normalizeTimedItems((array) data_get($json, 'segments', []), 'text');
            }

            return $result;
        } catch (\Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('Transcription fell back to the placeholder', ['error' => mb_substr(get_class($e).': '.$e->getMessage(), 0, 300)]);
            return $this->fallbackTranscript($fallbackTitle);
        }
    }

    /**
     * @return array<int, array{text:string,start:float,end:float}>
     */
    private function normalizeTimedItems(array $items, string $textKey): array
    {
        $normalized = [];

        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }

            $text = trim((string) ($item[$textKey] ?? $item['text'] ?? $item['word'] ?? ''));
            $start = (float) ($item['start'] ?? -1);
            $end = (float) ($item['end'] ?? -1);

            if ($text === '' || $start < 0 || $end <= $start) {
                continue;
            }

            $normalized[] = [
                'text' => $text,
                'start' => round($start, 3),
                'end' => round($end, 3),
            ];
        }

        return $normalized;
    }

    private function downloadAsset(Asset $asset): string
    {
        $rawUrl  = (string) $asset->storage_url;
        $storage = app(StorageService::class);

        if (! $storage->isManagedUrl($rawUrl) || ! $storage->exists($rawUrl)) {
            throw new RuntimeException('Asset file is not available for transcription.');
        }

        $path      = (string) $storage->extractPath($rawUrl);
        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: $this->extensionFromMime((string) $asset->mime_type);
        $localPath = sys_get_temp_dir().'/framecast-transcribe-'.Str::uuid().'.'.$extension;
        file_put_contents($localPath, $storage->get($rawUrl));

        return $localPath;
    }

    private function prepareTranscriptionFile(Asset $asset, string $localPath): string
    {
        if (! str_starts_with((string) $asset->mime_type, 'video/')) {
            return $localPath;
        }

        $audioPath = sys_get_temp_dir().'/framecast-transcribe-'.Str::uuid().'.mp3';
        $result = Process::timeout(120)->run([
            'ffmpeg',
            '-y',
            '-i',
            $localPath,
            '-vn',
            '-acodec',
            'libmp3lame',
            '-ar',
            '44100',
            '-ac',
            '1',
            $audioPath,
        ]);

        if (! $result->successful() || ! file_exists($audioPath)) {
            throw new RuntimeException('Could not extract audio from video for transcription.');
        }

        return $audioPath;
    }

    /**
     * @return array{transcript:string,provider_key:string,model:string}
     */
    /**
     * The name the file is sent under: the provider reads the format from its extension, so an upload saved without
     * one (a PHP temp file such as phpAb12Cd) is named by its content.
     */
    public static function uploadName(string $path): string
    {
        $base = basename($path);
        if (preg_match('/\.(flac|m4a|mp3|mp4|mpeg|mpga|oga|ogg|wav|webm)$/i', $base)) return $base;
        $mime = (string) (@mime_content_type($path) ?: '');
        $ext = match (true) {
            str_contains($mime, 'wav') => 'wav', str_contains($mime, 'mpeg') => 'mp3', str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'ogg') => 'ogg', str_contains($mime, 'webm') => 'webm', str_contains($mime, 'flac') => 'flac', default => 'wav',
        };
        return $base.'.'.$ext;
    }

    private function fallbackTranscript(string $title): array
    {
        return [
            'transcript' => "Transcript is not available yet for {$title}. Use this media as the source reference, then replace this draft once transcription is available.",
            'provider_key' => 'local_fallback',
            'model' => 'deterministic',
        ];
    }

    private function extensionFromMime(string $mimeType): string
    {
        return match ($mimeType) {
            'audio/mpeg' => 'mp3',
            'audio/mp4', 'audio/x-m4a' => 'm4a',
            'audio/wav', 'audio/x-wav' => 'wav',
            'video/mp4' => 'mp4',
            default => 'bin',
        };
    }
}
