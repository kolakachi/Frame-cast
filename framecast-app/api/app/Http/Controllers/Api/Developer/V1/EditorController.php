<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\Project\ProjectController;
use App\Models\ApiQuote;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Developer\EditOperations;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Editing through the API: read a project, read what may be changed,
 * propose a set of changes against a revision, apply them with the
 * revision as a precondition, export.
 *
 * A proposal is a quote of kind "edit": it freezes the validated changes
 * and their summed cost and expires in 10 minutes; apply claims it like
 * any quote (in-flight cap, key cap, balance), refuses if the project has
 * changed since, then executes the changes one by one through the
 * dashboard's own controllers and reports each result, partial failures
 * included. Proposals are free.
 */
class EditorController extends DeveloperController
{
    use ClaimsQuotes;

    public function __construct(private readonly CreditService $credits)
    {
    }

    public function project(Request $request, int $videoId): JsonResponse
    {
        $project = $this->find($request, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $show = EditOperations::run(fn () => app(ProjectController::class)->show(EditOperations::inner($request, [], 'GET'), $videoId));
        if ($show instanceof JsonResponse) {
            return $show;
        }
        $scenes = collect($show['scenes'] ?? [])->map(function (array $s) {
            $igs = (array) ($s['image_generation_settings_json'] ?? $s['image_generation_settings'] ?? []);
            $s['readiness'] = [
                'has_script' => trim((string) ($s['script_text'] ?? '')) !== '',
                'has_visual' => ! empty($s['visual_asset_id']),
                'has_voice' => ! empty(data_get($s, 'voice_settings_json.audio_asset_id') ?? data_get($s, 'voice_settings.audio_asset_id')),
                'has_animation' => ! empty($igs['animation_video_asset_id']),
                'in_progress' => ! empty($igs['in_progress']) || ! empty($igs['animation_in_progress']),
                'last_error' => $igs['last_error'] ?? $igs['animation_last_error'] ?? null,
                'locked_fields' => $s['locked_fields_json'] ?? $s['locked_fields'] ?? [],
            ];

            return $s;
        })->values();

        $latest = ExportJob::query()->where('project_id', $project->getKey())->latest('id')->first();
        $freshness = null;
        if ($latest && $latest->status === 'completed') {
            $f = EditOperations::run(fn () => app(ProjectController::class)->exportFreshness(EditOperations::inner($request, [], 'GET'), $videoId, (int) $latest->getKey()));
            $freshness = $f instanceof JsonResponse ? null : $f;
        }

        return response()->json(['data' => [
            'revision' => self::revision($project),
            'whole_video' => self::wholeVideo($project),
            'project' => $show['project'] ?? [],
            'scenes' => $scenes,
            'hook_options' => $show['hook_options'] ?? [],
            'latest_export' => $latest ? ['id' => $latest->getKey(), 'status' => $latest->status, 'aspect_ratio' => $latest->aspect_ratio, 'completed_at' => $latest->completed_at?->toIso8601String(), 'freshness' => $freshness] : null,
        ], 'meta' => []]);
    }

    public function schema(Request $request, int $videoId): JsonResponse
    {
        $project = $this->find($request, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $whole = self::wholeVideo($project);
        $ops = [];
        foreach (EditOperations::catalogue() as $name => $meta) {
            $available = ! ($whole && $meta['scene']) && ! ($whole && in_array($name, ['reorder_scenes', 'add_scene'], true));
            $ops[] = $meta + ['name' => $name, 'available' => $available, 'reason' => $available ? null : 'This take is a single generated video; its scenes cannot be edited. Revise it in its creation flow.'];
        }
        $ws = (int) $project->workspace_id;

        return response()->json(['data' => [
            'revision' => self::revision($project),
            'operations' => $ops,
            'scene_settings' => EditOperations::SCENE_SETTINGS,
            'enums' => [
                'aspect_ratios' => ['9:16', '1:1', '16:9'], 'rewrite_modes' => EditOperations::REWRITE_MODES, 'animate_tiers' => EditOperations::ANIMATE_TIERS,
                'visual_styles' => LookupController::visualStyles(),
                'caption_settings' => ['enabled', 'style_key', 'highlight_mode', 'position', 'font', 'highlight_color', 'color', 'size', 'preset_id', 'animation', 'highlight_style', 'panel_color', 'backdrop', 'ugc_headline'],
                'motion_settings' => ['effect', 'intensity', 'fit'],
            ],
            'plan' => [
                'tier' => $this->credits->planTier($ws),
                'custom_characters' => (bool) $this->credits->limitFor($ws, 'custom_characters'),
                'max_duration_seconds' => $this->credits->maxDurationSeconds($ws),
            ],
            'rules' => [
                'Proposals are free and expire in 10 minutes; apply needs the proposal id and the same revision.',
                'A scene with locked_fields refuses changes to those fields.',
                'Changing a script marks its narration stale; regenerate_voice re-records it.',
                'Exports are separate: export_video after applying; older exports are listed with freshness.',
            ],
        ], 'meta' => []]);
    }

    public function propose(Request $request, int $videoId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $project = $this->find($request, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $input = $this->validated($request, [
            'revision' => ['required', 'string', 'max:64'],
            'changes' => ['required', 'array', 'min:1', 'max:30'],
            'changes.*.op' => ['required', 'in:'.implode(',', array_keys(EditOperations::catalogue()))],
        ]);
        if ($input['revision'] !== self::revision($project)) {
            return $this->fail('revision_conflict', 'The project changed since you read it. Read it again and propose against the current revision.', 409, ['current_revision' => self::revision($project)]);
        }
        $whole = self::wholeVideo($project);
        $sceneIds = Scene::query()->where('project_id', $project->getKey())->pluck('id')->map(fn ($i) => (int) $i)->all();

        $validated = [];
        $total = 0;
        // The raw changes: validated() keeps only the keys it checked, and each
        // op's own inputs are validated per op below.
        foreach ((array) $request->input('changes') as $i => $change) {
            $op = $change['op'];
            $meta = EditOperations::catalogue()[$op];
            if ($whole && ($meta['scene'] || in_array($op, ['reorder_scenes', 'add_scene'], true))) {
                return $this->fail('whole_video_edit_unsupported', "Change {$i} ({$op}): this take is a single generated video; scenes cannot be edited.", 422, ['change' => $i]);
            }
            $v = Validator::make($change, EditOperations::rules($op));
            if ($v->fails()) {
                return $this->fail('validation_failed', "Change {$i} ({$op}): ".collect($v->errors()->all())->first(), 422, ['change' => $i, 'errors' => $v->errors()->toArray()]);
            }
            $c = $v->validated();
            if (! empty($c['scene_id']) && ! in_array((int) $c['scene_id'], $sceneIds, true)) {
                return $this->fail('invalid_scene', "Change {$i} ({$op}): scene {$c['scene_id']} is not in this video.", 422, ['change' => $i]);
            }
            if (! empty($c['scene_ids']) && array_diff(array_map('intval', $c['scene_ids']), $sceneIds)) {
                return $this->fail('invalid_scene', "Change {$i} ({$op}): scene_ids include scenes not in this video.", 422, ['change' => $i]);
            }
            if ($op === 'update_scene') {
                $unknown = array_diff(array_keys($c['settings']), EditOperations::SCENE_SETTINGS);
                if ($unknown) {
                    return $this->fail('validation_failed', "Change {$i}: unknown scene settings: ".implode(', ', $unknown), 422, ['change' => $i]);
                }
            }
            $scene = ! empty($c['scene_id']) ? Scene::query()->find($c['scene_id']) : null;
            $cost = EditOperations::price($op, $project, $scene, $c);
            $total += $cost;
            $validated[] = ['op' => $op] + $c + ['credits_max' => $cost];
        }

        $quote = ApiQuote::query()->create([
            'id' => ApiQuote::newId(), 'workspace_id' => (int) $user->workspace_id, 'api_key_id' => $request->attributes->get('api_key_id'),
            'created_by_user_id' => $user->getKey(),
            'payload_json' => ['__kind' => 'edit', 'project_id' => $project->getKey(), 'revision' => $input['revision'], 'changes' => $validated],
            'credits_min' => $total, 'credits_max' => $total, 'expires_at' => now()->addMinutes(ApiQuote::TTL_MINUTES),
        ]);
        $balance = $this->credits->balance((int) $user->workspace_id);

        return response()->json(['data' => [
            'proposal_id' => $quote->getKey(), 'revision' => $input['revision'], 'changes' => $validated,
            'credits' => ['max' => $total], 'balance' => $balance, 'can_afford' => $balance >= $total,
            'expires_at' => $quote->expires_at->toIso8601String(),
        ], 'meta' => []], 201);
    }

    public function apply(Request $request, int $videoId, string $proposalId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;
        $project = $this->find($request, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $input = $this->validated($request, ['idempotency_key' => ['nullable', 'string', 'max:128']]);
        $idempotencyKey = $this->idempotencyKeyFrom($request, $input) ?? $proposalId;

        $claim = $this->claimQuote($proposalId, $workspaceId, $idempotencyKey, $request->attributes->get('api_key_id'), 'edit', $this->credits);
        if ($claim instanceof JsonResponse) {
            return $claim;
        }
        /** @var ApiQuote $quote */
        $quote = $claim['quote'];
        $f = $quote->payload_json;
        if ((int) $f['project_id'] !== $videoId) {
            $this->releaseQuote($quote);

            return $this->fail('quote_kind_mismatch', 'This proposal is for a different video.', 409);
        }
        if (array_key_exists('replay', $claim)) {
            return response()->json(['data' => $f['result'] ?? ['applied' => [], 'revision' => self::revision($project)], 'meta' => []], 200);
        }
        if ($f['revision'] !== self::revision($project)) {
            $this->releaseQuote($quote);

            return $this->fail('revision_conflict', 'The project changed after this proposal was made. Read it again and propose again.', 409, ['current_revision' => self::revision($project)]);
        }

        $results = [];
        foreach ($f['changes'] as $i => $change) {
            $op = $change['op'];
            $out = EditOperations::execute($op, $request, $project, $change);
            if ($out instanceof JsonResponse) {
                $err = $out->getData(true)['error'] ?? [];
                $results[] = ['index' => $i, 'op' => $op, 'ok' => false, 'status' => $out->getStatusCode(), 'error' => ['code' => $err['code'] ?? 'refused', 'message' => $err['message'] ?? '']];
            } else {
                $results[] = ['index' => $i, 'op' => $op, 'ok' => true, 'result' => self::trim($out)];
            }
        }
        $project = $project->fresh();
        $result = ['proposal_id' => $quote->getKey(), 'revision' => self::revision($project), 'applied' => $results,
            'failed' => count(array_filter($results, fn ($r) => ! $r['ok'])), 'next' => 'Read the project again for the new state; export_video when ready.'];
        $quote->forceFill(['project_id' => $project->getKey(), 'payload_json' => $f + ['result' => $result]])->save();

        return response()->json(['data' => $result, 'meta' => []], 200);
    }

    public function export(Request $request, int $videoId): JsonResponse
    {
        $project = $this->find($request, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $input = $this->validated($request, [
            'aspect_ratios' => ['nullable', 'array', 'max:4'], 'aspect_ratios.*' => ['in:9:16,1:1,4:5,16:9'],
            'language' => ['nullable', 'string', 'max:16'], 'watermark_enabled' => ['nullable', 'boolean'],
        ]);
        $out = EditOperations::run(fn () => app(ProjectController::class)->export(EditOperations::inner($request, array_filter($input, fn ($v) => $v !== null)), $videoId));
        if ($out instanceof JsonResponse) {
            return $out;
        }
        $jobs = collect($out['export_jobs'] ?? [])->map(fn ($j) => ['id' => $j['id'], 'aspect_ratio' => $j['aspect_ratio'], 'status' => $j['status']])->values();

        return response()->json(['data' => ['exports' => $jobs, 'skipped_aspect_ratios' => $out['skipped_aspect_ratios'] ?? [], 'next' => 'Poll get_video_status; get_video_result returns the newest completed export.'], 'meta' => []], 202);
    }

    public function exports(Request $request, int $videoId): JsonResponse
    {
        $project = $this->find($request, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $out = EditOperations::run(fn () => app(ProjectController::class)->exports(EditOperations::inner($request, [], 'GET'), $videoId));
        if ($out instanceof JsonResponse) {
            return $out;
        }

        return response()->json(['data' => ['exports' => collect($out['export_jobs'] ?? [])->map(fn ($j) => array_intersect_key($j, array_flip(['id', 'aspect_ratio', 'language', 'status', 'progress_percent', 'failure_reason', 'completed_at', 'download_url'])))->values()], 'meta' => []]);
    }

    public function retry(Request $request, int $videoId): JsonResponse
    {
        $project = $this->find($request, $videoId);
        if (! $project) {
            return $this->fail('not_found', 'Video not found.', 404);
        }
        $method = $project->status === 'failed' ? 'retryGeneration' : 'resumeFailed';
        $out = EditOperations::run(fn () => app(ProjectController::class)->{$method}(EditOperations::inner($request, []), $videoId));

        return $out instanceof JsonResponse ? $out : response()->json(['data' => ['retried' => true, 'via' => $method], 'meta' => []], 202);
    }

    // ── internals ─────────────────────────────────────────────────────────

    public static function revision(Project $project): string
    {
        $scenes = Scene::query()->where('project_id', $project->getKey())->orderBy('scene_order')->get(['id', 'scene_order', 'updated_at'])
            ->map(fn ($s) => $s->id.':'.$s->scene_order.':'.$s->updated_at)->implode('|');

        return 'r_'.substr(sha1($project->updated_at.'|'.$project->status.'|'.$scenes), 0, 16);
    }

    public static function wholeVideo(Project $project): bool
    {
        return in_array(data_get($project->visual_brief, 'ugc_format'), ['one_shot', 'restyle'], true);
    }

    private function find(Request $request, int $videoId): ?Project
    {
        /** @var User $user */
        $user = $request->user();

        return Project::query()->whereKey($videoId)->where('workspace_id', $user->workspace_id)->first();
    }

    /** Keep results small: ids and status, not whole serialized scenes. */
    private static function trim(array $out): array
    {
        $keep = [];
        foreach ($out as $k => $v) {
            if (is_array($v) && isset($v['id'])) {
                $keep[$k] = array_intersect_key($v, array_flip(['id', 'status', 'scene_order', 'script_text', 'estimated_cost']));
            } elseif (! is_array($v) || count($v) <= 8) {
                $keep[$k] = $v;
            }
        }

        return $keep;
    }
}
