<?php

namespace App\Support;

/**
 * The language a brief is written in, for the languages videos can be made in (en, es, fr, de, pt, it, hi, ja, ar,
 * zh), without a model call (#11, 2026-10-12). Scripts by their writing system; the Latin-script languages by their
 * commonest small words and letters. Null when it is not clear: the caller keeps its own default.
 */
final class TextLanguage
{
    private const WORDS = [
        'en' => ['the', 'and', 'is', 'are', 'you', 'your', 'of', 'to', 'with', 'for', 'that', 'this', 'it', 'my', 'we', 'what', 'how', 'our', 'they', 'be', 'about'],
        'pt' => ['que', 'não', 'nao', 'uma', 'um', 'da', 'do', 'das', 'dos', 'para', 'com', 'os', 'você', 'voce', 'minha', 'meu', 'é', 'mais', 'por', 'na', 'no', 'eu', 'isso', 'mesma', 'também', 'quando', 'como', 'pra'],
        'es' => ['que', 'una', 'un', 'la', 'el', 'para', 'con', 'los', 'las', 'es', 'tu', 'mi', 'más', 'por', 'en', 'y', 'lo', 'yo', 'eso', 'usted', 'del', 'pero', 'cuando', 'como', 'muy'],
        'fr' => ['le', 'la', 'les', 'des', 'une', 'un', 'et', 'est', 'pour', 'avec', 'vous', 'je', 'que', 'pas', 'ce', 'mon', 'ma', 'dans', 'du', 'au', 'sur', 'qui', 'nous', 'votre'],
        'de' => ['der', 'die', 'das', 'und', 'ist', 'nicht', 'ein', 'eine', 'mit', 'für', 'ich', 'sie', 'wir', 'zu', 'auf', 'den', 'dem', 'du', 'auch', 'ihr', 'sich', 'wie'],
        'it' => ['il', 'la', 'le', 'che', 'non', 'una', 'un', 'di', 'per', 'con', 'sono', 'è', 'mio', 'mia', 'gli', 'del', 'della', 'anche', 'io', 'questo', 'come', 'più'],
    ];

    /** Letters only one of them uses: each one found counts like a word. */
    private const LETTERS = ['pt' => '/[ãõç]/u', 'es' => '/[ñ¿¡]/u', 'de' => '/[äöüß]/u', 'fr' => '/[êèçœû]/u', 'it' => '/[ìò]/u'];

    public static function detect(string $text): ?string
    {
        $text = mb_strtolower(mb_substr(trim($text), 0, 4000));
        if ($text === '') return null;
        $letters = max(1, preg_match_all('/\p{L}/u', $text));
        foreach (['ja' => '/[\p{Hiragana}\p{Katakana}]/u', 'zh' => '/\p{Han}/u', 'ar' => '/\p{Arabic}/u', 'hi' => '/\p{Devanagari}/u'] as $lang => $re) {
            if (preg_match_all($re, $text) / $letters > 0.3) return $lang;
        }
        $words = preg_split('/[^\p{L}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($words) < 6) return null;
        $scores = array_fill_keys(array_keys(self::WORDS), 0);
        $counts = array_count_values($words);
        foreach (self::WORDS as $lang => $list) {
            foreach ($list as $w) $scores[$lang] += $counts[$w] ?? 0;
            if (isset(self::LETTERS[$lang])) $scores[$lang] += preg_match_all(self::LETTERS[$lang], $text);
        }
        arsort($scores);
        $top = array_key_first($scores);
        $runnerUp = array_values($scores)[1] ?? 0;
        // Clear only: enough small words, and well ahead of the next language.
        return $scores[$top] >= 4 && $scores[$top] >= 1.5 * $runnerUp ? $top : null;
    }
}
