<?php

namespace App\Services\Generation;

/**
 * What not to ask an AI video model for (owner, 2026-11-05, from the Claude + Codex + Seedance workflow write-up and our
 * own failed takes). Video models garble these, so the planners write around them and the pre-purchase check
 * (ClipPrecheck) looks for them. One list, worded for each reader: the Create planner, the UGC director, the storyboard
 * beats and the check.
 */
final class VideoCraft
{
    /** Each rule: what goes wrong, what to do instead. Keys are what the check reports. */
    public const AVOID = [
        'crossing' => 'people or objects crossing in front of each other (limbs and bodies merge): keep them on their own sides of the frame',
        'small_items' => 'small handheld items with readable detail, text or labels (they melt): show them large and still, or compose the text on top',
        'mirrors' => 'mirrors, reflections and glass that should show a second view (the reflection disagrees with the room)',
        'lopsided' => 'faces or bodies with lopsided or extra features in the frame the clip starts from (the video keeps and worsens them)',
        'lighting_change' => 'light changing mid-shot (lights switching on, sunrise, a flash): keep one light for the whole shot',
        'many_actions' => 'more than one action in a shot (she opens it, smells it, then smiles): one clear action, the next one in the next shot',
        'overhead_faces' => 'overhead or top-down views when the faces matter (faces turn away or warp)',
        'crowds' => 'crowds or background extras (they move in unison or melt): keep the background empty or still',
    ];

    /** What every shot needs instead. */
    public const DO = [
        'staggered' => 'when several people react, they react one after another, never all at once',
        'always_moving' => 'something moves through the whole shot (a person, the light on a surface, steam, the camera): no frozen holds',
        'camera_reason' => 'a camera move has a reason (following the action, revealing what matters); otherwise the camera holds still',
    ];

    /** The rules as one paragraph for a planner or director prompt. $for: plan | ugc | storyboard. */
    public static function rules(string $for = 'plan'): string
    {
        $avoid = self::AVOID;
        if ($for === 'ugc') {
            // The UGC cameras include a mirror selfie and a top-down hands shot (no faces), both on purpose; a UGC clip
            // is a timed sheet of several simple movements, and showing the product in hand is the point (its look
            // comes from the reference photos and the reviewer checks it).
            unset($avoid['mirrors'], $avoid['overhead_faces'], $avoid['many_actions'], $avoid['small_items']);
        }
        if ($for === 'storyboard') {
            // One continuous take from a locked camera: camera moves are decided elsewhere.
            $do = array_diff_key(self::DO, ['camera_reason' => true]);
        }

        return 'Video models garble some things, so never ask for: '.implode('; ', $avoid).'. Every shot instead: '.implode('; ', $do ?? self::DO).'.';
    }
}
