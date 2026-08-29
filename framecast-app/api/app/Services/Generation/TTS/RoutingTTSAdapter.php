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
        private readonly ChatterboxTTSAdapter $chatterbox,
    ) {
    }

    public function synthesize(string $text, string $language, string $voiceId, float $speed = 1.0, array $options = []): array
    {
        return $this->pick($voiceId, $options)
            ->synthesize($text, $language, $voiceId, $speed, $options);
    }

    private function pick(string $voiceId, array $options): TTSAdapter
    {
        return match (self::engineFor($voiceId, $options)) {
            'openai'     => $this->openai,
            'chatterbox' => $this->chatterbox,
            default      => $this->gemini,
        };
    }

    /**
     * Which engine a scene will route to, as a plain string.
     *
     * Split out of pick() so a caller can know the engine WITHOUT synthesizing
     * — the three engines bill at different rates, so a bulk pre-flight quote
     * has to resolve the same way the real run will. Sharing this method is
     * what stops the quote and the charge drifting apart.
     *
     * @return 'openai'|'chatterbox'|'gemini'
     */
    public static function engineFor(string $voiceId, array $options = []): string
    {
        $provider = strtolower(trim((string) ($options['provider'] ?? '')));

        if ($provider === 'openai') {
            return 'openai';
        }
        // Cloned voices carry a replicate:chatterbox provider (or a clone_audio_url).
        if (str_contains($provider, 'chatterbox') || $provider === 'clone' || ! empty($options['clone_audio_url'])) {
            return 'chatterbox';
        }
        if ($provider === 'google' || $provider === 'gemini') {
            return 'gemini';
        }

        // No explicit provider — infer from the voice. Only the fixed OpenAI
        // voices stay on OpenAI; Gemini is the default for everything else.
        return in_array(strtolower($voiceId), self::OPENAI_VOICES, true)
            ? 'openai'
            : 'gemini';
    }
}
