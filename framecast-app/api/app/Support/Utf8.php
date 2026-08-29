<?php

namespace App\Support;

/**
 * Make externally-sourced text safe to store in Postgres.
 *
 * Postgres validates UTF8 strictly and rejects the whole statement on a bad
 * byte — it does not substitute or truncate. So a single malformed character
 * anywhere in an extracted document fails the INSERT and takes the entire
 * generation with it.
 *
 * The case that bit us: a PDF of course notes produced 0xED 0xA0 0xB5, a
 * UTF-8-encoded lone surrogate (U+D800–U+DFFF). Those are legal in UTF-16 as
 * half of a pair, and PDF text extraction can emit them unpaired, but they are
 * illegal in UTF-8 and Postgres refuses them:
 *
 *   SQLSTATE[22021]: invalid byte sequence for encoding "UTF8": 0xed 0xa0 0xb5
 *
 * Anything read from outside — PDFs, scraped pages, transcripts — should pass
 * through here before it reaches the database or a prompt.
 */
class Utf8
{
    public static function clean(?string $text): string
    {
        if ($text === null || $text === '') {
            return '';
        }

        // UTF-8-encoded surrogate halves: ED A0 80 … ED BF BF.
        $text = preg_replace('/\xED[\xA0-\xBF][\x80-\xBF]/', '', $text) ?? $text;

        // Null bytes are rejected by Postgres text columns outright.
        $text = str_replace("\0", '', $text);

        // Drop anything else that isn't well-formed UTF-8. //IGNORE discards
        // the bad bytes rather than throwing, which is what we want — one odd
        // glyph should never fail a document.
        if (! mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            $text = $converted !== false ? $converted : mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        }

        // Control characters (except tab/newline/carriage return) survive the
        // encoding check but render as garbage in a script.
        return preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text) ?? $text;
    }
}
