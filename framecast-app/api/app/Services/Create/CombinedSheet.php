<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\Process;

/**
 * The combined sheet (fair credits 3, owner 2026-10-20): the cast (each person, product and place) and every storyboard
 * panel drawn as ONE 4K picture, a 2×2 or 3×3 grid of cells in the video's own shape, then cut into the same files the
 * separate sheet and storyboard made. One image costs about $0.30 where six cost $0.80, the cast and panels are drawn
 * together so they match, and the user approves them once.
 *
 * The cells are separated by plain white gutters; the cut follows the gutters it finds, so an uneven grid is cut where
 * it really is. A grid with no gutters found is cut on the even grid with a small inset and reported for a look.
 */
class CombinedSheet
{
    /** Columns and rows for $cells cells: 2×2 up to four, else 3×3 (always the video's shape, so cells are too). */
    public static function layout(int $cells): array
    {
        return $cells <= 4 ? [2, 2] : [3, 3];
    }

    /**
     * The prompt for the whole sheet. $cells: [['kind' => 'subject'|'panel', 'text' => what the cell shows]], in reading
     * order; $refs: what each reference image is, in order (image 1 first).
     */
    public static function prompt(array $cells, array $refs, string $look, string $treatment, string $aspect): string
    {
        [$cols, $rows] = self::layout(count($cells));
        $lines = [];
        foreach (array_values($cells) as $k => $c) $lines[] = 'Cell '.($k + 1).': '.$c['text'];
        for ($k = count($cells); $k < $cols * $rows; $k++) $lines[] = 'Cell '.($k + 1).': plain light grey, empty.';
        return 'One image laid out as a grid of '.$cols.' columns and '.$rows.' rows: '.($cols * $rows).' equal cells, each '.$aspect.', separated by thin plain white gutters, read left to right, top to bottom. '
            .'Each cell is a separate, complete picture filling its cell edge to edge, in the same look: '.trim($look).($treatment !== '' ? ' Treatment: '.$treatment.'.' : '').' '
            .($refs ? implode(' ', array_map(fn ($r, $i) => 'Image '.($i + 1).' is '.$r.'.', $refs, array_keys($refs))).' ' : '')
            .'Every person, product and place looks exactly the same in every cell it appears in. '
            .implode(' ', $lines)
            .' No text, letters, numbers, labels, captions, logos or borders inside any cell.';
    }

    /**
     * The cells' rectangles in the image's own pixels, reading order, cut along the white gutters found near each
     * expected boundary; null when a boundary has no gutter.
     *
     * @return list<array{x: int, y: int, w: int, h: int}>|null
     */
    public static function cells(string $path, int $cols, int $rows): ?array
    {
        $size = self::size($path);
        if (! $size) return null;
        $sw = 360; $sh = max(1, (int) round($size['h'] * $sw / $size['w']));
        $r = Process::timeout(30)->run(['ffmpeg', '-v', 'error', '-i', $path, '-vf', "format=gray,scale={$sw}:{$sh}", '-frames:v', '1', '-f', 'rawvideo', '-pix_fmt', 'gray', '-']);
        $raw = $r->output();
        if (! $r->successful() || strlen($raw) < $sw * $sh) return null;
        $px = fn (int $x, int $y) => ord($raw[$y * $sw + $x]);
        // A gutter line: bright and even along most of its length (a picture's edge is never both).
        $isGutter = function (int $at, bool $vertical) use ($px, $sw, $sh): bool {
            $n = $vertical ? $sh : $sw; $bright = 0;
            for ($i = 0; $i < $n; $i++) if (($vertical ? $px($at, $i) : $px($i, $at)) >= 225) $bright++;
            return $bright >= 0.9 * $n;
        };
        $cuts = function (int $count, int $span, bool $vertical) use ($isGutter): ?array {
            $edges = [[0, 0]];
            for ($b = 1; $b < $count; $b++) {
                $mid = (int) round($b * $span / $count); $band = [];
                for ($at = max(1, $mid - (int) ($span * 0.04)); $at <= min($span - 2, $mid + (int) ($span * 0.04)); $at++) if ($isGutter($at, $vertical)) $band[] = $at;
                if (! $band) return null;
                $edges[] = [min($band), max($band)];
            }
            $edges[] = [$span - 1, $span - 1];
            $out = [];
            for ($i = 0; $i < $count; $i++) $out[] = [$edges[$i][1] + ($i === 0 ? 0 : 1), $edges[$i + 1][0] - ($i === $count - 1 ? 0 : 1)];
            return $out;
        };
        $xs = $cuts($cols, $sw, true); $ys = $cuts($rows, $sh, false);
        if (! $xs || ! $ys) return null;
        $k = $size['w'] / $sw; $out = [];
        foreach ($ys as [$y1, $y2]) foreach ($xs as [$x1, $x2]) {
            // A pixel in from each cut, so no gutter edge is left on a frame.
            $x = (int) ceil(($x1 + 1) * $k); $y = (int) ceil(($y1 + 1) * $k);
            $out[] = ['x' => $x, 'y' => $y, 'w' => max(1, (int) floor(($x2 - 1) * $k) - $x), 'h' => max(1, (int) floor(($y2 - 1) * $k) - $y)];
        }
        return $out;
    }

    /** The even grid, inset 2% from each edge: the cut when no gutters were found. */
    public static function evenCells(string $path, int $cols, int $rows): array
    {
        $size = self::size($path) ?? ['w' => 0, 'h' => 0];
        $cw = $size['w'] / $cols; $ch = $size['h'] / $rows; $out = [];
        for ($r = 0; $r < $rows; $r++) for ($c = 0; $c < $cols; $c++)
            $out[] = ['x' => (int) round($c * $cw + 0.02 * $cw), 'y' => (int) round($r * $ch + 0.02 * $ch), 'w' => (int) floor(0.96 * $cw), 'h' => (int) floor(0.96 * $ch)];
        return $out;
    }

    /** Cuts one cell to a PNG in the video's exact shape (a centred trim of a few pixels at most). */
    public static function cut(string $path, array $cell, string $aspect, string $out): bool
    {
        [$aw, $ah] = array_map('floatval', explode(':', preg_match('/^\d+:\d+$/', $aspect) ? $aspect : '9:16'));
        $w = $cell['w']; $h = $cell['h'];
        if ($w / $h > $aw / $ah) { $nw = (int) floor($h * $aw / $ah); $cell['x'] += intdiv($w - $nw, 2); $w = $nw; }
        else { $nh = (int) floor($w * $ah / $aw); $cell['y'] += intdiv($h - $nh, 2); $h = $nh; }
        $w -= $w % 2; $h -= $h % 2;
        Process::timeout(60)->run(['ffmpeg', '-v', 'error', '-y', '-i', $path, '-vf', "crop={$w}:{$h}:{$cell['x']}:{$cell['y']}", '-frames:v', '1', $out]);
        return is_file($out) && filesize($out) > 0;
    }

    /** @return array{w: int, h: int}|null */
    private static function size(string $path): ?array
    {
        $r = Process::timeout(30)->run(['ffprobe', '-v', 'error', '-show_entries', 'stream=width,height', '-of', 'csv=p=0', $path]);
        return preg_match('/^(\d+),(\d+)/', trim($r->output()), $m) ? ['w' => (int) $m[1], 'h' => (int) $m[2]] : null;
    }
}
