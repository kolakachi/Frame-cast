<?php
namespace App\Services\Create;

/**
 * HyperFrames composition variables: declared on <html> as
 * data-composition-variables='[{"id","type","label","default"}, ...]'. They are
 * the free edit surface: changing a value is a re-render, never a model call.
 */
class CompositionVariables
{
    private const PATTERN = '/(<html\b[^>]*?\sdata-composition-variables=)(\'([^\']*)\'|"([^"]*)")/is';

    /** @return array<int, array{id:string,type:string,label:string,default:mixed,options?:array}> */
    public static function declarations(?string $html): array
    {
        if (! $html || ! preg_match(self::PATTERN, $html, $m)) return [];
        $json = html_entity_decode(($m[3] ?? '') !== '' ? $m[3] : ($m[4] ?? ''), ENT_QUOTES | ENT_HTML5);
        $decoded = json_decode($json, true);
        if (! is_array($decoded)) return [];
        return collect($decoded)->filter(fn ($d) => is_array($d) && preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,39}$/', (string) ($d['id'] ?? ''))
            && in_array($d['type'] ?? '', ['string', 'color', 'number', 'enum'], true))
            ->map(fn ($d) => array_filter(['id' => $d['id'], 'type' => $d['type'], 'label' => mb_substr((string) ($d['label'] ?? $d['id']), 0, 60),
                'default' => $d['default'] ?? null, 'options' => isset($d['options']) && is_array($d['options']) ? array_values($d['options']) : null,
                'min' => $d['min'] ?? null, 'max' => $d['max'] ?? null], fn ($v) => $v !== null))
            ->unique('id')->take(20)->values()->all();
    }

    /** Validate new values against the declarations. Returns only real changes. */
    public static function validate(array $declarations, array $values): array
    {
        $byId = collect($declarations)->keyBy('id'); $changes = [];
        foreach ($values as $id => $value) {
            $d = $byId[$id] ?? null;
            abort_unless($d, 422, 'That field cannot be edited here.');
            $clean = match ($d['type']) {
                'string' => mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '', (string) $value), 0, 200),
                'color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) $value) ? strtolower((string) $value) : abort(422, 'Colours must be a hex value like #ff6b35.'),
                'number' => is_numeric($value) && (! isset($d['min']) || $value >= $d['min']) && (! isset($d['max']) || $value <= $d['max']) ? $value + 0 : abort(422, 'That number is out of range.'),
                'enum' => in_array($value, $d['options'] ?? [], true) ? $value : abort(422, 'Choose one of the listed values.'),
            };
            if ($clean !== $d['default']) $changes[$id] = $clean;
        }
        return $changes;
    }

    /** The same HTML with new defaults baked into the declarations. */
    public static function apply(string $html, array $declarations, array $changes): string
    {
        $next = array_map(function ($d) use ($changes) {
            if (array_key_exists($d['id'], $changes)) $d['default'] = $changes[$d['id']];
            return $d;
        }, $declarations);
        $json = htmlspecialchars(json_encode($next, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), ENT_QUOTES | ENT_HTML5);
        return preg_replace_callback(self::PATTERN, fn ($m) => $m[1]."'".$json."'", $html, 1);
    }
}
