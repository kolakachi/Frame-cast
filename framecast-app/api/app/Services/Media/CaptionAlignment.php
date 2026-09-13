<?php

namespace App\Services\Media;

/**
 * Reconcile speech-recognition timings against the script we actually sent to
 * the voice model.
 *
 * Captions were rendered straight from the recogniser's word list, which made
 * the transcript the source of truth for what appears on screen. It is not: we
 * wrote the line and handed it to TTS, so the words are known exactly. When the
 * recogniser drops one — and it does — that word simply never appears, and a
 * caption silently disagrees with the voice. Observed on a real project: a
 * 73-word script came back as 68 timed words, losing "spend" from "spend hours"
 * and inventing a leading "Ugh".
 *
 * So the recogniser is kept for timing only. Its words are matched to the
 * script by longest common subsequence over a loose normalisation (case and
 * punctuation removed), every script word is emitted whether it was recognised
 * or not, and anything the recogniser invented is discarded. Runs of unmatched
 * script words borrow the gap between their matched neighbours, so the line
 * stays in step with the audio rather than drifting.
 */
final class CaptionAlignment
{
    /**
     * @param  array<int,array{text?:string,word?:string,start?:float,end?:float}>  $asrWords
     * @return array<int,array{text:string,start:float,end:float}>
     */
    public static function align(string $script, array $asrWords, float $audioDuration): array
    {
        $scriptWords = preg_split('/\s+/u', trim($script), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($scriptWords === []) {
            return [];
        }

        $asr = [];
        foreach ($asrWords as $w) {
            if (! is_array($w)) {
                continue;
            }
            $text  = trim((string) ($w['text'] ?? $w['word'] ?? ''));
            $start = (float) ($w['start'] ?? -1);
            $end   = (float) ($w['end'] ?? -1);
            if ($text === '' || $start < 0 || $end < $start) {
                continue;
            }
            $asr[] = ['text' => $text, 'start' => $start, 'end' => $end];
        }

        // Nothing usable to align against: spread the script evenly so captions
        // still appear, rather than showing none at all.
        if ($asr === []) {
            return self::evenlySpaced($scriptWords, max(0.1, $audioDuration));
        }

        $matches = self::matchIndexes(
            array_map([self::class, 'normalise'], $scriptWords),
            array_map(fn (array $w): string => self::normalise($w['text']), $asr),
        );

        $duration = $audioDuration > 0
            ? $audioDuration
            : (float) end($asr)['end'];

        $out = [];
        foreach ($scriptWords as $i => $word) {
            $out[] = [
                'text'  => $word,
                'start' => null,
                'end'   => null,
                'fixed' => isset($matches[$i]),
            ];
            if (isset($matches[$i])) {
                $out[$i]['start'] = $asr[$matches[$i]]['start'];
                $out[$i]['end']   = $asr[$matches[$i]]['end'];
            }
        }

        return self::fillGaps($out, $duration);
    }

    /**
     * Longest common subsequence, returning script index => asr index. Keeps
     * the order of both sides, so a dropped or invented word shifts nothing
     * after it.
     *
     * @param  list<string>  $a  normalised script words
     * @param  list<string>  $b  normalised recognised words
     * @return array<int,int>
     */
    private static function matchIndexes(array $a, array $b): array
    {
        $n = count($a);
        $m = count($b);
        // Table is (n+1)×(m+1) of small ints; scripts are capped at 1,500
        // characters upstream, so this stays well inside a few hundred words.
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));

        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $a[$i] === $b[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }

        $matches = [];
        $i = 0;
        $j = 0;
        while ($i < $n && $j < $m) {
            if ($a[$i] === $b[$j]) {
                $matches[$i] = $j;
                $i++;
                $j++;
            } elseif ($lcs[$i + 1][$j] >= $lcs[$i][$j + 1]) {
                $i++;
            } else {
                $j++;
            }
        }

        return $matches;
    }

    /**
     * Give every unmatched word a slice of the time between its matched
     * neighbours, so a dropped word appears in roughly the right place rather
     * than being stacked onto the word beside it.
     *
     * @param  array<int,array{text:string,start:?float,end:?float,fixed:bool}>  $words
     * @return array<int,array{text:string,start:float,end:float}>
     */
    private static function fillGaps(array $words, float $duration): array
    {
        $count = count($words);

        for ($i = 0; $i < $count; $i++) {
            if ($words[$i]['fixed']) {
                continue;
            }

            // The run of unmatched words starting here.
            $runEnd = $i;
            while ($runEnd + 1 < $count && ! $words[$runEnd + 1]['fixed']) {
                $runEnd++;
            }

            $before = $i > 0 ? (float) $words[$i - 1]['end'] : 0.0;
            $after  = $runEnd + 1 < $count ? (float) $words[$runEnd + 1]['start'] : $duration;

            // Recognition often leaves no gap where it dropped a word: the
            // neighbours simply abut. Rather than overlap the word that
            // follows — which double-highlights during playback — borrow the
            // room from the tail of the word before, taking at most half of it
            // so the borrowed-from word stays legible.
            if ($after <= $before && $i > 0) {
                $need = min(0.25 * ($runEnd - $i + 1), $after - (float) $words[$i - 1]['start']) / 2;
                if ($need > 0) {
                    $before = max((float) $words[$i - 1]['start'], $before - $need);
                    $words[$i - 1]['end'] = $before;
                }
            }
            if ($after <= $before) {
                $after = min($duration, $before + 0.2 * ($runEnd - $i + 1));
            }

            $slice = ($after - $before) / (($runEnd - $i + 1) ?: 1);
            for ($k = $i; $k <= $runEnd; $k++) {
                $words[$k]['start'] = $before + $slice * ($k - $i);
                $words[$k]['end']   = $before + $slice * ($k - $i + 1);
            }

            $i = $runEnd;
        }

        $out = [];
        foreach ($words as $w) {
            $start = (float) $w['start'];
            $end   = (float) $w['end'];
            // The renderer drops anything non-increasing, which would put us
            // back to missing words.
            if ($end <= $start) {
                $end = $start + 0.08;
            }
            $out[] = ['text' => $w['text'], 'start' => round($start, 3), 'end' => round($end, 3)];
        }

        return $out;
    }

    /**
     * @param  list<string>  $scriptWords
     * @return array<int,array{text:string,start:float,end:float}>
     */
    private static function evenlySpaced(array $scriptWords, float $duration): array
    {
        $slice = $duration / max(1, count($scriptWords));
        $out = [];
        foreach ($scriptWords as $i => $word) {
            $out[] = [
                'text'  => $word,
                'start' => round($i * $slice, 3),
                'end'   => round(($i + 1) * $slice, 3),
            ];
        }

        return $out;
    }

    /** Compare on letters and digits only — punctuation and case are not speech. */
    private static function normalise(string $word): string
    {
        return mb_strtolower(preg_replace('/[^\p{L}\p{N}]+/u', '', $word) ?? '');
    }
}
