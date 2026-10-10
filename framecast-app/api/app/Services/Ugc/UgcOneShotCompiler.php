<?php

namespace App\Services\Ugc;

/**
 * Compiles a director's plan into screenplay prompts for one-full-video
 * generation (Veo 3.1 with native audio). The plan's beats stop being
 * generation units: they become dialogue lines and cut directions inside
 * as few generations as possible, chained start-frame to last-frame.
 *
 * Both moves were probe-proven before this was written: dialogue in the
 * prompt comes back spoken and lip-synced natively, and a segment prompted
 * as "the same presenter, same outfit, same setting, continues…" from the
 * previous segment's tail frame keeps the person recognizably themselves.
 */
class UgcOneShotCompiler
{
    /** Veo generates 4–8 second clips; longer ads chain segments. */
    public const MAX_SEGMENT_SECONDS = 8;

    public const MIN_SEGMENT_SECONDS = 4;

    /** Omni takes any length up to 10 s, so its clips need neither snapping nor the Veo 8-second cap. */
    public const OMNI_SEGMENT_SECONDS = 10;

    /**
     * What every take is told about the people and things in it. The hands rules came from the 2026-10-10 sweep: a box
     * floated in mid-air while one hand held the phone, so a second hand only joins once the phone is set down.
     */
    private const CLOSING = 'Casual creator energy, natural imperfect delivery. Only the cuts this script calls for — between them each shot holds as one continuous take, no unrequested angle changes, no camera zoom, natural head movement. Take ONLY the presenter\'s face and identity from the reference image — the setting, framing, clothing and action come from this description, never from the reference\'s own background or outfit. The presenter matches the reference person\'s face exactly where one is shown; where the presenter is given as a casting description instead, match it precisely — skin tone, hair and age are not negotiable. Either way they stay the same person throughout: same face, same hair, same clothing, no drift or deformation. Real subtle skin texture with natural pores — never a 3D, CGI or beauty-filter look. The phone is the camera filming this and never appears in the frame, except in a shot the description calls a mirror selfie: there the phone stays in one hand the whole shot and only the other hand moves or holds anything. To demonstrate something up close, a mirror selfie switches to the front camera: a cut to a handheld front-camera shot where the phone is no longer seen. Talking shots are handheld, so only one arm and hand are free and visible; anything that needs both hands (applying, opening, pouring) is filmed from the camera propped on the counter or a shelf, both hands free, phone out of sight. When the product, a box, a plate or anything else is not in a hand it rests on a counter, table or shelf. Nothing floats or hovers; anything held shows a real grip and weight, fingers relaxed, at a natural distance from the camera. No counting on fingers and no holding up a number of fingers (models miscount them). No studio lighting. No words, captions, titles, graphics or watermarks anywhere on screen (text is added later, never by you) — the product\'s own packaging, label and branding stay visible and legible. Natural room tone.';

    /**
     * @param  array<int, array{script_text: string, seconds: float|int, visual_brief: string, voice_direction?: string}>  $beats
     * @param  array{presenter?: string, setting?: string, product?: string, tone?: string}  $style
     * $maxSeconds: the longest clip the engine makes (Veo 8, snapped to 4/6/8; Omni 10, any length).
     * new_shot: the clip opens on a cut rather than carrying the previous shot on, so it can start from the reference
     * photos instead of the last frame (GenerateOneShotUgcJob).
     *
     * @return array<int, array{prompt: string, seconds: int, dialogue: string, new_shot: bool}>
     */
    public static function compile(array $beats, array $style = [], int $maxSeconds = self::MAX_SEGMENT_SECONDS): array
    {
        $beats = array_values(array_filter($beats, fn ($b) => is_array($b)));
        if ($beats === []) {
            return [];
        }

        $chunks = self::pack($beats, $maxSeconds);
        $preamble = self::preamble($style);
        $pron = PronunciationMap::parse((string) ($style['pronunciations'] ?? ''));

        $out = [];
        foreach ($chunks as $i => $chunk) {
            $lines = [];
            $dialogue = [];
            foreach ($chunk['beats'] as $j => $beat) {
                if ($j > 0 && ! empty($beat['continues'])) {
                    // The same shot carrying on (a long shot split to fit): no cut, they keep talking.
                } elseif ($j > 0) {
                    // A cut between beats inside one generation — the only
                    // edit UGC permits, landing where a creator would cut.
                    $lines[] = 'Cut to '.self::framing($beat).'.';
                } else {
                    // The chunk's opening framing — a continuation chunk
                    // needs it as much as the first (a beat's action lives
                    // here; dropping it lost "holds up the bottle").
                    $lines[] = ucfirst(self::framing($beat)).'.';
                }
                // Respell only what the voice model hears; stored text stays.
                $said = PronunciationMap::apply(trim((string) ($beat['script_text'] ?? '')), $pron);
                if ($said !== '') {
                    $delivery = trim((string) ($beat['voice_direction'] ?? ''));
                    $lines[] = ($delivery !== '' ? 'The presenter says, '.lcfirst(rtrim($delivery, '.')).': ' : 'The presenter says: ')
                        .'"'.$said.'"';
                    $dialogue[] = $said;
                }
            }

            $newShot = $i === 0 || empty($chunk['beats'][0]['continues']);
            // Exactly the scripted words (2026-10-10: a listicle came back with "One, two, three." and an ad-libbed
            // line between its scripted ones, which sounded like repeats at the joins).
            $wordsOnly = $dialogue !== []
                ? ' They say exactly these words, in this order, and nothing else: no added words, no ad-libs, no counting aloud. After the last word they stop talking and hold a natural, relaxed expression.'
                : '';
            $continuation = $i === 0 ? '' : ($newShot
                ? 'The next shot of the same presenter: same hair, outfit, setting and light as the previous shot\'s frame in the references. '
                : 'The same presenter — same outfit, same setting, same light — continues without a break. ');

            $out[] = [
                'prompt' => $preamble."\n\n".$continuation.implode(' ', $lines).$wordsOnly
                    ."\n\n".self::CLOSING,
                'seconds' => $chunk['seconds'],
                'dialogue' => implode(' ', $dialogue),
                'new_shot' => $newShot,
            ];
        }

        return $out;
    }

    /**
     * The whole plan as ONE generation — for engines that produce up to 30
     * seconds natively (Seedance 2.5). No chunking, no handoff frames: the
     * purest one-take, cuts directed in-prose at beat boundaries.
     *
     * @return array{prompt: string, seconds: int, dialogue: string}|null null when the plan exceeds the cap
     */
    public static function compileSingle(array $beats, array $style = [], int $maxSeconds = 30): ?array
    {
        $beats = array_values(array_filter($beats, fn ($b) => is_array($b)));
        if ($beats === []) {
            return null;
        }
        $total = (int) ceil(array_sum(array_map(fn ($b) => max(1.0, (float) ($b['seconds'] ?? 3)), $beats)));
        if ($total > $maxSeconds) {
            return null;
        }

        $pron = PronunciationMap::parse((string) ($style['pronunciations'] ?? ''));
        $demo = ! empty($style['demo']);
        $lines = [];
        $dialogue = [];
        foreach ($beats as $j => $beat) {
            $lines[] = ($j === 0 ? ucfirst(self::framing($beat)) : 'Cut to '.self::framing($beat)).'.';
            // Respell brand/product names only in the copy the voice model
            // reads — the stored script and captions keep real spelling.
            $said = PronunciationMap::apply(trim((string) ($beat['script_text'] ?? '')), $pron);
            if ($said !== '') {
                $delivery = trim((string) ($beat['voice_direction'] ?? ''));
                $lines[] = ($delivery !== '' ? 'The presenter says, '.lcfirst(rtrim($delivery, '.')).': ' : 'The presenter says: ').'"'.$said.'"';
                $dialogue[] = $said;
            }
        }

        // A real demo clip is spliced over a mid-ad window in post, and the
        // presenter's voice plays under it — so the VO needs a natural beat
        // that invites the cutaway ("watch this"), not a described insert.
        // Digital products (an app or site) have no physical object to hold,
        // and any interface the model paints on a phone/laptop screen comes
        // back as garbled, wrong text — so the presenter must keep every
        // device screen off-camera; the REAL interface is the spliced cutaway.
        $demoLine = $demo
            ? ' Midway, the presenter says something that invites showing the product in action — \'here, let me show you\' — and keeps narrating naturally for a few seconds, then returns to talking to the camera. The presenter never holds up, tilts or points a phone, tablet or laptop screen toward the camera to show the product: any interface rendered on a device screen would come out as unreadable, wrong text. Keep every device screen off-camera or turned away — the real interface is shown only in the cutaway.'
            : '';

        return [
            'prompt' => self::preamble($style)."

".implode(' ', $lines).$demoLine
                ."

".self::CLOSING,
            'seconds' => max(4, $total),
            'dialogue' => implode(' ', $dialogue),
        ];
    }

    /** Beats greedily packed into 4–8s generations, cuts kept inside chunks. */
    /**
     * A shot longer than one generation is split at sentence ends (then at commas) into pieces that each fit, with
     * the seconds shared by word count. Before 2026-10-10 a long talking shot went whole into one 8-second clip, and
     * the presenter was cut off mid-sentence (an 18-second direct-to-camera take delivered as 8 seconds).
     */
    private static function splitLong(array $beats, int $maxSeconds = self::MAX_SEGMENT_SECONDS): array
    {
        $out = [];
        foreach ($beats as $beat) {
            $text = trim((string) ($beat['script_text'] ?? ''));
            $words = self::words($text);
            // A beat is as long as its words need, whatever was planned: 25 words planned at 8 s still need ~11 s.
            $seconds = max(1.0, (float) ($beat['seconds'] ?? 3), $words > 0 ? $words / 2.5 + 1 : 0);
            if ($seconds <= $maxSeconds || $words < 2) { $out[] = $beat; continue; }
            $perWord = $seconds / $words;
            $limit = max(1, (int) floor($maxSeconds / $perWord));
            $pieces = [];
            foreach (preg_split('/(?<=[.!?…])\s+/u', $text) ?: [$text] as $sentence) {
                if (self::words($sentence) <= $limit) { $pieces[] = $sentence; continue; }
                foreach (preg_split('/(?<=[,;:—])\s+/u', $sentence) ?: [$sentence] as $part) {
                    if (self::words($part) <= $limit) { $pieces[] = $part; continue; }
                    // No punctuation to split at: by word count.
                    foreach (array_chunk(preg_split('/\s+/u', trim($part)) ?: [], $limit) as $chunk) $pieces[] = implode(' ', $chunk);
                }
            }
            $groups = []; $current = '';
            foreach ($pieces as $piece) {
                $joined = $current === '' ? $piece : $current.' '.$piece;
                if ($current !== '' && self::words($joined) > $limit) { $groups[] = $current; $current = $piece; }
                else $current = $joined;
            }
            if ($current !== '') $groups[] = $current;
            foreach ($groups as $i => $group) {
                $out[] = ['script_text' => $group, 'seconds' => round(self::words($group) * $perWord, 2), 'continues' => $i > 0] + $beat;
            }
        }
        return $out;
    }

    /** Words in any script (str_word_count counts none in non-Latin text, so such lines were never split). */
    private static function words(string $text): int
    {
        return preg_match_all('/\S+/u', $text);
    }

    private static function pack(array $beats, int $maxSeconds = self::MAX_SEGMENT_SECONDS): array
    {
        $beats = self::splitLong($beats, $maxSeconds);
        $chunks = [];
        $current = ['beats' => [], 'seconds' => 0.0];
        foreach ($beats as $beat) {
            $s = max(1.0, (float) ($beat['seconds'] ?? 3));
            if ($current['beats'] !== [] && $current['seconds'] + $s > $maxSeconds) {
                $chunks[] = $current;
                $current = ['beats' => [], 'seconds' => 0.0];
            }
            $current['beats'][] = $beat;
            $current['seconds'] += $s;
        }
        if ($current['beats'] !== []) {
            $chunks[] = $current;
        }

        // Veo accepts exactly 4, 6 or 8 seconds — snap up to the nearest. Omni takes any length up to its cap.
        $snap = $maxSeconds !== self::MAX_SEGMENT_SECONDS ? fn (float $s): int => (int) min($maxSeconds, max(self::MIN_SEGMENT_SECONDS, ceil($s))) : function (float $s): int {
            foreach ([4, 6, 8] as $allowed) {
                if (ceil($s) <= $allowed) {
                    return $allowed;
                }
            }

            return self::MAX_SEGMENT_SECONDS;
        };

        // A clip with words lasts as long as they take at a natural 2.5 words a second, plus a beat to finish: spare
        // seconds were filled with ad-libs and repeats.
        return array_map(function ($c) use ($snap) {
            $words = array_sum(array_map(fn ($b) => self::words((string) ($b['script_text'] ?? '')), $c['beats']));
            $seconds = $words > 0 ? min($c['seconds'], (float) ceil($words / 2.5) + 1) : $c['seconds'];
            return ['beats' => $c['beats'], 'seconds' => $snap($seconds)];
        }, $chunks);
    }

    private static function preamble(array $style): string
    {
        $presenter = trim((string) ($style['presenter'] ?? '')) ?: 'a relatable young creator';
        $setting = trim((string) ($style['setting'] ?? '')) ?: 'a natural everyday setting with soft daylight';
        $product = trim((string) ($style['product'] ?? ''));
        $tone = trim((string) ($style['tone'] ?? '')) ?: 'warm and genuine';

        $wardrobe = trim((string) ($style['wardrobe'] ?? ''));

        return 'A vertical 9:16 UGC-style selfie ad, one continuous handheld take. '
            .ucfirst($presenter).($wardrobe !== '' ? ', wearing '.$wardrobe.',' : '').' talks directly to the phone camera in '.$setting
            .($product !== '' ? ', featuring '.$product : '')
            .'. The delivery is '.$tone.', unpolished and believable.';
    }

    private static function framing(array $beat): string
    {
        // Shot briefs are written for the editor too, which lays a headline over "safe space". A one-take has no
        // overlay step, so the video model drew that text itself, garbled ("UGC her headlines", 2026-10-10 sweep):
        // anything about on-screen text goes before the brief reaches it.
        $brief = trim((string) preg_replace('/[^.;]*\b(headline|caption|on-screen text|overlay|text overlay|title card|safe space)\b[^.;]*[.;]?/iu', '', (string) ($beat['visual_brief'] ?? '')));
        $brief = trim((string) preg_replace('/\s{2,}/', ' ', $brief), " ,;");

        return $brief !== '' ? lcfirst(rtrim($brief, '.')) : 'the same framing, holding eye contact with the camera';
    }
}
