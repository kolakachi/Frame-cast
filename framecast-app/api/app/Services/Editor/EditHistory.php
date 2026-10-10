<?php

namespace App\Services\Editor;

use Illuminate\Support\Facades\DB;

/**
 * Undo and redo for the Classic editor (owner, 2026-10-10).
 *
 * Each editor request that changes a project is one entry: the scenes and project settings it changed, as they were
 * before. Capture compares whole snapshots taken before and after the request, so every write path counts (model
 * saves, bulk updates, the in-editor assistant's tools, the assistants' applied plans) without each one opting in.
 *
 * Undo puts the latest entry's "before" back, first saving the current state of the same rows as its redo. Redo puts
 * that back. A new edit clears what could be redone. Neither moves credits: generated files are kept, so going back
 * and forth is free. Both wait while anything in the project is still generating.
 */
final class EditHistory
{
    /** Project settings an edit can change; status, sharing, briefs and bookkeeping are not part of the history. */
    public const PROJECT_FIELDS = ['channel_id', 'brand_kit_id', 'title', 'aspect_ratio', 'tone', 'primary_language', 'duration_target_seconds',
        'music_asset_id', 'music_settings_json', 'waveform_settings_json', 'default_visual_style', 'default_voice_settings_json',
        'default_character_id', 'custom_visual_style', 'visual_generation_mode', 'ai_broll_style', 'character_board_json'];
    private const KEEP = 50;

    /** @return array{project: array<string,mixed>, scenes: array<int, array<string,mixed>>} */
    public static function snapshot(int $projectId): array
    {
        $project = (array) (DB::table('projects')->where('id', $projectId)->first(self::PROJECT_FIELDS) ?? []);
        $scenes = [];
        foreach (DB::table('scenes')->where('project_id', $projectId)->get() as $row) {
            $scenes[(int) $row->id] = (array) $row;
        }
        return ['project' => $project, 'scenes' => $scenes];
    }

    /**
     * What changed between two snapshots, as the "before" to put back: changed or deleted scenes as they were, scenes
     * the edit created as null (undo removes them), and the project settings that changed. Null when nothing changed.
     */
    public static function diff(array $before, array $after): ?array
    {
        $strip = fn (array $row) => array_diff_key($row, ['updated_at' => 1]);
        $scenes = [];
        foreach ($before['scenes'] as $id => $row) {
            if (! isset($after['scenes'][$id]) || $strip($after['scenes'][$id]) != $strip($row)) $scenes[$id] = $row;
        }
        foreach (array_diff_key($after['scenes'], $before['scenes']) as $id => $row) $scenes[$id] = null;
        $project = [];
        foreach ($before['project'] as $field => $value) {
            if (($after['project'][$field] ?? null) != $value) $project[$field] = $value;
        }
        return $scenes || $project ? ['scenes' => $scenes, 'project' => $project] : null;
    }

    public static function record(int $projectId, array $before, string $label, string $actor, ?int $userId): ?int
    {
        $change = self::diff($before, self::snapshot($projectId));
        if (! $change) return null;
        $workspaceId = (int) DB::table('projects')->where('id', $projectId)->value('workspace_id');
        return DB::transaction(function () use ($projectId, $workspaceId, $change, $label, $actor, $userId) {
            // A new edit ends the redo trail, as in any editor.
            DB::table('project_edits')->where('project_id', $projectId)->whereNotNull('undone_at')->delete();
            $id = DB::table('project_edits')->insertGetId(['project_id' => $projectId, 'workspace_id' => $workspaceId, 'actor' => $actor,
                'user_id' => $userId, 'label' => mb_substr($label, 0, 160), 'before_json' => json_encode($change), 'created_at' => now()]);
            $old = DB::table('project_edits')->where('project_id', $projectId)->orderByDesc('id')->skip(self::KEEP)->take(1000)->pluck('id');
            if ($old->isNotEmpty()) DB::table('project_edits')->whereIn('id', $old)->delete();
            return $id;
        });
    }

    /** @return array{can_undo: bool, can_redo: bool, undo_label: ?string, redo_label: ?string, busy: bool, entries: array} */
    public static function status(int $projectId): array
    {
        $undo = self::nextUndo($projectId);
        $redo = self::nextRedo($projectId);
        $busy = self::busy($projectId);
        return [
            'can_undo' => (bool) $undo && ! $busy, 'can_redo' => (bool) $redo && ! $busy,
            'undo_label' => $undo?->label, 'redo_label' => $redo?->label, 'busy' => $busy,
            'entries' => DB::table('project_edits')->where('project_id', $projectId)->orderByDesc('id')->limit(15)
                ->get(['id', 'label', 'actor', 'undone_at', 'created_at'])
                ->map(fn ($e) => ['id' => (int) $e->id, 'label' => $e->label, 'by' => $e->actor, 'undone' => $e->undone_at !== null,
                    'at' => \Illuminate\Support\Carbon::parse($e->created_at)->toIso8601String()])->all(),
        ];
    }

    /** Undo the latest edit. Returns its label, or throws \DomainException with a reason the user can read. */
    public static function undo(int $projectId): string
    {
        return DB::transaction(function () use ($projectId) {
            DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
            $entry = self::nextUndo($projectId) ?? throw new \DomainException('There is nothing to undo.');
            if (self::busy($projectId)) throw new \DomainException('Something in this video is still generating. Undo when it has finished.');
            $before = json_decode($entry->before_json, true);
            $redo = self::current($projectId, $before);
            self::apply($projectId, $before);
            DB::table('project_edits')->where('id', $entry->id)->update(['undone_at' => now(), 'redo_json' => json_encode($redo)]);
            return $entry->label;
        });
    }

    /** Redo the most recently undone edit. */
    public static function redo(int $projectId): string
    {
        return DB::transaction(function () use ($projectId) {
            DB::table('projects')->where('id', $projectId)->lockForUpdate()->first();
            $entry = self::nextRedo($projectId) ?? throw new \DomainException('There is nothing to redo.');
            if (self::busy($projectId)) throw new \DomainException('Something in this video is still generating. Redo when it has finished.');
            self::apply($projectId, json_decode((string) $entry->redo_json, true));
            DB::table('project_edits')->where('id', $entry->id)->update(['undone_at' => null, 'redo_json' => null]);
            return $entry->label;
        });
    }

    private static function nextUndo(int $projectId): ?object
    {
        return DB::table('project_edits')->where('project_id', $projectId)->whereNull('undone_at')->orderByDesc('id')->first();
    }

    private static function nextRedo(int $projectId): ?object
    {
        return DB::table('project_edits')->where('project_id', $projectId)->whereNotNull('undone_at')->orderByDesc('undone_at')->orderBy('id')->first();
    }

    /** A scene still generating (picture, animation or voice): undo would race the job that is writing it. */
    private static function busy(int $projectId): bool
    {
        foreach (DB::table('scenes')->where('project_id', $projectId)->get(['image_generation_settings_json', 'voice_settings_json']) as $s) {
            $img = json_decode((string) $s->image_generation_settings_json, true) ?: [];
            $voice = json_decode((string) $s->voice_settings_json, true) ?: [];
            if (! empty($img['in_progress']) || ! empty($img['animation_in_progress']) || ! empty($voice['in_progress'])) return true;
        }
        return false;
    }

    /** The current state of exactly the rows a change touches, in the same shape, to put back on redo. */
    private static function current(int $projectId, array $change): array
    {
        $now = self::snapshot($projectId);
        $scenes = [];
        foreach (array_keys($change['scenes'] ?? []) as $id) $scenes[$id] = $now['scenes'][$id] ?? null;
        $project = array_intersect_key($now['project'], $change['project'] ?? []);
        return ['scenes' => $scenes, 'project' => $project];
    }

    /** Put rows back as recorded: update or re-insert a scene, remove one recorded as absent, restore settings. */
    private static function apply(int $projectId, array $change): void
    {
        foreach ($change['scenes'] ?? [] as $id => $row) {
            $id = (int) $id;
            if ($row === null) { DB::table('scenes')->where('project_id', $projectId)->where('id', $id)->delete(); continue; }
            $row['project_id'] = $projectId;
            if (DB::table('scenes')->where('id', $id)->exists()) DB::table('scenes')->where('id', $id)->update(array_diff_key($row, ['id' => 1]));
            else DB::table('scenes')->insert($row);
        }
        if (! empty($change['project'])) {
            DB::table('projects')->where('id', $projectId)->update(array_intersect_key($change['project'], array_flip(self::PROJECT_FIELDS)) + ['updated_at' => now()]);
        }
    }
}
