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
     * When the prompt contains a URL ("a 5-scene ad for https://acme.com"),
     * fetch the page and return its text so the plan is grounded in the
     * REAL product (name, benefits, copy) instead of whatever the LLM
     * hallucinates from the domain name. Null when no URL / fetch fails —
     * the parse proceeds on the prompt alone, never throws.
     *
     * @return array{url: string, content: string}|null
     */
    public function extractUrlContext(string $userPrompt): ?array
    {
        return $this->extractUrlContexts($userPrompt, 1)[0] ?? null;
    }

    /**
     * All URLs in the prompt (deduped, capped) fetched as grounding contexts —
     * a prompt can reference a product page AND a pricing/docs page, etc.
     *
     * @return list<array{url: string, content: string}>
     */
    public function extractUrlContexts(string $userPrompt, int $max = 3): array
    {
        if (! preg_match_all('/https?:\/\/[^\s)\]>"\']+/i', $userPrompt, $m)) {
            return [];
        }
        $urls = array_slice(array_unique(array_map(fn ($u) => rtrim($u, '.,;'), $m[0])), 0, $max);

        $contexts = [];
        foreach ($urls as $url) {
            $ctx = $this->fetchUrlContext($url);
            if ($ctx) {
                $contexts[] = $ctx;
            }
        }

        return $contexts;
    }

    /**
     * SSRF guard: true only for an http(s) URL whose host resolves entirely to
     * public IPs. Resolves BOTH A and AAAA records (gethostbyname is IPv4-only)
     * and rejects if ANY resolved address is private/reserved/loopback/
     * link-local (incl. the 169.254.169.254 cloud-metadata endpoint).
     */
    private function isPublicHttpUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! in_array(strtolower($parts['scheme'] ?? ''), ['http', 'https'], true) || empty($parts['host'])) {
            return false;
        }
        $host = $parts['host'];

        // Literal IP host — validate directly.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            $ips = [$host];
        } else {
            $records = @dns_get_record($host, DNS_A + DNS_AAAA) ?: [];
            $ips = array_merge(
                array_column($records, 'ip'),
                array_column($records, 'ipv6'),
            );
            if (empty($ips)) {
                return false; // unresolvable → fail closed
            }
        }

        foreach ($ips as $ip) {
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return false; // any private/reserved hop disqualifies the host
            }
        }

        return true;
    }

    /** Fetch one URL as a grounding context (raw fetch → renderer fallback). */
    private function fetchUrlContext(string $url): ?array
    {
        if (! $this->isPublicHttpUrl($url)) {
            return null;
        }

        try {
            // SSRF hardening: do NOT follow redirects — a public URL could 302
            // to 169.254.169.254 / localhost / a private host, and the initial
            // host check would not see it. We re-validate every redirect Location
            // and refuse private hops (defends redirect-to-internal); pairing
            // with the per-hop check also shrinks the DNS-rebinding window.
            $response = Http::timeout(8)
                ->withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; WyvStudioBot/1.0; +https://wyvstudio.com)'])
                ->withOptions([
                    'allow_redirects' => [
                        'max'             => 3,
                        'strict'          => true,
                        'referer'         => false,
                        'protocols'       => ['http', 'https'],
                        'on_redirect'     => function ($request, $response, $uri) {
                            if (! $this->isPublicHttpUrl((string) $uri)) {
                                throw new \RuntimeException('SSRF: redirect to non-public host blocked');
                            }
                        },
                    ],
                ])
                ->get($url);
            if (! $response->ok()) {
                return null;
            }
            $body = (string) $response->body();

            // Meta/OG tags first — SPAs usually ship these server-side even
            // when the page body is an empty JS shell.
            $meta = [];
            if (preg_match('/<title[^>]*>(.*?)<\/title>/is', $body, $m)) {
                $meta[] = trim(html_entity_decode($m[1]));
            }
            foreach (['description', 'og:description', 'og:title'] as $key) {
                if (preg_match('/<meta[^>]+(?:name|property)=["\']'.preg_quote($key, '/').'["\'][^>]+content=["\']([^"\']+)["\']/i', $body, $m)
                    || preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+(?:name|property)=["\']'.preg_quote($key, '/').'["\']/i', $body, $m)) {
                    $meta[] = trim(html_entity_decode($m[1]));
                }
            }
            $metaText = implode(' · ', array_unique(array_filter($meta)));

            // Drop script/style blocks before stripping tags so we keep copy,
            // not JS bundles; collapse whitespace; cap to keep the parser lean.
            $stripped = preg_replace('/<(script|style|noscript)\b[^>]*>.*?<\/\1>/is', ' ', $body) ?? $body;
            $text = trim(preg_replace('/\s+/', ' ', html_entity_decode(strip_tags($stripped))) ?? '');

            // Thin body = client-rendered SPA. Fall back to our in-house
            // renderer service (headless Chromium in the compose stack) so
            // SPA product pages ground the plan too. Fail-soft: meta + thin
            // text still beat nothing.
            if (mb_strlen($text) < 400) {
                $rendered = $this->fetchRendered($url);
                if ($rendered !== null && mb_strlen($rendered) > mb_strlen($text)) {
                    $text = $rendered;
                }
            }

            $content = trim($metaText !== '' ? $metaText.' — '.$text : $text);

            return $content !== '' ? ['url' => $url, 'content' => mb_substr($content, 0, 4000)] : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Fetch a JS-rendered page as plain text via our own renderer service —
     * headless Chromium running inside the compose stack (services.renderer,
     * see framecast-app/renderer/). Self-hosted on purpose: no third-party
     * dependency, no rate limits, page content never leaves our infra.
     * Null on any failure.
     */
    private function fetchRendered(string $url): ?string
    {
        $base = rtrim((string) config('services.renderer.url', ''), '/');
        if ($base === '') {
            return null;
        }

        try {
            $response = Http::timeout(25)->get($base.'/render', ['url' => $url]);
            if (! $response->ok()) {
                return null;
            }
            $text = trim((string) $response->json('text', ''));
            $desc = trim((string) $response->json('description', ''));
            $combined = ($desc !== '' && ! str_contains($text, $desc))
                ? $desc.' — '.$text
                : $text;
            $combined = trim(preg_replace('/\s+/', ' ', $combined) ?? '');

            return $combined !== '' ? $combined : null;
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @return array{
     *   script: string,          // What the voice actually says
     *   visual: string,          // What the image shows
     *   music_mood: string,      // Genre/mood seed for MusicGen
     *   motion: string,          // Camera/subject motion for animation
     *   style: string,           // 'photorealistic' | 'cinematic' | ... — picks the image style
     * }
     */
    /**
     * Build the authoritative PRODUCT FACTS block appended to the SYSTEM
     * prompt when the user's prompt contained a URL. In the system prompt
     * (not the user message tail) on purpose: scene descriptions in the user
     * prompt are detailed and dominant, and the model would otherwise follow
     * a wrong product assumption over quietly-appended page content.
     */
    private function factsBlock(array $urlContexts): string
    {
        if (empty($urlContexts)) {
            return '';
        }

        // Budget the total facts payload across however many URLs were given.
        $perUrlCap = count($urlContexts) > 1 ? 2500 : 4000;
        $sections = '';
        foreach ($urlContexts as $ctx) {
            $sections .= "\nPRODUCT FACTS — fetched live from {$ctx['url']} (AUTHORITATIVE):\n"
                .mb_substr($ctx['content'], 0, $perUrlCap)."\n";
        }

        return <<<FACTS_BLOCK


PRODUCT FACTS RULES (these override the user's product assumptions):
1. Every product claim in every script line MUST come from the PRODUCT
   FACTS below — name, what the product does, features, pricing, offers.
2. If a user scene describes a capability that is NOT in the facts (e.g.
   collaboration, syncing, file organization), do NOT voice that
   capability. Rewrite that scene's script around a capability that IS in
   the facts, while keeping the scene's visual arc, mood and style.
3. Never invent features. When unsure whether a feature exists, leave it
   out and use one that is explicitly stated.

{$sections}
FACTS_BLOCK;
    }

    /**
     * Appended to the system prompt when reference images ride along, so the
     * PLAN reflects what the images actually show (not a blind guess).
     */
    private function imagesBlock(array $referenceImageUrls): string
    {
        if (empty($referenceImageUrls)) {
            return '';
        }

        return "\n\nREFERENCE IMAGES are attached to this request. Look at them carefully: "
            ."when the user's scenes mention 'the interface', 'this person', 'the product' "
            ."or similar, describe what the attached images ACTUALLY show (layout, colors, "
            ."on-screen text, the subject's appearance) in that scene's visual prompt, and "
            ."keep subjects consistent with the images across all scenes.";
    }

    /**
     * Build the user message for the chat call — plain text normally, a
     * multimodal content array (text + image parts, low detail) when
     * reference images are attached so the model can SEE them.
     *
     * @return string|array
     */
    private function userContent(string $prompt, array $referenceImageUrls)
    {
        if (empty($referenceImageUrls)) {
            return $prompt;
        }
        $parts = [['type' => 'text', 'text' => $prompt]];
        foreach (array_slice($referenceImageUrls, 0, 4) as $url) {
            $parts[] = ['type' => 'image_url', 'image_url' => ['url' => $url, 'detail' => 'low']];
        }

        return $parts;
    }

    public function parse(string $userPrompt, array $urlContexts = [], array $referenceImageUrls = []): array
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

  visual      — a RICH image-generation prompt (target 500-1000 chars).
                Be the prompt engineer the user isn't: expand their
                request into a vivid scene. Cover SUBJECT (who/what,
                pose, expression, clothing), SETTING (location, props,
                environment), LIGHTING (golden hour / overcast / neon /
                studio), CAMERA (wide / close-up / low angle / over-the-
                shoulder), MOOD, STYLE CUES, and concrete TEXTURAL detail
                (fabric, weather, surfaces, atmosphere). One flowing block
                of comma-separated phrases — NOT a list. Do NOT just echo
                the user's words. NO text-on-image.
                When the subject is a PERSON, decide gender, approximate age
                (child / teen / young-adult / adult / senior) and ethnicity
                from the prompt's context — the product, audience, story or
                any stated detail. NEVER default to one gender or a young
                woman by reflex; pick who realistically fits (a CFO skews
                older; a gamer skews younger; a dad is male, etc.). State it
                explicitly so the image model doesn't guess.

  music_mood  — 3-7 word genre/mood seed for MusicGen. Pick from:
                calm acoustic / cinematic ambient / upbeat indie pop /
                lo-fi chill / tense electronic / inspiring orchestral /
                warm folk / energetic synth / hopeful piano.
                Bias toward the actual scene mood, not a default.

  motion      — 1 short clause describing how the still image should
                animate. Subject motion + camera. e.g. "subtle hair
                drift, slow camera push-in" or "ambient steam rising,
                static frame". ~30-80 chars.

  voice_gender — the gender of the on-screen speaker so the voiceover MATCHES
                the person in `visual`: "male", "female", or "neutral" (neutral
                only for no-person / product / b-roll). A male subject MUST be
                "male", a female subject "female" — a mismatch breaks lip-sync.

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

  style_explicit — true ONLY if the user's prompt explicitly named a visual
                style (e.g. "anime", "3D", "pixar", "realistic", "watercolor");
                false if you inferred it.

Return STRICT JSON with exactly these seven keys (script, visual, music_mood, motion, voice_gender, style, style_explicit). No prose, no markdown.
SYS;
        $systemPrompt .= $this->factsBlock($urlContexts).$this->imagesBlock($referenceImageUrls);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(15)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'       => config('services.openai.cheap_model', 'gpt-4o-mini'),
                    'temperature' => 0.4,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $this->userContent($userPrompt, $referenceImageUrls)],
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
                'script'       => $this->cleanString($parsed['script']     ?? $fallback['script'],     400),
                'visual'       => $this->cleanString($parsed['visual']     ?? $fallback['visual'],     1500),
                'music_mood'   => $this->cleanString($parsed['music_mood'] ?? $fallback['music_mood'], 60),
                'motion'       => $this->cleanString($parsed['motion']     ?? $fallback['motion'],     160),
                'voice_gender' => $this->cleanGender($parsed['voice_gender'] ?? null),
                'style'        => $this->validStyle($parsed['style']       ?? 'photorealistic'),
                'style_explicit' => (bool) ($parsed['style_explicit'] ?? false),
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
    /**
     * Infer the user's INTENT for visual source + animation from explicit
     * prompt cues. Deterministic keyword pass — the baseline for all scene
     * counts and the fallback when the LLM omits the fields. Returns null
     * for "no opinion" so the caller's defaults / the user's pills win.
     *
     * @return array{visual_source: ?string, animate: ?bool}
     */
    public function inferHints(string $userPrompt): array
    {
        $p = mb_strtolower($userPrompt);

        $source = null;
        if (preg_match('/audiogram|waveform|audio.?visualizer|podcast.?(clip|visual)/u', $p)) {
            $source = 'waveform';
        } elseif (preg_match('/stock\s+(photo|image|picture|still)/u', $p)) {
            $source = 'stock_images';
        } elseif (preg_match('/stock\s+(video|footage|clip)|real\s+footage|b.?roll\s+footage/u', $p)) {
            $source = 'stock_video';
        } elseif (preg_match('/ai\s+(image|visual|art)|generate\s+(the\s+)?(image|visual)/u', $p)) {
            $source = 'ai_images';
        }

        $animate = null;
        if (preg_match('/\b(no|without|skip|don.?t)\s+(animation|animating|motion)\b|image[s]?\s+only|still[s]?\s+only|static\s+image/u', $p)) {
            $animate = false;
        } elseif (preg_match('/\banimat(e|ed|ion)\b|make\s+(it|them)\s+move|moving\s+scene|cinematic\s+motion/u', $p)) {
            $animate = true;
        }

        return ['visual_source' => $source, 'animate' => $animate];
    }

    public function parseMultiScene(string $userPrompt, int $sceneCount, array $referenceImageUrls = []): array
    {
        $sceneCount = max(1, min(8, $sceneCount));

        // URL in the prompt? Ground the plan in the real page content —
        // injected as an AUTHORITATIVE system-prompt block (factsBlock), not
        // appended user text, so wrong product assumptions in the prompt get
        // corrected instead of obeyed. Hints still read the ORIGINAL prompt
        // only (page copy could false-trigger source/animation cues).
        $urlContexts = $this->extractUrlContexts($userPrompt);

        $singleFallback = $this->parse($userPrompt, $urlContexts, $referenceImageUrls);
        $hints = $this->inferHints($userPrompt);
        $fallback = $this->fallbackMulti($singleFallback, $sceneCount);
        $fallback['hints'] = $hints;

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
                'hints'      => $hints,
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
  visual  — a RICH image-generation prompt for THIS scene (target
            500-1000 chars). Expand into a vivid scene: subject + pose +
            expression + clothing, setting + props, lighting, camera
            angle + framing, mood, style cues, and concrete textural
            detail. One flowing block of comma-separated phrases, not a
            list. Keep visual continuity across scenes (same subject /
            lighting feel where applicable). Do NOT echo the user's words.
            When the subject is a PERSON, decide gender, approximate age
            (child / teen / young-adult / adult / senior) and ethnicity from
            the prompt's context (product, audience, story, stated details) —
            NEVER default to one gender or a young woman by reflex; pick who
            realistically fits, and keep that SAME person across every scene.
  motion  — 1 short clause for how the still image animates. ~30-80 chars.
  voice_gender — the gender of the on-screen speaker for THIS scene so the
            voiceover MATCHES the person you described in `visual`: "male",
            "female", or "neutral" (use neutral only for b-roll / product /
            no-person scenes). A male subject MUST get "male", a female subject
            "female" — never mismatch, it breaks the lip-sync.
  characters — ONLY when this scene shows one or more of the NAMED people in
            the cast (below): an array of their names, e.g. ["Sarah"] or
            ["Sarah","Tom"]. If the scene has no named person (b-roll,
            product, scenery, an unnamed extra), return [].

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
  style_explicit — true ONLY if the user's prompt explicitly named/requested a
               visual style (e.g. "anime", "3D", "pixar", "realistic",
               "watercolor", "cinematic look"). false if you inferred it.
  character_sheet — ONLY when the scenes feature exactly ONE recurring person:
               define their appearance ONCE — gender, approximate age, hair
               (color, length, style), exact outfit (garments, colors),
               notable accessories. 1-2 sentences. Reuse that exact wording in
               every scene visual where the person appears. If there is a
               reference image of the person, describe THAT appearance. No
               recurring person, OR two-or-more named people -> null.
  cast       — ONLY when the prompt features TWO OR MORE DISTINCT NAMED people
               who recur (e.g. "Sarah" the founder and "Tom" the investor). An
               array of {name, appearance}; appearance covers gender, age,
               hair, exact outfit, accessories. Discernment rules:
               * People only. Products, logos, objects, screenshots, scenery
                 are NEVER cast members.
               * Unnamed extras / crowds are NOT cast.
               * 0 or 1 named person -> return [] and use character_sheet.
               If a reference image clearly depicts one of these people,
               describe THAT person's appearance.

Return STRICT JSON, no markdown:
{
  "scenes": [
    { "script": "…", "visual": "…", "motion": "…", "voice_gender": "male|female|neutral", "characters": [] }
  ],
  "music_mood": "…",
  "style": "…",
  "style_explicit": true,
  "character_sheet": "… or null",
  "cast": [ { "name": "…", "appearance": "…" } ]
}

The scenes array MUST have exactly {$sceneCount} items.
SYS;
        $systemPrompt .= $this->factsBlock($urlContexts).$this->imagesBlock($referenceImageUrls);

        try {
            $response = Http::withToken($apiKey)
                ->timeout(30)
                ->post('https://api.openai.com/v1/chat/completions', [
                    'model'           => config('services.openai.cheap_model', 'gpt-4o-mini'),
                    'temperature'     => 0.5,
                    'response_format' => ['type' => 'json_object'],
                    'messages' => [
                        ['role' => 'system', 'content' => $systemPrompt],
                        ['role' => 'user',   'content' => $this->userContent($userPrompt, $referenceImageUrls)],
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

            // Cast: 2+ distinct named people only (the model returns [] otherwise).
            $cast = $this->cleanCast($parsed['cast'] ?? null);
            $castNames = array_map(fn ($c) => mb_strtolower($c['name']), $cast);

            $scenes = [];
            foreach (array_slice($parsed['scenes'], 0, $sceneCount) as $s) {
                // Scene's named characters — keep only names that exist in the cast.
                $sceneChars = [];
                foreach ((array) ($s['characters'] ?? []) as $name) {
                    $n = trim((string) $name);
                    if ($n !== '' && in_array(mb_strtolower($n), $castNames, true)) {
                        $sceneChars[] = $n;
                    }
                }
                $scenes[] = [
                    'script'       => $this->cleanString($s['script'] ?? $singleFallback['script'], 400),
                    'visual'       => $this->cleanString($s['visual'] ?? $singleFallback['visual'], 1500),
                    'motion'       => $this->cleanString($s['motion'] ?? $singleFallback['motion'], 160),
                    'voice_gender' => $this->cleanGender($s['voice_gender'] ?? null),
                    'characters'   => array_values(array_unique($sceneChars)),
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
                    'voice_gender' => 'neutral',
                    'characters' => [],
                ];
            }

            return [
                'scenes'     => $scenes,
                'music_mood' => $this->cleanString($parsed['music_mood'] ?? $singleFallback['music_mood'], 60),
                'style'      => $this->validStyle($parsed['style'] ?? $singleFallback['style']),
                'style_explicit' => (bool) ($parsed['style_explicit'] ?? ($singleFallback['style_explicit'] ?? false)),
                'hints'      => $hints,
                // A single recurring subject keeps using character_sheet; a
                // 2+ named cast uses `cast` (character_sheet forced null then).
                'character_sheet' => empty($cast) && is_string($parsed['character_sheet'] ?? null) && trim($parsed['character_sheet']) !== '' && strtolower(trim($parsed['character_sheet'])) !== 'null'
                    ? mb_substr(trim($parsed['character_sheet']), 0, 500)
                    : null,
                'cast'       => $cast,
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
                'voice_gender' => $single['voice_gender'] ?? 'neutral',
            ];
        }
        return [
            'scenes'     => $scenes,
            'music_mood' => $single['music_mood'],
            'style'      => $single['style'],
            'style_explicit' => $single['style_explicit'] ?? false,
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
            'script'       => $userPrompt,
            'visual'       => $userPrompt,
            'music_mood'   => 'calm cinematic ambient',
            'motion'       => 'subtle natural motion, slow camera drift',
            'voice_gender' => 'neutral',
            'style'        => 'photorealistic',
            'style_explicit' => false,
        ];
    }

    /** Normalize an inferred speaker gender to male | female | neutral. */
    private function cleanGender(mixed $v): string
    {
        $g = mb_strtolower(trim((string) $v));
        if (str_starts_with($g, 'm')) {
            return 'male';
        }
        if (str_starts_with($g, 'f') || str_starts_with($g, 'w')) {
            return 'female';
        }

        return 'neutral';
    }

    private function cleanString(mixed $v, int $max): string
    {
        $s = is_string($v) ? trim($v) : '';
        return mb_substr($s, 0, $max);
    }

    /**
     * Normalize the planner's cast. Only a list of 2+ distinct named people
     * counts (the assistant must not invent a "cast" for a single subject or
     * for products/scenery). Returns [] otherwise.
     *
     * @return list<array{name: string, appearance: string}>
     */
    private function cleanCast(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $cast = [];
        $seen = [];
        foreach ($raw as $member) {
            if (! is_array($member)) {
                continue;
            }
            $name = $this->cleanString($member['name'] ?? '', 60);
            if ($name === '' || isset($seen[mb_strtolower($name)])) {
                continue;
            }
            $seen[mb_strtolower($name)] = true;
            $cast[] = [
                'name'       => $name,
                'appearance' => $this->cleanString($member['appearance'] ?? '', 500),
            ];
            if (count($cast) >= 6) {
                break; // sane cap on cast size
            }
        }

        // A "cast" is only meaningful with 2+ named people — one person is the
        // single-subject case (character_sheet), zero is b-roll/product.
        return count($cast) >= 2 ? $cast : [];
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
