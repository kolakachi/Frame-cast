<?php

namespace App\Services\Media;

use App\Models\Asset;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class MediaTranscriptionService
{
    /**
     * @return array{transcript:string,provider_key:string,model:string}
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
     * @return array{transcript:string,provider_key:string,model:string}
     */
    public function transcribeLocalFile(string $path, string $fallbackTitle = 'media file'): array
    {
        $apiKey = (string) config('services.openai.api_key');
        $model = (string) config('services.openai.transcription_model', 'whisper-1');

        if ($apiKey === '') {
            return $this->fallbackTranscript($fallbackTitle);
        }

        try {
            $response = Http::timeout(120)
                ->withToken($apiKey)
                ->attach('file', file_get_contents($path), basename($path))
                ->post('https://api.openai.com/v1/audio/transcriptions', [
                    'model' => $model,
                    'response_format' => 'json',
                ]);

            if (! $response->ok()) {
                throw new RuntimeException('Transcription provider request failed.');
            }

            $text = trim((string) data_get($response->json(), 'text', ''));

            if ($text === '') {
                throw new RuntimeException('Transcription provider returned empty text.');
            }

            return [
                'transcript' => $text,
                'provider_key' => 'openai',
                'model' => $model,
            ];
        } catch (\Throwable) {
            return $this->fallbackTranscript($fallbackTitle);
        }
    }

    private function downloadAsset(Asset $asset): string
    {
        $path = $this->extractB2Path((string) $asset->storage_url);

        if ($path === null || ! Storage::disk('b2')->exists($path)) {
            throw new RuntimeException('Asset file is not available for transcription.');
        }

        $extension = pathinfo($path, PATHINFO_EXTENSION) ?: $this->extensionFromMime((string) $asset->mime_type);
        $localPath = sys_get_temp_dir().'/framecast-transcribe-'.Str::uuid().'.'.$extension;
        file_put_contents($localPath, Storage::disk('b2')->get($path));

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

    private function extractB2Path(string $storageUrl): ?string
    {
        $url = trim($storageUrl);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'b2://')) {
            return ltrim(substr($url, 5), '/');
        }

        return null;
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
