<?php
namespace App\Services\Create;

/**
 * Settings a brief states in plain words: "square", "20 seconds", "in French",
 * "silent", "no captions". Applied only when unambiguous and supported, so the
 * quote reflects what was asked without a form; anything the lane cannot do is
 * turned into a question rather than silently ignored or approximated.
 */
class BriefSettings
{
    private const RATIOS = [
        '/\b(square|1\s*:\s*1|1x1)\b/i' => '1:1',
        '/\b(portrait|vertical|9\s*:\s*16|9x16|tiktok|reels?|shorts)\b/i' => '9:16',
        '/\b(landscape|horizontal|widescreen|16\s*:\s*9|16x9)\b/i' => '16:9',
        '/\b(4\s*:\s*5|4x5|feed post)\b/i' => '4:5',
    ];

    private const LANGUAGES = ['english' => 'en', 'french' => 'fr', 'spanish' => 'es', 'german' => 'de', 'portuguese' => 'pt',
        'italian' => 'it', 'dutch' => 'nl', 'arabic' => 'ar', 'hindi' => 'hi', 'japanese' => 'ja', 'korean' => 'ko', 'chinese' => 'zh', 'mandarin' => 'zh'];

    private const OTHER_LANGUAGES = ['swahili', 'yoruba', 'igbo', 'hausa', 'russian', 'turkish', 'polish', 'swedish', 'danish', 'norwegian', 'finnish',
        'greek', 'hebrew', 'thai', 'vietnamese', 'indonesian', 'malay', 'tagalog', 'urdu', 'bengali', 'tamil', 'ukrainian', 'czech', 'romanian', 'hungarian'];

    /** @return array{changes: array<string,mixed>, questions: string[]} */
    public static function infer(string $brief, array $settings, bool $referenceVideo = false): array
    {
        $changes = [];
        $questions = [];
        // Quoted words are copy the user wants on screen, not instructions.
        $unquoted = preg_replace('/["“][^"”]*["”]/u', ' ', $brief);
        $text = ' '.preg_replace('/\s+/', ' ', mb_strtolower($unquoted)).' ';
        $video = ($settings['output_kind'] ?? 'video') === 'video';

        // A format is set only when the message names exactly one. Several ("label the tiles 9:16, 1:1, 4:5 and
        // 16:9") are content, not a request to change the video's shape.
        $named = array_values(array_unique(array_filter(array_map(fn ($pattern) => preg_match($pattern, $text) ? self::RATIOS[$pattern] : null, array_keys(self::RATIOS)))));
        if (count($named) === 1 && ($settings['aspect_ratio'] ?? null) !== $named[0]) $changes['aspect_ratio'] = $named[0];

        // A length is the video's only when it is not about a part of it ("a closing card of about 4 seconds",
        // "the first 3 seconds") and the message names one length.
        $lengths = $video ? self::videoLengths($text) : [];
        $minutes = array_values(array_filter($lengths, fn ($l) => $l[1] === 'min'));
        if (count($lengths) === 1 && $lengths[0][1] === 's') {
            $seconds = $lengths[0][0];
            if ($seconds >= 5 && $seconds <= 30) {
                if ((int) ($settings['duration_seconds'] ?? 0) !== $seconds) $changes['duration_seconds'] = $seconds;
            } else {
                $questions[] = "Create makes videos from 5 to 30 seconds. {$seconds} seconds is outside that. Should I make it 30 seconds, or would you rather shorten the brief?";
            }
        } elseif (count($lengths) === 1 && $minutes) {
            $questions[] = "Create makes videos from 5 to 30 seconds, so {$minutes[0][0]} {$minutes[0][2]} is longer than this lane supports. Should I make a 30-second version?";
        }

        if (preg_match('/\b(?:in|into|to) ([a-z]+)\b/', $text, $m)) {
            $word = $m[1];
            if (isset(self::LANGUAGES[$word])) {
                if (($settings['language'] ?? 'en') !== self::LANGUAGES[$word]) $changes['language'] = self::LANGUAGES[$word];
            } elseif (in_array($word, self::OTHER_LANGUAGES, true)) {
                $questions[] = ucfirst($word).' is not one of the languages Create can narrate or caption yet (English, French, Spanish, German, Portuguese, Italian, Dutch, Arabic, Hindi, Japanese, Korean, Chinese). Should I continue in English?';
            }
        }

        if ($video) {
            // Silence is a setting only when the whole video is meant to be silent ("a silent video", "no audio",
            // "mute it"), not when a passage should avoid silence ("so nothing sits silent at the end").
            if (preg_match('/(?:^|[,;:]\s*)silent(?=\s*(?:[,;.]|$))|\b(silent (?:video|ad|clip|reel|version|promo|film)|(?:make|keep|render) it (?:silent|muted?)|(?:no|without) (?:audio|sound|music or voice)|muted? (?:video|version|audio)|mute (?:it|the audio|the sound))\b/', $text) && ! preg_match('/\bnot? (?:be )?(?:silent|muted?)\b|\bnothing (?:\w+ ){0,2}silent\b/', $text)) {
                if (($settings['audio'] ?? 'original') !== 'silent') $changes['audio'] = 'silent';
            } elseif (preg_match('/\bkeep (?:the |my )?(?:original )?(?:audio|sound|voice)\b/', $text)) {
                if (($settings['audio'] ?? 'original') !== 'original') $changes['audio'] = 'original';
            }
            if (preg_match('/\b(no captions?|without captions?|no subtitles?)\b/', $text) && ($settings['captions'] ?? 'off') !== 'off') {
                $changes['captions'] = 'off';
            }
        }

        // How closely to follow an attached reference, when the brief says so (planning asks when it does not).
        $match = empty($settings['reference_match']) ? ReferenceMatch::answer($brief) : ReferenceMatch::change($brief);
        if ($video && $referenceVideo && $match && ($settings['reference_match'] ?? null) !== $match) $changes['reference_match'] = $match;

        return ['changes' => $changes, 'questions' => $questions];
    }

    private const PARTS = 'card|beat|scene|step|shot|hold|intro|outro|hook|close|closing|ending|opening|transition|pause|segment|section|frame|sticker|title|cta|logo|slide|panel|each|per|every|first|last|final';

    /** Distinct lengths that describe the whole video: [[number, 's'|'min', unit word], ...]. */
    private static function videoLengths(string $text): array
    {
        preg_match_all('/\b(\d{1,3})\s*(?:-|\s)?\s*(seconds?|secs?|s|minutes?|mins?)\b/', $text, $all, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        $out = [];
        foreach ($all as $m) {
            $before = substr($text, max(0, $m[0][1] - 40), min(40, $m[0][1]));
            $after = substr($text, $m[0][1] + strlen($m[0][0]), 20);
            if (preg_match('/\b('.self::PARTS.')s?\b/', $before.' '.$after)) continue;
            $unit = str_starts_with($m[2][0], 'm') ? 'min' : 's';
            $out[$m[1][0].$unit] = [(int) $m[1][0], $unit, $m[2][0]];
        }
        return array_values($out);
    }

    /** The sentence the conversation shows for applied changes. */
    public static function describe(array $changes): string
    {
        $parts = [];
        foreach ($changes as $key => $value) {
            $parts[] = match ($key) {
                'aspect_ratio' => 'format '.match ($value) { '1:1' => 'square (1:1)', '16:9' => 'landscape (16:9)', '4:5' => '4:5', default => 'portrait (9:16)' },
                'duration_seconds' => "length {$value} seconds",
                'language' => 'language '.(array_search($value, self::LANGUAGES, true) ? ucfirst(array_search($value, self::LANGUAGES, true)) : $value),
                'audio' => $value === 'silent' ? 'audio off' : 'original audio kept',
                'captions' => 'captions off',
                'reference_match' => match ($value) { 'exact' => 'the reference matched exactly (same timing, layout, transitions and mascot placement, with your brand and content)', 'similar' => 'the reference followed closely (its format, look and pacing, with your own story and shots)', default => 'the reference used as inspiration' },
                default => "$key $value",
            };
        }

        return 'From your brief I set '.implode(', ', $parts).'. Change any of these in Details before approving.';
    }
}
