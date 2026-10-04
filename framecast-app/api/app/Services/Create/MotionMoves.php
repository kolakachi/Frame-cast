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
        'custom' => 'none of these: built by hand from the description',
    ];

    public static function valid(mixed $move): ?string
    {
        return is_string($move) && array_key_exists($move, self::MOVES) ? $move : null;
    }

    /** "words (a headline builds…), write_on (…)": the list a model chooses from. */
    public static function prompt(): string
    {
        return implode('; ', array_map(fn ($k, $v) => $k.' ('.$v.')', array_keys(self::MOVES), self::MOVES));
    }
}
