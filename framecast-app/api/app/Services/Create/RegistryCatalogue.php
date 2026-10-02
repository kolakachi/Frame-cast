<?php

namespace App\Services\Create;

/**
 * The HyperFrames registry shortlist the planner chooses from: finished blocks
 * and components the sandbox ships (resources/create/registry-shortlist.json,
 * written by hyperframes-worker/scripts/vendor-registry.mjs). A beat may name
 * the items it mounts; the builder wires them by name instead of hand-building.
 */
class RegistryCatalogue
{
    /** Items every video type can use, always offered. */
    private const CORE = ['cta-lockup', 'cta-close', 'logo-sting', 'logo-brand-close', 'browser-device-stage', 'device-frame-stage', 'cursor-glyph-trail',
        'gesture-tap', 'count-up', 'chart-story', 'caption-highlight', 'caption-pill-karaoke', 'light-leak', 'halftone-field', 'grain-overlay', 'drift-hold',
        'before-after-wipe', 'kinetic-center-build', 'badge-pop', 'lottie-character-walk'];

    /** Tags that matter per kind of video; the brief's words pick the kinds. */
    private const ROUTES = [
        'product' => ['mock-ui', 'product-demo', 'ui-props', 'cursor', 'showcase', 'chat', 'notification', 'data', 'chart', 'code'],
        'ad' => ['captions', 'caption-style', 'social', 'overlay', 'cta', 'ad-template', 'vertical', 'hook'],
        'mascot' => ['character', 'mascot', 'handwritten', 'texture', 'title-card', 'kinetic-text'],
        'motion' => ['typography', 'reveal', 'transition', 'background', 'texture', 'camera', 'logo', 'title-card', 'kinetic-text'],
    ];

    private static ?array $items = null;

    /** @return array<int, array<string, mixed>> */
    public static function all(): array
    {
        if (self::$items === null) {
            $file = resource_path('create/registry-shortlist.json');
            $decoded = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
            self::$items = is_array($decoded) ? array_values(array_filter($decoded, fn ($i) => is_array($i) && isset($i['name']))) : [];
        }
        return self::$items;
    }

    public static function has(?string $name): bool
    {
        return is_string($name) && $name !== '' && collect(self::all())->contains(fn ($i) => $i['name'] === $name);
    }

    /**
     * The items worth showing the planner for this brief: the core set, then the
     * best tag matches for the kinds of video the brief and settings suggest.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function shortlist(string $brief, array $settings = [], int $limit = 70): array
    {
        $items = self::all();
        if (! $items) return [];
        $text = strtolower($brief.' '.($settings['style_pack'] ?? '').' '.($settings['output_kind'] ?? ''));
        $kinds = [];
        if (preg_match('/\b(app|saas|dashboard|demo|product|feature|website|screen|ui|tool|software|workflow)\b/', $text)) $kinds[] = 'product';
        if (preg_match('/\b(ad|ads|ugc|offer|sale|promo|tiktok|reels|shorts|hook|cta|discount|creator)\b/', $text)) $kinds[] = 'ad';
        if (preg_match('/\b(mascot|character|presenter|avatar|explainer|halftone|cartoon)\b/', $text)) $kinds[] = 'mascot';
        if (! $kinds || preg_match('/\b(motion|logo|type|typography|intro|sting|title|reveal)\b/', $text)) $kinds[] = 'motion';
        $wanted = array_unique(array_merge(...array_map(fn ($k) => self::ROUTES[$k], $kinds)));
        $vertical = ($settings['aspect_ratio'] ?? '') === '9:16';
        $scored = [];
        foreach ($items as $i) {
            $tags = (array) ($i['tags'] ?? []);
            $score = count(array_intersect($tags, $wanted)) * 3 + (in_array($i['name'], self::CORE, true) ? 10 : 0) + ($vertical && in_array('vertical', $tags, true) ? 2 : 0);
            if ($score > 0) $scored[] = ['s' => $score, 'i' => $i];
        }
        usort($scored, fn ($a, $b) => $b['s'] <=> $a['s'] ?: strcmp($a['i']['name'], $b['i']['name']));
        return array_map(fn ($r) => ['name' => $r['i']['name'], 'type' => $r['i']['type'], 'what' => $r['i']['what'] ?? '', 'tags' => array_slice((array) ($r['i']['tags'] ?? []), 0, 4),
            'duration' => $r['i']['duration'] ?? null, 'mount' => $r['i']['mount'] ?? null, 'variables' => (array) ($r['i']['variables'] ?? [])], array_slice($scored, 0, max(1, $limit)));
    }
}
