<?php
namespace App\Services\Create;

use App\Services\CreditService;

/**
 * Which video model makes each generated shot and UGC take, and what it costs.
 * The planner chooses the best fit per shot (cost never chooses); this enforces
 * what the models can actually do, the owner's rules, and prices from the
 * catalogue rather than the model:
 *  - reference images (a cast/world sheet, the user's avatar) need an engine
 *    that takes them: Seedance 2.5, Omni or Veo 3.1;
 *  - a shot with the user's avatar never goes to Seedance (it declines and
 *    moderates real faces): Omni, or Veo 3.1 on Premium;
 *  - a first frame (exact framing) uses a first-frame engine;
 *  - durations and aspect ratios snap to what each model accepts.
 */
class ShotRoute
{
    /** Engines that take reference images, the most each takes, and their lengths. */
    public const REF = [
        'seedance25' => ['label' => 'Seedance 2.5', 'refs' => 30, 'min' => 4, 'max' => 30, 'aspects' => ['16:9', '4:3', '1:1', '3:4', '9:16', '21:9']],
        'omni' => ['label' => 'Gemini Omni', 'refs' => 3, 'min' => 2, 'max' => 10, 'aspects' => ['16:9', '9:16']],
        'veo_hq' => ['label' => 'Veo 3.1', 'refs' => 3, 'steps' => [4, 6, 8], 'aspects' => ['16:9', '9:16']],
    ];

    /** First-frame engines, as the animation tiers that already run them. */
    public const FIRST_FRAME = [
        'seedance_lite' => ['tier' => 'seedance_lite', 'label' => 'Seedance Lite', 'steps' => [5, 10], 'seedance' => true],
        'seedance_pro' => ['tier' => 'seedance_pro', 'label' => 'Seedance Pro', 'steps' => [5, 10], 'seedance' => true],
        'kling' => ['tier' => 'premium', 'label' => 'Kling 2.1', 'steps' => [5, 10]],
        'veo_fast' => ['tier' => 'veo_fast', 'label' => 'Veo 3.1 Fast', 'steps' => [4, 8]],
        'hailuo' => ['tier' => 'balanced', 'label' => 'Hailuo 2.3', 'steps' => [6, 10]],
        'wan' => ['tier' => 'quick', 'label' => 'Wan 2.5', 'steps' => [5, 10]],
    ];

    /** Plan media kinds this class routes and prices. */
    public const KINDS = ['reference_sheet', 'storyboard', 'generated_shot', 'ugc_take'];

    /** Credits for one storyboard panel (Nano Banana Pro, as a cast image). */
    public const PANEL_CREDITS = 35;

    /** Fields a routed item carries from the plan to the build. */
    public const ROUTE_KEYS = ['engine', 'engine_label', 'seconds', 'refs', 'first_frame', 'audio', 'line', 'aspect', 'segments', 'presenter', 'subjects', 'beat', 'why', 'route_notes', 'lines', 'problem', 'speech_mode', 'action', 'gaze', 'camera', 'end_state', 'panels', 'cast_sha256', 'screen'];

    /**
     * The storyboard: one panel per generated shot, drawn from the cast, showing the shot's opening moment (its
     * composition, pose, gaze and light). The approved panel is the shot's start frame. A note on a panel redraws it.
     */
    public static function storyboard(array $shots, array $notes = []): array
    {
        $panels = [];
        foreach (array_values($shots) as $k => $s) {
            $n = $k + 1;
            $panels[] = array_filter(['label' => 'Panel '.$n, 'shot' => $n, 'beat' => $s['beat'] ?? null, 'description' => $s['description'] ?? '',
                'action' => $s['action'] ?? null, 'gaze' => $s['gaze'] ?? null, 'camera' => $s['camera'] ?? null, 'aspect' => $s['aspect'] ?? null, 'screen' => ! empty($s['screen']) ? true : null,
                'refs' => array_values(array_filter((array) ($s['panel_refs'] ?? $s['refs'] ?? ['sheet']), fn ($r) => ! str_starts_with(mb_strtolower((string) $r), 'panel '))) ?: ['sheet'],
                'note' => isset($notes[$n]) ? mb_substr(trim((string) $notes[$n]), 0, 240) : null], fn ($v) => $v !== null && $v !== '');
        }
        return ['kind' => 'storyboard', 'description' => 'One panel per generated shot, drawn from the cast: the opening moment of each shot', 'panels' => $panels,
            'credits' => count($panels) * self::PANEL_CREDITS];
    }

    /**
     * When a model refuses a shot (its moderation declined it), the next-best engine that takes the same inputs, for
     * the user to choose with its price: never a silent retry. Null when nothing else fits.
     */
    public static function fallback(array $item, array $ctx): ?array
    {
        $engine = (string) ($item['engine'] ?? '');
        $first = ! empty($item['first_frame']);
        $order = match (true) {
            $engine === 'seedance25' => ['omni', 'veo_hq'],
            $engine === 'omni' => ['veo_hq', 'kling'],
            $engine === 'veo_hq' => ['omni'],
            in_array($engine, ['seedance_lite', 'seedance_pro', 'hailuo', 'wan'], true) => ['kling', 'veo_fast'],
            $engine === 'kling' => ['veo_fast', 'omni'],
            default => ['omni'],
        };
        foreach ($order as $next) {
            if (isset(self::FIRST_FRAME[$next]) && ! $first) continue;
            $routed = self::shot(['engine' => $next] + $item, ['video_tier' => 'standard'] + $ctx);
            if (($routed['engine'] ?? null) === $next && empty($routed['problem'])) return ['engine' => $next, 'label' => self::label($next), 'credits' => (int) $routed['credits']];
        }
        return null;
    }

    /** Whether a shot shows a person: the avatar, or a character subject named (or the whole sheet when it has one). */
    public static function hasPerson(array $m, array $subjects): bool
    {
        $characters = array_map('mb_strtolower', array_column(array_filter($subjects, fn ($s) => ($s['kind'] ?? 'character') === 'character'), 'name'));
        foreach ([...(array) ($m['refs'] ?? []), (string) ($m['first_frame'] ?? '')] as $r) {
            $r = mb_strtolower(preg_replace('/^sheet:/', '', (string) $r));
            if ($r === 'avatar' || ($r === 'sheet' && $characters) || in_array($r, $characters, true)) return true;
        }
        return false;
    }

    /** What the planner may say about a generated item; everything else is derived. */
    public static function plannerFields(array $m): array
    {
        $s = fn ($v, $n) => mb_substr(trim(is_string($v) ? $v : ''), 0, $n);
        return array_filter([
            'engine' => in_array($m['engine'] ?? null, self::ENGINES, true) ? $m['engine'] : null,
            'seconds' => is_numeric($m['seconds'] ?? null) ? (float) $m['seconds'] : null,
            'refs' => array_values(array_filter(array_map(fn ($r) => $s($r, 46), (array) ($m['refs'] ?? [])))) ?: null,
            'first_frame' => $s($m['first_frame'] ?? $m['start_frame'] ?? '', 40) ?: null,
            'audio' => in_array($m['audio'] ?? null, ['ambient', 'speech', 'none'], true) ? $m['audio'] : null,
            'line' => $s($m['line'] ?? '', 600) ?: null,
            'aspect' => preg_match('/^\d{1,2}:\d{1,2}$/', (string) ($m['aspect'] ?? '')) ? $m['aspect'] : null,
            'beat' => $s($m['beat'] ?? '', 40) ?: null,
            'why' => $s($m['why'] ?? '', 160) ?: null,
            'presenter' => in_array($m['presenter'] ?? null, ['avatar', 'sheet'], true) ? $m['presenter'] : null,
            // Which approved lines a take speaks; never cut, the words come from the approved narration (see take()).
            'lines' => array_values(array_filter(array_map(fn ($l) => $s($l, 600), (array) ($m['lines'] ?? [])))) ?: null,
            'subjects' => is_array($m['subjects'] ?? null) ? self::sheet($m)['subjects'] : null,
            // Direction for a generated shot: what happens, where people look, how the camera moves, how it ends.
            'action' => $s($m['action'] ?? '', 200) ?: null,
            'gaze' => $s($m['gaze'] ?? '', 120) ?: null,
            'camera' => $s($m['camera'] ?? '', 120) ?: null,
            'end_state' => $s($m['end_state'] ?? '', 160) ?: null,
            // A shot made to carry the real app on an in-world screen (a still, blank, glowing device screen).
            'screen' => ! empty($m['screen']) ? true : null,
        ], fn ($v) => $v !== null);
    }

    /**
     * The identity of the approved images one clip is made from: its start panel and the cast it names (all of it
     * for "sheet"; a take's presenter). A change to another panel or subject leaves this clip's identity alone.
     */
    public static function inputsSha(array $item, array $files, array $names): string
    {
        $named = [];
        foreach ($files as $k => $f) $named[mb_strtolower((string) ($names[$k] ?? ''))] = (string) ($f['sha256'] ?? '');
        $cast = array_filter($named, fn ($_, $n) => ! str_starts_with($n, 'panel '), ARRAY_FILTER_USE_BOTH);
        $use = [];
        foreach ([...(array) ($item['refs'] ?? []), (string) ($item['first_frame'] ?? ''), ($item['presenter'] ?? '') === 'sheet' ? 'sheet' : ''] as $r) {
            $r = mb_strtolower(preg_replace('/^sheet:/', '', (string) $r));
            if ($r === 'sheet') $use += $cast; elseif (isset($named[$r])) $use[$r] = $named[$r];
        }
        ksort($use);
        return hash('sha256', json_encode($use));
    }

    /** Whether an item is made from the cast/world sheet, so needs it approved first. */
    public static function usesSheet(array $m): bool
    {
        return match ($m['kind'] ?? '') {
            'generated_shot' => collect((array) ($m['refs'] ?? []))->contains(fn ($r) => $r !== 'avatar') || (($m['first_frame'] ?? null) && $m['first_frame'] !== 'avatar'),
            'ugc_take' => ($m['presenter'] ?? '') === 'sheet',
            default => false,
        };
    }

    public const ENGINES = ['seedance25', 'omni', 'veo_hq', 'seedance_lite', 'seedance_pro', 'kling', 'veo_fast', 'hailuo', 'wan'];

    /** A UGC take speaks; only these keep a real or approved presenter's identity with native speech. */
    public const TAKE_ENGINES = ['omni', 'veo_hq'];

    /** Credits a second for the reference engines (720p), from the one-shot price list. */
    public static function perSecond(string $engine): int
    {
        return (int) (CreditService::VIDEO_ONESHOT_PER_SECOND[$engine] ?? 0);
    }

    public static function label(string $engine): string
    {
        return self::REF[$engine]['label'] ?? self::FIRST_FRAME[$engine]['label'] ?? $engine;
    }

    /** The supported ratio closest to the slot the shot fills. */
    public static function aspect(?string $want, array $allowed, string $fallback): string
    {
        $ratio = fn (string $a) => (fn ($p) => count($p) === 2 && (float) $p[1] > 0 ? (float) $p[0] / (float) $p[1] : null)(explode(':', $a));
        $w = $want ? $ratio($want) : null;
        if (! $w) return in_array($fallback, $allowed, true) ? $fallback : $allowed[0];
        // A tie (a square slot between portrait and landscape) goes to the video's own shape.
        usort($allowed, fn ($a, $b) => [round(abs(log($ratio($a) / $w)), 6), $a === $fallback ? 0 : 1] <=> [round(abs(log($ratio($b) / $w)), 6), $b === $fallback ? 0 : 1]);
        return $allowed[0];
    }

    private static function landscape(string $aspect): bool
    {
        $p = explode(':', $aspect);
        return count($p) === 2 && (float) $p[1] > 0 && (float) $p[0] / (float) $p[1] >= 1.2;
    }

    private static function step(float $seconds, array $steps): int
    {
        foreach ($steps as $s) if ($seconds <= $s + 0.01) return $s;
        return end($steps);
    }

    /**
     * Route one generated shot. $item: description, engine, seconds, refs (['sheet', 'avatar']), first_frame
     * ('avatar' or a sheet subject's name), audio (ambient|speech|none), line, aspect, why.
     */
    /** What each engine takes: a start frame, reference images, or both at once. First-frame engines need a start frame. */
    public const INPUTS = [
        'seedance25' => ['start' => true, 'refs' => true, 'both' => false],
        // Omni on Replicate refuses a start frame with reference images (2026-10-05): one or the other.
        'omni' => ['start' => true, 'refs' => true, 'both' => false],
        'veo_hq' => ['start' => true, 'refs' => true, 'both' => true],
    ];

    /**
     * Resolve what a shot names (refs: "avatar", "sheet" for every subject, or one subject's name; first_frame:
     * "avatar" or a subject's name) against what the plan actually has. Anything unresolved is a problem to fix in
     * the plan, never a silent fallback to another image.
     */
    public static function inputs(array $item, array $ctx): array
    {
        $subjects = array_map('mb_strtolower', [...(array) ($ctx['subjects'] ?? []), ...(array) ($ctx['panels'] ?? [])]);
        $known = fn (string $n) => $n === 'avatar' ? ! empty($ctx['has_avatar'])
            : ($n === 'sheet' ? ! empty($ctx['has_sheet']) : in_array(mb_strtolower(preg_replace('/^sheet:/', '', $n)), $subjects, true));
        $refs = []; $unresolved = [];
        foreach (array_unique(array_map(fn ($r) => trim((string) $r), (array) ($item['refs'] ?? []))) as $r) {
            if ($r === '') continue;
            if ($known($r)) $refs[] = $r; else $unresolved[] = $r;
        }
        $first = trim((string) ($item['first_frame'] ?? ''));
        if ($first !== '' && ! $known($first)) { $unresolved[] = $first; $first = ''; }
        return [$refs, $first ?: null, $unresolved];
    }

    /**
     * Route one generated shot. $item: description, engine, seconds, refs, first_frame, audio (ambient|speech|none),
     * line, aspect, why. Inputs follow each engine's contract (INPUTS); a change is stated in route_notes.
     */
    public static function shot(array $item, array $ctx): array
    {
        $premium = ($ctx['video_tier'] ?? 'standard') === 'premium';
        [$refs, $first, $unresolved] = self::inputs($item, $ctx);
        $engine = in_array($item['engine'] ?? null, self::ENGINES, true) ? $item['engine'] : null;
        $avatar = in_array('avatar', $refs, true) || $first === 'avatar';
        $seconds = max(2.0, min(30.0, (float) ($item['seconds'] ?? 5)));
        $notes = [];
        $isSeedance = fn ($e) => $e === 'seedance25' || ! empty(self::FIRST_FRAME[$e]['seedance']);

        if ($engine === null) $engine = $avatar ? 'omni' : 'seedance25';
        // Never Seedance with the user's face: Omni takes the same inputs and keeps the person.
        if ($avatar && $isSeedance($engine)) { $engine = 'omni'; $notes[] = 'Your avatar is in this shot, so it uses Omni instead of Seedance.'; }
        if (isset(self::FIRST_FRAME[$engine])) {
            // First-frame engines take a start frame and nothing else.
            if (! $first) { $engine = $avatar ? 'omni' : 'seedance25'; $notes[] = self::label($engine).' is used: the chosen model needs a start frame and this shot has none.'; }
            elseif ($refs) { $notes[] = self::label($engine).' takes only the start frame; the references are not sent.'; $refs = []; }
        }
        if (isset(self::INPUTS[$engine]) && $first && $refs && ! self::INPUTS[$engine]['both']) {
            // Seedance and Omni take a start frame or references, not both: the approved frame already holds the cast.
            $notes[] = self::label($engine).' takes a start frame or references, not both: the start frame carries the cast.';
            $refs = [];
        }
        if (isset(self::REF[$engine])) {
            // Veo 3.1 takes references only for an 8 s landscape clip; a portrait slot would lose most of it to the crop.
            $veoFits = fn () => $seconds <= 8.01 && ($refs === [] || self::landscape($item['aspect'] ?? $ctx['aspect_ratio'] ?? '9:16'));
            if ($premium && $engine !== 'veo_hq' && $veoFits()) $engine = 'veo_hq';
            if ($engine === 'veo_hq' && ! $veoFits()) $engine = $avatar ? 'omni' : 'seedance25';
            if ($engine === 'omni' && $seconds > 10.01 && ! $avatar) $engine = 'seedance25';
            // The engine may have changed above: apply its start-frame-or-references rule again.
            if ($first && $refs && isset(self::INPUTS[$engine]) && ! self::INPUTS[$engine]['both']) { $notes[] = self::label($engine).' takes a start frame or references, not both: the start frame carries the cast.'; $refs = []; }
            $spec = self::REF[$engine];
            $seconds = isset($spec['steps']) ? self::step($seconds, $spec['steps']) : (int) round(max($spec['min'], min($spec['max'], $seconds)));
            $aspect = self::aspect($item['aspect'] ?? null, $spec['aspects'], $ctx['aspect_ratio'] ?? '9:16');
            if ($engine === 'veo_hq' && $refs !== []) { $seconds = 8; $aspect = '16:9'; }
            $refs = array_slice($refs, 0, $spec['refs']);
            $credits = $seconds * self::perSecond($engine);
        } else {
            if ($premium) $engine = match ($engine) { 'seedance_lite', 'wan', 'hailuo' => $avatar ? 'kling' : 'seedance_pro', default => $engine };
            $spec = self::FIRST_FRAME[$engine];
            $seconds = self::step($seconds, $spec['steps']);
            $aspect = self::aspect($item['aspect'] ?? null, ['16:9', '9:16', '1:1'], $ctx['aspect_ratio'] ?? '9:16');
            $credits = CreditService::animationCost($spec['tier'], null, $seconds);
        }
        $audio = in_array($item['audio'] ?? null, ['ambient', 'speech', 'none'], true) ? $item['audio'] : 'ambient';
        $line = $audio === 'speech' ? mb_substr(trim((string) ($item['line'] ?? '')), 0, 200) : '';
        if ($audio === 'speech' && $line === '') $audio = 'ambient';

        return array_filter([
            'engine' => $engine, 'engine_label' => self::label($engine), 'seconds' => $seconds, 'first_frame' => $first,
            'audio' => $audio, 'line' => $line ?: null, 'aspect' => $aspect, 'credits' => (int) $credits,
            'route_notes' => $notes ?: null,
            // An input the plan names but does not have: the plan is corrected before anything is bought.
            'problem' => $unresolved ? 'This shot names '.implode(', ', $unresolved).', which the plan does not have (use "avatar", "sheet" or a sheet subject\'s name).' : null,
        ], fn ($v) => $v !== null) + ['refs' => $refs];
    }

    /**
     * Route a UGC take: a presenter speaking the script to camera with native speech, in segments the engine can
     * make (Omni about 10 s, Veo 3.1 8 s), joined into one take. The presenter is the user's avatar or a sheet subject.
     */
    /** Bind a take to exact positions in the original script, never to similarity with edited words. */
    public static function approvedLines(array $asked, array $approved, ?array $original = null): array
    {
        if (! $asked) return $approved;
        $original ??= $approved;
        $text = fn (array $lines) => preg_replace('/\s+/u', ' ', trim(implode(' ', $lines)));
        if ($text($asked) === $text($original)) return $approved;
        // A take may cover a contiguous subset, including planner lines merged from several script lines.
        $matches = [];
        foreach (array_keys($original) as $start) {
            for ($length = 1; $length <= count($original) - $start; $length++) {
                if ($text(array_slice($original, $start, $length)) === $text($asked)) $matches[] = [$start, $length];
            }
        }
        abort_unless(count($matches) === 1 && count($approved) === count($original), 422,
            'The presenter script no longer maps to the approved narration. Re-plan this take before creating the video.');
        return array_slice($approved, ...$matches[0]);
    }

    public static function take(array $item, array $ctx): array
    {
        $premium = ($ctx['video_tier'] ?? 'standard') === 'premium';
        $asked = array_values(array_filter(array_map(fn ($l) => trim((string) $l), (array) ($item['lines'] ?? [])), fn ($l) => $l !== ''));
        $approved = array_values(array_filter(array_map(fn ($l) => trim((string) $l), (array) ($ctx['narration'] ?? [])), fn ($l) => $l !== ''));
        // The take speaks the approved script, as the user last edited it: its own lines only say which approved
        // lines it speaks, bound to their original positions before the user edited them.
        $lines = $approved ? self::approvedLines($asked, $approved, $ctx['original_narration'] ?? null) : $asked;
        $presenter = ($item['presenter'] ?? '') === 'avatar' && ! empty($ctx['has_avatar']) ? 'avatar'
            : (! empty($ctx['has_sheet']) ? 'sheet' : (! empty($ctx['has_avatar']) ? 'avatar' : 'none'));
        // A selected cloned voice: the approved cloned narration drives a lip-synced presenter (the existing route),
        // never a native take beside an unused cloned purchase.
        if (($ctx['voice'] ?? null) === 'clone') {
            $secs = (int) ceil(self::speechSeconds(implode(' ', $lines), (string) ($ctx['language'] ?? 'en')) + 1);
            return array_filter(['engine' => 'lipsync', 'engine_label' => 'Lip-sync to your cloned voice', 'presenter' => $presenter, 'speech_mode' => 'cloned_lipsync',
                'segments' => [['lines' => $lines, 'seconds' => $secs]], 'seconds' => $secs, 'aspect' => $ctx['aspect_ratio'] ?? '9:16',
                'credits' => CreditService::spokespersonCost((float) $secs),
                'problem' => $presenter === 'none' ? 'A lip-synced take needs a presenter image: attach your photo or add the presenter to the sheet.' : null], fn ($v) => $v !== null);
        }
        $engine = in_array($item['engine'] ?? null, self::TAKE_ENGINES, true) ? $item['engine'] : 'omni';
        if ($premium) $engine = 'veo_hq';
        // Veo 3.1 keeps a presenter from references only in 8 s landscape clips; a portrait take stays on Omni.
        if ($engine === 'veo_hq' && $presenter !== 'none' && ! self::landscape($item['aspect'] ?? $ctx['aspect_ratio'] ?? '9:16')) $engine = 'omni';
        $max = $engine === 'veo_hq' ? 8 : 10;
        $lang = (string) ($ctx['language'] ?? 'en');
        // Every approved word is spoken: a line too long for one segment is split at clauses, then words; segments are
        // packed up to the engine's length and none is ever dropped or clamped short of its speech.
        $room = $max - 0.8;
        $pieces = [];
        foreach ($lines as $l) array_push($pieces, ...self::splitSpeech($l, $room, $lang));
        $segments = []; $cur = [];
        foreach ($pieces as $piece) {
            if ($cur && self::speechSeconds(implode(' ', [...$cur, $piece]), $lang) > $room) { $segments[] = $cur; $cur = []; }
            $cur[] = $piece;
        }
        if ($cur) $segments[] = $cur;
        $segments = array_map(function ($ls) use ($engine, $max, $lang) {
            $s = min($max, max(3, self::speechSeconds(implode(' ', $ls), $lang) + 0.8));
            return ['lines' => $ls, 'seconds' => $engine === 'veo_hq' ? self::step($s, [4, 6, 8]) : (int) ceil($s)];
        }, $segments);
        $aspect = self::aspect($item['aspect'] ?? null, self::REF[$engine]['aspects'], $ctx['aspect_ratio'] ?? '9:16');
        if ($engine === 'veo_hq' && $presenter !== 'none') { $aspect = '16:9'; $segments = array_map(fn ($x) => array_merge($x, ['seconds' => 8]), $segments); }
        $credits = array_sum(array_map(fn ($s) => $s['seconds'] * self::perSecond($engine), $segments));
        return ['engine' => $engine, 'engine_label' => self::label($engine), 'presenter' => $presenter, 'segments' => $segments,
            'seconds' => array_sum(array_column($segments, 'seconds')), 'aspect' => $aspect, 'credits' => (int) $credits];
    }

    /** Replicate list prices per output second, with audio (USD); kept beside the credit tariff, never used to charge. */
    private const PROVIDER_USD_PER_SECOND = ['seedance25:720p' => 0.2312, 'seedance25:480p' => 0.1028, 'omni' => 0.15, 'veo_hq' => 0.40, 'veo' => 0.15];

    /**
     * What the provider charged us for one finished job: its reported output seconds (or the requested length) at the
     * model's list rate. An estimate from Replicate's metrics, labelled as such; failed jobs on output-priced models
     * are not billed.
     */
    public static function providerUsd(string $engine, array $metrics, float $seconds): ?float
    {
        $out = (float) ($metrics['video_output_duration_seconds'] ?? $seconds);
        if ($engine === 'seedance25') return round($out * self::PROVIDER_USD_PER_SECOND['seedance25:'.(($metrics['resolution_target'] ?? '720p') === '480p' ? '480p' : '720p')], 4);
        if (isset(self::PROVIDER_USD_PER_SECOND[$engine])) return round($out * self::PROVIDER_USD_PER_SECOND[$engine], 4);
        if ($engine === 'lipsync') return CreditService::spokespersonCogsUsd($out);
        if (isset(self::FIRST_FRAME[$engine])) return CreditService::animationCogsUsd(self::FIRST_FRAME[$engine]['tier'], null, (int) round($out));
        return null;
    }

    /** Speaking rates: words a second, or characters a second for scripts written without spaces. */
    private const WORDS_PER_SECOND = ['en' => 2.4, 'es' => 2.7, 'fr' => 2.6, 'it' => 2.7, 'pt' => 2.6, 'de' => 2.2, 'nl' => 2.4, 'ar' => 2.2, 'hi' => 2.4];
    private const CHARS_PER_SECOND = ['ja' => 7.0, 'zh' => 4.5, 'ko' => 4.5];

    /** About how long a line takes to say, in the script's language. */
    public static function speechSeconds(string $text, string $lang = 'en'): float
    {
        if (isset(self::CHARS_PER_SECOND[$lang])) return preg_match_all('/\p{L}/u', $text) / self::CHARS_PER_SECOND[$lang];
        return count(preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY)) / (self::WORDS_PER_SECOND[$lang] ?? 2.4);
    }

    /** A line split into pieces that each fit $room seconds of speech: at clauses first, then words (or characters). */
    public static function splitSpeech(string $line, float $room, string $lang = 'en'): array
    {
        $line = trim($line);
        if ($line === '' || self::speechSeconds($line, $lang) <= $room) return $line === '' ? [] : [$line];
        $cjk = isset(self::CHARS_PER_SECOND[$lang]);
        $parts = preg_split($cjk ? '/(?<=[、，。；：,;:])/u' : '/(?<=[,;:\x{2014}\x{2013}])\s+/u', $line, -1, PREG_SPLIT_NO_EMPTY);
        $out = []; $cur = '';
        $join = fn ($a, $b) => $a === '' ? $b : ($cjk ? $a.$b : $a.' '.$b);
        foreach ($parts as $part) {
            if (self::speechSeconds($part, $lang) > $room) {
                // A clause still too long: pack its words (or characters) one by one.
                if ($cur !== '') { $out[] = $cur; $cur = ''; }
                foreach ($cjk ? mb_str_split($part) : preg_split('/\s+/u', $part, -1, PREG_SPLIT_NO_EMPTY) as $w) {
                    if ($cur !== '' && self::speechSeconds($join($cur, $w), $lang) > $room) { $out[] = $cur; $cur = ''; }
                    $cur = $join($cur, $w);
                }
                continue;
            }
            if ($cur !== '' && self::speechSeconds($join($cur, $part), $lang) > $room) { $out[] = $cur; $cur = ''; }
            $cur = $join($cur, $part);
        }
        if ($cur !== '') $out[] = $cur;
        return $out;
    }

    /** The cast and world sheet: one still per subject (up to four), in the video's look. */
    /** The cast and world to draw; $looks holds the user's own description for a subject, which replaces the planned one. */
    public static function sheet(array $item, array $looks = []): array
    {
        $subjects = collect((array) ($item['subjects'] ?? []))->filter(fn ($s) => is_array($s) && trim((string) ($s['name'] ?? '')) !== '')
            ->map(fn ($s) => ['name' => mb_substr(trim((string) $s['name']), 0, 40), 'looks' => mb_substr(trim((string) ($looks[trim((string) $s['name'])] ?? $s['looks'] ?? '')), 0, 240),
                // What it is decides how it is drawn: a character neutral, a place empty, a product in clear view.
                'kind' => in_array($s['kind'] ?? null, ['character', 'place', 'product'], true) ? $s['kind'] : 'character',
                'framing' => in_array($s['framing'] ?? null, ['full', 'portrait'], true) ? $s['framing'] : 'full',
                'avatar' => (bool) ($s['avatar'] ?? false)])->unique('name')->take(4)->values()->all();
        return ['subjects' => $subjects, 'credits' => count($subjects) * CapabilityCatalogue::CHARACTER_MASTER_CREDITS];
    }
}
