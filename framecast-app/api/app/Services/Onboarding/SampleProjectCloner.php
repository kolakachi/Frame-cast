<?php

namespace App\Services\Onboarding;

use App\Models\Asset;
use App\Models\Character;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use Illuminate\Support\Facades\DB;

/**
 * Deep-clones a finished sample project into a target workspace for onboarding
 * (B7). The new owner lands on a complete, playable, *remix-able* starter:
 *
 *  - Assets are SHALLOW-copied — new Asset rows in the target workspace that
 *    reference the SAME object-storage URL. No file duplication, no credit
 *    charge; the source's B2/MinIO objects are read-only sample media.
 *  - Characters used by the project are cloned (with their reference photos)
 *    so a spokesperson scene can actually be re-rendered, not just watched.
 *  - The completed export is cloned so the dashboard shows a finished video
 *    immediately ("wow"), without re-running generation.
 *  - Asset ids embedded inside *_settings_json (e.g. animation_original_image_
 *    asset_id, audio_asset_id) are remapped too, not just the columns.
 *
 * Source-agnostic: works for any finished project, driven by
 * config('onboarding.sample_project_id'). Never charges credits.
 */
class SampleProjectCloner
{
    public function clone(int $sourceProjectId, int $targetWorkspaceId, int $targetUserId): ?Project
    {
        $source = Project::query()->find($sourceProjectId);
        if (! $source) {
            return null;
        }

        $scenes = Scene::query()->where('project_id', $sourceProjectId)->orderBy('scene_order')->get();

        return DB::transaction(function () use ($source, $scenes, $targetWorkspaceId, $targetUserId): Project {
            // ── 1. Characters used anywhere in the project ───────────────────
            $charIds = $this->characterIds($source, $scenes);
            $characters = Character::query()->whereIn('id', $charIds)->get();

            // ── 2. Every asset referenced by project / scenes / characters /
            //       the completed export — collected, then shallow-cloned ─────
            $export = ExportJob::query()
                ->where('project_id', $source->getKey())
                ->where('status', 'completed')
                ->whereNotNull('output_asset_id')
                ->orderByDesc('id')
                ->first();

            $assetIds = $this->assetIds($source, $scenes, $characters, $export);
            $assetMap = [];
            foreach (Asset::query()->whereIn('id', $assetIds)->get() as $asset) {
                $assetMap[$asset->getKey()] = $this->cloneAsset($asset, $targetWorkspaceId, $targetUserId);
            }

            // ── 3. Clone characters (remap their reference photos) ───────────
            $charMap = [];
            foreach ($characters as $char) {
                $charMap[$char->getKey()] = $this->cloneCharacter($char, $targetWorkspaceId, $targetUserId, $assetMap);
            }

            // ── 4. Clone the project itself ──────────────────────────────────
            $project = $source->replicate([
                'current_revision_id', 'family_id', 'share_token', 'is_shared',
            ]);
            $project->workspace_id = $targetWorkspaceId;
            $project->created_by_user_id = $targetUserId;
            $project->channel_id = null;
            $project->brand_kit_id = null;
            $project->series_id = null;
            $project->series_episode_number = null;
            $project->series_episode_summary = null;
            $project->share_token = null;
            $project->is_shared = false;
            $project->current_revision_id = null;
            $project->family_id = null;
            $project->music_asset_id = $assetMap[$source->music_asset_id] ?? null;
            $project->default_character_id = $charMap[$source->default_character_id] ?? null;
            $project->source_image_asset_ids = $this->remapIdList($source->source_image_asset_ids, $assetMap);
            $project->save();

            // ── 5. Clone scenes (remap columns + JSON-embedded asset ids) ────
            foreach ($scenes as $scene) {
                $new = $scene->replicate();
                $new->project_id = $project->getKey();
                $new->visual_asset_id = $assetMap[$scene->visual_asset_id] ?? null;
                $new->sound_asset_id = $assetMap[$scene->sound_asset_id] ?? null;
                $new->character_id = $charMap[$scene->character_id] ?? null;
                $new->character_ids = $this->remapIdList($scene->character_ids, $charMap);
                // Voice profiles are workspace-scoped; the voice config itself
                // lives in voice_settings_json, so drop the cross-workspace ref.
                $new->voice_profile_id = null;
                $new->image_generation_settings_json = $this->remapAssetsInArray($scene->image_generation_settings_json, $assetMap);
                $new->voice_settings_json = $this->remapAssetsInArray($scene->voice_settings_json, $assetMap);
                $new->motion_settings_json = $this->remapAssetsInArray($scene->motion_settings_json, $assetMap);
                $new->sound_settings_json = $this->remapAssetsInArray($scene->sound_settings_json, $assetMap);
                $new->save();
            }

            // ── 6. Clone the completed export → finished, playable on arrival ─
            if ($export && isset($assetMap[$export->output_asset_id])) {
                ExportJob::query()->create([
                    'workspace_id'      => $targetWorkspaceId,
                    'project_id'        => $project->getKey(),
                    'aspect_ratio'      => $export->aspect_ratio,
                    'language'          => $export->language,
                    'file_name'         => $export->file_name,
                    'watermark_enabled' => $export->watermark_enabled,
                    'status'            => 'completed',
                    'progress_percent'  => 100,
                    'output_asset_id'   => $assetMap[$export->output_asset_id],
                    'queued_at'         => now(),
                    'started_at'        => now(),
                    'completed_at'      => now(),
                ]);
            }

            return $project;
        });
    }

    /** Shallow-copy an asset row into the target workspace (same storage object). */
    private function cloneAsset(Asset $asset, int $workspaceId, int $userId): int
    {
        $copy = $asset->replicate();
        $copy->workspace_id = $workspaceId;
        $copy->channel_id = null;
        $copy->restriction_scope = 'workspace';
        $copy->status = 'active';
        $copy->usage_count = 0;
        $copy->created_by_user_id = $userId;
        $copy->save();

        return $copy->getKey();
    }

    private function cloneCharacter(Character $char, int $workspaceId, int $userId, array $assetMap): int
    {
        $copy = $char->replicate();
        $copy->workspace_id = $workspaceId;
        $copy->created_by_user_id = $userId;
        $copy->reference_asset_id = $assetMap[$char->reference_asset_id] ?? null;
        $copy->reference_asset_ids = $this->remapIdList($char->reference_asset_ids, $assetMap);
        $copy->save();

        return $copy->getKey();
    }

    /** @return int[] */
    private function characterIds(Project $project, $scenes): array
    {
        $ids = [];
        if ($project->default_character_id) {
            $ids[] = (int) $project->default_character_id;
        }
        foreach ($scenes as $scene) {
            if ($scene->character_id) {
                $ids[] = (int) $scene->character_id;
            }
            foreach ((array) ($scene->character_ids ?? []) as $id) {
                if (is_numeric($id)) {
                    $ids[] = (int) $id;
                }
            }
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** @return int[] */
    private function assetIds(Project $project, $scenes, $characters, ?ExportJob $export): array
    {
        $ids = [];
        $add = function ($v) use (&$ids): void {
            if (is_numeric($v)) {
                $ids[] = (int) $v;
            }
        };

        $add($project->music_asset_id);
        foreach ((array) ($project->source_image_asset_ids ?? []) as $id) {
            $add($id);
        }

        foreach ($scenes as $scene) {
            $add($scene->visual_asset_id);
            $add($scene->sound_asset_id);
            foreach (['image_generation_settings_json', 'voice_settings_json', 'motion_settings_json', 'sound_settings_json'] as $field) {
                $this->collectAssetIdsFromArray($scene->{$field}, $ids);
            }
        }

        foreach ($characters as $char) {
            $add($char->reference_asset_id);
            foreach ((array) ($char->reference_asset_ids ?? []) as $id) {
                $add($id);
            }
        }

        if ($export) {
            $add($export->output_asset_id);
        }

        return array_values(array_unique(array_filter($ids)));
    }

    /** Walk a settings array collecting any *asset_id / *asset_ids values. */
    private function collectAssetIdsFromArray($data, array &$ids): void
    {
        if (! is_array($data)) {
            return;
        }
        foreach ($data as $key => $value) {
            if (is_string($key) && str_ends_with($key, 'asset_id') && is_numeric($value)) {
                $ids[] = (int) $value;
            } elseif (is_string($key) && str_ends_with($key, 'asset_ids') && is_array($value)) {
                foreach ($value as $v) {
                    if (is_numeric($v)) {
                        $ids[] = (int) $v;
                    }
                }
            } elseif (is_array($value)) {
                $this->collectAssetIdsFromArray($value, $ids);
            }
        }
    }

    /** Remap any *asset_id / *asset_ids values inside a settings array. */
    private function remapAssetsInArray($data, array $map)
    {
        if (! is_array($data)) {
            return $data;
        }
        $out = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && str_ends_with($key, 'asset_id') && is_numeric($value)) {
                $out[$key] = $map[(int) $value] ?? $value;
            } elseif (is_string($key) && str_ends_with($key, 'asset_ids') && is_array($value)) {
                $out[$key] = array_map(fn ($v) => is_numeric($v) ? ($map[(int) $v] ?? $v) : $v, $value);
            } elseif (is_array($value)) {
                $out[$key] = $this->remapAssetsInArray($value, $map);
            } else {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** Remap a flat list of ids through a map, dropping any that don't resolve. */
    private function remapIdList($list, array $map): ?array
    {
        if (! is_array($list) || empty($list)) {
            return is_array($list) ? [] : null;
        }
        $out = [];
        foreach ($list as $id) {
            if (is_numeric($id) && isset($map[(int) $id])) {
                $out[] = $map[(int) $id];
            }
        }

        return $out;
    }
}
