<?php
namespace App\Services\Create;

/** Planner interpretation of the brief, not a capability or purchasing entitlement. */
class CreativeIntent
{
    public const FORMATS = ['educational', 'talking_head', 'footage_edit', 'slideshow', 'motion_graphics', 'character_animation', 'mixed', 'still_image'];
    public const MOTION = ['none', 'restrained', 'kinetic', 'natural', 'mixed'];
    public const TIMING = ['narration', 'source', 'music', 'visual'];

    public static function normalize(mixed $raw, array $context): ?array
    {
        $previous = $context['previous_plan']['creative_intent'] ?? null;
        $raw = is_array($raw) ? $raw : [];
        $messages = array_values(array_filter($context['messages'] ?? [], fn ($m) => ($m['role'] ?? '') === 'user'));
        $text = implode("\n", array_column($messages, 'content'));
        $quote = trim(is_string($raw['source_quote'] ?? null) ? $raw['source_quote'] : '');
        $valid = $quote !== '' && mb_strlen($quote) <= 240 && str_contains($text, $quote)
            && in_array($raw['format'] ?? '', self::FORMATS, true)
            && in_array($raw['motion'] ?? '', self::MOTION, true)
            && in_array($raw['timing_driver'] ?? '', self::TIMING, true);
        $intent = $valid ? ['format' => $raw['format'], 'motion' => $raw['motion'], 'timing_driver' => $raw['timing_driver'],
            'reason' => mb_substr(is_string($raw['reason'] ?? null) ? $raw['reason'] : '', 0, 300),
            'source_quote' => $quote, 'provenance' => 'planner_interpretation'] : $previous;
        if (! is_array($intent)) return null; // Legacy/unknown: infer from context, never force motion.
        // A previous timing correction is not authorization to classify all future edits as timing-only.
        $editQuote = trim(is_string($raw['edit_source_quote'] ?? null) ? $raw['edit_source_quote'] : '');
        $timingOnly = ! empty($context['previous_plan']) && ($raw['edit_scope'] ?? '') === 'timing_only'
            && $editQuote !== '' && mb_strlen($editQuote) <= 240 && str_contains(end($messages)['content'] ?? '', $editQuote);
        return [...$intent, 'edit_scope' => $timingOnly ? 'timing_only' : 'content', 'edit_source_quote' => $timingOnly ? $editQuote : null];
    }
}
