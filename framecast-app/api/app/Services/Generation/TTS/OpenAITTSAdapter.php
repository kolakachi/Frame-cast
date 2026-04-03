<?php

namespace App\Services\Generation\TTS;

use Illuminate\Support\Str;

class OpenAITTSAdapter implements TTSAdapter
{
    public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0): array
    {
        $seed = rawurlencode(Str::slug(mb_substr($text, 0, 40)).'-'.Str::random(6));

        return [
            'audio_url' => "https://example.com/audio/{$seed}.mp3",
            'duration_seconds' => $this->estimateDuration($text, $speed),
            'provider_key' => 'openai',
            'provider_voice_id' => $voiceId,
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
