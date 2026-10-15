<?php

namespace App\Services\Ugc;

use App\Services\Media\FontMetrics;

/** One layout contract for editor and FFmpeg. Headlines are independent of speech captions. */
final class UgcHeadline
{
    public static function layout(string $text): array
    {
        $text = str_replace(['\\', '{', '}'], ['＼', '｛', '｝'], mb_substr(trim($text), 0, 180));
        $lines = [];
        foreach (preg_split('/\R/u', $text) as $paragraph) {
            $line = '';
            foreach (preg_split('/\s+/u', trim($paragraph), -1, PREG_SPLIT_NO_EMPTY) as $word) {
                if ($line !== '' && mb_strlen($line.' '.$word) > 30) {
                    $lines[] = $line;
                    $line = '';
                }
                while (mb_strlen($word) > 30) {
                    $lines[] = mb_substr($word, 0, 30);
                    $word = mb_substr($word, 30);
                }
                $line = $line === '' ? $word : $line.' '.$word;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
        }

        return ['text' => $text, 'lines' => $lines, 'font' => 'Arial', 'font_width_ratio' => 0.041,
            'line_height' => 1.2, 'top_ratio' => 0.10, 'stroke_width_ratio' => 0.002];
    }

    /**
     * When each headline shows, from the plan: a shot's headline from its start until the next shot with one.
     *
     * @return list<array{text: string, start: float, end: float}>
     */
    public static function cues(array $segments): array
    {
        $cues = [];
        $at = 0.0;
        foreach ($segments as $s) {
            $text = trim((string) ($s['headline'] ?? ''));
            if ($text !== '') {
                if ($cues) $cues[count($cues) - 1]['end'] = $at;
                $cues[] = ['text' => $text, 'start' => $at, 'end' => 0.0];
            }
            $at += max(0.0, (float) ($s['seconds'] ?? 0));
        }
        if ($cues) $cues[count($cues) - 1]['end'] = $at;
        return $cues;
    }

    /** Timed headlines, stretched to the video's real length (a take can run a little over or under its plan). */
    public static function assTimed(array $cues, int $width, int $height, float $duration): string
    {
        $planned = (float) (end($cues)['end'] ?? 0);
        $scale = $planned > 0 ? $duration / $planned : 1.0;
        $out = '';
        foreach ($cues as $i => $cue) {
            $ass = self::ass((string) $cue['text'], $width, $height, (float) $cue['end'] * $scale, (float) $cue['start'] * $scale);
            // One header, then every cue's lines.
            $out .= $i === 0 ? $ass : substr($ass, strpos($ass, "Dialogue:"));
        }
        return $out;
    }

    public static function ass(string $text, int $width, int $height, float $duration, float $start = 0.0): string
    {
        $layout = self::layout($text);
        $size = $width * $layout['font_width_ratio'];
        $metrics = app(FontMetrics::class);
        // Arial is substituted with metric-compatible Liberation Sans on Linux.
        // SVG font-size is an em; ASS Fontsize is the Win ascent+descent box.
        $family = $metrics->assFontSize('Arial', $size) !== null ? 'Arial' : 'Liberation Sans';
        $assSize = $metrics->assFontSize($family, $size) ?? $size * 1.1171875;
        $descent = $metrics->verticalMetrics($family, $size)['descent'] ?? $size * 0.2119140625;
        $outline = $width * $layout['stroke_width_ratio'];
        $x = $width / 2;
        $top = $height * $layout['top_ratio'];
        $time = function (float $seconds): string {
            $cs = (int) round($seconds * 100);
            return sprintf('%d:%02d:%02d.%02d', intdiv($cs, 360000), intdiv($cs, 6000) % 60, intdiv($cs, 100) % 60, $cs % 100);
        };
        $end = $time($duration);
        $from = $time($start);
        $ass = "[Script Info]\nScriptType: v4.00+\nPlayResX: {$width}\nPlayResY: {$height}\nScaledBorderAndShadow: yes\nWrapStyle: 2\n\n[V4+ Styles]\n";
        $ass .= "Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding\n";
        $ass .= "Style: Headline,{$family},{$assSize},&H00FFFFFF,&H00FFFFFF,&H00000000,&H00000000,-1,0,0,0,100,100,0,0,1,{$outline},0,2,0,0,0,1\n\n[Events]\n";
        $ass .= "Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text\n";
        foreach ($layout['lines'] as $i => $line) {
            // Never allow user text to inject ASS overrides or line escapes.
            $safe = str_replace(['\\', '{', '}'], ['＼', '｛', '｝'], $line);
            $y = $top + $size + $descent + $i * $size * $layout['line_height'];
            $ass .= "Dialogue: 1,{$from},{$end},Headline,,0,0,0,,{\\an2\\pos({$x},{$y})}{$safe}\n";
        }

        return $ass;
    }
}
