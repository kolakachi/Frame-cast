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

  style       — pick ONE of the 21 styles below that best fits the
                prompt's vibe. Do NOT default — read the prompt and
                let the subject + tone drive the choice. If the user
                explicitly names a style ("3D pixar look", "anime
                style", "watercolor", "comic book") match it exactly.

                photorealistic — sharp, modern, true-to-life, like a
                                 high-end DSLR photo. Use for product
                                 shots, real-world lifestyle, founders
                                 to camera, b-roll of real environments.
                realistic      — same as photorealistic but slightly
                                 softer / less retouched. Documentary
                                 vibe.
                cinematic      — colour-graded, shallow depth, mood
                                 lighting, hero composition. Trailers,
                                 ads, anything that wants to FEEL
                                 like a film still.
                documentary    — handheld, natural light, candid. Use
                                 for journalism, real stories.
                dark           — moody, low-key, deep shadow. Horror,
                                 thriller, dramatic narration.
                film_noir      — high-contrast B&W, hard shadows,
                                 1940s detective aesthetic.
                vintage        — washed colour, light grain, retro
                                 feel. Lifestyle nostalgia, family
                                 archive, 70s/80s product.
                minimalist     — clean negative space, single subject,
                                 muted palette. Tech, design, brand.
                neon           — vivid neon lights, night urban, rim
                                 lighting. Gaming, nightlife, edgy.
                cyberpunk_80s  — neon + retro tech, scan lines,
                                 chrome. Tech futurism, synthwave.
                anime          — modern Japanese animation, clean line,
                                 saturated colour. General anime.
                anime_80s      — Akira / Bubblegum Crisis era, cel
                                 shading, painterly bg.
                anime_90s      — Ghost in the Shell era, gritty, mature.
                dark_fantasy   — Witcher / dark Souls feel, painterly,
                                 moody. Mythic horror, lore-heavy.
                fantasy_retro  — 80s pulp fantasy, vivid, painterly,
                                 Frank Frazetta vibes.
                comic          — bold ink, halftone dots, speech-bubble
                                 ready. Western comics, action.
                line_drawing   — pen sketch on white, illustrator-style.
                                 Explainer, technical, b/w editorial.
                watercolor     — soft washes, paper texture, hand-painted
                                 feel. Children's books, gentle stories.
                paper_cutout   — layered paper, drop shadow, craft feel.
                                 Quirky, indie, hand-made vibe.
                cartoon        — modern 2D cartoon, bright outlines,
                                 flat shading. Saturday morning vibe.
                3d_animated    — Pixar / DreamWorks 3D render, soft
                                 light, volumetric. Use for cute
                                 characters, family, animation-ad
                                 vibes, kids content, anything where
                                 the user says "3D" or "Pixar" or
                                 "Disney" or "animated".

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
     * Multi-scene variant. Splits a user prompt into N scenes that flow as
     * a short-form video — for N=3 follows hook/body/CTA, N=5 narrative arc,
     * N=8 a richer storyboard. Each scene gets its own script + visual +
     * motion. music_mood + style are shared across all scenes (one music
     * bed, consistent visual treatment).
     *
     * @return array{
     *   scenes: list<array{script:string,visual:string,motion:string}>,
     *   music_mood: string,
     *   style: string,
     * }
     */
    public function parseMultiScene(string $userPrompt, int $sceneCount): array
    {
        $sceneCount = max(1, min(8, $sceneCount));
        $singleFallback = $this->parse($userPrompt);
        $fallback = $this->fallbackMulti($singleFallback, $sceneCount);

        // For single-scene, no need for the extra LLM call — wrap the
        // existing parse() output to keep the shape consistent.
        if ($sceneCount === 1) {
            return [
                'scenes' => [[
                    'script' => $singleFallback['script'],
                    'visual' => $singleFallback['visual'],
                    'motion' => $singleFallback['motion'],
                ]],
                'music_mood' => $singleFallback['music_mood'],
                'style'      => $singleFallback['style'],
            ];
        }

        $apiKey = config('services.openai.api_key');
        if (empty($apiKey)) {
            Log::warning('OneShotPromptParser: OpenAI key missing — multi-scene fallback');
            return $fallback;
        }

        $arc = $sceneCount === 3
            ? "Use the classic short-form ad arc: scene 1 = HOOK (pattern interrupt or problem), scene 2 = BODY (product / proof / use-case), scene 3 = CTA (call-to-action / offer)."
            : ($sceneCount <= 5
                ? "Build a narrative arc across {$sceneCount} scenes that escalates emotion and pays off."
                : "Build a rich storyboard across {$sceneCount} scenes. Each scene should feel distinct visually and progress the story.");

        $systemPrompt = <<<SYS
You convert a single user prompt into {$sceneCount} short-form video scenes.
Each scene gets its own script (voice-over), visual (image prompt), and
motion (animation direction). music_mood and style are shared across all
scenes — pick ONE for the whole video.

{$arc}

Per scene, return:
  script  — what the voice ACTUALLY SAYS. 1 sentence, first/second person.
            Never describe the scene; speak it. ~50-130 chars per scene.
            Across scenes the voice should feel continuous, not disjoint.
  visual  — what the image generator should produce for this scene. Keep
            visual continuity across scenes (same subject if applicable,
            same lighting feel). ~80-180 chars.
  motion  — 1 short clause for how the still image animates. ~30-80 chars.

Shared:
  music_mood — 3-7 word genre/mood seed. Pick from:
               calm acoustic / cinematic ambient / upbeat indie pop /
               lo-fi chill / tense electronic / inspiring orchestral /
               warm folk / energetic synth / hopeful piano.
  style      — pick ONE of these for the whole video, matching the prompt:
               photorealistic, realistic, cinematic, documentary, dark,
               film_noir, vintage, minimalist, neon, cyberpunk_80s, anime,
               anime_80s, anime_90s, dark_fantasy, fantasy_retro, comic,
               line_drawing, watercolor, paper_cutout, cartoon, 3d_animated.

Return STRICT JSON, no markdown:
{
  "scenes": [
    { "script": "…", "visual": "…", "motion": "…" }
  ],
  "music_mood": "…",
  "style": "…"
}

The scenes array MUST have exactly {$sceneCount} items.
SYS;

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => config('services.openai.cheap_model', 'gpt-4o-mini'),
                    'temperature'     => 0.5,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $userPrompt],
                    ],
                ]);

            if (! $response->successful()) {
                Log::warning('OneShotPromptParser: multi-scene HTTP not successful', ['status' => $response->status()]);
                return $fallback;
            }

            $content = (string) data_get($response->json(), 'choices.0.message.content', '');
            $parsed  = json_decode($content, true);
            if (! is_array($parsed) || ! is_array($parsed['scenes'] ?? null)) {
                Log::warning('OneShotPromptParser: multi-scene non-JSON or missing scenes');
                return $fallback;
            }

            $scenes = [];
            foreach (array_slice($parsed['scenes'], 0, $sceneCount) as $s) {
                $scenes[] = [
                    'script' => $this->cleanString($s['script'] ?? $singleFallback['script'], 400),
                    'visual' => $this->cleanString($s['visual'] ?? $singleFallback['visual'], 500),
                    'motion' => $this->cleanString($s['motion'] ?? $singleFallback['motion'], 160),
                ];
            }
            // Top up if the model returned fewer than requested (rare but
            // not impossible). Pad with copies of the last scene rather
            // than throwing — better degraded output than 500.
            while (count($scenes) < $sceneCount) {
                $scenes[] = $scenes[count($scenes) - 1] ?? [
                    'script' => $singleFallback['script'],
                    'visual' => $singleFallback['visual'],
                    'motion' => $singleFallback['motion'],
                ];
            }

            return [
                'scenes'     => $scenes,
                'music_mood' => $this->cleanString($parsed['music_mood'] ?? $singleFallback['music_mood'], 60),
                'style'      => $this->validStyle($parsed['style'] ?? $singleFallback['style']),
            ];
        } catch (\Throwable $e) {
            Log::warning('OneShotPromptParser: multi-scene exception', ['error' => $e->getMessage()]);
            return $fallback;
        }
    }

    private function fallbackMulti(array $single, int $sceneCount): array
    {
        $scenes = [];
        for ($i = 0; $i < $sceneCount; $i++) {
            $scenes[] = [
                'script' => $single['script'],
                'visual' => $single['visual'],
                'motion' => $single['motion'],
            ];
        }
        return [
            'scenes'     => $scenes,
            'music_mood' => $single['music_mood'],
            'style'      => $single['style'],
        ];
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
        // Mirror AI_IMAGE_STYLES in EditorView.vue. Keep in sync if the
        // editor adds / drops styles — the parser is intentionally given
        // the full canonical list rather than a curated subset so a user
        // who prompts "3D Pixar look" actually gets 3d_animated, not
        // a forced fallback to photorealistic.
        $allowed = [
            'cinematic', 'dark', 'documentary', 'anime', 'minimalist',
            'realistic', 'vintage', 'neon', 'photorealistic',
            'cyberpunk_80s', 'anime_80s', 'anime_90s',
            'dark_fantasy', 'fantasy_retro', 'comic', 'film_noir',
            'line_drawing', 'watercolor', 'paper_cutout', 'cartoon',
            '3d_animated',
        ];
        $s = is_string($v) ? strtolower(trim($v)) : '';
        return in_array($s, $allowed, true) ? $s : 'photorealistic';
    }
}
