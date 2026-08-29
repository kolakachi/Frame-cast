<?php

namespace App\Services;

/**
 * How many scenes a video of a given length should be cut into.
 *
 * Scene count and total duration are INDEPENDENT. A segment's length is driven
 * by its voiceover (RendersExportScenes reads audio duration first), so the
 * finished video is as long as the narration takes to speak — the script's
 * length sets the runtime, not the number of scenes. Splitting the same script
 * into fewer scenes therefore produces the same video with longer scenes, not
 * a shorter one.
 *
 * That matters because cost scales with scene COUNT, not runtime: every scene
 * is its own image and, if animated, its own video render. Animated b-roll at
 * 50-125 credits a scene is the most expensive thing the product makes, so an
 * animated video is deliberately cut into fewer, longer scenes. Video visuals
 * loop to fill their segment (`-stream_loop -1`), so a 5s clip covers a 10s
 * scene without freezing.
 */
class ScenePacing
{
    /**
     * Seconds of screen time per scene, by how the visuals are produced.
     *
     * Stills are cheap, so they can cut fast — which also looks better, since
     * a static image outstays its welcome quickly. Animated scenes hold longer
     * because each one is a paid render.
     */
    public const SECONDS_PER_SCENE = [
        'animated'     => 10.0,  // i2v b-roll — 50-125 cr per scene
        'ai_images'    => 5.0,   // generated stills — ~16 cr per scene
        'stock'        => 5.0,   // stock clips — included
        'stock_images' => 5.0,
        'waveform'     => 20.0,  // audiogram: one waveform, no reason to cut
    ];

    public const DEFAULT_SECONDS_PER_SCENE = 5.0;

    /**
     * Upper bound on scenes in one project.
     *
     * Was a flat 20 in the breakdown prompt, which 3-minute videos hit
     * immediately — and at 180s that forced ~9s stills, or worse, the model
     * quietly under-produced (one real 180s project came back with 7 scenes at
     * ~26s each, which does not read as short-form at all).
     */
    public const MAX_SCENES = 30;

    public const MIN_SCENES = 2;

    /**
     * Runaway guard for the breakdown parser — NOT a content limit.
     *
     * Deliberately far above MAX_SCENES. The parser used to slice at a flat 20,
     * which silently DROPPED every scene past the twentieth along with its
     * narration, so a long video lost its ending and nothing said so. A cap
     * here can only ever discard the user's own script, so it sits high enough
     * that real content never reaches it and only a malformed response does.
     */
    public const PARSER_HARD_LIMIT = 60;

    /**
     * Above this, a video needs internal structure rather than one arc.
     *
     * A 3-minute video is not a 60-second video three times over: attention
     * has to be re-won part way through, and 30 scenes of identical length
     * read as a list rather than a story. Below the threshold a single
     * hook-body-CTA arc is the right shape and sectioning would fragment it,
     * so 30/60/90s deliberately get no structural instruction at all.
     */
    public const LONG_FORM_SECONDS = 120;

    /**
     * Structural guidance for long videos; empty for short ones.
     *
     * Pacing variation is already expressible — the breakdown returns a
     * duration_seconds per scene — but nothing asked for it, so long videos
     * came back uniformly paced.
     */
    public static function structureGuidance(int $durationSeconds): string
    {
        if ($durationSeconds < self::LONG_FORM_SECONDS) {
            return '';
        }

        return 'This is a long video, so give it internal structure rather than one flat arc: '
            .'group the scenes into 3-5 distinct sections, and open each section with its own '
            .'short re-hook so a viewer arriving mid-way is pulled back in. '
            .'Vary the pace deliberately — set a shorter duration_seconds (3-4s) on hooks, '
            .'turns and recaps, and a longer one (7-9s) where something is being explained. '
            .'Scenes of identical length read as a list rather than a story. '
            .'Close on a single clear takeaway rather than trailing off.';
    }

    /** Scenes to aim for at this length and visual mode. */
    public static function targetScenes(int $durationSeconds, ?string $visualMode, bool $animated = false): int
    {
        $perScene = self::secondsPerScene($visualMode, $animated);
        $target   = (int) round(max(1, $durationSeconds) / $perScene);

        return max(self::MIN_SCENES, min(self::MAX_SCENES, $target));
    }

    public static function secondsPerScene(?string $visualMode, bool $animated = false): float
    {
        if ($animated) {
            return self::SECONDS_PER_SCENE['animated'];
        }

        return self::SECONDS_PER_SCENE[$visualMode ?? ''] ?? self::DEFAULT_SECONDS_PER_SCENE;
    }

    /**
     * Instruction for the breakdown prompt.
     *
     * Explicit numbers, because "1-20 scenes, fit the duration" left the model
     * to guess: 30s videos averaged 8 scenes and 60s averaged 10.5, so
     * doubling the runtime added only 2.5 scenes and silently stretched each
     * one instead. A stated target with a tolerance removes the guess.
     */
    public static function guidance(int $durationSeconds, ?string $visualMode, bool $animated = false): string
    {
        $target   = self::targetScenes($durationSeconds, $visualMode, $animated);
        $perScene = self::secondsPerScene($visualMode, $animated);
        $low      = max(self::MIN_SCENES, (int) round($target * 0.8));
        $high     = min(self::MAX_SCENES, (int) round($target * 1.2));

        $note = $animated
            ? ' Each scene is an expensive animated render, so prefer fewer, longer scenes — never pad the count.'
            : '';

        return sprintf(
            'Aim for about %d scenes (%d-%d is fine), averaging roughly %s seconds of narration each.%s',
            $target,
            $low,
            $high,
            rtrim(rtrim(number_format($perScene, 1), '0'), '.'),
            $note,
        );
    }
}
