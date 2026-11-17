<?php

namespace App\Services\Generation\TTS;

use App\Services\ApiUsageService;
use App\Services\Media\StorageService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class OpenAITTSAdapter implements TTSAdapter
{
    public function __construct(
        private readonly ApiUsageService $usage,
    ) {
    }

    public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0, array $options = []): array
    {
        $apiKey = (string) config('services.openai.api_key');
        $model = 'tts-1';
        $usageContext = $this->usage->contextFromOptions($options);

        if ($apiKey === '') {
            $seed = rawurlencode(Str::slug(mb_substr($text, 0, 40)).'-'.Str::random(6));

            return [
                'audio_url' => "https://example.com/audio/{$seed}.mp3",
                'duration_seconds' => $this->estimateDuration($text, $speed),
                'provider_key' => 'openai',
                'provider_voice_id' => $voiceId ?: 'alloy',
            ];
        }

        $safeVoice = in_array($voiceId, ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'], true)
            ? $voiceId
            : 'alloy';

        try {
            $response = Http::timeout(60)
                ->withToken($apiKey)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model' => $model,
                    'input' => $text ?: ' ',
                    'voice' => $safeVoice,
                    'speed' => max(0.25, min(4.0, $speed)),
                    'response_format' => 'mp3',
                ]);
        } catch (Throwable $exception) {
            $this->usage->record([
                ...$usageContext,
                'provider' => 'openai',
                'service' => 'tts',
                'operation' => 'speech',
                'model' => $model,
                'status' => 'failed',
                'units' => mb_strlen($text),
                'estimated_cost_usd' => $this->usage->estimateTtsCost($model, $text),
                'error_code' => 'connection_error',
                'error_message' => $exception->getMessage(),
            ]);

            throw new RuntimeException('Voice generation could not reach OpenAI. Please try again in a moment.', previous: $exception);
        }

        if (! $response->ok()) {
            $this->usage->record([
                ...$usageContext,
                'provider' => 'openai',
                'service' => 'tts',
                'operation' => 'speech',
                'model' => $model,
                'status' => 'failed',
                'units' => mb_strlen($text),
                'estimated_cost_usd' => $this->usage->estimateTtsCost($model, $text),
                'error_code' => (string) $response->status(),
                'error_message' => $response->body(),
            ]);

            throw new RuntimeException('OpenAI TTS request failed with status '.$response->status().': '.$response->body());
        }

        $path = 'audio/tts/'.Str::uuid().'.mp3';

        $audioStorageUrl = app(StorageService::class)->put($path, $response->body(), [
            'ContentType' => 'audio/mpeg',
        ]);

        $this->usage->record([
            ...$usageContext,
            'provider' => 'openai',
            'service' => 'tts',
            'operation' => 'speech',
            'model' => $model,
            'status' => 'succeeded',
            'units' => mb_strlen($text),
            'estimated_cost_usd' => $this->usage->estimateTtsCost($model, $text),
            'metadata_json' => [
                ...($usageContext['metadata_json'] ?? []),
                'voice_id' => $safeVoice,
                'language' => $language,
            ],
        ]);

        return [
            'audio_url' => $audioStorageUrl,
            // Measured, as the Gemini and Chatterbox adapters do (2026-11-17): the word-count estimate was off by up to
            // a second a scene (project 59: 95.6 s estimated, 86 s spoken), and the stored length sets the scene's
            // length in the editor, its preview pacing and where transitions fall.
            'duration_seconds' => self::measure($response->body()) ?? $this->estimateDuration($text, $speed),
            'provider_key' => 'openai',
            'provider_voice_id' => $safeVoice,
        ];
    }

    /** The audio's real length in seconds (ffprobe), or null when it can't be read. */
    public static function measure(string $bytes, string $ext = 'mp3'): ?float
    {
        return rescue(function () use ($bytes, $ext): ?float {
            $tmp = tempnam(sys_get_temp_dir(), 'tts-').'.'.$ext;
            file_put_contents($tmp, $bytes);
            $out = [];
            exec('ffprobe -v error -show_entries format=duration -of csv=p=0 '.escapeshellarg($tmp), $out);
            @unlink($tmp);
            $d = isset($out[0]) ? (float) $out[0] : 0.0;

            return $d > 0.05 ? round($d, 3) : null;
        }, null, false);
    }

    private function estimateDuration(string $text, float $speed): float
    {
        $wordCount = max(1, count(preg_split('/\s+/', trim($text)) ?: []));
        $wpm = max(80.0, 150.0 * max(0.5, min(2.0, $speed)));
        $seconds = ($wordCount / $wpm) * 60.0;

        return max(2.0, round($seconds, 2));
    }
}
