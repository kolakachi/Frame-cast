<?php

namespace App\Services\Export;

/**
 * Transitions between scenes (owner, 2026-11-17): twenty of ffmpeg's own xfade transitions, none by default.
 *
 * A scene's transition_rule holds the cut after it as "type:seconds" (e.g. "fade:0.5"); empty is a plain cut. The
 * transition overlaps only the picture: the outgoing scene holds its last frame for half of it and the incoming one
 * its first frame for the other half, so every voice starts when it did, nothing is cut, and the video's length is
 * unchanged. The editor previews the same formulas (web/src/editor/next/transitions.js).
 */
final class SceneTransitions
{
    /** xfade transition => the name people see. Order is the picker's. */
    public const CATALOGUE = [
        'fade'        => 'Fade',
        'dissolve'    => 'Dissolve',
        'fadeblack'   => 'Through black',
        'fadewhite'   => 'Through white',
        'wipeleft'    => 'Wipe left',
        'wiperight'   => 'Wipe right',
        'wipeup'      => 'Wipe up',
        'wipedown'    => 'Wipe down',
        'slideleft'   => 'Slide left',
        'slideright'  => 'Slide right',
        'slideup'     => 'Slide up',
        'slidedown'   => 'Slide down',
        'smoothleft'  => 'Soft wipe left',
        'smoothright' => 'Soft wipe right',
        'circleopen'  => 'Circle',
        'radial'      => 'Clock',
        'pixelize'    => 'Pixelate',
        'zoomin'      => 'Zoom',
        'hblur'       => 'Blur',
        'squeezeh'    => 'Squeeze',
    ];

    public const DEFAULT_SECONDS = 0.5;
    public const MIN_SECONDS = 0.2;
    public const MAX_SECONDS = 1.5;

    /** ['type' => ..., 'seconds' => ...] for a stored rule, or null for a cut (empty, unknown, or the old free text). */
    public static function parse(?string $rule): ?array
    {
        $rule = trim((string) $rule);
        if ($rule === '') return null;
        [$type, $seconds] = array_pad(explode(':', $rule, 2), 2, null);
        if (! isset(self::CATALOGUE[$type])) return null;
        $s = is_numeric($seconds) ? (float) $seconds : self::DEFAULT_SECONDS;

        return ['type' => $type, 'seconds' => round(max(self::MIN_SECONDS, min(self::MAX_SECONDS, $s)), 2)];
    }

    /** The stored form; null for a cut. */
    public static function format(?string $type, float $seconds = self::DEFAULT_SECONDS): ?string
    {
        if ($type === null || $type === '' || $type === 'none') return null;
        if (! isset(self::CATALOGUE[$type])) throw new \InvalidArgumentException("Unknown transition {$type}.");

        return $type.':'.rtrim(rtrim(number_format(max(self::MIN_SECONDS, min(self::MAX_SECONDS, $seconds)), 2, '.', ''), '0'), '.');
    }

    /** Whether a value is one this accepts: empty (a cut) or a known "type:seconds". */
    public static function valid(?string $rule): bool
    {
        $rule = trim((string) $rule);
        return $rule === '' || (self::parse($rule) !== null && preg_match('/^[a-z]+(:\d+(\.\d+)?)?$/', $rule) === 1);
    }

    /**
     * The video half of the join: a filter graph over inputs [0:v]..[n-1:v] ending in [outv], or null when every cut
     * is plain (the export then joins as it always has). $durations are the segments' lengths in seconds;
     * $transitions[$i] is the cut after segment $i (parse() output or null).
     *
     * @param  list<float>  $durations
     * @param  list<array{type:string,seconds:float}|null>  $transitions
     */
    public static function videoGraph(array $durations, array $transitions, int $fps = 30): ?string
    {
        $n = count($durations);
        $cuts = [];
        for ($i = 0; $i < $n - 1; $i++) {
            $t = $transitions[$i] ?? null;
            if (! $t) { $cuts[$i] = null; continue; }
            // Never longer than most of either scene: a short scene keeps its own moment on screen.
            $d = min($t['seconds'], 0.8 * $durations[$i], 0.8 * $durations[$i + 1]);
            $cuts[$i] = $d >= 0.1 ? ['type' => $t['type'], 'seconds' => round($d, 3)] : null;
        }
        if (array_filter($cuts) === []) return null;

        $f = [];
        for ($i = 0; $i < $n; $i++) {
            // Hold the first frame for the half of the transition before this scene, the last for the half after.
            $head = isset($cuts[$i - 1]) && $cuts[$i - 1] ? $cuts[$i - 1]['seconds'] / 2 : 0.0;
            $tail = isset($cuts[$i]) && $cuts[$i] ? $cuts[$i]['seconds'] / 2 : 0.0;
            $pad = [];
            if ($head > 0) $pad[] = sprintf('start_mode=clone:start_duration=%.3F', $head);
            if ($tail > 0) $pad[] = sprintf('stop_mode=clone:stop_duration=%.3F', $tail);
            $f[] = sprintf('[%d:v]fps=%d,format=yuv420p,setsar=1,settb=AVTB%s[p%d]', $i, $fps, $pad ? ',tpad='.implode(':', $pad) : '', $i);
            $durations[$i] += $head + $tail;
        }

        $current = '[p0]';
        $length = $durations[0];
        for ($i = 0; $i < $n - 1; $i++) {
            $next = '[p'.($i + 1).']';
            $label = $i === $n - 2 ? '[outv]' : '[j'.$i.']';
            if ($cuts[$i]) {
                $d = $cuts[$i]['seconds'];
                $f[] = sprintf('%s%sxfade=transition=%s:duration=%.3F:offset=%.3F%s', $current, $next, $cuts[$i]['type'], $d, max(0, $length - $d), $label);
                $length += $durations[$i + 1] - $d;
            } else {
                $f[] = sprintf('%s%sconcat=n=2:v=1:a=0%s', $current, $next, $label);
                $length += $durations[$i + 1];
            }
            $current = $label;
        }
        if ($n === 1) return null;

        return implode(';', $f);
    }
}
