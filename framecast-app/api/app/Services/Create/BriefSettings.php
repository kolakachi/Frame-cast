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
    public static function infer(string $brief, array $settings): array
    {
        $changes = [];
        $questions = [];
        $text = ' '.preg_replace('/\s+/', ' ', mb_strtolower($brief)).' ';
        $video = ($settings['output_kind'] ?? 'video') === 'video';

        foreach (self::RATIOS as $pattern => $ratio) {
            if (preg_match($pattern, $text)) {
                if (($settings['aspect_ratio'] ?? null) !== $ratio) $changes['aspect_ratio'] = $ratio;
                break;
            }
        }

        if ($video && preg_match('/\b(\d{1,3})\s*(?:-|\s)?\s*(seconds?|secs?|s)\b/', $text, $m) && ! preg_match('/\b\d{1,3}\s*(?:-|\s)?\s*(minutes?|mins?)\b/', $text)) {
            $seconds = (int) $m[1];
            if ($seconds >= 5 && $seconds <= 30) {
                if ((int) ($settings['duration_seconds'] ?? 0) !== $seconds) $changes['duration_seconds'] = $seconds;
            } else {
                $questions[] = "Create makes videos from 5 to 30 seconds. {$seconds} seconds is outside that. Should I make it 30 seconds, or would you rather shorten the brief?";
            }
        } elseif ($video && preg_match('/\b(\d{1,3})\s*(minutes?|mins?)\b/', $text, $m)) {
            $questions[] = "Create makes videos from 5 to 30 seconds, so {$m[1]} {$m[2]} is longer than this lane supports. Should I make a 30-second version?";
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
            if (preg_match('/\b(silent|no audio|no sound|mute[d]?|without (?:audio|sound))\b/', $text)) {
                if (($settings['audio'] ?? 'original') !== 'silent') $changes['audio'] = 'silent';
            } elseif (preg_match('/\bkeep (?:the |my )?(?:original )?(?:audio|sound|voice)\b/', $text)) {
                if (($settings['audio'] ?? 'original') !== 'original') $changes['audio'] = 'original';
            }
            if (preg_match('/\b(no captions?|without captions?|no subtitles?)\b/', $text) && ($settings['captions'] ?? 'off') !== 'off') {
                $changes['captions'] = 'off';
            }
        }

        return ['changes' => $changes, 'questions' => $questions];
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
                default => "$key $value",
            };
        }

        return 'From your brief I set '.implode(', ', $parts).'. Change any of these in Details before approving.';
    }
}
