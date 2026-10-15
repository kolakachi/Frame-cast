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

    /** Omni uses a prompt duration target up to 10 s, without the Veo 4/6/8-second snapping. */
    public const OMNI_SEGMENT_SECONDS = 10;

    /**
     * What every take is told about the people and things in it. The hands rules came from the 2026-10-10 sweep: a box
     * floated in mid-air while one hand held the phone, so a second hand only joins once the phone is set down.
     */
    private const CLOSING = 'Casual creator energy, natural relaxed delivery with no repeated words, false starts or stammering. Only the cuts this script calls for — between them each shot holds as one continuous take, no unrequested angle changes, no camera zoom, natural head movement. Take ONLY the face and identity from the presenter\'s photo, never its background or outfit; the setting, framing, clothing and action come from this description. The presenter matches the reference person\'s face exactly where one is shown; where the presenter is given as a casting description instead, match it precisely — skin tone, hair and age are not negotiable. Either way they stay the same person throughout: same face, same hair, same clothing, no drift or deformation. Real subtle skin texture with natural pores — never a 3D, CGI or beauty-filter look. The phone is the camera filming this and never appears in the frame, except in a shot the description calls a mirror selfie: there the phone stays in one hand the whole shot and only the other hand moves or holds anything. To demonstrate something up close, a mirror selfie switches to the front camera: a cut to a handheld front-camera shot where the phone is no longer seen. Talking shots are handheld, so only one arm and hand are free and visible; anything that needs both hands (applying, opening, pouring) is filmed from the camera propped on the counter or a shelf, both hands free, phone out of sight. When the product, a box, a plate or anything else is not in a hand it rests on a counter, table or shelf. Nothing floats or hovers; anything held shows a real grip and weight, fingers relaxed, at a natural distance from the camera. No counting on fingers and no holding up a number of fingers (models miscount them). No studio lighting. No words, subtitles, captions, titles, graphics or watermarks anywhere on screen: every spoken line is heard, never written (text is added later, never by you) — the product\'s own packaging, label and branding stay visible and legible. There is exactly one of the product: never a second box, bottle or pack beside the one being held or used, unless the description asks for several. No other brand appears anywhere: no logos, brand names or packaging from any other company, on the product or in the room; anything else on screen is plain and unbranded. Natural room tone.';

    /**
     * @param  array<int, array{script_text: string, seconds: float|int, visual_brief: string, voice_direction?: string}>  $beats
     * @param  array{presenter?: string, setting?: string, product?: string, product_look?: string, tone?: string}  $style
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
            $speaker = $chunk['beats'][0]['speaker'] ?? null;
            $chunkStyle = $style;
            if ($speaker && isset($style['speakers'][$speaker])) $chunkStyle['presenter'] = $style['speakers'][$speaker];
            // "Choose your own spot" is for the opening shot only: on a later clip it re-imagined the room each time (take 285).
            if ($i > 0 && ! $speaker) $chunkStyle['place_note'] = '';
            $preamble = self::preamble($chunkStyle);
            foreach ($chunk['beats'] as $j => $beat) {
                if ($j > 0 && ! empty($beat['continues'])) {
                    // The same shot carrying on (a long shot split to fit): no cut, they keep talking.
                } elseif ($j > 0) {
                    // A cut between beats inside one generation — the only
                    // edit UGC permits, landing where a creator would cut.
                    $lines[] = 'Cut to '.self::framing($beat).'.';
                } elseif ($i > 0 && ! empty($beat['continues'])) {
                    // The next piece of a long talking shot is its own scene (owner, 2026-10-12): a jump cut, closer
                    // and wider in turn, never the same shot carrying on (take 281 said its next line's words twice).
                    $lines[] = 'A jump cut to a slightly '.($i % 2 ? 'closer' : 'wider').' framing of the same moment: '.self::framing($beat).'.';
                } else {
                    // The chunk's opening framing — a continuation chunk
                    // needs it as much as the first (a beat's action lives
                    // here; dropping it lost "holds up the bottle").
                    $lines[] = ucfirst(self::framing($beat)).'.';
                }
                if (! empty($beat['done_before'])) $lines[] = 'Already done in the earlier shots, so never repeat it: '.trim($beat['done_before']).' Continue from exactly that state.';
                // Respell only what the voice model hears; stored text stays.
                if (! empty($beat['motion_prompt'])) $lines[] = 'Action and sound: '.rtrim(trim($beat['motion_prompt']), '.').'.';
                $said = PronunciationMap::apply(trim((string) ($beat['script_text'] ?? '')), $pron);
                if ($said !== '') {
                    $delivery = trim((string) ($beat['voice_direction'] ?? ''));
                    $lines[] = ($delivery !== '' ? 'The presenter says, '.lcfirst(rtrim($delivery, '.')).': ' : 'The presenter says: ')
                        .'"'.$said.'"';
                    $dialogue[] = $said;
                }
            }

            // Every clip is a scene with its own statement (owner, 2026-10-12): each opens on a cut, so it can be cut back to
            // exactly its words and the next clip says only its own.
            $newShot = true;
            // Exactly the scripted words (2026-10-10: a listicle came back with "One, two, three." and an ad-libbed
            // line between its scripted ones, which sounded like repeats at the joins).
            $wordsOnly = $dialogue !== []
                ? ' They say exactly these words, in this order, and nothing else: no added words or ad-libs. Say numbers only where they appear in the script. Finish each quoted line completely before any cut or change of shot. Never cut away mid-word or mid-sentence. After the last word they stop talking and hold a natural, relaxed expression for a full second.'
                : '';
            // The same room, not a re-imagined one (take 283's office changed between clips).
            $continuation = $i === 0 || $speaker ? '' : 'The next shot of the same presenter in the same room: same furniture, walls, window, hair, outfit and light as the previous shot\'s frame in the references. ';
            // Two hands for the action means the phone is not in one of them (take 283 held the phone, the pouch and the
            // peanuts at once): the phone is propped for this shot, whatever the shot's own wording says.
            $actionText = implode(' ', array_map(fn ($b) => ($b['motion_prompt'] ?? '').' '.($b['visual_brief'] ?? ''), $chunk['beats']));
            if (self::twoHanded($actionText)) {
                $preamble = str_replace(['one continuous handheld take', 'talks directly to the phone camera'], ['filmed on a phone propped on a surface', 'talks directly to the propped phone camera'], $preamble);
                $lines = array_map(fn ($l) => (string) preg_replace('/\bhand-?held\s*/iu', '', $l), $lines);
                $lines[] = 'Staging: the phone is propped on the desk, table or counter for this shot, so both hands are free; no hand holds a phone.';
            }

            // A silent shot is that shot alone (take 285: a 2-second bite asked for 4 opened on 2 seconds of the presenter
            // talking, unscripted, in another kitchen): no delivery, no talking to camera.
            if ($dialogue === []) {
                $preamble = (string) preg_replace('/ The delivery is [^.]*\./u', '', str_replace([' talks directly to the propped phone camera in ', ' talks directly to the phone camera in '], ' appears in ', $preamble));
                $lines[0] = 'One silent shot from the first frame to the last, nothing before or after it: '.lcfirst($lines[0] ?? '');
            }

            $out[] = [
                'prompt' => $preamble."\n\n".$continuation.implode(' ', $lines).$wordsOnly.($dialogue === [] ? ' No speech, narration, singing or music. Preserve the described physical sounds.' : '')
                    ."\n\n".self::CLOSING,
                'seconds' => $chunk['seconds'],
                'dialogue' => implode(' ', $dialogue),
                'new_shot' => $newShot,
                'requires_audio' => ($style['ugc_type'] ?? '') === 'asmr',
                'speaker' => $speaker,
                'source_asset_id' => $chunk['beats'][0]['asset_id'] ?? null,
                'source_offset' => $chunk['beats'][0]['source_offset'] ?? 0,
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
        $total = (int) ceil(array_sum(array_map(fn ($b) => max(1.0, (float) ($b['seconds'] ?? 3), self::speechSeconds($b)), $beats)));
        if ($total > $maxSeconds) {
            return null;
        }

        $pron = PronunciationMap::parse((string) ($style['pronunciations'] ?? ''));
        $demo = ! empty($style['demo']);
        $lines = [];
        $dialogue = [];
        foreach ($beats as $j => $beat) {
            $lines[] = ($j === 0 ? ucfirst(self::framing($beat)) : 'Cut to '.self::framing($beat)).'.';
            if (! empty($beat['motion_prompt'])) $lines[] = 'Action and sound: '.rtrim(trim($beat['motion_prompt']), '.').'.';
            // Two hands for the action: the phone is propped for this shot (take 283).
            if (self::twoHanded(($beat['motion_prompt'] ?? '').' '.($beat['visual_brief'] ?? ''))) $lines[] = 'For this shot the phone is propped on a surface, so both hands are free; no hand holds a phone.';
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

".implode(' ', $lines).$demoLine.($dialogue === [] ? ' No speech, narration, singing or music. Preserve the described physical sounds.' : ' Say exactly the quoted words, including scripted numbers, and no others. Finish each line before cutting. Hold for a full second after the final word.')
                ."

".self::CLOSING,
            'seconds' => max(4, $total),
            'requires_audio' => ($style['ugc_type'] ?? '') === 'asmr',
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
            $seconds = max(1.0, (float) ($beat['seconds'] ?? 3), self::speechSeconds($beat));
            $beat['seconds'] = $seconds;
            if ($words === 0 && $seconds > $maxSeconds) {
                for ($remaining = $seconds; $remaining > 0; $remaining -= $maxSeconds) {
                    $out[] = ['seconds' => min($remaining, $maxSeconds), 'source_offset' => $seconds - $remaining] + $beat;
                }
                continue;
            }

            if ($seconds <= $maxSeconds || $words < 2) { $out[] = $beat; continue; }
            // Every generated clip needs its own finishing pause; do not divide one pause across the whole ad.
            $perWord = ($seconds - 1.0) / $words;
            $limit = max(1, (int) floor(($maxSeconds - 1.0) / $perWord));
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
            $offset = 0.0;
            // The shot's actions are shared out over its pieces, in order (take 283: every piece got the whole routine,
            // so the pouch was opened and poured three times). Later pieces start where the last one ended.
            $actions = self::actions((string) ($beat['motion_prompt'] ?? ''));
            $n = count($groups);
            foreach ($groups as $i => $group) {
                $mine = array_slice($actions, intdiv($i * count($actions), $n), intdiv(($i + 1) * count($actions), $n) - intdiv($i * count($actions), $n));
                $done = array_slice($actions, 0, intdiv($i * count($actions), $n));
                $piece = ['script_text' => $group, 'seconds' => min($maxSeconds, self::words($group) * $perWord + 1.0), 'continues' => $i > 0, 'source_offset' => $offset,
                    'motion_prompt' => implode(' ', $mine), 'done_before' => implode(' ', $done)] + $beat;
                // A later piece keeps the place and framing, never the actions the brief may narrate.
                if ($i > 0) $piece['visual_brief'] = self::settingOnly((string) ($beat['visual_brief'] ?? '')) ?: 'the same setup as the previous shot';
                $out[] = $piece;
                $offset += self::words($group) * $perWord + 1.0;
            }
        }
        return $out;
    }

    /** A brief without its sentences about doing things: the place, framing and light only. */
    private static function settingOnly(string $brief): string
    {
        $keep = array_filter(preg_split('/(?<=[.;])\s+/u', trim($brief)) ?: [], fn ($sentence) => UgcPlan::actionKinds($sentence) === []
            && ! self::twoHanded($sentence) && ! preg_match('/\b(holds?|holding|lifts?|shows?|reaches?|grabs?|starts by|then)\b/iu', $sentence));

        return trim(implode(' ', $keep));
    }

    /** A shot's actions, one per sentence or "then", in order. */
    private static function actions(string $motion): array
    {
        $parts = preg_split('/(?<=[.!?;])\s+|,?\s+then\s+/iu', trim($motion)) ?: [];

        return array_values(array_filter(array_map(fn ($p) => rtrim(trim($p), '.;').'.', $parts), fn ($p) => mb_strlen($p) > 2));
    }

    /** Actions that need both hands: the phone cannot be in one of them. */
    public const TWO_HANDED = '/\b(open(s|ed|ing)?|peel(s|ed|ing)?|tear(s|ing)?|rip(s|ping)?|pour(s|ed|ing)?|unscrew(s|ing)?|unwrap(s|ping)?|unbox(es|ing)?|appl(y|ies|ying)|squeez(e|es|ing)|scoop(s|ing)?|spread(s|ing)?|cut(s|ting)?|mix(es|ing)?|shak(e|es|ing)|tips? [^.]{0,40}into (her|his|their) (hand|palm))\b/iu';

    public static function twoHanded(string $text): bool
    {
        // "An open-plan office" or "opens up about" is not opening anything.
        $text = (string) preg_replace(['/\bopen(s)?(-|\s)(plan|kitchen|shelv\w*|window\w*|air|space|up)\b/iu', '/\b(jump\s)?cuts?\s(to|away|back)\b|\bcutaway\b/iu'], '', $text);

        return (bool) preg_match(self::TWO_HANDED, $text);
    }

    /** Allow natural speech plus a full second to finish each generated clip. */
    private static function speechSeconds(array $beat): float
    {
        $words = self::words(trim((string) ($beat['script_text'] ?? '')));
        $speed = max(0.5, min(2.0, (float) ($beat['speed'] ?? 1)));

        return $words > 0 ? $words / (2.5 * $speed) + 1.0 : 0.0;
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
            if ($current['beats'] !== [] && ($current['seconds'] + $s > $maxSeconds || ! empty($beat['asset_id']) || ! empty($current['beats'][0]['asset_id']) || ($current['beats'][0]['speaker'] ?? null) !== ($beat['speaker'] ?? null) || trim((string) ($beat['script_text'] ?? '')) === '' || trim((string) ($current['beats'][0]['script_text'] ?? '')) === '')) {
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
        // Omni takes any length, so a short silent action is asked for its own length, never padded to 4 seconds.
        $snap = $maxSeconds !== self::MAX_SEGMENT_SECONDS ? fn (float $s, bool $silent = false): int => (int) min($maxSeconds, max($silent ? 2 : self::MIN_SEGMENT_SECONDS, ceil($s))) : function (float $s, bool $silent = false): int {
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
            $speech = array_sum(array_map(fn ($b) => max(0.0, self::speechSeconds($b) - 1.0), $c['beats'])) + 1.0;
            $seconds = $words > 0 ? $speech : $c['seconds'];
            return ['beats' => $c['beats'], 'seconds' => $snap($seconds, $words === 0)];
        }, $chunks);
    }

    private static function preamble(array $style): string
    {
        $presenter = trim((string) ($style['presenter'] ?? '')) ?: 'a relatable young creator';
        $setting = trim((string) ($style['setting'] ?? '')) ?: 'a natural everyday setting with soft daylight';
        $product = trim((string) ($style['product'] ?? ''));
        $tone = trim((string) ($style['tone'] ?? '')) ?: 'warm and genuine';

        $wardrobe = trim((string) ($style['wardrobe'] ?? ''));

        $type = (string) ($style['ugc_type'] ?? 'testimonial');
        $scenes = in_array($type, ['asmr', 'unboxing', 'day_in_life', 'problem_solution', 'before_after', 'comparison', 'skit'], true);
        // A presenter talking to camera keeps the selfie take it was proven on (#2, 2026-10-12): "appears as directed"
        // left the direct-to-camera delivery to each shot's wording.
        $opening = $scenes
            ? 'A vertical 9:16 creator video. Follow each shot\'s framing and action exactly, including product-only close-ups. '
            : 'A vertical 9:16 UGC-style selfie ad, one continuous handheld take. ';
        if ($type === 'asmr') $opening .= 'ASMR: macro close-ups of hands and the product only, never a face; slow, deliberate movements, each shot built around one clear, crisp physical sound close to the microphone (the crack, pour, fizz, crunch, peel, tap or spray it names); a quiet room, no music, no voiceover; speech only when explicitly scripted, always whispered. ';
        if ($type === 'skit') $opening .= 'Shot/reverse-shot dialogue. Only the selected speaker appears and speaks in this shot, looking toward the other person off camera. ';
        if ($type === 'green_screen') {
            $wardrobe = 'a charcoal grey top';
            $key = match ($style['chroma_key'] ?? '') { '0x0000FF' => 'blue (#0000FF)', '0xFF00FF' => 'magenta (#FF00FF)', default => 'green (#00FF00)' };
            $setting = 'a completely flat evenly lit pure chroma '.$key.' background, no shadows or scenery';
            $opening .= 'Locked camera, waist-up presenter fully inside frame. The presenter wears neutral grey. Use this exact chroma colour even if a shot description names a different backdrop. Preserve the product colours. The chroma backdrop must fill every background pixel for compositing. ';
            // Where they will sit over the page (2026-10-14): turned a little toward it, so a gesture points at what is there.
            $placement = (string) ($style['placement'] ?? 'bottom_right');
            $toward = str_ends_with($placement, '_right') ? 'left' : (str_ends_with($placement, '_left') ? 'right' : null);
            if (str_starts_with($placement, 'bubble')) $opening .= 'Head and shoulders, centred, facing the lens. ';
            elseif ($toward !== null) $opening .= 'The page they talk about will be to the '.$toward.' of the frame: they turn slightly toward the '.$toward.' edge and, when a line refers to the page, gesture toward it. ';
        }
        return $opening
            .ucfirst($presenter).($wardrobe !== '' ? ', wearing '.$wardrobe.',' : '').($type === 'asmr' ? ' supplies only hands when the shot calls for hands, in ' : ($type === 'skit' ? ' performs the scripted speaker in ' : ($scenes ? ' appears as directed in ' : ($type === 'green_screen' ? ' talks directly to the camera in ' : ' talks directly to the phone camera in ')))).$setting
            .($product !== '' ? ', featuring '.$product : '')
            .'. The delivery is '.$tone.', unpolished and believable.'
            // The product as its photos show it (VISION, 2026-10-11): a squeeze tube came out as a pump bottle in 3 of 6.
            // Never read aloud: take 281's presenter read "fragrance-free" off the label in a spare second. Worded without
            // "words seen on the pack": that version came back with burned-in subtitles (take 282, 2026-10-12).
            .(trim((string) ($style['product_look'] ?? '')) !== '' ? ' The product is exactly the one in the product reference photos: '.rtrim(trim((string) $style['product_look']), '.')
                .'. Keep its exact form, size, colours, cap and label in every shot; never turn it into a different kind of container or package. The presenter only says their quoted lines and never reads the packaging aloud.' : '')
            .($type !== 'green_screen' && trim((string) ($style['place_note'] ?? '')) !== '' ? ' '.trim((string) $style['place_note']) : '');
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
