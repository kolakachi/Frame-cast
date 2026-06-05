<?php

namespace App\Services\Generation;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Split a single user prompt into the four channels the one-shot pipeline
 * needs: voice-over script, visual prompt, music mood, motion direction.
 *
 * Without this, every channel got the same raw user prompt — which made
 * TTS literally read out the *description* of the scene ("a calm founder
 * explaining her morning ritual…") instead of speaking *as* the character.
 * Music also got a visual description rather than a mood. This service
 * fixes both with one cheap GPT-4o-mini call (~$0.001 per parse).
 *
 * Returns sensible fallbacks if the LLM call fails — never throws. The
 * caller can always proceed with the raw prompt in the worst case.
 */
class OneShotPromptParser
{
    /**
     * @return array{
     *   script: string,          // What the voice actually says
     *   visual: string,          // What the image shows
     *   music_mood: string,      // Genre/mood seed for MusicGen
     *   motion: string,          // Camera/subject motion for animation
     *   style: string,           // 'photorealistic' | 'cinematic' | ... — picks the image style
     * }
     */
    public function parse(string $userPrompt): array
    {
        $fallback = $this->fallback($userPrompt);

        $apiKey = config('services.openai.api_key');
        if (empty($apiKey)) {
            Log::warning('OneShotPromptParser: OpenAI key missing — using fallback');
            return $fallback;
        }

        $systemPrompt = <<<SYS
You convert a single user prompt about a short video scene into four channels:

  script      — what the on-screen voice ACTUALLY SAYS. 1-2 sentences,
                spoken in first or second person as the subject. NEVER
                describe the scene; speak it. ~80-180 chars.

  visual      — what the image generator should produce. Keep the user's
                concrete details (subject, setting, lighting, mood) but
                add visual specifics. ~80-200 chars. NO text-on-image.

  music_mood  — 3-7 word genre/mood seed for MusicGen. Pick from:
                calm acoustic / cinematic ambient / upbeat indie pop /
                lo-fi chill / tense electronic / inspiring orchestral /
                warm folk / energetic synth / hopeful piano.
                Bias toward the actual scene mood, not a default.

  motion      — 1 short clause describing how the still image should
                animate. Subject motion + camera. e.g. "subtle hair
                drift, slow camera push-in" or "ambient steam rising,
                static frame". ~30-80 chars.

  style       — one of: photorealistic, cinematic, minimalist, vintage,
                comic, watercolor, line_drawing, anime, neon, 3d_animated.
                Pick the one that fits the prompt's tone; default
                photorealistic for human/lifestyle/product subjects.

Return STRICT JSON with exactly these five keys. No prose, no markdown.
SYS;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => config('services.openai.cheap_model', 'gpt-4o-mini'),
                    'temperature' => 0.4,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('OneShotPromptParser: HTTP not successful', ['status' => $response->status(), 'body' => $response->body()]);
                return $fallback;
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $parsed  = json_decode($content, true);

            if (! is_array($parsed)) {
                Log::warning('OneShotPromptParser: LLM returned non-JSON', ['content' => $content]);
                return $fallback;
            }

            return [
                'script'     => $this->cleanString($parsed['script']     ?? $fallback['script'],     400),
                'visual'     => $this->cleanString($parsed['visual']     ?? $fallback['visual'],     500),
                'music_mood' => $this->cleanString($parsed['music_mood'] ?? $fallback['music_mood'], 60),
                'motion'     => $this->cleanString($parsed['motion']     ?? $fallback['motion'],     160),
                'style'      => $this->validStyle($parsed['style']       ?? 'photorealistic'),
            ];
        } catch (\Throwable $e) {
            Log::warning('OneShotPromptParser: exception — using fallback', ['error' => $e->getMessage()]);
            return $fallback;
        }
    }

    /**
     * When the LLM call fails (no key, network error, malformed response),
     * fall back to "use the raw prompt for everything" so the one-shot
     * still ships. Worse output, but better than 500.
     */
    private function fallback(string $userPrompt): array
    {
        return [
            'script'     => $userPrompt,
            'visual'     => $userPrompt,
            'music_mood' => 'calm cinematic ambient',
            'motion'     => 'subtle natural motion, slow camera drift',
            'style'      => 'photorealistic',
        ];
    }

    private function cleanString(mixed $v, int $max): string
    {
        $s = is_string($v) ? trim($v) : '';
        return mb_substr($s, 0, $max);
    }

    private function validStyle(mixed $v): string
    {
        $allowed = ['photorealistic', 'cinematic', 'minimalist', 'vintage', 'comic',
                    'watercolor', 'line_drawing', 'anime', 'neon', '3d_animated'];
        $s = is_string($v) ? strtolower(trim($v)) : '';
        return in_array($s, $allowed, true) ? $s : 'photorealistic';
    }
}
