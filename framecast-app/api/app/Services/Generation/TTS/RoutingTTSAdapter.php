<?php

namespace App\Services\Generation\TTS;

/**
 * Picks the TTS engine per request. Gemini 3.1 Flash is the default expressive
 * engine; OpenAI tts-1 stays available as the cheap/legacy option.
 *
 * Routing precedence:
 *   1. explicit options['provider'] — 'openai' vs 'google'/'gemini'
 *   2. otherwise infer from the voice id — the 6 fixed OpenAI voices route to
 *      OpenAI; everything else (Gemini voices, empty, unknown) → Gemini.
 *
 * Bound as the TTSAdapter implementation, so GenerateTTSJob (which resolves
 * TTSAdapter from the container) transparently fans out to the right engine.
 */
class RoutingTTSAdapter implements TTSAdapter
{
    private const OPENAI_VOICES = ['alloy', 'echo', 'fable', 'onyx', 'nova', 'shimmer'];

    public function __construct(
        private readonly GeminiTTSAdapter $gemini,
        private readonly OpenAITTSAdapter $openai,
    ) {
    }

    public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0, array $options = []): array
    {
        return $this->pick($voiceId, $options)
            ->synthesize($text, $language, $voiceId, $speed, $options);
    }

    private function pick(string $voiceId, array $options): TTSAdapter
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));
        if ($provider === 'openai') {
            return $this->openai;
        }
        if ($provider === 'google' || $provider === 'gemini') {
            return $this->gemini;
        }

        // No explicit provider — infer from the voice. Only the fixed OpenAI
        // voices stay on OpenAI; Gemini is the default for everything else.
        return in_array(strtolower($voiceId), self::OPENAI_VOICES, true)
            ? $this->openai
            : $this->gemini;
    }
}
