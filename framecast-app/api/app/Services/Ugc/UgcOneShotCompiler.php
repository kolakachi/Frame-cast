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

    /**
     * @param  array<int, array{script_text: string, seconds: float|int, visual_brief: string, voice_direction?: string}>  $beats
     * @param  array{presenter?: string, setting?: string, product?: string, tone?: string}  $style
     * @return array<int, array{prompt: string, seconds: int, dialogue: string}>
     */
    public static function compile(array $beats, array $style = []): array
    {
        $beats = array_values(array_filter($beats, fn ($b) => is_array($b)));
        if ($beats === []) {
            return [];
        }

        $chunks = self::pack($beats);
        $preamble = self::preamble($style);

        $out = [];
        foreach ($chunks as $i => $chunk) {
            $lines = [];
            $dialogue = [];
            foreach ($chunk['beats'] as $j => $beat) {
                if ($j > 0) {
                    // A cut between beats inside one generation — the only
                    // edit UGC permits, landing where a creator would cut.
                    $lines[] = 'Cut to '.self::framing($beat).'.';
                } else {
                    // The chunk's opening framing — a continuation chunk
                    // needs it as much as the first (a beat's action lives
                    // here; dropping it lost "holds up the bottle").
                    $lines[] = ucfirst(self::framing($beat)).'.';
                }
                $said = trim((string) ($beat['script_text'] ?? ''));
                if ($said !== '') {
                    $delivery = trim((string) ($beat['voice_direction'] ?? ''));
                    $lines[] = ($delivery !== '' ? 'The presenter says, '.lcfirst(rtrim($delivery, '.')).': ' : 'The presenter says: ')
                        .'"'.$said.'"';
                    $dialogue[] = $said;
                }
            }

            $continuation = $i === 0 ? '' : 'The same presenter — same outfit, same setting, same light — continues without a break. ';

            $out[] = [
                'prompt' => $preamble."\n\n".$continuation.implode(' ', $lines)
                    ."\n\nCasual creator energy, natural imperfect delivery, no studio lighting, no text overlays, no logos or watermarks. Natural room tone.",
                'seconds' => $chunk['seconds'],
                'dialogue' => implode(' ', $dialogue),
            ];
        }

        return $out;
    }

    /** Beats greedily packed into 4–8s generations, cuts kept inside chunks. */
    private static function pack(array $beats): array
    {
        $chunks = [];
        $current = ['beats' => [], 'seconds' => 0.0];
        foreach ($beats as $beat) {
            $s = max(1.0, (float) ($beat['seconds'] ?? 3));
            if ($current['beats'] !== [] && $current['seconds'] + $s > self::MAX_SEGMENT_SECONDS) {
                $chunks[] = $current;
                $current = ['beats' => [], 'seconds' => 0.0];
            }
            $current['beats'][] = $beat;
            $current['seconds'] += $s;
        }
        if ($current['beats'] !== []) {
            $chunks[] = $current;
        }

        // Veo accepts exactly 4, 6 or 8 seconds — snap up to the nearest.
        $snap = function (float $s): int {
            foreach ([4, 6, 8] as $allowed) {
                if (ceil($s) <= $allowed) {
                    return $allowed;
                }
            }

            return self::MAX_SEGMENT_SECONDS;
        };

        return array_map(fn ($c) => [
            'beats' => $c['beats'],
            'seconds' => $snap($c['seconds']),
        ], $chunks);
    }

    private static function preamble(array $style): string
    {
        $presenter = trim((string) ($style['presenter'] ?? '')) ?: 'a relatable young creator';
        $setting = trim((string) ($style['setting'] ?? '')) ?: 'a natural everyday setting with soft daylight';
        $product = trim((string) ($style['product'] ?? ''));
        $tone = trim((string) ($style['tone'] ?? '')) ?: 'warm and genuine';

        return 'A vertical 9:16 UGC-style selfie ad, one continuous handheld take. '
            .ucfirst($presenter).' talks directly to the phone camera in '.$setting
            .($product !== '' ? ', featuring '.$product : '')
            .'. The delivery is '.$tone.', unpolished and believable.';
    }

    private static function framing(array $beat): string
    {
        $brief = trim((string) ($beat['visual_brief'] ?? ''));

        return $brief !== '' ? lcfirst(rtrim($brief, '.')) : 'the same framing, holding eye contact with the camera';
    }
}
