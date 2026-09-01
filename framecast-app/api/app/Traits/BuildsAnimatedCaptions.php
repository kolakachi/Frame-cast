<?php

namespace App\Traits;

/**
 * Animated caption (.ass) generators — the export-side twins of the browser
 * presets in web/src/components/CaptionPreview.vue. Every generator works on
 * per-word timestamps and emits one Dialogue event per visual state change,
 * which libass renders natively (\t transforms, karaoke alpha, BorderStyle=3
 * boxes, per-run \3c box colors, animated \blur).
 *
 * `animation` absent or "plain" never reaches this trait — the caller keeps
 * the original static builder, so existing projects export byte-identical.
 */
trait BuildsAnimatedCaptions
{
    /** Presets rendered as ALL CAPS on export (mirrors the CSS text-transform). */
    protected const ANIM_UPPERCASE = ['beast', 'comic', 'sticker', 'karaoke', 'blur', 'punch', 'tracking', 'neon'];

    /** Font-size multiplier per preset (mirrors the component's em scale). */
    protected const ANIM_FONT_SCALE = [
        'beast' => 1.55, 'comic' => 1.40, 'glitch' => 1.45, 'sticker' => 1.15,
        'punch' => 1.10, 'blur' => 1.10, 'marker' => 1.05, 'neon' => 0.95,
        'stream' => 0.85, 'news' => 0.75,
    ];

    /**
     * Heavy-cut font substitution: libass's Bold flag only reaches weight
     * 700, so presets designed around 800/900 swap to the named family of
     * the bundled cut (resources/fonts). Applied ONLY when the user is on
     * the preset's default font — a custom font choice is left alone.
     */
    protected const ANIM_FONT_FAMILY = [
        'beast' => ['Montserrat' => 'Montserrat Black'],
        'punch' => ['Montserrat' => 'Montserrat Black'],
        'karaoke' => ['Montserrat' => 'Montserrat ExtraBold'],
        'box' => ['Nunito' => 'Nunito ExtraBold'],
        'news' => ['Nunito' => 'Nunito ExtraBold'],
        'stream' => ['Roboto Mono' => 'Roboto Mono Medium'],
    ];

    /** Words per line per preset (word_by_word highlight mode forces 1). */
    protected const ANIM_CHUNK = [
        'beast' => 1, 'comic' => 1, 'glitch' => 1,
        'sticker' => 3, 'blur' => 3, 'punch' => 3, 'neon' => 3, 'marker' => 3,
        'stream' => 8, 'news' => 5,
    ];

    /**
     * @param array<int,array{text:string,start:float,end:float}> $timedWords
     * @param array{
     *   playResX:int, playResY:int, alignment:int, marginV:int, marginLR:int,
     *   fontName:string, fontSize:int, primary:string, highlight:string,
     *   highlightStyle:string, panelColor:?string, backdrop:?bool, duration:float
     * } $ctx
     */
    protected function buildAnimatedASSContent(string $animation, array $timedWords, array $ctx): string
    {
        $fontSize = (int) round($ctx['fontSize'] * (self::ANIM_FONT_SCALE[$animation] ?? 1.0));
        $isPanelPreset = in_array($animation, ['stream', 'news'], true);
        $backdrop = $ctx['backdrop'] ?? null;
        $panelOn = $isPanelPreset ? ($backdrop !== false) : ($backdrop === true);

        // News Bar defaults to dark text on its light bar unless the user
        // picked a non-default text color (or removed the bar).
        $primaryCss = $ctx['primary'];
        if ($animation === 'news' && $panelOn && in_array(strtolower($primaryCss), ['#fff', '#ffffff'], true)) {
            $primaryCss = '#111111';
        }

        [$primary] = $this->assColorAlpha($primaryCss);
        $highlightCss = $ctx['highlightStyle'] === 'plain' ? $primaryCss : $ctx['highlight'];
        [$highlight] = $this->assColorAlpha($highlightCss);
        $underline = $ctx['highlightStyle'] === 'underline';

        $panelCss = $ctx['panelColor'] ?: ($animation === 'news' ? '#ffffff' : 'rgba(0,0,0,0.62)');
        [$panelColor, $panelAlpha] = $this->assColorAlpha($panelCss);

        $bold = in_array($animation, ['beast', 'karaoke', 'box', 'slide', 'punch', 'news', 'neon'], true) ? -1 : 0;
        $italic = in_array($animation, ['karaoke', 'punch'], true) ? -1 : 0;

        $panelBGR = substr($panelColor, 2, 6); // strip &H … &
        if ($panelOn) {
            // BorderStyle=3: the "outline" becomes a filled box behind the
            // text (its color+alpha = OutlineColour); Outline width is the
            // padding. libass draws the box per override run, which is what
            // makes per-word markers possible.
            $padding = max(4, (int) round($fontSize * 0.35));
            $styleTail = sprintf('3,%d,0', $padding);
            $outlineColourFull = '&H'.$panelAlpha.$panelBGR.'&';
            $backColour = '&H80000000&';
        } else {
            $outlineWidth = in_array($animation, ['sticker'], true) ? 5 : 3;
            $styleTail = sprintf('1,%d,2', $outlineWidth);
            $outlineColourFull = '&H00000000&';
            $backColour = '&H80000000&';
        }

        $primaryStyleColour = str_replace('&H', '&H00', $primary);

        $fontName = self::ANIM_FONT_FAMILY[$animation][$ctx['fontName']] ?? $ctx['fontName'];

        $style = sprintf(
            'Style: Default,%s,%d,%s,&H000000FF&,%s,%s,%d,%d,0,0,100,100,0,0,%s,%d,%d,%d,%d,1',
            $fontName,
            $fontSize,
            $primaryStyleColour,
            $outlineColourFull,
            $backColour,
            $bold,
            $italic,
            $styleTail,
            $ctx['alignment'],
            $ctx['marginLR'],
            $ctx['marginLR'],
            $ctx['marginV'],
        );

        $words = $this->prepareAnimatedWords($animation, $timedWords, $ctx['duration']);
        // word_by_word = one word for every preset; line_by_line = full lines
        // even for one-word presets; keywords = the preset's natural default.
        $chunk = self::ANIM_CHUNK[$animation] ?? 4;
        $mode = (string) ($ctx['highlightMode'] ?? 'keywords');
        if ($mode === 'word_by_word') {
            $chunk = 1;
        } elseif ($mode === 'line_by_line' && $chunk === 1) {
            $chunk = 4;
        }
        $lines = array_chunk($words, max(1, $chunk));

        $events = match (true) {
            $animation === 'stream' => $this->animTypewriterEvents($lines, $highlight, true),
            $animation === 'news' => $this->animTypewriterEvents($lines, $highlight, false),
            $chunk === 1 => $this->animWordEvents($animation, $lines, $highlight, $underline),
            default => $this->animLineEvents($animation, $lines, $highlight, $underline),
        };

        $dialogue = array_map(
            static fn (array $e): string => sprintf(
                'Dialogue: %d,%s,%s,Default,,0,0,0,,%s',
                $e[3] ?? 0, $e[0], $e[1], $e[2]
            ),
            $events
        );

        return implode("\n", [
            '[Script Info]',
            'ScriptType: v4.00+',
            "PlayResX: {$ctx['playResX']}",
            "PlayResY: {$ctx['playResY']}",
            'WrapStyle: 0',
            'ScaledBorderAndShadow: yes',
            '',
            '[V4+ Styles]',
            'Format: Name, Fontname, Fontsize, PrimaryColour, SecondaryColour, OutlineColour, BackColour, Bold, Italic, Underline, StrikeOut, ScaleX, ScaleY, Spacing, Angle, BorderStyle, Outline, Shadow, Alignment, MarginL, MarginR, MarginV, Encoding',
            $style,
            '',
            '[Events]',
            'Format: Layer, Start, End, Style, Name, MarginL, MarginR, MarginV, Effect, Text',
            implode("\n", $dialogue),
        ]);
    }

    /** @return array<int,array{text:string,start:float,end:float}> */
    protected function prepareAnimatedWords(string $animation, array $timedWords, float $duration): array
    {
        $upper = in_array($animation, self::ANIM_UPPERCASE, true);
        $out = [];
        foreach ($timedWords as $word) {
            $text = trim((string) ($word['text'] ?? ''));
            $start = (float) ($word['start'] ?? -1);
            $end = (float) ($word['end'] ?? -1);
            if ($text === '' || $start < 0 || $end <= $start) {
                continue;
            }
            $out[] = [
                'text' => $upper ? mb_strtoupper($text) : $text,
                'start' => max(0.0, min($duration, $start)),
                'end' => max($start + 0.03, min($duration, $end)),
            ];
        }

        return $out;
    }

    /**
     * One word on screen at a time; each word holds until the next begins.
     *
     * @param array<int,array<int,array{text:string,start:float,end:float}>> $lines
     * @return list<array{string,string,string}>
     */
    protected function animWordEvents(string $animation, array $lines, string $highlight, bool $underline): array
    {
        $words = array_merge(...($lines ?: [[]]));
        $events = [];
        $count = count($words);

        foreach ($words as $i => $word) {
            $start = $word['start'];
            $end = $i + 1 < $count ? max($word['end'], $words[$i + 1]['start']) : $word['end'] + 0.35;
            $text = $this->escapeASSText($word['text']);
            $u = $underline ? '\\u1' : '';

            $tags = match ($animation) {
                // spring pop-in
                'beast' => '\\fscx35\\fscy35\\t(0,120,\\fscx100\\fscy100)',
                // overshoot bounce with alternating tilt
                'comic' => sprintf(
                    '\\frz%d\\fscx20\\fscy20\\t(0,150,\\fscx118\\fscy118)\\t(150,220,\\fscx100\\fscy100\\frz%d)',
                    $i % 2 === 0 ? 14 : -14,
                    $i % 2 === 0 ? 3 : -3,
                ),
                // skew + color flicker settling to primary
                'glitch' => sprintf(
                    '\\fax0.25\\alpha&H60&\\1c&HFFFF00&\\t(0,60,\\1c&HFF00FF&)\\t(60,120,\\alpha&H00&\\fax-0.12)\\t(120,200,\\fax0\\1c%s)',
                    $highlight,
                ),
                default => sprintf('\\1c%s', $highlight),
            };

            $events[] = [
                $this->formatASSTime($start),
                $this->formatASSTime($end),
                '{'.$tags.$u.'}'.$text,
            ];
        }

        return $events;
    }

    /**
     * Line stays on screen; re-emitted once per word activation so the
     * spoken word carries the preset's motion/highlight tags.
     *
     * @param array<int,array<int,array{text:string,start:float,end:float}>> $lines
     * @return list<array{string,string,string}>
     */
    protected function animLineEvents(string $animation, array $lines, string $highlight, bool $underline): array
    {
        $events = [];
        $lineCount = count($lines);

        foreach ($lines as $li => $line) {
            $nextStart = $li + 1 < $lineCount ? $lines[$li + 1][0]['start'] : null;
            $lineEnd = $line[count($line) - 1]['end'];
            $hideAt = $nextStart !== null ? min($lineEnd + 0.35, $nextStart) : $lineEnd + 0.35;

            foreach ($line as $wi => $word) {
                $start = $word['start'];
                $end = $wi + 1 < count($line) ? max($word['end'], $line[$wi + 1]['start']) : $hideAt;
                if ($end <= $start) {
                    continue;
                }

                $parts = [];
                foreach ($line as $j => $other) {
                    $text = $this->escapeASSText($other['text']);
                    $state = $j < $wi ? 'spoken' : ($j === $wi ? 'active' : 'unspoken');
                    $parts[] = $this->animWordTag($animation, $state, $highlight, $underline).$text.'{\\r}';
                }

                $prefix = $this->animLinePrefix($animation, $wi === 0);
                $events[] = [
                    $this->formatASSTime($start),
                    $this->formatASSTime($end),
                    $prefix.implode(' ', $parts),
                ];
            }
        }

        return $events;
    }

    /** Whole-line override tags emitted before the words. */
    protected function animLinePrefix(string $animation, bool $firstEventOfLine): string
    {
        return match ($animation) {
            // \frz is CCW-positive: browser -2deg tilt = \frz2
            'sticker', 'marker' => '{\\frz2}',
            // letter-spacing opens up as the line enters
            'tracking' => $firstEventOfLine ? '{\\fsp1\\t(0,500,\\fsp8)}' : '{\\fsp8}',
            default => '',
        };
    }

    /** Per-word override tags for the line-based presets. */
    protected function animWordTag(string $animation, string $state, string $highlight, bool $underline): string
    {
        $u = $underline && $state === 'active' ? '\\u1' : '';

        $tags = match ($animation) {
            // one-word presets forced into line mode: dim line, active pops
            'beast', 'comic', 'glitch' => match ($state) {
                'unspoken' => '\\alpha&HA6&',
                'active' => "\\1c{$highlight}\\fscx55\\fscy55\\t(0,140,\\fscx100\\fscy100)",
                default => '',
            },
            'karaoke' => match ($state) {
                'unspoken' => '\\alpha&H9E&',
                'active' => "\\1c{$highlight}\\fscx108\\fscy108",
                default => '',
            },
            'box' => match ($state) {
                // BorderStyle=3 + per-run \3c: only the active word's box is
                // visible (its alpha is opaque); the rest stay transparent.
                'active' => "\\3a&H00&\\3c{$highlight}\\1c&H111111&\\bord6",
                default => '\\3a&HFF&\\bord6',
            },
            'sticker' => match ($state) {
                'unspoken' => '\\alpha&HFF&',
                'active' => "\\1c{$highlight}\\fscx40\\fscy40\\t(0,160,\\fscx100\\fscy100)",
                default => '',
            },
            'blur' => match ($state) {
                'unspoken' => '\\alpha&HFF&',
                'active' => "\\1c{$highlight}\\blur8\\fscx112\\fscy112\\t(0,250,\\blur0\\fscx100\\fscy100)",
                default => '',
            },
            'slide' => match ($state) {
                'unspoken' => '\\alpha&HFF&',
                // baseline-anchored \fscy growth reads as a rise
                'active' => "\\1c{$highlight}\\alpha&HFF&\\fscy55\\t(0,50,\\alpha&H00&)\\t(0,220,\\fscy100)",
                default => '',
            },
            'wave' => match ($state) {
                'active' => "\\1c{$highlight}\\t(0,130,\\fscx112\\fscy112)\\t(130,320,\\fscx100\\fscy100)",
                default => '',
            },
            'punch' => match ($state) {
                'unspoken' => '\\alpha&HB3&',
                'active' => "\\1c{$highlight}\\fscx122\\fscy122",
                default => '',
            },
            'tracking' => match ($state) {
                'unspoken' => '\\alpha&H8C&',
                'active' => "\\1c{$highlight}",
                default => '',
            },
            'neon' => match ($state) {
                'unspoken' => '\\alpha&HB3&\\blur2',
                'active' => "\\1c{$highlight}\\blur3\\alpha&HD9&\\t(0,60,\\alpha&H00&)\\t(60,90,\\alpha&H73&)\\t(90,130,\\alpha&H00&)",
                default => '\\alpha&H26&\\blur2',
            },
            'marker' => match ($state) {
                'unspoken' => '\\alpha&HFF&',
                'active' => "\\1c{$highlight}\\frz6\\fscx135\\fscy135\\t(0,180,\\frz0\\fscx100\\fscy100)",
                default => '',
            },
            default => $state === 'active' ? "\\1c{$highlight}" : '',
        };

        return '{'.$tags.$u.'}';
    }

    /**
     * Stream / News Bar: text types on inside a growing BorderStyle=3 panel,
     * with a caret glyph riding the last typed character. Stream types per
     * character group, News Bar per word (its active word gets the marker).
     *
     * @param array<int,array<int,array{text:string,start:float,end:float}>> $lines
     * @return list<array{string,string,string}>
     */
    protected function animTypewriterEvents(array $lines, string $highlight, bool $perCharacter): array
    {
        $events = [];
        $lineCount = count($lines);
        $caret = '\\h▌';

        foreach ($lines as $li => $line) {
            $nextStart = $li + 1 < $lineCount ? $lines[$li + 1][0]['start'] : null;
            $lineEnd = $line[count($line) - 1]['end'];
            $hideAt = $nextStart !== null ? min($lineEnd + 0.6, $nextStart) : $lineEnd + 0.6;

            foreach ($line as $wi => $word) {
                $prefixWords = array_map(
                    fn (array $w): string => $this->escapeASSText($w['text']),
                    array_slice($line, 0, $wi)
                );
                $prefixText = implode(' ', $prefixWords);
                $wordStart = $word['start'];
                $wordEnd = $word['end'];

                if ($perCharacter) {
                    $chars = preg_split('//u', $word['text'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
                    $charCount = count($chars);
                    $step = max(1, (int) ceil($charCount / 4)); // ≤4 events per word
                    for ($ci = $step; $ci <= $charCount; $ci += $step) {
                        $shown = min($ci, $charCount);
                        $frac0 = ($shown - $step) / $charCount;
                        $frac1 = $shown / $charCount;
                        $start = $wordStart + $frac0 * ($wordEnd - $wordStart);
                        $end = $shown >= $charCount && $wi + 1 >= count($line)
                            ? $hideAt
                            : ($shown >= $charCount
                                ? max($wordEnd, $line[$wi + 1]['start'])
                                : $wordStart + $frac1 * ($wordEnd - $wordStart));
                        if ($end <= $start) {
                            continue;
                        }
                        $partial = $this->escapeASSText(implode('', array_slice($chars, 0, $shown)));
                        $text = trim($prefixText.' '.$partial).$caret;
                        $events[] = [$this->formatASSTime($start), $this->formatASSTime($end), $text];
                    }
                } else {
                    $start = $wordStart;
                    $end = $wi + 1 < count($line) ? max($wordEnd, $line[$wi + 1]['start']) : $hideAt;
                    if ($end <= $start) {
                        continue;
                    }
                    $active = sprintf(
                        '{\\3c%s\\3a&H00&}%s{\\r}',
                        $highlight,
                        $this->escapeASSText($word['text'])
                    );
                    $text = trim($prefixText.' ').($prefixText !== '' ? ' ' : '').$active.$caret;
                    $events[] = [$this->formatASSTime($start), $this->formatASSTime($end), $text];
                }
            }
        }

        return $events;
    }

    /**
     * CSS color (#hex or rgba()) → [ASS &HBBGGRR& color, alpha byte hex].
     * ASS alpha is inverted: 00 = opaque, FF = transparent.
     *
     * @return array{string,string}
     */
    protected function assColorAlpha(string $css): array
    {
        $css = trim($css);

        if (preg_match('/^rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*(?:,\s*([\d.]+)\s*)?\)$/i', $css, $m)) {
            $r = min(255, (int) $m[1]);
            $g = min(255, (int) $m[2]);
            $b = min(255, (int) $m[3]);
            $opacity = isset($m[4]) ? max(0.0, min(1.0, (float) $m[4])) : 1.0;
            $alpha = strtoupper(str_pad(dechex((int) round((1 - $opacity) * 255)), 2, '0', STR_PAD_LEFT));

            return [sprintf('&H%02X%02X%02X&', $b, $g, $r), $alpha];
        }

        $hex = ltrim($css, '#');
        if (strlen($hex) === 3) {
            $hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
        }
        if (strlen($hex) !== 6 || ! ctype_xdigit($hex)) {
            return ['&HFFFFFF&', '00'];
        }

        return [
            '&H'.strtoupper(substr($hex, 4, 2).substr($hex, 2, 2).substr($hex, 0, 2)).'&',
            '00',
        ];
    }
}
