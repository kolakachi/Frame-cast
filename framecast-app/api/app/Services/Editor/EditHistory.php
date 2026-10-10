<?php

namespace App\Services\Editor;

use Illuminate\Support\Facades\DB;

/**
 * Undo and redo for the Classic editor (owner, 2026-10-10).
 *
 * Each editor request that changes a project is one step. A step remembers only what that request changed — the
 * scene columns and the keys inside a scene's settings — as they were before. Undo puts exactly those back and leaves
 * everything else as it is now: a picture or voice that finished generating since, a retake, another scene. It never
 * restores a generation's in-progress state (TRANSIENT), so a scene cannot be left "generating" with no job behind it.
 *
 * Generating (pictures, animation, voices, music) is not a step: its result arrives later, from a job. Undo covers
 * editing. Neither undo nor redo moves credits. Both wait while anything in the project is generating.
 *
 * A step: ['scenes' => [id => ['created' => true] | ['deleted' => row] | ['cols' => [col => before],
 *   'keys' => [jsonCol => [key => before | ABSENT]]]], 'project' => [field => before]]
 */
final class EditHistory
{
    /** Project settings an edit can change; status, sharing, briefs and bookkeeping are not part of the history. */
    public const PROJECT_FIELDS = ['channel_id', 'brand_kit_id', 'title', 'aspect_ratio', 'tone', 'primary_language', 'duration_target_seconds',
        'music_asset_id', 'music_settings_json', 'waveform_settings_json', 'default_visual_style', 'default_voice_settings_json',
        'default_character_id', 'custom_visual_style', 'visual_generation_mode', 'ai_broll_style', 'character_board_json'];
    private const JSON_COLS = ['image_generation_settings_json', 'voice_settings_json', 'caption_settings_json', 'motion_settings_json',
        'sound_settings_json', 'locked_fields_json'];
    /** References that may be deleted after the edit: a restore skips one that no longer exists instead of failing. */
    private const REFS = ['visual_asset_id' => 'assets', 'sound_asset_id' => 'assets', 'voice_profile_id' => 'voice_profiles',
        'character_id' => 'characters', 'channel_id' => 'channels', 'brand_kit_id' => 'brand_kits', 'music_asset_id' => 'assets',
        'default_character_id' => 'characters'];
    private const ABSENT = '__absent__';
    private const KEEP = 50;
    /** Consecutive saves of the same thing (typing in the script box) within this many seconds are one step. */
    private const MERGE_SECONDS = 20;

    /** Generation state, never recorded or restored: in-progress flags, tokens, timing, provider ids, errors. */
    public static function transient(string $key): bool
    {
        return $key === 'in_progress' || str_contains($key, 'cancel')
            || (bool) preg_match('/(in_progress|_token|token$|_started_at|_prediction_id|last_error|still_rendering|refunded|needs_visual|_queued_at)$/', $key);
    }

    /** Signs a background job worked on a scene: the generation flags, tokens, provider ids and start times. */
    private static function jobMarker(string $key): bool
    {
        return (bool) preg_match('/(^in_progress|in_progress$|_token$|^token$|_prediction_id$|_started_at$)/', $key);
    }

    /** True when a job touched this scene between the two snapshots (its markers changed): its fields are not this edit's. */
    private static function jobTouched(array $was, array $now): bool
    {
        foreach (self::JSON_COLS as $col) {
            $a = json_decode((string) ($was[$col] ?? ''), true); $b = json_decode((string) ($now[$col] ?? ''), true);
            $a = is_array($a) ? $a : []; $b = is_array($b) ? $b : [];
            foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
                if (self::jobMarker((string) $k) && ($a[$k] ?? null) !== ($b[$k] ?? null)) return true;
            }
        }
        return false;
    }

    /** @return array{project: array<string,mixed>, scenes: array<int, array<string,mixed>>} */
    public static function snapshot(int $projectId, ?array $sceneIds = null): array
    {
        $project = (array) (DB::table('projects')->where('id', $projectId)->first(self::PROJECT_FIELDS) ?? []);
        $scenes = [];
        foreach (DB::table('scenes')->where('project_id', $projectId)->when($sceneIds !== null, fn ($q) => $q->whereIn('id', $sceneIds))->get() as $row) {
            $scenes[(int) $row->id] = (array) $row;
        }
        return ['project' => $project, 'scenes' => $scenes];
    }

    /** What changed between two snapshots, field by field, as the values to put back. Null when nothing did. */
    public static function diff(array $before, array $after): ?array
    {
        $scenes = [];
        foreach ($before['scenes'] as $id => $row) {
            if (! isset($after['scenes'][$id])) { $scenes[$id] = ['deleted' => $row]; continue; }
            // A generation landing on this scene mid-request: what changed is the job's, not the edit's.
            if (self::jobTouched($row, $after['scenes'][$id])) continue;
            $change = self::rowDiff($row, $after['scenes'][$id]);
            if ($change) $scenes[$id] = $change;
        }
        foreach (array_diff_key($after['scenes'], $before['scenes']) as $id => $row) $scenes[$id] = ['created' => true];
        $project = [];
        foreach ($before['project'] as $field => $value) {
            if (self::differs($value, $after['project'][$field] ?? null)) $project[$field] = $value;
        }
        return $scenes || $project ? ['scenes' => $scenes, 'project' => $project] : null;
    }

    private static function rowDiff(array $was, array $now): ?array
    {
        $cols = $keys = [];
        foreach ($was as $col => $value) {
            if (in_array($col, ['id', 'project_id', 'created_at', 'updated_at'], true)) continue;
            if (in_array($col, self::JSON_COLS, true)) {
                $a = json_decode((string) $value, true); $b = json_decode((string) ($now[$col] ?? ''), true);
                if (is_array($a) || is_array($b)) {
                    $a = is_array($a) ? $a : []; $b = is_array($b) ? $b : [];
                    foreach (array_unique(array_merge(array_keys($a), array_keys($b))) as $k) {
                        if (self::transient((string) $k)) continue;
                        $av = array_key_exists($k, $a) ? $a[$k] : self::ABSENT; $bv = array_key_exists($k, $b) ? $b[$k] : self::ABSENT;
                        if ($av !== $bv) $keys[$col][$k] = $av;
                    }
                    continue;
                }
            }
            if (self::differs($value, $now[$col] ?? null)) $cols[$col] = $value;
        }
        if (! $cols && ! $keys) return null;
        // With the script: which voice recording went with it, so undo can tell whether that voice is still on the scene.
        $audio = array_key_exists('script_text', $cols) ? (json_decode((string) ($was['voice_settings_json'] ?? ''), true)['audio_asset_id'] ?? null) : null;
        return array_filter(['cols' => $cols, 'keys' => $keys, 'audio' => $audio], fn ($v) => $v !== null && $v !== []);
    }

    /** Strict, but a number read back as a string is the same value. */
    private static function differs(mixed $a, mixed $b): bool
    {
        if (is_scalar($a) && is_scalar($b)) return (string) $a !== (string) $b;
        return $a !== $b;
    }

    /** Record one request's change. A repeat of the latest step (same label, same person, moments later) joins it. */
    public static function record(int $projectId, array $before, string $label, string $actor, ?int $userId, ?array $sceneIds = null): ?int
    {
        $change = self::diff($before, self::snapshot($projectId, $sceneIds));
        if (! $change) return null;
        $workspaceId = (int) DB::table('projects')->where('id', $projectId)->value('workspace_id');
        return DB::transaction(function () use ($projectId, $workspaceId, $change, $label, $actor, $userId) {
            DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
            // A new edit ends the redo trail, as in any editor.
            DB::table('project_edits')->where('project_id', $projectId)->whereNotNull('undone_at')->delete();
            $last = DB::table('project_edits')->where('project_id', $projectId)->orderByDesc('id')->first();
            // Only a person's own repeated saves merge (typing); separate assistant edits stay separate steps.
            if ($last && $actor === 'user' && $last->label === $label && (int) $last->user_id === (int) $userId && $last->actor === $actor
                && now()->subSeconds(self::MERGE_SECONDS)->lte($last->created_at)) {
                DB::table('project_edits')->where('id', $last->id)->update(['before_json' => json_encode(self::merge(json_decode($last->before_json, true), $change)), 'created_at' => now()]);
                return (int) $last->id;
            }
            $id = DB::table('project_edits')->insertGetId(['project_id' => $projectId, 'workspace_id' => $workspaceId, 'actor' => $actor,
                'user_id' => $userId, 'label' => mb_substr($label, 0, 160), 'before_json' => json_encode($change), 'created_at' => now()]);
            $old = DB::table('project_edits')->where('project_id', $projectId)->orderByDesc('id')->skip(self::KEEP)->take(1000)->pluck('id');
            if ($old->isNotEmpty()) DB::table('project_edits')->whereIn('id', $old)->delete();
            DB::table('project_edits')->where('project_id', $projectId)->where('created_at', '<', now()->subDays(60))->delete();
            return $id;
        });
    }

    /** The earlier step's "before" wins for anything both touched; what only the newer one touched is added. */
    private static function merge(array $older, array $newer): array
    {
        foreach ($newer['scenes'] ?? [] as $id => $c) {
            if (! isset($older['scenes'][$id])) { $older['scenes'][$id] = $c; continue; }
            $o = &$older['scenes'][$id];
            if (isset($o['created']) || isset($o['deleted'])) continue;
            if (isset($c['created'])) { $o = $c; continue; }
            if (isset($c['deleted'])) {
                // Edited, then deleted: bring the scene back as it was before the edit, not as it was when deleted.
                $row = $c['deleted'];
                foreach ($o['cols'] ?? [] as $col => $v) $row[$col] = $v;
                foreach ($o['keys'] ?? [] as $col => $ks) {
                    $j = json_decode((string) ($row[$col] ?? ''), true); $j = is_array($j) ? $j : [];
                    foreach ($ks as $k => $v) { if ($v === self::ABSENT) unset($j[$k]); else $j[$k] = $v; }
                    $row[$col] = json_encode($j);
                }
                $o = ['deleted' => $row];
                continue;
            }
            // The voice that went with the script comes from the step that first changed the script.
            if (! isset($o['cols']['script_text']) && isset($c['audio'])) $o['audio'] = $c['audio'];
            $o['cols'] = ($o['cols'] ?? []) + ($c['cols'] ?? []);
            foreach ($c['keys'] ?? [] as $col => $ks) $o['keys'][$col] = ($o['keys'][$col] ?? []) + $ks;
            unset($o);
        }
        $older['project'] = ($older['project'] ?? []) + ($newer['project'] ?? []);
        return $older;
    }

    /** @return array{can_undo: bool, can_redo: bool, undo_id: ?int, redo_id: ?int, undo_label: ?string, redo_label: ?string, busy: bool, entries: array} */
    public static function status(int $projectId): array
    {
        $undo = self::nextUndo($projectId);
        $redo = self::nextRedo($projectId);
        $busy = self::busy($projectId);
        return [
            'can_undo' => (bool) $undo && ! $busy, 'can_redo' => (bool) $redo && ! $busy,
            'undo_id' => $undo ? (int) $undo->id : null, 'redo_id' => $redo ? (int) $redo->id : null,
            'undo_label' => $undo?->label, 'redo_label' => $redo?->label, 'busy' => $busy,
            'entries' => DB::table('project_edits')->where('project_id', $projectId)->orderByDesc('id')->limit(15)
                ->get(['id', 'label', 'actor', 'undone_at', 'created_at'])
                ->map(fn ($e) => ['id' => (int) $e->id, 'label' => $e->label, 'by' => $e->actor, 'undone' => $e->undone_at !== null,
                    'at' => \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String()])->all(),
        ];
    }

    /** Undo the latest step (or refuse if $expectId names another). Returns its label; throws \DomainException to show the user. */
    public static function undo(int $projectId, ?int $expectId = null): string
    {
        return DB::transaction(function () use ($projectId, $expectId) {
            DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
            $entry = self::nextUndo($projectId) ?? throw new \DomainException('There is nothing to undo.');
            if ($expectId && $expectId !== (int) $entry->id) throw new \DomainException('The video changed since you looked: the latest edit is now "'.$entry->label.'".');
            if (self::busy($projectId)) throw new \DomainException('Something in this video is still generating. Undo when it has finished.');
            $before = json_decode($entry->before_json, true);
            $redo = self::currentFor($projectId, $before);
            self::apply($projectId, $before);
            DB::table('project_edits')->where('id', $entry->id)->update(['undone_at' => now(), 'redo_json' => json_encode($redo)]);
            return $entry->label;
        });
    }

    public static function redo(int $projectId, ?int $expectId = null): string
    {
        return DB::transaction(function () use ($projectId, $expectId) {
            DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
            $entry = self::nextRedo($projectId) ?? throw new \DomainException('There is nothing to redo.');
            if ($expectId && $expectId !== (int) $entry->id) throw new \DomainException('The video changed since you looked: the next redo is now "'.$entry->label.'".');
            if (self::busy($projectId)) throw new \DomainException('Something in this video is still generating. Redo when it has finished.');
            self::apply($projectId, json_decode((string) $entry->redo_json, true));
            DB::table('project_edits')->where('id', $entry->id)->update(['undone_at' => null, 'redo_json' => null]);
            return $entry->label;
        });
    }

    /** Something new was generated: a redo would put back what it replaced, so there is nothing left to redo. */
    public static function endRedo(int $projectId): void
    {
        DB::table('project_edits')->where('project_id', $projectId)->whereNotNull('undone_at')->delete();
    }

    public static function forget(int $projectId): void
    {
        DB::table('project_edits')->where('project_id', $projectId)->delete();
    }

    private static function nextUndo(int $projectId): ?object
    {
        return DB::table('project_edits')->where('project_id', $projectId)->whereNull('undone_at')->orderByDesc('id')->first();
    }

    private static function nextRedo(int $projectId): ?object
    {
        return DB::table('project_edits')->where('project_id', $projectId)->whereNotNull('undone_at')->orderByDesc('undone_at')->orderBy('id')->first();
    }

    /** Anything still generating: a picture or animation (scene flags), the pipeline (project status) or AI music. */
    public static function busy(int $projectId): bool
    {
        if (DB::table('projects')->where('id', $projectId)->value('status') === 'generating') return true;
        if (\Illuminate\Support\Facades\Cache::has(\App\Jobs\GenerateAIMusicJob::inFlightKey($projectId))) return true;
        foreach (DB::table('scenes')->where('project_id', $projectId)->get(['image_generation_settings_json']) as $s) {
            $img = json_decode((string) $s->image_generation_settings_json, true) ?: [];
            if (! empty($img['in_progress']) || ! empty($img['animation_in_progress'])) return true;
        }
        return false;
    }

    /** The current values of exactly what a step touches, in the same shape, to put back on redo. */
    private static function currentFor(int $projectId, array $step): array
    {
        $now = self::snapshot($projectId, array_map('intval', array_keys($step['scenes'] ?? [])));
        $scenes = [];
        foreach ($step['scenes'] ?? [] as $id => $c) {
            $row = $now['scenes'][(int) $id] ?? null;
            if (isset($c['created'])) { $scenes[$id] = $row ? ['deleted' => $row] : null; continue; }   // undo removes it; redo restores it
            if (isset($c['deleted'])) { $scenes[$id] = ['created' => true]; continue; }               // undo restores it; redo removes it
            if (! $row) continue;
            $cols = []; foreach ($c['cols'] ?? [] as $col => $_) $cols[$col] = $row[$col] ?? null;
            $audio = array_key_exists('script_text', $cols) ? (json_decode((string) ($row['voice_settings_json'] ?? ''), true)['audio_asset_id'] ?? null) : null;
            $keys = [];
            foreach ($c['keys'] ?? [] as $col => $ks) {
                $cur = json_decode((string) ($row[$col] ?? ''), true); $cur = is_array($cur) ? $cur : [];
                foreach ($ks as $k => $_) $keys[$col][$k] = array_key_exists($k, $cur) ? $cur[$k] : self::ABSENT;
            }
            $scenes[$id] = array_filter(['cols' => $cols, 'keys' => $keys, 'audio' => $audio], fn ($v) => $v !== null && $v !== []);
        }
        $project = array_intersect_key($now['project'], $step['project'] ?? []);
        return ['scenes' => array_filter($scenes, fn ($s) => $s !== null), 'project' => $project];
    }

    /** Put a step's values back onto the rows as they are now; a reference deleted since is skipped. */
    private static function apply(int $projectId, array $step): void
    {
        foreach ($step['scenes'] ?? [] as $id => $c) {
            $id = (int) $id;
            if (isset($c['created'])) { DB::table('scenes')->where('project_id', $projectId)->where('id', $id)->delete(); continue; }
            if (isset($c['deleted'])) {
                if (DB::table('scenes')->where('id', $id)->exists()) continue;
                $row = $c['deleted'];
                $row['project_id'] = $projectId;
                foreach (self::JSON_COLS as $col) {
                    $j = json_decode((string) ($row[$col] ?? ''), true);
                    if (is_array($j)) $row[$col] = json_encode(array_filter($j, fn ($k) => ! self::transient((string) $k), ARRAY_FILTER_USE_KEY));
                }
                foreach (self::REFS as $col => $table) if (! empty($row[$col]) && ! self::exists($table, $row[$col])) $row[$col] = null;
                DB::table('scenes')->insert($row);
                continue;
            }
            $row = DB::table('scenes')->where('project_id', $projectId)->where('id', $id)->first();
            if (! $row) continue;
            $update = [];
            foreach ($c['cols'] ?? [] as $col => $value) {
                if (isset(self::REFS[$col]) && $value !== null && ! self::exists(self::REFS[$col], $value)) continue;
                $update[$col] = $value;
            }
            foreach ($c['keys'] ?? [] as $col => $ks) {
                $cur = json_decode((string) ($row->{$col} ?? ''), true); $cur = is_array($cur) ? $cur : [];
                foreach ($ks as $k => $v) { if ($v === self::ABSENT) unset($cur[$k]); else $cur[$k] = $v; }
                $update[$col] = json_encode($cur);
            }
            // The script coming back while a different voice recording is on the scene than went with it: that voice
            // does not say these words, so it is marked outdated for the user to re-record.
            if (array_key_exists('script_text', $update) && ! isset($c['keys']['voice_settings_json']['audio_asset_id'])) {
                $voice = json_decode((string) ($update['voice_settings_json'] ?? $row->voice_settings_json ?? ''), true);
                if (is_array($voice) && ! empty($voice['audio_asset_id']) && (string) $voice['audio_asset_id'] !== (string) ($c['audio'] ?? '')) {
                    $voice['is_outdated'] = true;
                    $update['voice_settings_json'] = json_encode($voice);
                }
            }
            if ($update) DB::table('scenes')->where('id', $id)->update($update + ['updated_at' => now()]);
        }
        $project = array_intersect_key($step['project'] ?? [], array_flip(self::PROJECT_FIELDS));
        foreach ($project as $f => $v) if (isset(self::REFS[$f]) && $v !== null && ! self::exists(self::REFS[$f], $v)) unset($project[$f]);
        if ($project) DB::table('projects')->where('id', $projectId)->update($project + ['updated_at' => now()]);
    }

    private static function exists(string $table, mixed $id): bool
    {
        return DB::table($table)->where('id', $id)->exists();
    }
}
