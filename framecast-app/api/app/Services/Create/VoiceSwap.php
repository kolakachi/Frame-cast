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
    public static function plan(object $c, ?object $base, ?array $plan, array $planMedia, array $settings, bool $step): ?array
    {
        if (! $base || ! $plan || $step || ($plan['look_first'] ?? false) || ($plan['planner_task'] ?? null) !== 'edit') return null;
        if (($settings['output_kind'] ?? 'video') !== 'video' || ($settings['video_mode'] ?? 'composition') !== 'composition') return null;
        // Exactly one new item, and it is a voice.
        if (count($planMedia) !== 1 || ! in_array($planMedia[0]['kind'] ?? '', self::KINDS, true)) return null;
        $baseRun = ConversationService::revisionRunId($base->id);
        $baseInput = $baseRun ? json_decode((string) DB::table('composition_runs')->where('id', $baseRun)->value('input_json'), true) : null;
        $basePlan = $baseInput['plan'] ?? null;
        if (! is_array($basePlan) || empty($basePlan['plan_id'])) return null;
        // The beats stay as they are; only what is said (and how) changes, line for line.
        $old = array_values(array_map('strval', (array) ($basePlan['narration'] ?? [])));
        $new = array_values(array_map('strval', (array) ($plan['narration'] ?? [])));
        if (! $old || count($old) !== count($new)) return null;
        // The planner names the parts a change alters; only a change to the voice alone is swapped. (The on-screen copy
        // field is not used: change plans wrote notes into it, 2026-10-08.)
        if (($plan['change_touches'] ?? null) !== ['voice']) return null;
        if (array_column((array) ($basePlan['scenes'] ?? []), 'label') !== array_column((array) ($plan['scenes'] ?? []), 'label')) return null;
        // A swap that already failed for this plan (a new line too long for its slot) goes to the builder instead.
        if (DB::table('composition_runs')->where('conversation_id', $c->id)->where('input_json->plan->plan_id', $plan['plan_id'] ?? '')
            ->whereNotNull('input_json->voice_swap')->where('status', 'failed')->exists()) return null;
        // The narration clip in the version: the one audio file descended from the voice the base plan bought.
        $voiceAsset = DB::table('create_plan_media')->where('plan_id', $basePlan['plan_id'])->whereIn('kind', self::KINDS)->where('status', 'succeeded')
            ->get(['record_json'])->map(fn ($r) => (int) (json_decode((string) $r->record_json, true)['file']['asset_id'] ?? 0))->filter()->first();
        if (! $voiceAsset) return null;
        $bundle = json_decode((string) $base->bundle_json, true) ?: [];
        preg_match_all('/<audio\b[^>]*\bsrc="(asset-(\d+)-[0-9a-f]+\.(?:wav|mp3|m4a))"/i', implode("\n", array_map('strval', $bundle)), $m, PREG_SET_ORDER);
        $narration = array_values(array_unique(array_map(fn ($x) => $x[1], array_filter($m, fn ($x) => self::rootOf((int) $x[2]) === $voiceAsset))));
        if (count($narration) !== 1) return null;
        $workspace = (int) $c->workspace_id;
        return ['old_src' => $narration[0], 'old_lines' => $old,
            // Lined up against what the new voice says, names as saved.
            'new_lines' => array_map(fn ($l) => PlanMediaExecutor::pronounce($l, $workspace), $new)];
    }

    /** The first asset a derived file was made from (a spaced, sped or ducked copy of a voice leads back to the voice). */
    private static function rootOf(int $assetId): int
    {
        for ($hops = 0; $hops < 12; $hops++) {
            $from = (int) (json_decode((string) DB::table('assets')->where('id', $assetId)->value('metadata_json'), true)['derived_from_asset_id'] ?? 0);
            if (! $from) return $assetId;
            $assetId = $from;
        }
        return $assetId;
    }
}
