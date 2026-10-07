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
    ];

    public static function valid(mixed $move): ?string
    {
        return is_string($move) && array_key_exists($move, self::MOVES) ? $move : null;
    }

    /** "words (a headline builds…), write_on (…)": the list a model chooses from. */
    public static function prompt(): string
    {
        return implode('; ', array_map(fn ($k, $v) => $k.' ('.$v.(isset(self::ENERGY[$k]) ? '; '.self::ENERGY[$k][0].' energy, ~'.self::ENERGY[$k][1].' s' : '').')', array_keys(self::MOVES), self::MOVES));
    }
}
