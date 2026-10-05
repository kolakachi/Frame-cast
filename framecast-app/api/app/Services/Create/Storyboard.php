<?php
namespace App\Services\Create;

use Illuminate\Support\Facades\DB;

/**
 * Storyboard bookkeeping. A panel is identified by what it shows and what it is drawn from: its shot direction and
 * note, the cast's exact images, and the look. An unchanged panel is reused across plans and redraws; a cast change
 * changes every panel's identity, so they are all redrawn from the new cast.
 */
class Storyboard
{
    /** The cast's identity: its images' hashes, in order. */
    public static function castSha(array $files): string
    {
        return hash('sha256', implode('|', array_map(fn ($f) => (string) ($f['sha256'] ?? ''), $files)));
    }

    public static function panelHash(array $panel, string $castSha, string $style, string $aspect): string
    {
        return hash('sha256', json_encode([array_intersect_key($panel, array_flip(['description', 'action', 'gaze', 'camera', 'aspect', 'refs', 'note'])), $castSha, $style, $aspect]));
    }

    /** The cast this plan drew (its sheet's files, each with its subject's name), or null before it exists. */
    public static function cast(string $planId): ?array
    {
        $row = DB::table('create_plan_media')->where('plan_id', $planId)->where('kind', 'reference_sheet')->where('status', 'succeeded')->orderByDesc('updated_at')->first();
        if (! $row) return null;
        $r = json_decode((string) $row->record_json, true) ?: [];
        $files = array_values(array_filter([$r['file'] ?? null, ...($r['more_files'] ?? [])]));
        return $files ? array_map(fn ($f, $k) => $f + ['subject' => $r['poses'][$k] ?? null], $files, array_keys($files)) : null;
    }

    /** Panels already drawn in this creation, by panel hash: [hash => file]. */
    public static function prior(string $conversationId): array
    {
        $out = [];
        foreach (DB::table('create_plan_media')->where('conversation_id', $conversationId)->where('kind', 'storyboard')->where('status', 'succeeded')->orderBy('updated_at')->get() as $row) {
            $r = json_decode((string) $row->record_json, true) ?: [];
            $files = array_values(array_filter([$r['file'] ?? null, ...($r['more_files'] ?? [])]));
            foreach ((array) ($r['panel_hashes'] ?? []) as $k => $h) if (isset($files[$k])) $out[$h] = $files[$k];
        }
        return $out;
    }

    /** Panels still to draw for this storyboard item, given the cast it will be drawn from. */
    public static function toDraw(array $item, ?array $cast, string $conversationId, string $style, string $aspect): int
    {
        if (! $cast) return count($item['panels'] ?? []);
        $prior = self::prior($conversationId); $sha = self::castSha($cast);
        return count(array_filter($item['panels'] ?? [], fn ($p) => ! isset($prior[self::panelHash($p, $sha, $style, $p['aspect'] ?? $aspect)])));
    }
}
