<?php
namespace App\Services\Create;

/**
 * How closely a build follows an attached reference video: "exact" keeps every moment's timing,
 * layout, transitions and the mascot's placement and mannerisms, swapping only brand, voice and
 * content; "similar" keeps chosen traits (its format, look, pacing, structure, camera journey) with
 * room to change the execution; "inspired" takes only the idea. Set in Details or inferred from
 * the brief; when a brief with a reference video says none clearly, planning asks first.
 */
class ReferenceMatch
{
    public const QUESTION = 'How closely should I follow the reference video? Reply "exactly" to copy it moment for moment (same timing, layout, transitions and mascot placement, with your brand, voice and content swapped in), "similar" to keep its format, look and pacing with your own story and shots, or "inspired" to take just the idea and make something of your own. You can also set this in Details.';

    private const EXACT = '/frame[- ]by[- ]frame|moment[- ]for[- ]moment|move[- ]for[- ]move|shot[- ]for[- ]shot|beat[- ]for[- ]beat|(copy|match|recreate|replicate|follow|clone|mirror)[^.]{0,40}\bexact(ly)?\b|\bexact(ly)? (like|as) (the|this|that) (reference|video)|\bexact (copy|replica|recreation)|\breplica(te|tion)?\b|\bclone\b|copy (it|this|that|the reference|the video|its)|same (layout|structure|placements?|transitions|frames?|timing)|(placements?|structure|transitions|layout)[^.]{0,60}(should|must) (remain|stay|be kept)|keep (the|its) (layout|structure|placements?)/u';
    private const SIMILAR = '/\bsimilar\b|in the (style|spirit|feel|vibe) of|same (style|look|feel|vibe|format|pacing|energy)|keep (the|its) (style|look|feel|format|pacing|drawing style)|something like (this|it|that)/u';
    private const INSPIRED = '/inspired by|\binspiration\b|\bloosely\b|borrow|take ideas|just the (idea|vibe|feel)|my own (take|version)/u';

    /** "exact", "inspired" or null when the brief says neither (or both). */
    public static function infer(string $text): ?string
    {
        // Quoted words are copy for the screen, not instructions.
        $t = mb_strtolower(preg_replace('/["“][^"”]*["”]/u', ' ', $text));
        $hits = array_keys(array_filter(['exact' => (bool) preg_match(self::EXACT, $t), 'similar' => (bool) preg_match(self::SIMILAR, $t), 'inspired' => (bool) preg_match(self::INSPIRED, $t)]));
        // Exact wording outranks the rest ("copy it exactly, same style"); otherwise one clear signal or none.
        if (in_array('exact', $hits, true)) return count($hits) === 1 || ! in_array('inspired', $hits, true) ? 'exact' : null;
        return count($hits) === 1 ? $hits[0] : null;
    }

    /** Only a clear instruction changes a mode that is already set ("like the reference" in a follow-up does not). */
    public static function change(string $text): ?string
    {
        $t = mb_strtolower(preg_replace('/["“][^"”]*["”]/u', ' ', $text));
        $hits = array_keys(array_filter(['exact' => (bool) preg_match('/frame[- ]by[- ]frame|moment[- ]for[- ]moment|shot[- ]for[- ]shot|\bexact(ly)?\b/u', $t),
            'similar' => (bool) preg_match('/\bmore similar\b|\bcloser to (the|this) reference\b|keep (its|the reference\'s) (format|look|pacing|style)/u', $t),
            'inspired' => (bool) preg_match('/inspired by|\binspiration\b|\bloosely\b|\b(own|different) layout/u', $t)]));
        return count($hits) === 1 ? $hits[0] : self::short($text);
    }

    /** A reply to the question: a short answer counts even without the full wording. */
    public static function answer(string $text): ?string
    {
        return self::short($text) ?? self::infer($text);
    }

    private static function short(string $text): ?string
    {
        $t = trim(mb_strtolower($text), " .!\t\n");
        if (preg_match('/^(exact(ly)?|copy( it)?|replicate|frame by frame)$/u', $t)) return 'exact';
        if (preg_match('/^(inspired|loosely|ideas|inspiration)$/u', $t)) return 'inspired';
        if (preg_match('/^(similar|like (it|this)|close|closely)$/u', $t)) return 'similar';
        // A short reply with a typo ("exaclty and also 16:9", "inpsired") still answers the question.
        $words = preg_split('/[^\p{L}]+/u', $t, -1, PREG_SPLIT_NO_EMPTY);
        if (count($words) > 8) return null;
        $near = fn (string $target, int $within) => collect($words)->contains(fn ($w) => mb_strlen($w) >= 4 && levenshtein($w, $target) <= $within);
        $hits = array_keys(array_filter(['exact' => $near('exactly', 2) || $near('exact', 1), 'similar' => $near('similar', 2), 'inspired' => $near('inspired', 2) || $near('loosely', 1)]));
        return count($hits) === 1 ? $hits[0] : null;
    }
}
