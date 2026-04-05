<?php

namespace App\Services\Generation\TTS;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class OpenAITTSAdapter implements TTSAdapter
{
    public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0): array
    {
        $apiKey = (string) config('services.openai.api_key');

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

        $response = Http::timeout(60)
            ->withToken($apiKey)
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => 'tts-1',
                'input' => $text ?: ' ',
                'voice' => $safeVoice,
                'speed' => max(0.25, min(4.0, $speed)),
                'response_format' => 'mp3',
            ]);

        if (! $response->ok()) {
            throw new RuntimeException('OpenAI TTS request failed with status '.$response->status().': '.$response->body());
        }

        $path = 'audio/tts/'.Str::uuid().'.mp3';

        Storage::disk('b2')->put($path, $response->body(), [
            'ContentType' => 'audio/mpeg',
        ]);

        // S3 v4 presigned URLs must expire within 7 days.
        $audioUrl = Storage::disk('b2')->temporaryUrl($path, now()->addDays(6));

        return [
            'audio_url' => $audioUrl,
            'duration_seconds' => $this->estimateDuration($text, $speed),
            'provider_key' => 'openai',
            'provider_voice_id' => $safeVoice,
        ];
    }

    private function estimateDuration(string $text, float $speed): float
    {
        $wordCount = max(1, count(preg_split('/\s+/', trim($text)) ?: []));
        $wpm = max(80.0, 150.0 * max(0.5, min(2.0, $speed)));
        $seconds = ($wordCount / $wpm) * 60.0;

        return max(2.0, round($seconds, 2));
    }
}
