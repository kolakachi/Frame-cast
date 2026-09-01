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

    /**
     * Font-size multiplier per preset — these ARE the `font-size: Xem` values
     * in CaptionPreview.vue, so preview and export land on the same fraction
     * of frame height (buildASSCaption already scales the editor's own
     * CAPTION_SIZE_MAP px to the export resolution). They were previously
     * ~0.62x these values, which is why every preset exported smaller than
     * it previewed.
     */
    protected const ANIM_FONT_SCALE = [
        'beast' => 2.50, 'comic' => 2.10, 'glitch' => 2.10, 'sticker' => 1.70,
        'blur' => 1.60, 'punch' => 1.55, 'karaoke' => 1.50, 'wave' => 1.50,
        'box' => 1.45, 'marker' => 1.45, 'slide' => 1.40, 'tracking' => 1.35,
        'neon' => 1.30, 'stream' => 1.15, 'news' => 0.95,
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

    /**
     * [outline, shadow] as a fraction of font size, mirroring each preset's
     * -webkit-text-stroke / text-shadow in CaptionPreview.vue. Fixed 3px/2px
     * for every preset made the chunky presets look thin next to the preview,
     * since these scale with font size in CSS but didn't here.
     *
     * Presets the preview draws with no stroke still keep a thin outline:
     * their legibility there comes from a *blurred* text-shadow halo, which
     * ASS's hard offset shadow can't reproduce — dropping to 0 would leave
     * pale captions unreadable on light footage.
     *
     * Shadow only tracks the CSS value where that shadow is HARD (blur 0:
     * Comic `0.06em 0.09em 0`, Punch, Sticker...). Where the CSS blurs it
     * into a halo (Slide/Karaoke/Box/Blur/Tracking `0 0.1em 0.2em`), a
     * matching ASS offset renders a crisp duplicate of the text a tenth of
     * an em away — which reads as doubled captions, not depth. Those get a
     * token offset and lean on the outline instead.
     */
    protected const ANIM_EDGE = [
        // hard CSS shadow — offset carries the look
        'beast' => [0.035, 0.06], 'comic' => [0.045, 0.07], 'sticker' => [0.070, 0.06],
        'punch' => [0.030, 0.09], 'wave' => [0.040, 0.06], 'marker' => [0.030, 0.05],
        'glitch' => [0.030, 0.05],
        // blurred CSS halo — keep the offset token
        'karaoke' => [0.035, 0.02], 'box' => [0.030, 0.02], 'blur' => [0.035, 0.02],
        'slide' => [0.035, 0.02], 'tracking' => [0.035, 0.02],
        // neon's outline IS the glow (coloured + blurred), so it runs wider
        'neon' => [0.060, 0.02],
    ];

    /**
     * Presets laid out one \pos'd word at a time.
     *
     * Any preset that scales the spoken word must be here: CSS `transform:
     * scale()` doesn't reflow, but ASS `\fscx` changes the glyph advance, so
     * inside a single line event the whole line re-centres on every word —
     * a horizontal wobble the preview never shows. Positioning each word
     * pins it. Comic/Slide additionally need it for \frz / \move.
     * Stream and News are excluded: they're one growing run by design.
     */
    protected const ANIM_POSITIONED = [
        'comic', 'slide', 'beast', 'sticker', 'karaoke', 'blur', 'wave', 'punch', 'marker', 'box',
    ];

    /** Whole-line tilt in ASS degrees (counter-clockwise), matching the CSS rotate. */
    protected const ANIM_LINE_TILT = ['sticker' => 2.0, 'marker' => 2.0];

    /**
     * Extra gap between positioned words, as a fraction of font size, for
     * presets whose spoken word grows past 100%. Positions are fixed at the
     * resting width, so without this the peak of the pop overlaps the
     * neighbours (Marker peaks at 135%, Punch 122%, Comic 107%).
     */
    protected const ANIM_WORD_GAP = [
        'marker' => 0.30, 'comic' => 0.26, 'punch' => 0.20, 'blur' => 0.12,
        'wave' => 0.08, 'sticker' => 0.12, 'beast' => 0.10, 'karaoke' => 0.08,
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

        // Box draws a pill behind the spoken word, which is BorderStyle=3 with
        // a per-word \3c — so it needs the box border mode even when no
        // backdrop is on. Without this its pill silently never rendered.
        // News keeps its per-word marker even with the bar switched off (the
        // preview does — CSS background doesn't need the panel), and that
        // marker is also a \3c box.
        // Every box — panel, pill, marker, backdrop — is drawn as a vector
        // (see assRoundedRect) rather than BorderStyle=3, so corners can be
        // rounded like the preview and a backdrop can stay static while the
        // text animates over it. The style itself is always outline mode.
        [$outlineEm, $shadowEm] = self::ANIM_EDGE[$animation] ?? [0.035, 0.05];
        if ($isPanelPreset) {
            // On the bar/console the mockup draws plain text — an outline and
            // drop shadow there read as a blobby smear.
            [$outlineEm, $shadowEm] = $panelOn ? [0.0, 0.0] : [0.030, 0.02];
        }
        $outlinePx = $outlineEm > 0 ? max(1.0, $fontSize * $outlineEm) : 0.0;
        $styleTail = sprintf('1,%.1f,%.1f', $outlinePx, max(1.0, $fontSize * $shadowEm));
        // Neon's halo is a blurred outline in the highlight colour —
        // blurring the default black outline just made a dark smudge.
        $outlineColourFull = $animation === 'neon'
            ? str_replace('&H', '&H00', $highlight)
            : '&H00000000&';
        $backColour = '&H80000000&';

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

        // Comic Pop tilts each word independently. ASS rotates a run around
        // the LINE anchor, so an in-place spin is only possible when every
        // word is its own \pos'd event — which needs real font metrics.
        // Falls back to the shared line builder if the font can't be measured.
        // Slide joins Comic on the positioned path: \move translates a word
        // for real, where the inline approximation could only squash it
        // vertically (\fscy) and left the active word looking distorted.
        $positioned = null;
        if (in_array($animation, self::ANIM_POSITIONED, true) && ($chunk > 1 || $animation === 'slide')) {
            $positioned = $this->animPositionedWordEvents($animation, $lines, $highlight, $underline, [
                'fontName' => $fontName,
                'fontSize' => $fontSize,
                'playResX' => $ctx['playResX'],
                'playResY' => $ctx['playResY'],
                'alignment' => $ctx['alignment'],
                'marginV' => $ctx['marginV'],
                'marginLR' => $ctx['marginLR'],
                'outlinePx' => $outlinePx ?? 0.0,
                'panelOn' => $panelOn,
                'panelColor' => $panelColor,
                'panelAlpha' => $panelAlpha,
            ]);
        }

        $typewriterLayout = [
            'fontName' => $fontName,
            'fontSize' => $fontSize,
            'playResX' => $ctx['playResX'],
            'playResY' => $ctx['playResY'],
            'alignment' => $ctx['alignment'],
            'marginV' => $ctx['marginV'],
            'panelOn' => $panelOn,
            'panelColor' => $panelColor,
            'panelAlpha' => $panelAlpha,
            'animation' => $animation,
        ];

        $events = match (true) {
            $positioned !== null => $positioned,
            $animation === 'stream' => $this->animTypewriterEvents($lines, $highlight, true, $typewriterLayout),
            $animation === 'news' => $this->animTypewriterEvents($lines, $highlight, false, $typewriterLayout),
            $chunk === 1 => $this->animWordEvents($animation, $lines, $highlight, $underline),
            default => $this->animLineEvents($animation, $lines, $highlight, $underline, $fontSize),
        };

        $dialogue = array_map(
            static fn (array $e): string => sprintf(
                'Dialogue: %d,%s,%s,Default,,0,0,0,,%s',
                $e[3] ?? 1, $e[0], $e[1], $e[2]
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
                    '\\1c%s\\fax-0.22\\alpha&H60&\\t(0,60,\\alpha&H00&\\fax0.10)\\t(60,160,\\fax0)',
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
    protected function animLineEvents(string $animation, array $lines, string $highlight, bool $underline, int $fontSize = 0): array
    {
        $events = [];
        $lineCount = count($lines);

        foreach ($lines as $li => $line) {
            $nextStart = $li + 1 < $lineCount ? $lines[$li + 1][0]['start'] : null;
            $lineEnd = $line[count($line) - 1]['end'];
            $hideAt = $this->animLineHideAt($lineEnd, $nextStart, 0.35);

            foreach ($line as $wi => $word) {
                $start = $word['start'];
                $end = $wi + 1 < count($line) ? max($word['end'], $line[$wi + 1]['start']) : $hideAt;
                if ($end <= $start) {
                    continue;
                }

                // Line-level tags have to be repeated on every word: each word
                // is terminated with {\r}, which resets ALL overrides — so a
                // prefix-only \fsp survived on the first word alone.
                $prefix = trim($this->animLinePrefix($animation, $wi === 0, $fontSize), '{}');

                $parts = [];
                foreach ($line as $j => $other) {
                    $text = $this->escapeASSText($other['text']);
                    $state = $j < $wi ? 'spoken' : ($j === $wi ? 'active' : 'unspoken');
                    $tag = trim($this->animWordTag($animation, $state, $highlight, $underline, $j), '{}');
                    $parts[] = '{'.$prefix.$tag.'}'.$text.'{\\r}';
                }

                $events[] = [
                    $this->formatASSTime($start),
                    $this->formatASSTime($end),
                    implode(' ', $parts),
                ];
            }
        }

        return $events;
    }

    /**
     * Per-word positioned events, so each word can rotate around its OWN
     * centre (\an5 + \pos + \frz) instead of orbiting the line anchor.
     * Words are laid out by measuring the real font, wrapped to the caption
     * width, and stacked to sit where the style's alignment/margin would.
     *
     * @param array<int,array<int,array{text:string,start:float,end:float}>> $lines
     * @param array{fontName:string,fontSize:int,playResX:int,playResY:int,alignment:int,marginV:int,marginLR:int} $layout
     * @return list<array{string,string,string}>|null null = can't measure, use the line builder
     */
    protected function animPositionedWordEvents(string $animation, array $lines, string $highlight, bool $underline, array $layout): ?array
    {
        $metrics = app(\App\Services\Media\FontMetrics::class);
        $fontSize = (float) $layout['fontSize'];
        $spaceWidth = $metrics->width(' ', $layout['fontName'], $fontSize);
        if ($spaceWidth === null) {
            return null;
        }
        // The font's space alone isn't enough between positioned words: the
        // outline bleeds outward on both sides (measurement covers glyph
        // advances only), and presets that pop past 100% need headroom on
        // top of that.
        $spaceWidth += 2 * ($layout['outlinePx'] ?? 0.0)
            + $fontSize * (self::ANIM_WORD_GAP[$animation] ?? 0.0);

        $maxWidth = max(100.0, $layout['playResX'] - 2 * $layout['marginLR']);
        $rowHeight = $fontSize * 1.18;
        $events = [];
        $lineCount = count($lines);

        foreach ($lines as $li => $line) {
            // Measure every word; bail out entirely if any glyph is unknown so
            // we never render a half-misaligned line.
            $widths = [];
            foreach ($line as $word) {
                $w = $metrics->width($word['text'], $layout['fontName'], $fontSize);
                if ($w === null) {
                    return null;
                }
                $widths[] = $w;
            }

            // Greedy wrap into rows that fit the caption width.
            $rows = [];
            $current = [];
            $currentWidth = 0.0;
            foreach ($line as $wi => $word) {
                $addition = $widths[$wi] + ($current === [] ? 0 : $spaceWidth);
                if ($current !== [] && $currentWidth + $addition > $maxWidth) {
                    $rows[] = $current;
                    $current = [];
                    $currentWidth = 0.0;
                    $addition = $widths[$wi];
                }
                $current[] = $wi;
                $currentWidth += $addition;
            }
            if ($current !== []) {
                $rows[] = $current;
            }

            $blockHeight = count($rows) * $rowHeight;
            $blockTop = match ($layout['alignment']) {
                8 => (float) $layout['marginV'],
                5 => ($layout['playResY'] - $blockHeight) / 2,
                default => $layout['playResY'] - $layout['marginV'] - $blockHeight,
            };

            // x/y centre for every word index in this line.
            $positions = [];
            foreach ($rows as $ri => $row) {
                $rowWidth = 0.0;
                foreach ($row as $n => $wi) {
                    $rowWidth += $widths[$wi] + ($n === 0 ? 0 : $spaceWidth);
                }
                $cursor = ($layout['playResX'] - $rowWidth) / 2;
                $y = $blockTop + $ri * $rowHeight + $rowHeight / 2;
                foreach ($row as $n => $wi) {
                    if ($n > 0) {
                        $cursor += $spaceWidth;
                    }
                    $positions[$wi] = [$cursor + $widths[$wi] / 2, $y];
                    $cursor += $widths[$wi];
                }

                // Whole-line tilt (Sticker/Marker). Positioning words kills the
                // line-level \frz, so rotate each word and ride it along the
                // tilt: a word dx from centre lifts by dx*sin(theta).
                $tilt = self::ANIM_LINE_TILT[$animation] ?? 0.0;
                if ($tilt !== 0.0) {
                    $rad = deg2rad($tilt);
                    $centreX = $layout['playResX'] / 2;
                    foreach ($row as $wi) {
                        $dx = $positions[$wi][0] - $centreX;
                        $positions[$wi] = [
                            $centreX + $dx * cos($rad),
                            $positions[$wi][1] - $dx * sin($rad),
                        ];
                    }
                }
            }

            $nextStart = $li + 1 < $lineCount ? $lines[$li + 1][0]['start'] : null;
            $lineEnd = $line[count($line) - 1]['end'];
            $hideAt = $this->animLineHideAt($lineEnd, $nextStart, 0.35);
            $lineStart = $line[0]['start'];

            // One static backdrop for the whole line. Attaching a box to each
            // word made the backdrop scale and rotate along with whatever word
            // was animating — Marker's looked like it was moving.
            if ($layout['panelOn'] ?? false) {
                $x0 = null;
                $x1 = null;
                foreach ($positions as $pi => $pos) {
                    $x0 = $x0 === null ? $pos[0] - $widths[$pi] / 2 : min($x0, $pos[0] - $widths[$pi] / 2);
                    $x1 = $x1 === null ? $pos[0] + $widths[$pi] / 2 : max($x1, $pos[0] + $widths[$pi] / 2);
                }
                $padX = $fontSize * 0.55;
                $padY = $fontSize * 0.35;
                $events[] = [
                    $this->formatASSTime($lineStart),
                    $this->formatASSTime($hideAt),
                    $this->assRoundedRect(
                        $x0 - $padX,
                        $blockTop - $padY,
                        ($x1 - $x0) + 2 * $padX,
                        $blockHeight + 2 * $padY,
                        $fontSize * 0.45,
                        $layout['panelColor'] ?? '&H000000&',
                        $layout['panelAlpha'] ?? '9E'
                    ),
                    0,
                ];
            }

            foreach ($line as $wi => $word) {
                [$x, $y] = $positions[$wi];
                $text = $this->escapeASSText($word['text']);
                $activeStart = $word['start'];
                $activeEnd = $wi + 1 < count($line) ? max($word['end'], $line[$wi + 1]['start']) : $hideAt;

                // Box's pill, drawn so it can have the preview's corner radius.
                if ($animation === 'box' && $activeEnd > $activeStart) {
                    $padX = $fontSize * 0.18;
                    $pillH = $fontSize * 1.06;
                    $events[] = [
                        $this->formatASSTime($activeStart),
                        $this->formatASSTime($activeEnd),
                        $this->assRoundedRect(
                            $x - $widths[$wi] / 2 - $padX,
                            $y - $pillH / 2,
                            $widths[$wi] + 2 * $padX,
                            $pillH,
                            $fontSize * 0.28,
                            $highlight
                        ),
                        0,
                    ];
                }

                // waiting its turn
                if ($activeStart > $lineStart) {
                    $events[] = [
                        $this->formatASSTime($lineStart),
                        $this->formatASSTime($activeStart),
                        $this->animPositionedOverride($animation, 'unspoken', $x, $y, $layout['fontSize'], $highlight, $underline, $wi).$text,
                    ];
                }

                // spoken now — pops, tilts, holds the highlight colour
                if ($activeEnd > $activeStart) {
                    if ($animation === 'wave') {
                        // A hop is up-then-down; \move is a single linear leg,
                        // so the active word is split into two events. Mirrors
                        // the preview's translateY(-0.32em) at 40% of 320ms.
                        $lift = $fontSize * 0.32;
                        $apex = min($activeStart + 0.128, $activeEnd);

                        $events[] = [
                            $this->formatASSTime($activeStart),
                            $this->formatASSTime($apex),
                            sprintf(
                                '{\\an5\\move(%.1f,%.1f,%.1f,%.1f,0,128)\\1c%s\\t(0,128,\\fscx112\\fscy112)}',
                                $x, $y, $x, $y - $lift, $highlight
                            ).$text,
                        ];

                        if ($activeEnd > $apex) {
                            $events[] = [
                                $this->formatASSTime($apex),
                                $this->formatASSTime($activeEnd),
                                sprintf(
                                    '{\\an5\\move(%.1f,%.1f,%.1f,%.1f,0,192)\\1c%s\\fscx112\\fscy112\\t(0,192,\\fscx100\\fscy100)}',
                                    $x, $y - $lift, $x, $y, $highlight
                                ).$text,
                            ];
                        }
                    } else {
                        $events[] = [
                            $this->formatASSTime($activeStart),
                            $this->formatASSTime($activeEnd),
                            $this->animPositionedOverride($animation, 'active', $x, $y, $layout['fontSize'], $highlight, $underline, $wi).$text,
                        ];
                    }
                }

                // already said
                if ($hideAt > $activeEnd) {
                    $events[] = [
                        $this->formatASSTime($activeEnd),
                        $this->formatASSTime($hideAt),
                        $this->animPositionedOverride($animation, 'spoken', $x, $y, $layout['fontSize'], $highlight, $underline, $wi).$text,
                    ];
                }
            }
        }

        return $events;
    }

    /**
     * Override block for one positioned word. Most presets just pin the word
     * with \pos and reuse the shared inline tags; presets whose motion is a
     * translation need \move instead (mutually exclusive with \pos).
     */
    protected function animPositionedOverride(string $animation, string $state, float $x, float $y, int $fontSize, string $highlight, bool $underline, int $index): string
    {
        if ($animation === 'slide') {
            return match ($state) {
                // rises into place as it's spoken, mirroring translateY(0.9em)
                'active' => sprintf(
                    '{\\an5\\move(%.1f,%.1f,%.1f,%.1f,0,220)\\1c%s\\fad(70,0)%s}',
                    $x, $y + $fontSize * 0.9, $x, $y, $highlight, $underline ? '\\u1' : ''
                ),
                'unspoken' => sprintf('{\\an5\\pos(%.1f,%.1f)\\alpha&HFF&}', $x, $y),
                default => sprintf('{\\an5\\pos(%.1f,%.1f)}', $x, $y),
            };
        }

        $tilt = self::ANIM_LINE_TILT[$animation] ?? 0.0;

        if ($animation === 'marker') {
            // Marker animates \frz itself, which would cancel the line tilt —
            // settle onto the tilt instead of onto 0.
            return match ($state) {
                'active' => sprintf(
                    '{\\an5\\pos(%.1f,%.1f)\\1c%s\\frz%.1f\\fscx135\\fscy135\\t(0,180,\\frz%.1f\\fscx100\\fscy100)}',
                    $x, $y, $highlight, $tilt + 6, $tilt
                ),
                'unspoken' => sprintf('{\\an5\\pos(%.1f,%.1f)\\frz%.1f\\alpha&HFF&}', $x, $y, $tilt),
                default => sprintf('{\\an5\\pos(%.1f,%.1f)\\frz%.1f}', $x, $y, $tilt),
            };
        }

        $frz = $tilt !== 0.0 ? sprintf('\\frz%.1f', $tilt) : '';

        return sprintf('{\\an5\\pos(%.1f,%.1f)%s%s}', $x, $y, $frz, trim(
            $this->animWordTag($animation, $state, $highlight, $underline, $index, true), '{}'
        ));
    }

    /**
     * When a line should disappear. Dropping it a fixed hold after its last
     * word leaves a blank hole whenever the pause to the next line is barely
     * longer than that hold — a 30ms hole renders as a one-frame flash. Hold
     * the line until the next one starts across normal speech pauses; only
     * genuinely long silences go to black.
     */
    protected function animLineHideAt(float $lineEnd, ?float $nextStart, float $hold): float
    {
        if ($nextStart === null) {
            return $lineEnd + $hold;
        }

        return $nextStart - $lineEnd <= 1.2 ? $nextStart : $lineEnd + $hold;
    }

    /** Whole-line override tags emitted before the words. */
    protected function animLinePrefix(string $animation, bool $firstEventOfLine, int $fontSize = 0): string
    {
        return match ($animation) {
            // \frz is CCW-positive: browser -2deg tilt = \frz2
            'sticker', 'marker' => '{\\frz2}',
            // letter-spacing opens up as the line enters. \fsp is in pixels,
            // so it has to scale with the font — a flat \fsp8 was nearly
            // invisible next to the preview's 0.35em (~31px at this size).
            'tracking' => (function () use ($firstEventOfLine, $fontSize) {
                $fsp = max(4.0, $fontSize * 0.35);
                return $firstEventOfLine
                    ? sprintf('{\\fsp%.1f\\t(0,500,\\fsp%.1f)}', $fsp * 0.15, $fsp)
                    : sprintf('{\\fsp%.1f}', $fsp);
            })(),
            default => '',
        };
    }

    /**
     * Per-word override tags for the line-based presets.
     *
     * $positioned means the caller emits this word as its own \an5\pos event,
     * so \frz spins it in place. Without that, \frz would orbit the whole
     * line's anchor, and the tilt has to be approximated with \fax shear.
     */
    protected function animWordTag(string $animation, string $state, string $highlight, bool $underline, int $index = 0, bool $positioned = false): string
    {
        $u = $underline && $state === 'active' ? '\\u1' : '';
        $sign = $index % 2 === 0 ? '-' : '';
        $faxIn = "{$sign}0.28";
        $faxRest = "{$sign}0.14";
        // True rotation, mirroring the preview: a big entry kick settling to a
        // small alternating lean. (\frz is counter-clockwise, CSS rotate is
        // clockwise, hence the flipped signs against CaptionPreview.vue.)
        $frzIn = $index % 2 === 0 ? 14 : -14;
        $frzRest = $index % 2 === 0 ? 3 : -2.5;

        $tags = match ($animation) {
            'beast' => match ($state) {
                'unspoken' => '\\alpha&HA6&',
                'active' => "\\1c{$highlight}\\fscx55\\fscy55\\t(0,140,\\fscx100\\fscy100)",
                default => '',
            },
            'comic' => match ($state) {
                'unspoken' => '\\alpha&HA6&',
                // Positioned words sit at their 100% width, so a big overshoot
                // bleeds into the neighbours (118% collided visibly). Keep the
                // bounce, cap the peak.
                'active' => $positioned
                    ? "\\1c{$highlight}\\frz{$frzIn}\\fscx35\\fscy35\\t(0,150,\\fscx107\\fscy107\\frz{$frzRest})\\t(150,260,\\fscx100\\fscy100)"
                    : "\\1c{$highlight}\\fax{$faxIn}\\fscx20\\fscy20\\t(0,150,\\fscx118\\fscy118)\\t(150,260,\\fscx100\\fscy100\\fax{$faxRest})",
                default => '',
            },
            'glitch' => match ($state) {
                'unspoken' => '\\alpha&HA6&',
                // CSS skewX(+12deg) leans the opposite way to ASS \fax, so the
                // signs are flipped; and the word settles on the user's
                // highlight colour rather than a hardcoded magenta.
                'active' => "\\1c{$highlight}\\fax-0.22\\alpha&H60&\\t(0,60,\\alpha&H00&\\fax0.10)\\t(60,160,\\fax0)",
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
                // Text colour flips with the pill's brightness so a dark
                // highlight doesn't render dark-on-dark.
                'active' => '\\1c'.$this->assPillTextColor($highlight),
                default => '',
            },
            'sticker' => match ($state) {
                'unspoken' => '\\alpha&HFF&',
                'active' => "\\1c{$highlight}\\fscx40\\fscy40\\t(0,160,\\fscx100\\fscy100)",
                default => '',
            },
            'blur' => match ($state) {
                'unspoken' => '\\alpha&HFF&',
                // \blur8 over 250ms was barely visible at export sizes.
                'active' => "\\1c{$highlight}\\blur22\\fscx112\\fscy112\\t(0,420,\\blur0\\fscx100\\fscy100)",
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
                // \fax matches the preview's skewX(-4deg) on every word.
                'unspoken' => '\\alpha&HB3&\\fax0.07',
                'active' => "\\1c{$highlight}\\fax0.07\\fscx122\\fscy122",
                default => '\\fax0.07',
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
    protected function animTypewriterEvents(array $lines, string $highlight, bool $perCharacter, array $layout = []): array
    {
        $events = [];
        $lineCount = count($lines);
        $caret = '\\h▌';

        // Panel geometry. The bar/console is drawn as a rounded rect sized to
        // whatever is on screen at that moment, so it grows with the typing
        // and carries the preview's corner radius (a BorderStyle=3 box is
        // always square). Needs metrics; without them we simply draw no panel.
        $anim = $layout['animation'] ?? 'stream';
        $panelOn = (bool) ($layout['panelOn'] ?? false);
        $fontSize = (float) ($layout['fontSize'] ?? 0);
        $fontName = (string) ($layout['fontName'] ?? '');
        $metrics = $fontSize > 0 && $fontName !== '' ? app(\App\Services\Media\FontMetrics::class) : null;
        $padX = $fontSize * ($anim === 'news' ? 0.75 : 0.70);
        $padY = $fontSize * 0.50;
        $radius = $fontSize * ($anim === 'news' ? 0.22 : 0.50);
        $rowH = $fontSize * 1.18;
        $maxWidth = max(100.0, ($layout['playResX'] ?? 1080) - 2 * $padX - 40);
        $centreX = ($layout['playResX'] ?? 1080) / 2;
        $bottom = match ($layout['alignment'] ?? 2) {
            8 => (float) ($layout['marginV'] ?? 0) + $rowH,
            5 => ($layout['playResY'] ?? 1920) / 2 + $rowH / 2,
            default => ($layout['playResY'] ?? 1920) - ($layout['marginV'] ?? 0),
        };

        // Emits the panel behind one frame of typed text.
        $panelFor = function (string $plain, string $start, string $end) use (
            $metrics, $panelOn, $fontName, $fontSize, $padX, $padY, $radius,
            $rowH, $maxWidth, $centreX, $bottom, $layout
        ): ?array {
            if (! $panelOn || $metrics === null) {
                return null;
            }
            $w = $metrics->width($plain.' ▌', $fontName, $fontSize);
            if ($w === null) {
                return null;
            }
            $rows = max(1, (int) ceil($w / $maxWidth));
            $boxW = min($w, $maxWidth) + 2 * $padX;
            $boxH = $rows * $rowH + 2 * $padY;

            return [
                $start,
                $end,
                $this->assRoundedRect(
                    $centreX - $boxW / 2,
                    $bottom - $rows * $rowH - $padY,
                    $boxW,
                    $boxH,
                    $radius,
                    $layout['panelColor'] ?? '&H000000&',
                    $layout['panelAlpha'] ?? '00'
                ),
                0,
            ];
        };

        foreach ($lines as $li => $line) {
            $nextStart = $li + 1 < $lineCount ? $lines[$li + 1][0]['start'] : null;
            $lineEnd = $line[count($line) - 1]['end'];
            $hideAt = $this->animLineHideAt($lineEnd, $nextStart, 0.6);

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
                    // Boundaries must END on charCount. Stepping by $step and
                    // stopping at <= charCount skipped the final group whenever
                    // the length wasn't a multiple of the step ("certain": 2,4,6
                    // — never 7), so the word never finished typing and left a
                    // hole in the timeline until the next word began.
                    $stops = [];
                    for ($ci = $step; $ci < $charCount; $ci += $step) {
                        $stops[] = $ci;
                    }
                    $stops[] = $charCount;

                    $prevShown = 0;
                    foreach ($stops as $shown) {
                        $frac0 = $prevShown / $charCount;
                        $prevShown = $shown;
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
                        $panel = $panelFor(trim($prefixText.' '.$partial), $this->formatASSTime($start), $this->formatASSTime($end));
                        if ($panel !== null) {
                            $events[] = $panel;
                        }
                        $events[] = [$this->formatASSTime($start), $this->formatASSTime($end), $text];
                    }
                } else {
                    $start = $wordStart;
                    $end = $wi + 1 < count($line) ? max($wordEnd, $line[$wi + 1]['start']) : $hideAt;
                    if ($end <= $start) {
                        continue;
                    }
                    $active = sprintf(
                        '{\\1c%s}%s{\\r}',
                        $this->assPillTextColor($highlight),
                        $this->escapeASSText($word['text'])
                    );
                    $text = trim($prefixText.' ').($prefixText !== '' ? ' ' : '').$active.$caret;
                    $plain = trim($prefixText.' '.$word['text']);
                    $panel = $panelFor($plain, $this->formatASSTime($start), $this->formatASSTime($end));
                    if ($panel !== null) {
                        $events[] = $panel;
                    }

                    // Marker sweep behind the spoken word, drawn so it gets the
                    // same rounding as the bar. Only while the line fits one
                    // row — past that libass owns the wrap and we can't place it.
                    if ($metrics !== null) {
                        $full = $metrics->width($plain.' ▌', $fontName, $fontSize);
                        $lead = $metrics->width($prefixText === '' ? '' : $prefixText.' ', $fontName, $fontSize);
                        $wordW = $metrics->width($word['text'], $fontName, $fontSize);
                        if ($full !== null && $lead !== null && $wordW !== null && $full <= $maxWidth) {
                            // Sit the marker on the glyphs' real ink band —
                            // deriving it from fontSize alone put it visibly
                            // high, since libass renders an em smaller than
                            // the nominal size.
                            $vm = $metrics->verticalMetrics($fontName, $fontSize);
                            $markH = ($vm['inkHeight'] ?? $fontSize * 0.75) * 1.30;
                            $centreY = $vm !== null
                                ? $bottom - $vm['descent'] - $vm['inkRise']
                                : $bottom - $rowH / 2;
                            $events[] = [
                                $this->formatASSTime($start),
                                $this->formatASSTime($end),
                                $this->assRoundedRect(
                                    $centreX - $full / 2 + $lead - $fontSize * 0.06,
                                    $centreY - $markH / 2,
                                    $wordW + $fontSize * 0.12,
                                    $markH,
                                    $fontSize * 0.15,
                                    $highlight
                                ),
                                0,
                            ];
                        }
                    }
                    $events[] = [$this->formatASSTime($start), $this->formatASSTime($end), $text];
                }
            }
        }

        return $events;
    }

    /**
     * A filled rounded rectangle, as an ASS vector drawing.
     *
     * BorderStyle=3 boxes are square-cornered and are drawn per override run,
     * so they can't match the preview's border-radius and they scale/rotate
     * with whatever word they're attached to. Drawing the shape ourselves
     * gives real corner radii and lets a backdrop stay put while the text
     * animates on top of it.
     *
     * Returns the Dialogue Text field; anchor is the rect's top-left.
     */
    protected function assRoundedRect(float $x, float $y, float $w, float $h, float $r, string $colour, string $alphaHex = '00'): string
    {
        $r = max(0.0, min($r, min($w, $h) / 2));
        $w = round($w, 1);
        $h = round($h, 1);
        $r = round($r, 1);

        // Corner control points sit on the corner itself — a good-enough
        // circular approximation at these radii, and cheap to emit.
        $path = sprintf(
            'm %s 0 l %s 0 b %s 0 %s 0 %s %s l %s %s b %s %s %s %s %s %s l %s %s b 0 %s 0 %s 0 %s l 0 %s b 0 0 0 0 %s 0',
            $r, $w - $r,
            $w, $w, $w, $r,
            $w, $h - $r,
            $w, $h, $w, $h, $w - $r, $h,
            $r, $h,
            $h, $h, $h - $r,
            $r,
            $r
        );

        return sprintf(
            '{\\an7\\pos(%.1f,%.1f)\\p1\\bord0\\shad0\\1c%s\\1a&H%s&}%s{\\p0}',
            $x, $y, $colour, strtoupper($alphaHex), $path
        );
    }

    /**
     * Readable text colour to sit on a pill of the given ASS colour. A user
     * who picks a dark highlight would otherwise get dark text on a dark
     * pill (the browser preset hardcodes #111 and has the same blind spot).
     */
    protected function assPillTextColor(string $assColor): string
    {
        if (! preg_match('/&H([0-9A-Fa-f]{6})&/', $assColor, $m)) {
            return '&H111111&';
        }

        // ASS packs colours as BBGGRR.
        $b = hexdec(substr($m[1], 0, 2));
        $g = hexdec(substr($m[1], 2, 2));
        $r = hexdec(substr($m[1], 4, 2));
        $luma = 0.299 * $r + 0.587 * $g + 0.114 * $b;

        return $luma > 140 ? '&H111111&' : '&HFFFFFF&';
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
