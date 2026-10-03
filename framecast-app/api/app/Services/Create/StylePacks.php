<?php
namespace App\Services\Create;

use App\Models\Asset;
use Illuminate\Support\Facades\DB;

/**
 * Style packs: the craft a build starts from. Every build takes one route:
 * a built-in pack, a saved workspace style, a studied reference (a one-off
 * pack for that build), or free design. The pack is frozen into the run, so a
 * later edit to a pack or style never changes a build in flight.
 */
class StylePacks
{
    public const ROUTES = ['pack', 'saved', 'reference', 'free'];

    public static function dir(): string
    {
        return resource_path('create/styles');
    }

    /** What the planner and the style picker see. */
    public static function catalogue(): array
    {
        $out = [];
        foreach (glob(self::dir().'/*/style.json') ?: [] as $file) {
            $m = json_decode((string) file_get_contents($file), true);
            if (! is_array($m) || ! preg_match('/^[a-z0-9-]{2,40}$/', (string) ($m['slug'] ?? '')) || basename(dirname($file)) !== $m['slug']) continue;
            $out[] = ['slug' => $m['slug'], 'name' => (string) ($m['name'] ?? $m['slug']), 'when' => (string) ($m['when'] ?? ''),
                'video_types' => array_values((array) ($m['video_types'] ?? [])), 'look' => (string) ($m['look'] ?? ''), 'pace' => (string) ($m['pace'] ?? ''),
                'has_example' => is_file(dirname($file).'/example/index.html')];
        }
        usort($out, fn ($a, $b) => strcmp($a['name'], $b['name']));
        return $out;
    }

    public static function exists(?string $slug): bool
    {
        return is_string($slug) && in_array($slug, array_column(self::catalogue(), 'slug'), true);
    }

    /** The route as the plan card shows it. */
    public static function route(array $raw, array $ctx): array
    {
        $studied = collect($ctx['files'] ?? [])->contains(fn ($f) => ($f['purpose'] ?? '') === 'reference' && ! empty($f['reference']));
        $pinned = $ctx['settings']['style_pack'] ?? null;
        $route = in_array($raw['route'] ?? null, self::ROUTES, true) ? $raw['route'] : 'free';
        $pack = $raw['pack'] ?? null;
        // The user's pick in the composer wins over the planner's.
        if (self::exists($pinned)) { $route = 'pack'; $pack = $pinned; }
        // A house style is a default, not an override of a deliberate planner route.
        elseif (! in_array($raw['route'] ?? null, self::ROUTES, true) && ! empty($ctx['house_style'])) $route = 'saved';
        if ($route === 'pack' && ! self::exists($pack)) $route = 'free';
        if ($route === 'saved' && empty($ctx['house_style'])) $route = 'free';
        if ($route === 'reference' && ! $studied) $route = 'free';
        $name = match ($route) {
            'pack' => collect(self::catalogue())->firstWhere('slug', $pack)['name'],
            'saved' => (string) ($ctx['house_style']['name'] ?? 'Saved style'),
            'reference' => 'From your reference',
            default => 'Free design',
        };
        return ['route' => $route, 'pack' => $route === 'pack' ? $pack : null, 'name' => $name,
            'why' => mb_substr(trim(is_string($raw['why'] ?? null) ? $raw['why'] : ''), 0, 140)];
    }

    /** The pack a build follows, frozen into the run. Null for free design. */
    public static function resolve(?array $route, int $workspaceId, array $settings, array $referenceAssetIds = []): ?array
    {
        $r = $route['route'] ?? 'free';
        if ($r === 'pack' && self::exists($route['pack'] ?? null)) {
            $dir = self::dir().'/'.$route['pack'];
            $example = [];
            foreach (['index.html', 'style.css', 'main.js'] as $f) if (is_file("$dir/example/$f")) $example[$f] = (string) file_get_contents("$dir/example/$f");
            $rules = (string) file_get_contents("$dir/STYLE.md");
            $fingerprint = is_file("$dir/DEMO.md") ? (string) file_get_contents("$dir/DEMO.md") : '';
            return ['route' => 'pack', 'slug' => $route['pack'], 'name' => $route['name'] ?? $route['pack'], 'rules' => $rules, 'fingerprint' => $fingerprint,
                'example' => $example ?: null, 'version' => substr(hash('sha256', $rules.$fingerprint.json_encode($example)), 0, 16)];
        }
        if ($r === 'saved' && ($id = $settings['style_id'] ?? null)) {
            $s = DB::table('create_styles')->where('workspace_id', $workspaceId)->where('id', $id)->first();
            if (! $s) return null;
            $style = json_decode($s->style_json, true) ?: [];
            $example = null;
            if ($s->source === 'version') {
                $bundle = json_decode((string) DB::table('composition_revisions')->where('id', $s->source_ref)->value('bundle_json'), true);
                $example = is_array($bundle) ? array_intersect_key($bundle, array_flip(['index.html', 'style.css', 'main.js'])) : null;
            }
            return ['route' => 'saved', 'name' => $s->name, 'rules' => self::rulesFromNotes($s->name, $style), 'fingerprint' => self::fingerprintFromNotes($style),
                'example' => $example ?: null, 'version' => 'style-'.$s->id.'-v'.$s->version];
        }
        if ($r === 'reference') {
            foreach ($referenceAssetIds as $assetId) {
                $a = data_get(Asset::where('workspace_id', $workspaceId)->find($assetId)?->metadata_json, 'reference_analysis');
                if (! is_array($a['notes'] ?? null)) continue;
                $notes = [...$a['notes'], 'average_shot_seconds' => $a['average_shot_seconds'] ?? null];
                return ['route' => 'reference', 'name' => 'From your reference', 'rules' => self::rulesFromNotes('your reference', $notes),
                    'fingerprint' => self::fingerprintFromNotes($notes), 'example' => null, 'version' => 'reference-'.$assetId];
            }
        }
        return null;
    }

    /** A one-off pack written from studied notes (a reference or a saved style). */
    private static function rulesFromNotes(string $name, array $n): string
    {
        $line = fn ($label, $v) => trim(is_array($v) ? implode('; ', array_filter($v, 'is_string')) : (string) $v) !== '' ? "- $label: ".(is_array($v) ? implode('; ', array_filter($v, 'is_string')) : $v)."\n" : '';
        return "# Style: $name\n\nFollow this look and craft. Take the pacing, type, palette logic and motion ideas; never copy characters, logos, footage, on-screen text or speech.\n\n"
            .$line('Summary', $n['summary'] ?? '').$line('Look', $n['look'] ?? '').$line('Type', $n['type'] ?? '').$line('Motion', $n['motion'] ?? '')
            .$line('Structure', $n['structure'] ?? $n['layout'] ?? '').$line('Palette', $n['palette'] ?? []).$line('Borrow', $n['borrow'] ?? [])
            .$line('Do not copy', $n['avoid_copying'] ?? [])
            .$line('Moves worth building (see the motion kit)', $n['recipes'] ?? [])
            .(is_numeric($n['average_shot_seconds'] ?? null) ? '- Average shot: about '.round((float) $n['average_shot_seconds'], 1)." s\n" : '');
    }

    private static function fingerprintFromNotes(array $n): string
    {
        $f = is_array($n['fingerprint'] ?? null) ? array_filter($n['fingerprint'], fn ($v) => is_string($v) && trim($v) !== '') : [];
        if ($f) return "# The source, on six points (differ from it on at least four)\n\n".implode("\n", array_map(fn ($k, $v) => '- '.ucfirst(str_replace('_', ' ', $k)).': '.$v, array_keys($f), $f))."\n";
        $s = trim((string) ($n['structure'] ?? $n['layout'] ?? ''));
        return $s !== '' ? "Structure of the source: $s" : '';
    }
}
