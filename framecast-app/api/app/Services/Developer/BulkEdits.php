<?php

namespace App\Services\Developer;

use App\Http\Controllers\Api\V1\Project\{BulkAnimateController, BulkVisualController, BulkVoiceController};
use App\Models\Project;
use Illuminate\Http\{JsonResponse, Request};

/** Uses dashboard previews and dispatch, including shared-animation pricing. */
class BulkEdits
{
    public const CONTROLLERS = ['rerecord_all' => BulkVoiceController::class, 'restyle_all' => BulkVisualController::class, 'animate_all' => BulkAnimateController::class];

    public static function preview(string $op, Request $outer, Project $project, array $input): array|JsonResponse
    {
        $scenes = $project->scenes()->orderBy('scene_order')->get();
        $selected = $input['scene_ids'] ?? $scenes->modelKeys();
        $fields = $op === 'rerecord_all' ? ['script_text', 'voice_settings_json', 'voice_profile_id'] : ['visual_asset_id', 'visual_prompt', 'visual_style', 'image_generation_settings_json'];
        $excluded = [];
        foreach ($scenes as $scene) {
            if (! in_array($scene->id, $selected)) continue;
            $locks = (array) $scene->locked_fields_json;
            $busy = $op === 'rerecord_all' && data_get($scene->voice_settings_json, 'in_progress');
            if ($busy || array_intersect($locks, $fields)) {
                $excluded[] = ['scene_id' => $scene->id, 'reason' => $busy ? 'Narration already generating' : 'Relevant scene fields are locked', 'selectable' => false];
            }
        }
        if ($excluded) $input['scene_ids'] = array_values(array_diff($selected, array_column($excluded, 'scene_id')));
        $payload = self::payload($op, $input);
        $out = EditOperations::run(fn () => app(self::CONTROLLERS[$op])(EditOperations::inner($outer, $payload + ['confirm' => false]), $project->id));
        if ($out instanceof JsonResponse) return $out;
        $excludedIds = array_column($excluded, 'scene_id');
        $out['skipped'] = array_values(array_merge(array_filter($out['skipped'] ?? [], fn ($s) => ! in_array($s['scene_id'], $excludedIds)), $excluded));
        $out['dispatch_payload'] = $payload;
        return $out;
    }

    public static function payload(string $op, array $input): array
    {
        $keys = match ($op) {
            'rerecord_all' => ['scene_ids'],
            'restyle_all' => ['scene_ids', 'style', 'custom_visual_style', 'model_key'],
            'animate_all' => ['scene_ids', 'tier', 'duration_seconds', 'motion_prompt', 'quality', 'source_asset_id', 'consent'],
        };
        return array_filter(array_intersect_key($input, array_flip($keys)), fn ($v) => $v !== null);
    }

    public static function execute(string $op, Request $outer, Project $project, array $input): array|JsonResponse
    {
        $current = self::preview($op, $outer, $project, $input);
        if ($current instanceof JsonResponse) return $current;
        $approved = $input['preview'];
        if ($current['scenes'] !== $approved['scenes'] || $current['dispatch_payload'] !== $approved['dispatch_payload'] || $current['total_cost'] > $input['credits_max']) {
            return response()->json(['error' => ['code' => 'bulk_preview_changed', 'message' => 'Eligibility, sources or pricing changed. Read the project and propose this bulk action again.']], 409);
        }
        $out = EditOperations::run(fn () => app(self::CONTROLLERS[$op])(EditOperations::inner($outer, $current['dispatch_payload'] + ['confirm' => true]), $project->id));
        if ($out instanceof JsonResponse) return $out;
        $out['skipped'] = $current['skipped'];
        $out['scenes'] = array_map(fn ($row) => $row + ['dispatch_state' => isset($row['shares_with']) ? 'waiting_for_shared_clip' : 'queued'], $out['scenes']);
        $out['next'] = 'Poll get_operation and get_project for per-scene errors/results. Replay this proposal after a timeout; do not run the whole bulk action again. For failures, inspect results, reconcile any uncertain operation, then propose only the failed scene_ids.';
        return $out;
    }
}
