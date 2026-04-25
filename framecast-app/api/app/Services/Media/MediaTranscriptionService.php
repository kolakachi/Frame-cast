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

            $response = Http::timeout(120)
                ->withToken($apiKey)
                ->attach('file', file_get_contents($path), basename($path))
                ->post('https://api.openai.com/v1/audio/transcriptions', $payload);

            if (! $response->ok()) {
                throw new RuntimeException('Transcription provider request failed.');
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
        } catch (\Throwable) {
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
