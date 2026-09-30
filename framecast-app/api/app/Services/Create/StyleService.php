<?php
namespace App\Services\Create;

use App\Models\{Asset, User};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Saved workspace styles: the look of a finished version or a studied
 * reference, kept only when the user asks. A conversation can pick one; the
 * planner and build agent then treat it as the house style.
 */
class StyleService
{
    public const FIELDS = ['summary' => 240, 'look' => 200, 'type' => 160, 'motion' => 200, 'structure' => 240];

    public function list(User $user): array
    {
        return DB::table('create_styles')->where('workspace_id', $user->workspace_id)->orderByDesc('updated_at')->limit(50)->get()->map(fn ($s) => $this->present($s))->all();
    }

    public function fromRevision(User $user, string $conversationId, string $revisionId, string $name): array
    {
        app(ConversationService::class)->authorize($user, true);
        $c = app(ConversationService::class)->conversation($user, $conversationId);
        $rev = DB::table('composition_revisions')->where('conversation_id', $c->id)->where('id', $revisionId)->firstOrFail();
        $html = (string) (json_decode($rev->bundle_json, true)['index.html'] ?? '');
        $palette = [];
        foreach (CompositionVariables::declarations($html) as $d) if (($d['type'] ?? '') === 'color' && preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) ($d['default'] ?? ''))) $palette[] = $d['default'];
        preg_match_all('/font-family\s*:\s*([^;}]+)/i', $html, $f);
        $fonts = array_values(array_unique(array_filter(array_map(fn ($x) => trim(explode(',', html_entity_decode($x))[0], " '\"\\"), $f[1] ?? []))));
        $plan = DB::table('create_plans')->where('conversation_id', $c->id)->orderByDesc('created_at')->first();
        $p = $plan ? json_decode($plan->plan_json, true) : [];
        $scenes = $p['scenes'] ?? [];
        $style = ['summary' => (string) ($p['summary'] ?? $rev->summary ?? ''), 'palette' => array_slice(array_values(array_unique($palette)), 0, 5),
            'type' => $fonts ? implode(', ', array_slice($fonts, 0, 3)) : '',
            'structure' => $scenes ? implode(' → ', array_map(fn ($s) => $s['label'] ?? '', array_slice($scenes, 0, 6))) : '',
            'average_shot_seconds' => $scenes ? round(array_sum(array_map(fn ($s) => max(0, ($s['end'] ?? 0) - ($s['start'] ?? 0)), $scenes)) / count($scenes), 1) : null];
        return $this->store($user, $name, 'version', $revisionId, $style);
    }

    public function fromReference(User $user, int $assetId, string $name): array
    {
        app(ConversationService::class)->authorize($user, true);
        $asset = Asset::where('workspace_id', $user->workspace_id)->findOrFail($assetId);
        $a = data_get($asset->metadata_json, 'reference_analysis');
        abort_unless(is_array($a) && is_array($a['notes'] ?? null), 422, 'This reference has not been studied, so there is no style to save.');
        return $this->store($user, $name, 'reference', (string) $asset->id, [...$a['notes'], 'average_shot_seconds' => $a['average_shot_seconds'] ?? null]);
    }

    public function update(User $user, string $id, array $changes): array
    {
        app(ConversationService::class)->authorize($user, true);
        return DB::transaction(function () use ($user, $id, $changes) {
            $s = DB::table('create_styles')->where('workspace_id', $user->workspace_id)->where('id', $id)->lockForUpdate()->firstOrFail();
            $style = json_decode($s->style_json, true);
            if (isset($changes['style'])) $style = $this->clean([...$style, ...$changes['style']]);
            DB::table('create_styles')->where('id', $id)->update(['name' => isset($changes['name']) ? Str::limit(trim($changes['name']), 80, '') : $s->name,
                'style_json' => json_encode($style), 'version' => $s->version + 1, 'updated_at' => now()]);
            return $this->present(DB::table('create_styles')->where('id', $id)->first());
        });
    }

    public function delete(User $user, string $id): void
    {
        app(ConversationService::class)->authorize($user, true);
        abort_unless(DB::table('create_styles')->where('workspace_id', $user->workspace_id)->where('id', $id)->delete(), 404);
    }

    /** The style as the planner and agent see it, frozen into a quote. */
    public static function brief(?string $id, int $workspaceId): ?array
    {
        if (! $id) return null;
        $s = DB::table('create_styles')->where('workspace_id', $workspaceId)->where('id', $id)->first();
        return $s ? ['name' => $s->name, 'version' => (int) $s->version, ...json_decode($s->style_json, true)] : null;
    }

    private function store(User $user, string $name, string $source, string $ref, array $style): array
    {
        abort_if(DB::table('create_styles')->where('workspace_id', $user->workspace_id)->count() >= 50, 422, 'Use at most 50 saved styles. Delete one first.');
        $id = (string) Str::uuid();
        DB::table('create_styles')->insert(['id' => $id, 'workspace_id' => $user->workspace_id, 'created_by_user_id' => $user->id,
            'name' => Str::limit(trim($name) ?: 'Untitled style', 80, ''), 'source' => $source, 'source_ref' => $ref, 'style_json' => json_encode($this->clean($style)),
            'version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return $this->present(DB::table('create_styles')->where('id', $id)->first());
    }

    private function clean(array $s): array
    {
        $out = [];
        foreach (self::FIELDS as $k => $n) $out[$k] = mb_substr(trim((string) ($s[$k] ?? '')), 0, $n);
        $out['palette'] = array_values(array_slice(array_filter((array) ($s['palette'] ?? []), fn ($c) => is_string($c) && preg_match('/^#[0-9a-fA-F]{3,8}$/', $c)), 0, 5));
        $out['borrow'] = array_values(array_slice(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 140), array_filter((array) ($s['borrow'] ?? []))), 0, 4));
        $out['avoid_copying'] = array_values(array_slice(array_map(fn ($x) => mb_substr(trim((string) $x), 0, 140), array_filter((array) ($s['avoid_copying'] ?? []))), 0, 6));
        $out['average_shot_seconds'] = is_numeric($s['average_shot_seconds'] ?? null) ? round((float) $s['average_shot_seconds'], 1) : null;
        return $out;
    }

    private function present(object $s): array
    {
        return ['id' => $s->id, 'name' => $s->name, 'source' => $s->source, 'version' => (int) $s->version, 'style' => json_decode($s->style_json, true), 'updated_at' => $s->updated_at];
    }
}
