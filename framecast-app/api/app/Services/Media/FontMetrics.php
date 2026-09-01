<?php

namespace App\Services\Media;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Measures text width in a TrueType font by reading the font's own tables.
 *
 * Needed because presets that tilt individual words must be laid out by hand:
 * ASS rotates a run around the LINE's anchor, so the only way to spin a word
 * in place is to emit it as its own \pos'd event — which requires knowing how
 * wide every preceding word is.
 *
 * Font files are resolved through fc-match, so we measure the exact file
 * libass will render with. No GD/Imagick/FreeType extension needed: we parse
 * head (unitsPerEm), hhea/hmtx (advance widths) and cmap (char -> glyph).
 */
class FontMetrics
{
    /** @var array<string, array{units:int, advances:array<int,int>, cmap:array<int,int>, default:int}|null> */
    private static array $cache = [];

    /**
     * Width of $text in pixels at $fontSizePx, or null when the font can't be
     * measured (caller falls back to a non-positioned layout).
     */
    public function width(string $text, string $family, float $fontSizePx): ?float
    {
        $font = $this->load($family);
        if ($font === null) {
            return null;
        }

        $units = 0;
        foreach ($this->codepoints($text) as $cp) {
            $gid = $font['cmap'][$cp] ?? 0;
            $units += $font['advances'][$gid] ?? $font['default'];
        }

        return $units / $font['units'] * $fontSizePx;
    }

    /** @return array<int,int> */
    private function codepoints(string $text): array
    {
        $out = [];
        $len = mb_strlen($text, 'UTF-8');
        for ($i = 0; $i < $len; $i++) {
            $char = mb_substr($text, $i, 1, 'UTF-8');
            $out[] = mb_ord($char, 'UTF-8') ?: 32;
        }

        return $out;
    }

    /** @return array{units:int, advances:array<int,int>, cmap:array<int,int>, default:int}|null */
    private function load(string $family): ?array
    {
        if (array_key_exists($family, self::$cache)) {
            return self::$cache[$family];
        }

        self::$cache[$family] = null;

        try {
            $path = $this->resolveFontFile($family);
            if ($path === null || ! is_readable($path)) {
                return null;
            }

            $data = file_get_contents($path);
            if ($data === false || strlen($data) < 12) {
                return null;
            }

            $tables = $this->tableDirectory($data);
            if (! isset($tables['head'], $tables['hhea'], $tables['hmtx'], $tables['cmap'])) {
                return null;
            }

            $upem = $this->uint16($data, $tables['head']['offset'] + 18);
            $numHMetrics = $this->uint16($data, $tables['hhea']['offset'] + 34);
            if ($upem <= 0 || $numHMetrics <= 0) {
                return null;
            }

            // libass sizes a font so that usWinAscent+usWinDescent equals the
            // requested Fontsize — NOT unitsPerEm. Dividing by unitsPerEm
            // overestimates width by ~20% on fonts like Luckiest Guy
            // (2048 upem vs 2510 win height), which would drift every word
            // position. Verified empirically against libass renders.
            $units = $upem;
            if (isset($tables['OS/2'])) {
                $os2 = $tables['OS/2']['offset'];
                $winAscent = $this->uint16($data, $os2 + 74);
                $winDescent = $this->uint16($data, $os2 + 76);
                if ($winAscent + $winDescent > 0) {
                    $units = $winAscent + $winDescent;
                }
            }

            $advances = [];
            $hmtx = $tables['hmtx']['offset'];
            for ($i = 0; $i < $numHMetrics; $i++) {
                $advances[$i] = $this->uint16($data, $hmtx + $i * 4);
            }

            $cmap = $this->parseCmap($data, $tables['cmap']['offset']);
            if ($cmap === null) {
                return null;
            }

            self::$cache[$family] = [
                'units' => $units,
                'advances' => $advances,
                'cmap' => $cmap,
                'default' => $advances[$numHMetrics - 1] ?? (int) round($units * 0.5),
            ];
        } catch (\Throwable $e) {
            Log::warning('font metrics unavailable', ['family' => $family, 'error' => $e->getMessage()]);
        }

        return self::$cache[$family];
    }

    /** Ask fontconfig for the file libass would pick for this family. */
    private function resolveFontFile(string $family): ?string
    {
        $process = new Process(['fc-match', '-f', '%{file}', $family]);
        $process->setTimeout(10);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $path = trim($process->getOutput());

        // fc-match always answers, even with an unrelated fallback. A wrong
        // file would silently mis-measure, so verify the family really matched.
        $check = new Process(['fc-match', '-f', '%{family}', $family]);
        $check->setTimeout(10);
        $check->run();
        $matched = strtolower(trim($check->getOutput()));
        if ($matched !== '' && ! str_contains($matched, strtolower($family)) && ! str_contains(strtolower($family), $matched)) {
            return null;
        }

        return $path !== '' ? $path : null;
    }

    /** @return array<string, array{offset:int, length:int}> */
    private function tableDirectory(string $data): array
    {
        $numTables = $this->uint16($data, 4);
        $tables = [];
        for ($i = 0; $i < $numTables; $i++) {
            $rec = 12 + $i * 16;
            if ($rec + 16 > strlen($data)) {
                break;
            }
            $tag = substr($data, $rec, 4);
            $tables[$tag] = [
                'offset' => $this->uint32($data, $rec + 8),
                'length' => $this->uint32($data, $rec + 12),
            ];
        }

        return $tables;
    }

    /** @return array<int,int>|null codepoint => glyph id */
    private function parseCmap(string $data, int $offset): ?array
    {
        $numSubtables = $this->uint16($data, $offset + 2);
        $best = null;
        for ($i = 0; $i < $numSubtables; $i++) {
            $rec = $offset + 4 + $i * 8;
            $platform = $this->uint16($data, $rec);
            $encoding = $this->uint16($data, $rec + 2);
            $subOffset = $offset + $this->uint32($data, $rec + 4);
            $format = $this->uint16($data, $subOffset);
            if ($format !== 4) {
                continue;
            }
            // Prefer Windows BMP (3,1); accept Unicode (0,*) as a fallback.
            if ($platform === 3 && $encoding === 1) {
                $best = $subOffset;
                break;
            }
            if ($best === null && ($platform === 0 || $platform === 3)) {
                $best = $subOffset;
            }
        }

        if ($best === null) {
            return null;
        }

        $segCount = intdiv($this->uint16($data, $best + 6), 2);
        $endBase = $best + 14;
        $startBase = $endBase + $segCount * 2 + 2;
        $deltaBase = $startBase + $segCount * 2;
        $rangeBase = $deltaBase + $segCount * 2;

        $map = [];
        for ($s = 0; $s < $segCount; $s++) {
            $end = $this->uint16($data, $endBase + $s * 2);
            $start = $this->uint16($data, $startBase + $s * 2);
            $delta = $this->uint16($data, $deltaBase + $s * 2);
            $rangeOffset = $this->uint16($data, $rangeBase + $s * 2);
            if ($start > $end || $end === 0xFFFF && $start === 0xFFFF) {
                continue;
            }
            // Guard against pathological fonts blowing memory on a huge range.
            if ($end - $start > 5000) {
                $end = $start + 5000;
            }

            for ($cp = $start; $cp <= $end; $cp++) {
                if ($rangeOffset === 0) {
                    $gid = ($cp + $delta) & 0xFFFF;
                } else {
                    $glyphAddr = $rangeBase + $s * 2 + $rangeOffset + ($cp - $start) * 2;
                    if ($glyphAddr + 2 > strlen($data)) {
                        continue;
                    }
                    $gid = $this->uint16($data, $glyphAddr);
                    if ($gid !== 0) {
                        $gid = ($gid + $delta) & 0xFFFF;
                    }
                }
                if ($gid !== 0) {
                    $map[$cp] = $gid;
                }
            }
        }

        return $map ?: null;
    }

    private function uint16(string $data, int $offset): int
    {
        if ($offset + 2 > strlen($data)) {
            return 0;
        }

        return unpack('n', substr($data, $offset, 2))[1];
    }

    private function uint32(string $data, int $offset): int
    {
        if ($offset + 4 > strlen($data)) {
            return 0;
        }

        return unpack('N', substr($data, $offset, 4))[1];
    }
}
