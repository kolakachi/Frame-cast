<?php

namespace App\Services\Create;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * A change that only swaps parts of a finished version needs no builder (Templates, 2026-10-16: the owner's "if the
 * option leads to the production requiring no AI builder cool"). The parts are its text and colour values (composition
 * variables), which picture fills a slot (the user's attached images, used as they are) and what the voice says (the
 * VOICE-ONLY swap). The new values and pictures are baked into the version's code here; a new voice is bought and lined
 * up by the worker as for a voice-only change. Anything else (layout, size, motion, timing, music, new elements) is an
 * ordinary edit by the builder. The Creative Director decides which it is; this only checks the swap is real.
 */
class PartSwap
{
    /** The parts a swap may touch (PlanPrompt's change_touches names). */
    public const TOUCHES = ['voice', 'on_screen_words', 'pictures'];

    /** The longest new text a slot takes without the builder: about what it holds now. Display type is wide, so more room
     *  is not safe ("money, sorted?" in a "paid social?" slot ran off the frame at 1.3x, 2026-10-16); the render's edge
     *  check reports anything that still does not fit. */
    public static function maxChars(string $current): int
    {
        return max(4, mb_strlen($current) + 1);
    }

    /** The version's pictures: each <img> whose file is a Create asset, once per file. */
    public static function pictures(array $bundle): array
    {
        $html = (string) ($bundle['index.html'] ?? '');
        $out = [];
        preg_match_all('/<img\b[^>]*>/i', $html, $tags);
        foreach ($tags[0] as $tag) {
            if (! preg_match('/\bsrc="(asset-(\d+)-[0-9a-f]{64}\.(?:png|jpe?g|webp|svg))"/i', $tag, $s) || isset($out[$s[1]])) continue;
            preg_match('/\sid="([^"]+)"/', $tag, $id);
            $out[$s[1]] = ['src' => $s[1], 'asset_id' => (int) $s[2], 'element' => $id[1] ?? null];
        }
        return array_values($out);
    }

    /** What each picture shows, for the planner: the plan item that made it (through a copied template's origin), or its title. */
    public static function described(array $pictures, int $workspaceId): array
    {
        return array_map(function ($p) use ($workspaceId) {
            $asset = Asset::where('workspace_id', $workspaceId)->find($p['asset_id']);
            $ids = array_filter([$p['asset_id'], (int) data_get($asset?->metadata_json, 'copied_from_asset_id'), (int) data_get($asset?->metadata_json, 'derived_from_asset_id')]);
            $what = null;
            foreach ($ids as $id) {
                $row = DB::table('create_plan_media')->where('status', 'succeeded')->where('record_json->file->asset_id', $id)->value('record_json');
                if ($row && ($what = data_get(json_decode((string) $row, true), 'description'))) break;
                $from = (int) data_get(json_decode((string) DB::table('assets')->where('id', $id)->value('metadata_json'), true), 'derived_from_asset_id');
                if ($from && ($row = DB::table('create_plan_media')->where('status', 'succeeded')->where('record_json->file->asset_id', $from)->value('record_json'))
                    && ($what = data_get(json_decode((string) $row, true), 'description'))) break;
            }
            return ['file' => $p['src'], 'element' => $p['element'], 'what' => mb_substr((string) ($what ?: $asset?->title ?: 'a picture'), 0, 200)];
        }, $pictures);
    }

    /**
     * The planner's swap, checked against the real version: values it has, pictures it shows, images the user attached.
     * Null when nothing real is left or the change touches more than the swappable parts.
     */
    public static function normalize(mixed $raw, array $ctx, array $touches): ?array
    {
        if (! is_array($raw) || ! $touches || array_diff($touches, self::TOUCHES)) return null;
        $values = [];
        if (is_array($raw['values'] ?? null) && ! empty($ctx['current_variables'])) {
            $decls = array_map(fn ($v) => ['id' => $v['id'], 'type' => $v['type'], 'default' => $v['current']], $ctx['current_variables']);
            // Fields the version does not have are dropped; the rest are checked as a free edit's are.
            $known = array_intersect_key($raw['values'], array_flip(array_column($decls, 'id')));
            // Text longer than its slot takes would run off the frame: that is the builder's job, not a swap.
            foreach ($ctx['current_variables'] as $v) if (($v['type'] ?? '') === 'string' && isset($known[$v['id']]) && mb_strlen((string) $known[$v['id']]) > self::maxChars((string) ($v['current'] ?? ''))) return null;
            try { $values = $known ? CompositionVariables::validate($decls, $known) : []; } catch (\Symfony\Component\HttpKernel\Exception\HttpException) { $values = []; }
        }
        $current = array_column($ctx['current_pictures'] ?? [], 'file');
        $images = collect($ctx['files'] ?? [])->filter(fn ($f) => ($f['asset_type'] ?? '') === 'image' && ($f['purpose'] ?? '') === 'source')->pluck('asset_id')->map(fn ($id) => (int) $id)->all();
        $pictures = collect(is_array($raw['pictures'] ?? null) ? $raw['pictures'] : [])
            ->filter(fn ($p) => is_array($p) && in_array($p['from'] ?? null, $current, true) && in_array((int) ($p['to_asset_id'] ?? 0), $images, true))
            ->unique('from')->map(fn ($p) => ['from' => (string) $p['from'], 'to_asset_id' => (int) $p['to_asset_id']])->values()->all();
        $voice = in_array('voice', $touches, true);
        if (! $values && ! $pictures && ! $voice) return null;
        // What the change says it touches must be what the swap does: words changed but no values to set is not a swap.
        if (in_array('on_screen_words', $touches, true) && ! $values) return null;
        if (in_array('pictures', $touches, true) && ! $pictures) return null;
        return ['values' => $values, 'pictures' => $pictures, 'voice' => $voice];
    }

    /**
     * The quote's swap: the version's code with the new values and pictures baked in, and the voice swap when the voice
     * changes. Null when this plan is not a swap, or its parts cannot be applied (it then goes to the builder).
     *
     * @return array{bundle: array, voice_swap: ?array, free: bool, values: array, pictures: list<array>}|null
     */
    public static function forQuote(object $c, ?object $base, ?array $plan, array $planMedia, array $settings, bool $step, array $files): ?array
    {
        $swap = $plan['swap'] ?? null;
        // A swap changes a finished version, whatever the planning job was called: a conversation started from a template
        // has its version before its first plan, which counts as "creative" planning (plannerTask).
        if (! is_array($swap) || ! $base || ! $plan || $step || ($plan['look_first'] ?? false)) return null;
        if (($settings['output_kind'] ?? 'video') !== 'video' || ($settings['video_mode'] ?? 'composition') !== 'composition') return null;
        // A swap keeps the version's picture, so it can't change the shape or length (a new shape needs the builder).
        $was = (array) ((json_decode((string) ($base->metadata_json ?? ''), true) ?: [])['settings'] ?? []);
        if (isset($was['aspect_ratio']) && $was['aspect_ratio'] !== ($settings['aspect_ratio'] ?? null)) return null;
        if (isset($was['duration_seconds']) && (int) $was['duration_seconds'] !== (int) ($settings['duration_seconds'] ?? 0)) return null;
        $voice = (bool) ($swap['voice'] ?? false);
        // The only thing a swap buys is a new voice, and only when the voice changes.
        if ($voice ? (count($planMedia) !== 1 || ! in_array($planMedia[0]['kind'] ?? '', VoiceSwap::KINDS, true)) : count($planMedia) !== 0) return null;
        $voiceSwap = $voice ? VoiceSwap::plan($c, $base, $plan, $planMedia, $settings, $step, true) : null;
        if ($voice && ! $voiceSwap) return null;
        $bundle = json_decode((string) $base->bundle_json, true) ?: [];
        $values = (array) ($swap['values'] ?? []);
        if ($values) {
            $decls = CompositionVariables::declarations($bundle['index.html'] ?? null);
            try { $bundle['index.html'] = CompositionVariables::apply($bundle['index.html'], $decls, CompositionVariables::validate($decls, $values)); }
            catch (\Symfony\Component\HttpKernel\Exception\HttpException) { return null; }
        }
        $byAsset = collect($files)->keyBy('asset_id');
        $current = array_column(self::pictures($bundle), 'src');
        foreach ($swap['pictures'] ?? [] as $p) {
            $to = $byAsset[(int) $p['to_asset_id']] ?? null;
            if (! $to || ($to['asset_type'] ?? '') !== 'image' || ! in_array($p['from'], $current, true)) return null;
            foreach ($bundle as $name => $text) if (is_string($text)) $bundle[$name] = str_replace($p['from'], (string) $to['name'], $text);
        }
        return ['bundle' => $bundle, 'voice_swap' => $voiceSwap, 'free' => ! $voice, 'values' => $values, 'pictures' => array_values($swap['pictures'] ?? [])];
    }
}
