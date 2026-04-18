<?php

namespace App\Services\Generation\TTS;

interface TTSAdapter
{
    /**
     * @return array{
     *   audio_url:string,
     *   duration_seconds:float,
     *   provider_key:string,
     *   provider_voice_id:string
     * }
     */
    public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0, array $options = []): array;
}
