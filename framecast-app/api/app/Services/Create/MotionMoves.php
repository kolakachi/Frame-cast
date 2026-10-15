<?php
namespace App\Services\Create;

/**
 * The motion kit's moves (wyv-motion.js), named so a reference study can say which
 * move reproduces a recurring element or transition, the plan can keep it, and the
 * build can be checked for using it. Ids match hyperframes-worker/agent/move-check.mjs.
 */
class MotionMoves
{
    public const MOVES = [
        'words' => 'a headline builds word by word on the voice',
        'write_on' => 'an emphasis word is written on left to right, then underlined',
        'iris' => 'the next scene opens as a circle growing from an element',
        'toss' => 'a card is thrown in spinning and lands upright',
        'pop' => 'something appears from a point with a bounce (bubble, tile, chip, sticker)',
        'device' => 'a full-frame panel shrinks into a phone or laptop screen',
        'through' => 'push into an element until it fills the frame, then open from another (match cut on shape)',
        'fly' => 'a chip arcs from one place into another, which updates (a total, a list)',
        'stamp' => 'a badge or word slams in and settles like an ink stamp',
        'type' => 'text types in character by character',
        'count' => 'a number counts up',
        'cursor' => 'a cursor moves and clicks',
        'press' => 'a button dips and springs back',
        'morph' => 'one shape changes size and form through states',
        'whip' => 'whip pan with motion blur between scenes',
        'wipe' => 'a hard-edged wipe reveals the next scene',
        'push' => 'the next scene pushes the last one out',
        'giant_wipe' => 'one huge word sweeps across as the cut',
        'field' => 'the background cuts to a new colour on a hit',
        'camera' => 'the view zooms into one element (a button, a field, a number) and back out, like a screen recording',
        'flood' => 'a colour grows from a point to fill the frame, holds a beat, then shrinks into the next scene',
        'rise' => 'text rises out of a mask line',
        'edges' => 'a pill or tab highlight slides with its leading edge ahead and its trailing edge catching up',
        'push_in' => 'a slow push into a held scene that keeps a still beat tense (at most twice a video)',
        'pull_back' => 'open tight on a detail, then pull out to reveal the whole',
        'dutch' => 'the scene enters tilted and rolls level',
        'cold_open' => 'three or more very short shots cut hard, then a beat of black before the title',
        'text_mask' => 'a big word is a window onto a picture or colour moving behind it',
        'ramp_freeze' => 'an element shoots in, brakes hard and freezes with a flash',
        'hidden_cut' => 'an object sweeps across the frame and the scene changes behind it',
        'odometer' => 'the digits of an approved number roll into place',
        'gauge' => 'a ring or bar sweeps to an approved value',
        'streak' => 'speed lines tear across the frame over a cut',
        'smash' => 'a hard cut on the hit with a one-frame flash; with the music cut, a smash to silence',
        'split' => 'two panels slide into halves of the frame with a divider: before and after, two options',
        'stack' => 'a list builds on the voice: each new item pushes in, earlier ones step up and dim',
        'parallax' => 'a still photo in layers drifts with depth, like a moving camera',
        'tear' => 'torn-paper strips sweep across the frame as the cut (a collage or zine look)',
        'sticker' => 'a cut-out or label with a white die-cut border slaps on with a tilt',
        'cards' => 'a rapid montage: pictures flash one after another every few frames, slightly tilted',
        'confetti' => 'paper pieces burst from a point and fall',
        'circle_text' => 'a line of text set around a circle, turning (a badge or stamp)',
        'book' => 'pages turn like a real book',
        'slides' => 'a deck\'s slides change one after another, each filling the frame',
        'page_focus' => 'the camera moves into a part of a page or slide and back out',
        'blur_in' => 'lines of text sharpen in from a soft blur, like a lens finding them',
        'letters' => 'a short headline\'s letters rise one by one out of a mask line',
        'rack_focus' => 'focus pulls from one thing to another: the rest goes soft, the thing that matters sharpens',
        'glow' => 'a key word, price or button flashes with light and settles',
        'heart_burst' => 'hearts float up from the like button and fade, as when a video is liked',
        'reply_sticker' => 'the "Reply to @name\'s comment" card pops on with a tilt to open a reply video',
        'stitch' => 'the clip being answered shrinks into a corner thumbnail while the reply fills the frame',
        'custom' => 'none of these: built by hand from the description',
    ];

    /** Each move's energy (low, mid, high) and typical length in seconds: beats are matched to the playbook's energy. */
    public const ENERGY = [
        'words' => ['mid', 1.5], 'write_on' => ['mid', 0.8], 'iris' => ['mid', 0.5], 'toss' => ['high', 0.5], 'pop' => ['mid', 0.4],
        'device' => ['mid', 0.6], 'through' => ['high', 0.8], 'fly' => ['mid', 0.6], 'stamp' => ['high', 0.45], 'type' => ['low', 1.5],
        'count' => ['mid', 1.4], 'cursor' => ['low', 1.5], 'press' => ['low', 0.3], 'morph' => ['mid', 1.0], 'whip' => ['high', 0.7],
        'wipe' => ['mid', 0.55], 'push' => ['mid', 0.6], 'giant_wipe' => ['high', 0.8], 'field' => ['high', 0.1], 'camera' => ['mid', 2.4],
        'flood' => ['high', 0.9], 'rise' => ['mid', 0.55], 'edges' => ['low', 0.5], 'push_in' => ['low', 2.5], 'pull_back' => ['mid', 0.9],
        'dutch' => ['mid', 0.7], 'cold_open' => ['high', 1.3], 'text_mask' => ['mid', 2.0], 'ramp_freeze' => ['high', 0.6], 'hidden_cut' => ['high', 0.7],
        'odometer' => ['mid', 1.0], 'gauge' => ['mid', 1.2], 'streak' => ['high', 0.35],
        'smash' => ['high', 0.05], 'split' => ['mid', 0.7], 'stack' => ['mid', 0.4], 'parallax' => ['low', 3.0],
        'blur_in' => ['low', 0.7], 'letters' => ['mid', 0.8], 'rack_focus' => ['low', 0.6], 'glow' => ['mid', 0.5],
        'heart_burst' => ['mid', 1.6], 'reply_sticker' => ['mid', 0.5], 'stitch' => ['high', 0.55],
        'tear' => ['high', 0.7], 'sticker' => ['mid', 0.45], 'cards' => ['high', 1.0], 'confetti' => ['high', 1.6], 'circle_text' => ['low', 4.0],
        'book' => ['mid', 1.1], 'slides' => ['mid', 0.6], 'page_focus' => ['low', 2.9],
    ];

    public static function valid(mixed $move): ?string
    {
        return is_string($move) && array_key_exists($move, self::MOVES) ? $move : null;
    }

    /** "words (a headline builds…), write_on (…)": the list a model chooses from. */
    /**
     * Which moves suit what a beat is doing (RD-06, from awesome-ai-motion's scene-purpose tables, product-launch-motion's
     * beat catalogue and cinetic's techniques, 2026-10-15). A guide for the planner, never a rule a reference overrides.
     */
    public const BY_PURPOSE = [
        'a hook (the first seconds)' => ['cold_open', 'giant_wipe', 'stamp', 'smash', 'ramp_freeze', 'words', 'letters'],
        'a number or a result' => ['odometer', 'count', 'gauge'],
        'a list or steps' => ['stack', 'pop', 'toss', 'cards'],
        'a product, an app or a screen' => ['device', 'camera', 'cursor', 'press', 'morph', 'edges', 'rack_focus'],
        'a comparison or before and after' => ['split', 'morph'],
        'a reveal or a payoff' => ['iris', 'pull_back', 'through', 'flood', 'text_mask'],
        'a change of topic' => ['whip', 'push', 'wipe', 'hidden_cut', 'streak'],
        'an emphasis word' => ['write_on', 'rise', 'text_mask', 'field', 'glow'],
        'a calm or premium hold' => ['push_in', 'parallax', 'pull_back', 'blur_in', 'rack_focus'],
        'feed-native social proof (a reply to a comment, a stitch, likes)' => ['reply_sticker', 'stitch', 'heart_burst'],
        'the close and call to action' => ['stamp', 'sticker', 'circle_text', 'field'],
    ];

    /** The purpose guide as one line for the planner. */
    public static function purposePrompt(): string
    {
        return 'Choose motion by the job each beat does: '.implode('; ', array_map(fn ($job, $moves) => $job.': '.implode(', ', $moves), array_keys(self::BY_PURPOSE), self::BY_PURPOSE))
            .'. Vary it: one move at most twice in a video unless it is the video\'s signature, and the energy rises to the payoff.';
    }

    public static function prompt(): string
    {
        return implode('; ', array_map(fn ($k, $v) => $k.' ('.$v.(isset(self::ENERGY[$k]) ? '; '.self::ENERGY[$k][0].' energy, ~'.self::ENERGY[$k][1].' s' : '').')', array_keys(self::MOVES), self::MOVES));
    }
}
