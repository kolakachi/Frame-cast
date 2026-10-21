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

    /**
     * How long a swapped text may be (SWAP-SAFE, 2026-10-19). One word (a web address, a brand name) cannot wrap, and the
     * motion kit sets text that runs out of its box smaller, so it may be twice the field's own; words that wrap may be
     * half as long again (what the template audit's long pass tests: an extra line can run past a mask, which the kit
     * does not shrink); a price or number takes any currency ("Rs 2,700" where "$45" stood). At least 4.
     */
    public static function maxChars(string $current): int
    {
        $current = trim($current); $n = mb_strlen($current);
        if (self::isNumber($current)) return max(10, 2 * $n);
        return max(4, preg_match('/\s/u', $current) ? (int) ceil($n * 1.5) : 2 * $n);
    }

    /**
     * The letters a swapped text can show in the time the version gives the text it replaces (owner, 2026-10-21: "the
     * agent can come out with something creative to fit"). Reading time is letters, not spaces (the reads check: about
     * 17 a second plus a second to find it), and the version passed that check with its own words, so their letter count
     * is the budget. A swap keeps the timing: "Hand-dipped pairs" in a reason's slot was on screen 1.1 s of the 1.9 s
     * it needs. One word (an address, a name) and numbers are left to maxChars.
     */
    public static function readChars(string $current): ?int
    {
        $current = trim($current);
        if (! preg_match('/\s/u', $current) || self::isNumber($current)) return null;
        return max(6, self::letters($current));
    }

    public static function letters(string $text): int
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', trim($text)));
    }

    /** A price, count or percentage ("$45", "3", "20%"), as the template audit reads one. */
    public static function isNumber(string $value): bool
    {
        return (bool) preg_match('/^[\s$€£¥₦₹+\-]*\d[\d.,\s]*[%x×]?$/iu', trim($value));
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
        // The same version made again, with nothing changed: a free re-render (SWAP-SAFE, 2026-10-19: "render it again"
        // was priced as a full build, 1,005 credits). The render picks up fixes since (the motion kit's text fit).
        if (is_array($raw) && ($raw['rerender'] ?? false) === true && ! $touches)
            return ['values' => [], 'pictures' => [], 'voice' => false, 'rerender' => true];
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
        // The only thing a swap buys is a new voice, and only when the voice changes. A swap that keeps the voice may
        // still list the version's narration, unchanged: it is not bought again (SWAP-SAFE canary 2026-10-20: a free
        // re-render of a voiced copy was quoted as a 355-credit build because its plan carried the narration).
        $voiceItems = array_filter($planMedia, fn ($m) => in_array($m['kind'] ?? '', VoiceSwap::KINDS, true));
        if (count($voiceItems) !== count($planMedia)) return null;
        if ($voice && count($voiceItems) !== 1) return null;
        // Its own narration is what the version says (its run keeps the lines as spoken, maybe shortened by SpokenFit), so
        // only a plan that says the voice changes is held to it.
        if (! $voice && $voiceItems && in_array('voice', (array) ($plan['change_touches'] ?? []), true)
            && array_map('trim', array_map('strval', (array) ($plan['narration'] ?? []))) !== array_map('trim', SpokenFit::baseNarration($base))) return null;
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
