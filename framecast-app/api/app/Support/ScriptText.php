<?php

namespace App\Support;

/**
 * Cleans conversational preamble off a generated script.
 *
 * The model is told to return the script alone, and almost always does — but
 * occasionally it hands the work over first: "Here's your hook, are you over
 * 40 and…" or "Here's your draft — note: this is based on the provided
 * reference transcript…". That lead-in was stored verbatim as the script and
 * shown in the editor, which is what a customer reported: the script "should
 * be exactly" their line, without the handover in front of it.
 *
 * Deliberately narrow. It only strips a lead-in that names the deliverable
 * (hook, script, draft, video, copy, version) or a bare acknowledgement, and
 * only at the very start. A script that legitimately opens "Here's your chance
 * to…" or "Here's what nobody tells you…" is left alone, because the phrase
 * has to be followed by a handover punctuation mark to count.
 */
final class ScriptText
{
    /** Bare acknowledgements a model sometimes opens with. */
    private const ACKS = '(?:sure|certainly|of course|okay|ok|absolutely|got it|no problem|happy to help)';

    /** Words that mean the model is describing its output rather than writing it. */
    private const DELIVERABLES = '(?:hook|script|draft|video|copy|version|voiceover|vo|text)';

    public static function stripPreamble(string $script): string
    {
        $out = ltrim($script);

        // Up to two passes: "Sure, here's your script: …" is two lead-ins.
        for ($i = 0; $i < 2; $i++) {
            $before = $out;

            // "Sure," / "Okay!" / "Certainly." on its own at the start.
            $out = (string) preg_replace(
                '/^'.self::ACKS.'\s*[,.!—–-]\s*/iu',
                '',
                $out,
                1
            );

            // "Here's your hook," / "Here is a script:" / "Here's your draft —"
            // The trailing [:,—–-] is what keeps a genuine opening line safe:
            // "Here's your chance to fix that." has a space, not a handover mark.
            $out = (string) preg_replace(
                '/^here(?:\'|\x{2019})?s?\s+(?:is\s+)?(?:your|a|the)\s+'
                .self::DELIVERABLES
                .'[^\n:,—–-]{0,40}?\s*[:,—–-]\s*/iu',
                '',
                $out,
                1
            );

            if ($out === $before) {
                break;
            }
            $out = ltrim($out);
        }

        // A stripped lead-in can leave the first letter lowercased where the
        // model had it mid-sentence. Only touch it when the result clearly
        // starts a sentence, never mid-word.
        if ($out !== '' && preg_match('/^\p{Ll}/u', $out)) {
            $out = mb_strtoupper(mb_substr($out, 0, 1)).mb_substr($out, 1);
        }

        // Never hand back nothing: if the pattern ate the whole script,
        // something was wrong with the pattern, not the script.
        return trim($out) === '' ? trim($script) : $out;
    }

    /** Ways a model opens a decline. Anchored, so only a lead-in counts. */
    private const DECLINE = '(?:can(?:\'|\x{2019})?t|cannot|can\s+not|won(?:\'|\x{2019})?t|will\s+not|am\s+not\s+able\s+to|am\s+unable\s+to)(?:\s+be\s+able\s+to)?';

    /** The thing being declined — the word that proves it's about the task. */
    private const TASK = '(?:write|create|produce|generate|make|provide|fulfil|fulfill|help(?:\s+you)?\s+with|assist(?:\s+you)?\s+with|continue\s+with)';

    /**
     * True when the model declined the brief instead of writing a script.
     *
     * A refusal used to be stored as script_text and the whole pipeline —
     * title, hooks, scene breakdown, TTS, images — ran on it. The customer
     * got a finished video of whatever example topic the refusal suggested,
     * never saw the decline, and rated it 1/5 ("the AI did not follow my
     * prompt"). Catching it at the source is the only place it stays cheap.
     *
     * Deliberately narrow, like stripPreamble. The decline has to open the
     * text AND name the task, so a script that legitimately contains "I can't
     * help but notice…" or "I can't believe this works" is left alone — the
     * pattern requires "help with", not a bare "help".
     */
    public static function looksLikeRefusal(string $script): bool
    {
        $out = ltrim($script);

        if ($out === '') {
            return false;
        }

        $patterns = [
            // "I can't write this one —", "I'm sorry, but I cannot create that"
            '/^(?:(?:i(?:\'|\x{2019})?m\s+|i\s+am\s+)?sorry[,.!\s\x{2014}\x{2013}-]+)?(?:but\s+)?i\s+'
                .self::DECLINE.'\s+'.self::TASK.'\b/iu',
            // "I'm not comfortable producing this"
            '/^(?:i(?:\'|\x{2019})?m|i\s+am)\s+not\s+comfortable\b/iu',
            // "As an AI language model, I…"
            '/^as\s+an\s+ai\b/iu',
        ];

        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $out) === 1) {
                return true;
            }
        }

        return false;
    }
}
