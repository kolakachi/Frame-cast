<?php
namespace App\Services\Create;

/**
 * A parametric 3D mascot (hyperframes-worker/runtime/wyv-mascot3d.js): a character described by parts, built in
 * three.js and rigged by construction (head turn, blink, wink, gaze, mouth on the words, expressions). The planner
 * proposes one; this keeps only parts and values the builder has. No image generation is involved.
 */
class MascotSpec
{
    public const PARTS = [
        'head.shape' => ['sphere', 'egg', 'round-square'],
        'hair.style' => ['curls', 'waves', 'bob', 'spikes', 'bun', 'none'],
        'eyes.style' => ['disc', 'oval', 'dot'],
        'brows.style' => ['bar', 'none'],
        'nose.style' => ['button', 'none'],
        'body.outfit' => ['sweater', 'tee', 'hoodie'],
        'body.collar' => ['turtleneck', 'crew'],
        'finish' => ['clay', 'dither', 'toon', 'halftone', 'plush', 'ceramic'],
    ];
    private const COLOURS = ['head.skin', 'hair.color', 'eyes.color', 'brows.color', 'cheeks.color', 'body.color'];

    /** The parts and colours a planner may use, for the planning instruction. */
    public static function prompt(): string
    {
        $parts = collect(self::PARTS)->map(fn ($v, $k) => $k.': '.implode('|', $v))->implode('; ');
        return $parts.'; colours as #rrggbb: '.implode(', ', self::COLOURS).'; body.pocket: true|false; hair.volume 0.7 to 1.3; eyes.size and eyes.spacing 0.8 to 1.2';
    }

    /** A clean spec from a planner's proposal, or null when it is not a mascot at all. */
    public static function normalize(mixed $raw): ?array
    {
        if (! is_array($raw)) return null;
        $get = fn (string $path) => data_get($raw, $path);
        $out = ['seed' => is_int($raw['seed'] ?? null) ? max(1, min(99999, $raw['seed'])) : 7];
        foreach (self::PARTS as $path => $allowed) {
            $v = $get($path);
            if (is_string($v) && in_array($v, $allowed, true)) data_set($out, $path, $v);
        }
        foreach (self::COLOURS as $path) {
            $v = $get($path);
            if (is_string($v) && preg_match('/^#[0-9a-fA-F]{6}$/', $v)) data_set($out, $path, strtolower($v));
        }
        if (is_bool($get('body.pocket'))) data_set($out, 'body.pocket', $get('body.pocket'));
        foreach (['hair.volume' => [0.7, 1.3], 'eyes.size' => [0.8, 1.2], 'eyes.spacing' => [0.8, 1.2]] as $path => [$lo, $hi])
            if (is_numeric($get($path))) data_set($out, $path, round(max($lo, min($hi, (float) $get($path))), 2));
        // A mascot needs at least a head shape or hair and a finish to be the planner's design rather than defaults.
        return count($out) > 2 ? $out : null;
    }
}
