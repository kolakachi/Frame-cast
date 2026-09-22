<?php

namespace App\Services\Export;

use App\Models\ExportJob;
use App\Models\Project;

class ExportFreshnessService
{
    public function fingerprint(Project $project): string
    {
        $payload = [
            'project' => $project->only(['aspect_ratio', 'primary_language', 'music_asset_id', 'music_settings_json', 'waveform_settings_json']),
            'scenes' => ($project->relationLoaded('scenes') ? $project->scenes : $project->scenes()->orderBy('scene_order')->orderBy('id')->get())->map(fn ($scene) => $scene->only([
                'id', 'label', 'scene_order', 'scene_type', 'script_text', 'duration_seconds',
                'voice_settings_json', 'caption_settings_json', 'visual_type', 'visual_asset_id',
                'sound_asset_id', 'sound_settings_json', 'motion_settings_json', 'transition_rule',
                'image_generation_settings_json',
            ]))->all(),
        ];
        return hash('sha256', json_encode($this->canonical($payload), JSON_THROW_ON_ERROR));
    }

    public function check(Project $project, ExportJob $export): array
    {
        if ($export->source_fingerprint) {
            return ['is_stale' => ! hash_equals($export->source_fingerprint, $this->fingerprint($project)), 'verified' => true];
        }
        // Exports created before the snapshot migration have no exact baseline.
        // Use save times conservatively rather than claim an exact match.
        $queuedAt = $export->queued_at;
        $changed = ! $queuedAt || ($project->updated_at && $project->updated_at->gt($queuedAt))
            || $project->scenes()->where('updated_at', '>', $queuedAt)->exists();
        return ['is_stale' => $changed, 'verified' => false];
    }

    private function canonical(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        if (! array_is_list($value)) ksort($value);
        return array_map(fn ($item) => $this->canonical($item), $value);
    }
}
