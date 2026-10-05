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
    public const KINDS = ['reference_sheet', 'generated_shot', 'ugc_take'];

    /** Fields a routed item carries from the plan to the build. */
    public const ROUTE_KEYS = ['engine', 'engine_label', 'seconds', 'refs', 'first_frame', 'audio', 'line', 'aspect', 'segments', 'presenter', 'subjects', 'beat', 'why', 'route_notes', 'lines'];

    /** What the planner may say about a generated item; everything else is derived. */
    public static function plannerFields(array $m): array
    {
        $s = fn ($v, $n) => mb_substr(trim(is_string($v) ? $v : ''), 0, $n);
        return array_filter([
            'engine' => in_array($m['engine'] ?? null, self::ENGINES, true) ? $m['engine'] : null,
            'seconds' => is_numeric($m['seconds'] ?? null) ? (float) $m['seconds'] : null,
            'refs' => array_values(array_intersect((array) ($m['refs'] ?? []), ['sheet', 'avatar'])) ?: null,
            'first_frame' => $s($m['first_frame'] ?? '', 40) ?: null,
            'audio' => in_array($m['audio'] ?? null, ['ambient', 'speech', 'none'], true) ? $m['audio'] : null,
            'line' => $s($m['line'] ?? '', 200) ?: null,
            'aspect' => preg_match('/^\d{1,2}:\d{1,2}$/', (string) ($m['aspect'] ?? '')) ? $m['aspect'] : null,
            'beat' => $s($m['beat'] ?? '', 40) ?: null,
            'why' => $s($m['why'] ?? '', 160) ?: null,
            'presenter' => in_array($m['presenter'] ?? null, ['avatar', 'sheet'], true) ? $m['presenter'] : null,
            'lines' => array_values(array_filter(array_map(fn ($l) => $s($l, 160), (array) ($m['lines'] ?? [])))) ?: null,
            'subjects' => is_array($m['subjects'] ?? null) ? self::sheet($m)['subjects'] : null,
        ], fn ($v) => $v !== null);
    }

    /** Whether an item is made from the cast/world sheet, so needs it approved first. */
    public static function usesSheet(array $m): bool
    {
        return match ($m['kind'] ?? '') {
            'generated_shot' => in_array('sheet', (array) ($m['refs'] ?? []), true) || (($m['first_frame'] ?? null) && $m['first_frame'] !== 'avatar'),
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
    public static function shot(array $item, array $ctx): array
    {
        $premium = ($ctx['video_tier'] ?? 'standard') === 'premium';
        $hasAvatar = (bool) ($ctx['has_avatar'] ?? false);
        $hasSheet = (bool) ($ctx['has_sheet'] ?? false);
        $refs = array_values(array_intersect(array_unique((array) ($item['refs'] ?? [])), [...($hasSheet ? ['sheet'] : []), ...($hasAvatar ? ['avatar'] : [])]));
        $first = is_string($item['first_frame'] ?? null) && trim($item['first_frame']) !== '' ? trim($item['first_frame']) : null;
        if ($first === 'avatar' && ! $hasAvatar) $first = null;
        if ($first && $first !== 'avatar' && ! $hasSheet) $first = null;
        $engine = in_array($item['engine'] ?? null, self::ENGINES, true) ? $item['engine'] : null;
        $avatar = in_array('avatar', $refs, true) || $first === 'avatar';
        $seconds = max(2.0, min(30.0, (float) ($item['seconds'] ?? 5)));
        $notes = [];

        if ($first) {
            // Exact framing from a still: a first-frame engine. Never Seedance with the user's face.
            if (! isset(self::FIRST_FRAME[$engine])) $engine = $avatar ? 'kling' : 'seedance_lite';
            if ($avatar && ! empty(self::FIRST_FRAME[$engine]['seedance'])) { $engine = 'kling'; $notes[] = 'Your avatar is in this shot, so it uses Kling instead of Seedance.'; }
            if ($premium) $engine = match ($engine) { 'seedance_lite', 'wan', 'hailuo' => $avatar ? 'kling' : 'seedance_pro', default => $engine };
            $refs = [];
        } else {
            // Reference images (or none, text alone): an engine that takes references.
            if (! isset(self::REF[$engine])) $engine = $avatar ? 'omni' : 'seedance25';
            if ($avatar && $engine === 'seedance25') { $engine = 'omni'; $notes[] = 'Your avatar is in this shot, so it uses Omni instead of Seedance.'; }
            // Veo 3.1 takes references only for an 8 s landscape clip; a portrait slot would lose most of it to the crop.
            $veoFits = fn () => $seconds <= 8.01 && ($refs === [] || self::landscape($item['aspect'] ?? $ctx['aspect_ratio'] ?? '9:16'));
            if ($premium && $engine !== 'veo_hq' && $veoFits()) $engine = 'veo_hq';
            if ($engine === 'veo_hq' && ! $veoFits()) { $engine = $avatar ? 'omni' : 'seedance25'; }
            if ($engine === 'omni' && $seconds > 10.01) $engine = $avatar ? 'omni' : 'seedance25';
        }

        if (isset(self::REF[$engine])) {
            $spec = self::REF[$engine];
            $seconds = isset($spec['steps']) ? self::step($seconds, $spec['steps']) : (int) round(max($spec['min'], min($spec['max'], $seconds)));
            $aspect = self::aspect($item['aspect'] ?? null, $spec['aspects'], $ctx['aspect_ratio'] ?? '9:16');
            if ($engine === 'veo_hq' && $refs !== []) { $seconds = 8; $aspect = '16:9'; }
            $refs = array_slice($refs, 0, $spec['refs']);
            $credits = $seconds * self::perSecond($engine);
        } else {
            $spec = self::FIRST_FRAME[$engine];
            $seconds = self::step($seconds, $spec['steps']);
            $aspect = self::aspect($item['aspect'] ?? null, ['16:9', '9:16', '1:1'], $ctx['aspect_ratio'] ?? '9:16');
            $credits = CreditService::animationCost($spec['tier'], null, $seconds);
        }
        $audio = in_array($item['audio'] ?? null, ['ambient', 'speech', 'none'], true) ? $item['audio'] : 'ambient';
        $line = $audio === 'speech' ? mb_substr(trim((string) ($item['line'] ?? '')), 0, 200) : '';
        if ($audio === 'speech' && $line === '') $audio = 'ambient';

        return array_filter([
            'engine' => $engine, 'engine_label' => self::label($engine), 'seconds' => $seconds, 'refs' => $refs, 'first_frame' => $first,
            'audio' => $audio, 'line' => $line ?: null, 'aspect' => $aspect, 'credits' => (int) $credits,
            'route_notes' => $notes ?: null,
        ], fn ($v) => $v !== null) + ['refs' => $refs];
    }

    /**
     * Route a UGC take: a presenter speaking the script to camera with native speech, in segments the engine can
     * make (Omni about 10 s, Veo 3.1 8 s), joined into one take. The presenter is the user's avatar or a sheet subject.
     */
    public static function take(array $item, array $ctx): array
    {
        $premium = ($ctx['video_tier'] ?? 'standard') === 'premium';
        $lines = array_values(array_filter(array_map(fn ($l) => trim((string) $l), (array) ($item['lines'] ?? [])), fn ($l) => $l !== ''));
        if (! $lines) $lines = array_values(array_filter(array_map('strval', (array) ($ctx['narration'] ?? []))));
        $presenter = ($item['presenter'] ?? '') === 'avatar' && ! empty($ctx['has_avatar']) ? 'avatar'
            : (! empty($ctx['has_sheet']) ? 'sheet' : (! empty($ctx['has_avatar']) ? 'avatar' : 'none'));
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
    public static function sheet(array $item): array
    {
        $subjects = collect((array) ($item['subjects'] ?? []))->filter(fn ($s) => is_array($s) && trim((string) ($s['name'] ?? '')) !== '')
            ->map(fn ($s) => ['name' => mb_substr(trim((string) $s['name']), 0, 40), 'looks' => mb_substr(trim((string) ($s['looks'] ?? '')), 0, 240),
                'avatar' => (bool) ($s['avatar'] ?? false)])->unique('name')->take(4)->values()->all();
        return ['subjects' => $subjects, 'credits' => count($subjects) * CapabilityCatalogue::CHARACTER_MASTER_CREDITS];
    }
}
