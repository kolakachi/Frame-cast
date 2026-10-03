<?php
namespace App\Services\Create;

/** A bounded, reviewable colour proposal; never arbitrary CSS from a model. */
class ColourTreatment
{
    public static function normalize(mixed $value, ?array $previous = null): ?array
    {
        if (! is_array($value)) return $previous;
        $roles = [];
        foreach (['background', 'text', 'accent', 'secondary'] as $role) {
            $item = $value['roles'][$role] ?? null;
            if (! is_array($item) || ! is_string($item['hex'] ?? null) || ! preg_match('/^#(?:[a-f0-9]{3}|[a-f0-9]{6})$/i', $item['hex'])) continue;
            $hex = strtoupper($item['hex']);
            if (strlen($hex) === 4) $hex = '#'.$hex[1].$hex[1].$hex[2].$hex[2].$hex[3].$hex[3];
            $roles[$role] = ['hex' => $hex, 'locked' => ($item['locked'] ?? false) === true];
        }
        // A replanning call cannot erase a previously approved role or change its lock.
        foreach ($previous['roles'] ?? [] as $role => $item) {
            if (($item['locked'] ?? false) || ! isset($roles[$role])) $roles[$role] = $item;
        }
        if (! $roles) return $previous;
        $source = in_array($value['source'] ?? null, ['user', 'brand_kit', 'saved_style', 'reference', 'agent', 'existing'], true) ? $value['source'] : 'agent';
        $text = fn ($key) => is_string($value[$key] ?? null) ? mb_substr(trim($value[$key]), 0, 240) : '';
        return ['source' => $source, 'source_note' => $text('source_note'), 'roles' => $roles, 'usage' => $text('usage')];
    }
}
