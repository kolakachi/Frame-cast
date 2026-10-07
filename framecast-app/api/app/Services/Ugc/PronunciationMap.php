<?php

namespace App\Services\Ugc;

/**
 * Turns a client's free-text pronunciation notes into spoken text.
 *
 * The notes reach the scriptwriter as background, but the scriptwriter writes
 * DISPLAY text ("WyvStudio") and the voice — Veo/Seedance for one-shot, TTS
 * for scenes — never saw a phonetic hint, so brand names came out wrong. This
 * respells names ONLY in the copy that becomes speech; the authored script and
 * captions keep their real spelling.
 *
 * Notes are free text, so parsing is best-effort over the shapes people
 * actually write, one mapping per line:
 *   WyvStudio: "wiv studio"
 *   WyvStudio = wiv studio
 *   Say "Nguyen" as "win"
 *   Nguyen (win)
 */
class PronunciationMap
{
    /** @return array<string, string> name => spoken form */
    public static function parse(string $notes): array
    {
        $map = [];
        foreach (preg_split('/[\r\n;]+/', $notes) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            // "Say X as Y" / "pronounce X as Y"
            if (preg_match('/(?:say|pronounce)\s+(.+?)\s+as\s+(.+)/iu', $line, $m)) {
                self::add($map, $m[1], $m[2]);

                continue;
            }
            // X (Y)
            if (preg_match('/^(.+?)\s*\(([^)]+)\)\s*$/u', $line, $m)) {
                self::add($map, $m[1], $m[2]);

                continue;
            }
            // X : Y   |   X = Y   |   X - Y   (dash forms only when a quote or
            // clear phonetic follows, so ordinary hyphenated names survive)
            if (preg_match('/^(.+?)\s*[:=]\s*(.+)$/u', $line, $m)
                || preg_match('/^(.+?)\s+[-–—]\s*(["\'].+)$/u', $line, $m)) {
                self::add($map, $m[1], $m[2]);
            }
        }

        return $map;
    }

    /** Respell every mapped name in the spoken text (whole word, case-insensitive). */
    public static function apply(string $spoken, array $map): string
    {
        foreach ($map as $name => $said) {
            // \b misses names with punctuation/spaces, so guard with
            // non-word lookarounds that also treat string ends as boundaries.
            $spoken = preg_replace(
                '/(?<![\p{L}\p{N}])'.preg_quote($name, '/').'(?![\p{L}\p{N}])/iu',
                $said,
                $spoken,
            ) ?? $spoken;
        }

        return $spoken;
    }

    /** Convenience: parse notes and apply in one step. */
    public static function respell(string $spoken, string $notes): string
    {
        $map = self::parse($notes);

        return $map === [] ? $spoken : self::apply($spoken, $map);
    }

    /**
     * Pronunciations stated in a brief's own words ("say WyvStudio as 'wiv studio'", "WyvStudio is pronounced wiv
     * studio", "WyvStudio (pronounced wiv studio)"). Stricter than parse(): the name must start with a capital or be
     * quoted, and the spoken form ends at punctuation and is at most five words, so ordinary sentences never match.
     *
     * @return array<string, string> name => spoken form
     */
    public static function fromBrief(string $text): array
    {
        $name = '(?:["“\'‘]([^"”\'’]{1,60})["”\'’]|(\p{Lu}[\p{L}\p{N}.&\'-]*(?:\s+\p{Lu}[\p{L}\p{N}.&\'-]*){0,2}))';
        $said = '(?:["“\'‘]([^"”\'’]{1,80})["”\'’]|((?:[\p{L}\p{N}\'-]+)(?:\s+[\p{L}\p{N}\'-]+){0,4}))';
        $map = [];
        $patterns = [
            '/\b(?i:say|pronounce)\s+'.$name.'\s+as\s+(?:in\s+)?'.$said.'/u',
            '/'.$name.'\s*(?:,|\(|\bis\b)?\s*(?:is\s+)?pronounced\s+(?i:as\s+|like\s+)?'.$said.'/u',
        ];
        foreach ($patterns as $re) {
            preg_match_all($re, $text, $all, PREG_SET_ORDER);
            foreach ($all as $m) {
                $written = ($m[1] ?? '') !== '' ? $m[1] : ($m[2] ?? '');
                $spoken = ($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? '');
                if (mb_strtolower(trim($written)) === mb_strtolower(trim($spoken))) continue;
                self::add($map, $written, $spoken);
            }
        }
        return $map;
    }

    private static function add(array &$map, string $name, string $said): void
    {
        $name = trim($name, " \t\"'“”‘’.");
        // Strip surrounding quotes and any trailing note from the spoken form.
        $said = trim($said, " \t\"'“”‘’.");
        if ($name === '' || $said === '' || mb_strlen($name) > 60 || mb_strlen($said) > 80) {
            return;
        }
        $map[$name] = $said;
    }
}
