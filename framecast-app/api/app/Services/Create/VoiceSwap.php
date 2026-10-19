<?php

namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/**
 * A change that only re-voices the narration needs no builder: the new voice is bought, lined up with the old line
 * timings and swapped into the version as it is (VOICE-ONLY, 2026-10-08: a re-voice ran the builder for 107-133
 * credits, held about 650, and the builder sometimes changed the picture). Anything else is a normal edit.
 */
class VoiceSwap
{
    public const KINDS = ['voiceover', 'cloned_voiceover'];

    /**
     * The swap for this quote, or null when the change is more than a new voice.
     *
     * @return array{old_src: string, old_lines: list<string>, new_lines: list<string>}|null
     */
    /** $withParts: the voice is one part of a swap (PartSwap) that also sets text, colours or pictures. */
    /** The audio files the build tagged as the voice (id "vo", "voice", "voiceover" or "narration"), once each. */
    public static function taggedVoice(array $bundle): array
    {
        preg_match_all('/<audio\b[^>]*>/i', implode("\n", array_filter($bundle, 'is_string')), $tags);
        $files = [];
        foreach ($tags[0] as $tag) {
            if (! preg_match('/\bid="(vo|voice|voiceover|narration)"/i', $tag) || ! preg_match('/\bsrc="(asset-\d+-[0-9a-f]+\.(?:wav|mp3|m4a))"/i', $tag, $src)) continue;
            $files[] = $src[1];
        }
        return array_values(array_unique($files));
    }

    public static function plan(object $c, ?object $base, ?array $plan, array $planMedia, array $settings, bool $step, bool $withParts = false): ?array
    {
        if (! $base || ! $plan || $step || ($plan['look_first'] ?? false) || (! $withParts && ($plan['planner_task'] ?? null) !== 'edit')) return null;
        if (($settings['output_kind'] ?? 'video') !== 'video' || ($settings['video_mode'] ?? 'composition') !== 'composition') return null;
        // Exactly one new item, and it is a voice.
        if (count($planMedia) !== 1 || ! in_array($planMedia[0]['kind'] ?? '', self::KINDS, true)) return null;
        $baseRun = ConversationService::revisionRunId($base->id);
        $baseInput = $baseRun ? json_decode((string) DB::table('composition_runs')->where('id', $baseRun)->value('input_json'), true) : null;
        $basePlan = $baseInput['plan'] ?? null;
        if (! is_array($basePlan) || empty($basePlan['plan_id'])) return null;
        // Only what is said (and how) changes, line for line.
        $old = array_values(array_map('strval', (array) ($basePlan['narration'] ?? [])));
        $new = array_values(array_map('strval', (array) ($plan['narration'] ?? [])));
        // A swap with the parts (a template's free ad, PartSwap) whose new words come in a different number of lines is
        // re-split into the version's lines, each sized like the line it replaces (owner's free-ad test, 2026-10-17: the
        // plan wrote 4 lines for a 5-line voice and the approval was refused).
        $refit = false;
        if ($withParts && $old && $new && count($old) !== count($new) && ($fitted = self::fitLines($new, $old))) { $new = $fitted; $refit = true; }
        if (! $old || count($old) !== count($new)) return null;
        // The planner names the parts a change alters; only a change to the voice alone is swapped. (The on-screen copy
        // field is not used: change plans wrote notes into it, 2026-10-08.)
        $touches = (array) ($plan['change_touches'] ?? []);
        if ($withParts ? (! in_array('voice', $touches, true) || array_diff($touches, PartSwap::TOUCHES)) : $touches !== ['voice']) return null;
        // Beat names are not compared: a swap keeps the version exactly, and re-plans rename beats (brief 2, 2026-10-08).
        // A swap that could not fit a line for this plan goes to the builder instead (any other failure may simply retry).
        if (DB::table('composition_runs')->where('conversation_id', $c->id)->where('input_json->plan->plan_id', $plan['plan_id'] ?? '')
            ->whereNotNull('input_json->voice_swap')->where('status', 'failed')->where('stage', 'like', '%needs the full editor%')->exists()) return null;
        // The narration clip in the version: the one audio file descended from the voice the base plan bought.
        $voiceAsset = DB::table('create_plan_media')->where('plan_id', $basePlan['plan_id'])->whereIn('kind', self::KINDS)->where('status', 'succeeded')
            ->get(['record_json'])->map(fn ($r) => (int) (json_decode((string) $r->record_json, true)['file']['asset_id'] ?? 0))->filter()->first();
        $bundle = json_decode((string) $base->bundle_json, true) ?: [];
        preg_match_all('/<audio\b[^>]*\bsrc="(asset-(\d+)-[0-9a-f]+\.(?:wav|mp3|m4a))"/i', implode("\n", array_map('strval', $bundle)), $m, PREG_SET_ORDER);
        $narration = $voiceAsset ? array_values(array_unique(array_map(fn ($x) => $x[1], array_filter($m, fn ($x) => self::rootOf((int) $x[2]) === $voiceAsset)))) : [];
        // A version imported from another server (create:version import, the templates built locally) keeps its code and
        // plan but not the record of which file its voice was: the one audio the build tagged as the voice stands in.
        if (count($narration) !== 1) $narration = self::taggedVoice($bundle);
        if (count($narration) !== 1) return null;
        $workspace = (int) $c->workspace_id;
        return ['old_src' => $narration[0], 'old_lines' => $old,
            // Re-split lines are read as one continuous take: cutting them line by line broke sentences (voice-swap.mjs).
            ...($refit ? ['continuous' => true] : []),
            // Lined up against what the new voice says, names as saved.
            'new_lines' => array_map(fn ($l) => PlanMediaExecutor::pronounce($l, $workspace), $new)];
    }

    /**
     * The new words in as many lines as the old ones, each about as long as the line it replaces (by its share of all the
     * old words). Null when there are fewer words than lines.
     *
     * @param list<string> $new
     * @param list<string> $old
     * @return list<string>|null
     */
    public static function fitLines(array $new, array $old): ?array
    {
        $n = count($old);
        $text = trim(preg_replace('/\s+/u', ' ', implode(' ', $new)));
        if ($n < 1 || count(preg_split('/\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY)) < $n) return null;
        // Whole sentences first; then the longest is halved at its middle word, or the shortest neighbours joined.
        $lines = preg_split('/(?<=[.!?])\s+/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $words = fn ($l) => preg_split('/\s+/u', trim($l), -1, PREG_SPLIT_NO_EMPTY);
        while (count($lines) < $n) {
            $longest = 0;
            foreach ($lines as $k => $l) if (count($words($l)) > count($words($lines[$longest]))) $longest = $k;
            $w = $words($lines[$longest]);
            if (count($w) < 2) return null;
            $half = intdiv(count($w), 2);
            array_splice($lines, $longest, 1, [implode(' ', array_slice($w, 0, $half)), implode(' ', array_slice($w, $half))]);
        }
        while (count($lines) > $n) {
            $best = 0; $size = PHP_INT_MAX;
            for ($k = 0; $k < count($lines) - 1; $k++) {
                $pair = count($words($lines[$k])) + count($words($lines[$k + 1]));
                if ($pair < $size) { $size = $pair; $best = $k; }
            }
            array_splice($lines, $best, 2, [$lines[$best].' '.$lines[$best + 1]]);
        }

        return array_values($lines);
    }

    /** The first asset a derived file was made from (a spaced, sped or ducked copy of a voice leads back to the voice). */
    private static function rootOf(int $assetId): int
    {
        for ($hops = 0; $hops < 12; $hops++) {
            $meta = json_decode((string) DB::table('assets')->where('id', $assetId)->value('metadata_json'), true) ?: [];
            // A template's files are copied into the user's workspace (copied_from_asset_id) and between installs
            // (imported_asset_id); both lead back to the voice the template's plan bought (owner's free-ad test, 2026-10-17).
            $from = (int) ($meta['derived_from_asset_id'] ?? $meta['copied_from_asset_id'] ?? $meta['imported_asset_id'] ?? 0);
            if (! $from) return $assetId;
            $assetId = $from;
        }
        return $assetId;
    }
}
