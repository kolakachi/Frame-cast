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
