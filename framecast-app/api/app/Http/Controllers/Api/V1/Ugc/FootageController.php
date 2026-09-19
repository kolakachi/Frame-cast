<?php

namespace App\Http\Controllers\Api\V1\Ugc;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\FootageSession;
use App\Models\User;
use App\Services\Ugc\UgcFootagePlanner;
use App\Services\Ugc\UgcFootageReader;
use App\Services\Ugc\UgcPlan;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The My Footage flow: bring a video, see what we read off it, correct it,
 * review the plan for your version, approve the cost, produce. Production
 * itself goes through UgcController::generate — same caps, same billing,
 * same run tracking as any other take.
 */
class FootageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $sessions = FootageSession::query()
            ->where('workspace_id', $request->user()->workspace_id)
            ->with('sourceAsset:id,title,thumbnail_url,duration_seconds')
            ->latest('id')->limit(20)->get();

        return response()->json(['data' => ['sessions' => $sessions], 'meta' => []]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $v = $request->validate([
            'asset_id' => ['required', 'integer', 'min:1'],
            'brief' => ['nullable', 'string', 'max:2000'],
            'rights' => ['required', Rule::in(['reuse', 'reference'])],
            'selection' => ['sometimes', 'array'],
            'selection.start' => ['nullable', 'numeric', 'min:0'],
            'selection.end' => ['nullable', 'numeric', 'min:0'],
        ]);

        $asset = Asset::query()->whereKey($v['asset_id'])
            ->where('workspace_id', $user->workspace_id)->first();
        if (! $asset) {
            throw ValidationException::withMessages(['asset_id' => 'That video was not found in this workspace.']);
        }

        $session = FootageSession::query()->create([
            'workspace_id' => $user->workspace_id,
            'user_id' => $user->id,
            'source_asset_id' => $asset->id,
            'brief' => trim((string) ($v['brief'] ?? '')),
            'rights' => $v['rights'],
            'selection_json' => $v['selection'] ?? null,
            'status' => 'draft',
        ]);

        return response()->json(['data' => ['session' => $session->load('sourceAsset:id,title,thumbnail_url,duration_seconds')], 'meta' => []], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $session = $this->find($request, $id);

        return response()->json(['data' => [
            'session' => $session->load('sourceAsset:id,title,thumbnail_url,duration_seconds'),
            'corrected' => $session->read_json ? $session->correctedRead() : null,
        ], 'meta' => []]);
    }

    /** Watch the selected passage and store what we saw. */
    public function read(Request $request, int $id, UgcFootageReader $reader): JsonResponse
    {
        $session = $this->find($request, $id);
        $read = $reader->read($session->sourceAsset, (array) ($session->selection_json ?? []));
        $session->forceFill(['read_json' => $read, 'status' => 'read'])->save();

        return response()->json(['data' => ['session' => $session, 'corrected' => $session->correctedRead()], 'meta' => []]);
    }

    /** The user's corrections — kept apart from the read, never overwriting it. */
    public function update(Request $request, int $id): JsonResponse
    {
        $session = $this->find($request, $id);
        $v = $request->validate([
            'brief' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'rights' => ['sometimes', Rule::in(['reuse', 'reference'])],
            'corrections' => ['sometimes', 'array'],
            'corrections.passages' => ['sometimes', 'array'],
            'corrections.passages.*.transcript' => ['nullable', 'string', 'max:1000'],
            'corrections.passages.*.title' => ['nullable', 'string', 'max:80'],
            'corrections.passages.*.important' => ['nullable', 'boolean'],
            'corrections.passages.*.drop' => ['nullable', 'boolean'],
            'corrections.speakers' => ['sometimes', 'array'],
            'corrections.speakers.*.label' => ['nullable', 'string', 'max:60'],
        ]);

        $fill = [];
        if (array_key_exists('brief', $v)) {
            $fill['brief'] = trim((string) $v['brief']);
        }
        if (array_key_exists('rights', $v)) {
            $fill['rights'] = $v['rights'];
        }
        if (array_key_exists('corrections', $v)) {
            $fill['corrections_json'] = array_replace_recursive($session->corrections_json ?? [], $v['corrections']);
            // A corrected source invalidates a plan built on the old read.
            $fill['plan_json'] = null;
            if ($session->status === 'planned') {
                $fill['status'] = 'read';
            }
        }
        $session->forceFill($fill)->save();

        return response()->json(['data' => ['session' => $session, 'corrected' => $session->correctedRead()], 'meta' => []]);
    }

    /** Plan the user's version from the corrected read. */
    public function plan(Request $request, int $id, UgcFootagePlanner $planner): JsonResponse
    {
        $session = $this->find($request, $id);
        if (! $session->read_json) {
            throw ValidationException::withMessages(['plan' => 'Read the source before planning a version of it.']);
        }

        $plan = $planner->plan($session);

        // Price it the way it will be charged: the passages are UGC segments,
        // and a reused passage is the source clip itself — free footage, not
        // a generated still.
        $raw = $this->segmentsFor($plan['passages'], $session);
        $format = $this->formatFor($raw);
        $segments = UgcPlan::normalise($raw, $format);
        $plan['format'] = $format;
        $plan['credits'] = UgcPlan::quote($segments);

        $session->forceFill(['plan_json' => $plan, 'status' => 'planned'])->save();

        return response()->json(['data' => ['session' => $session, 'plan' => $plan], 'meta' => []]);
    }

    /**
     * Approve and produce. Consent is recorded, then the planned segments run
     * through the same generate() as every UGC take — caps, balance check,
     * billing and run id included.
     */
    public function produce(Request $request, int $id, UgcController $ugc): JsonResponse
    {
        $session = $this->find($request, $id);
        $plan = $session->plan_json;
        if (! $plan) {
            throw ValidationException::withMessages(['produce' => 'Plan your version before producing it.']);
        }

        $v = $request->validate([
            'consent_reference' => ['accepted'],
            'consent_presenter' => ['accepted'],
            'character_id' => ['nullable', 'integer', 'min:1'],
            'product_asset_id' => ['nullable', 'integer', 'min:1'],
            'aspect_ratio' => ['sometimes', Rule::in(['9:16', '1:1', '16:9'])],
            'language' => ['sometimes', 'string', 'max:12'],
            'credits' => ['required', 'integer'],
            'answers' => ['sometimes', 'array'],
            'answers.*' => ['nullable', 'string', 'max:500'],
        ]);

        $segments = $this->segmentsFor((array) $plan['passages'], $session);

        $hasPerformance = (bool) array_filter($segments, fn ($s) => $s['kind'] !== 'b_roll');
        if ($hasPerformance && empty($v['character_id'])) {
            throw ValidationException::withMessages(['character_id' => 'Pick the presenter for the new performance first.']);
        }

        $session->forceFill(['consent_json' => [
            'reference' => true, 'presenter' => true,
            'at' => now()->toIso8601String(), 'user_id' => $request->user()->id,
            'answers' => $v['answers'] ?? [],
        ]])->save();

        $format = $this->formatFor($segments);
        $normalised = UgcPlan::normalise($segments, $format);
        $generateRequest = Request::create('/api/v1/ugc/generate', 'POST', [
            'request_id' => (string) \Ramsey\Uuid\Uuid::uuid5(\Ramsey\Uuid\Uuid::NAMESPACE_URL, 'footage:'.$session->id.':'.hash('sha256',json_encode([$normalised,$v]))),
            'format' => $format,
            'segments' => $normalised,
            'script' => UgcPlan::script($normalised) ?: null,
            // Omitted entirely when nobody presents: a still-only run casts
            // no one, and an empty array fails min:1 rather than meaning it.
            ...(! empty($v['character_id']) ? ['character_ids' => [(int) $v['character_id']]] : []),
            'aspect_ratio' => $v['aspect_ratio'] ?? '9:16',
            'language' => $v['language'] ?? 'en',
            'voices' => [],
            'product_asset_id' => $v['product_asset_id'] ?? null,
            'consent' => true,
            'reviewed' => true,
            'credits_per_character' => (int) $v['credits'],
        ]);
        $generateRequest->setUserResolver(fn () => $request->user());

        $response = $ugc->generate($generateRequest);
        $payload = $response->getData(true);

        $runId = data_get($payload, 'data.run_id');
        $session->forceFill(['run_id' => $runId, 'status' => 'producing'])->save();

        return response()->json(['data' => [
            'session' => $session,
            'takes' => data_get($payload, 'data.takes'),
            'run_id' => $runId,
        ], 'meta' => []], 201);
    }

    /**
     * The UGC format this plan actually is. A presenter makes it a story;
     * cards with words make it text-led; clips of the user's own footage
     * with no headlines are a demo — assembled footage, not a caption ad.
     */
    private function formatFor(array $segments): string
    {
        if (array_filter($segments, fn ($s) => $s['kind'] !== 'b_roll')) {
            return 'story';
        }
        if (array_filter($segments, fn ($s) => trim((string) ($s['headline'] ?? '')) !== '')) {
            return 'text_led';
        }

        return 'demo';
    }

    /**
     * Video-to-video restyle: the whole clip, re-rendered with the user's
     * instruction and the original motion kept. Own footage only — feeding
     * someone else's video into a model is exactly what reference-only rights
     * exist to prevent.
     */
    public function restyle(Request $request, int $id): JsonResponse
    {
        $session = $this->find($request, $id);
        $v = $request->validate([
            'consent_owner' => ['accepted'],
            'mode' => ['sometimes', Rule::in(\App\Services\Generation\Video\ReplicateModifyVideoAdapter::MODES)],
            'instruction' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'credits' => ['required', 'integer'],
        ]);

        if ($session->rights !== 'reuse') {
            throw ValidationException::withMessages(['rights' => 'Restyling feeds the clip itself into a video model, so it needs footage you own. Switch the source to "Reuse directly", or use Reference only to rebuild from structure.']);
        }
        $source = $session->sourceAsset;
        $duration = (float) ($source->duration_seconds ?? 0);
        if ($duration <= 0 || $duration > \App\Services\Generation\Video\ReplicateModifyVideoAdapter::MAX_SECONDS) {
            throw ValidationException::withMessages(['source' => sprintf(
                'Restyle handles clips up to %d seconds; this one is %s. Trim it first.',
                \App\Services\Generation\Video\ReplicateModifyVideoAdapter::MAX_SECONDS,
                $duration > 0 ? round($duration).'s' : 'of unknown length',
            )]);
        }

        $instruction = trim((string) ($v['instruction'] ?? '')) ?: trim((string) $session->brief);
        if ($instruction === '') {
            throw ValidationException::withMessages(['instruction' => 'Say what should change — that sentence is the whole instruction.']);
        }

        $quote = (int) ceil($duration) * \App\Services\CreditService::VIDEO_RESTYLE_PER_SECOND;
        if ((int) $v['credits'] !== $quote) {
            throw ValidationException::withMessages(['credits' => "The price changed — this restyle is {$quote} credits. Review and approve again."]);
        }
        $svc = app(\App\Services\CreditService::class);
        if ($svc->balance((int) $session->workspace_id) < $quote) {
            throw ValidationException::withMessages(['credits' => "This restyle needs {$quote} credits."]);
        }

        $runId = (string) Str::uuid();
        $project = \App\Models\Project::query()->create([
            'workspace_id' => $session->workspace_id,
            'created_by_user_id' => $request->user()->id,
            'title' => mb_substr('Restyle — '.$instruction, 0, 120),
            'aspect_ratio' => '9:16',
            'duration_target_seconds' => (int) ceil($duration),
            'status' => 'generating',
            'source_type' => 'video_upload',
            'primary_language' => 'en',
            'source_content_raw' => $instruction,
            'visual_brief' => [
                'ugc_format' => 'restyle',
                'ugc_estimated_credits' => $quote,
                'ugc_run_id' => $runId,
            ],
        ]);
        $scene = \App\Models\Scene::query()->create([
            'project_id' => $project->id, 'scene_order' => 1, 'scene_type' => 'narration',
            'label' => 'Restyled clip', 'script_text' => '', 'duration_seconds' => $duration,
            'voice_settings_json' => ['enabled' => false],
            'caption_settings_json' => ['enabled' => false],
            'visual_type' => 'video', 'status' => 'draft',
            'image_generation_settings_json' => [
                'in_progress' => true, 'ugc_kind' => 'b_roll',
                'restyle_mode' => $v['mode'] ?? 'flex_1',
                'generation_started_at' => now()->toIso8601String(),
            ],
        ]);
        \App\Jobs\RestyleVideoJob::dispatch(
            $session->id, $project->id, $scene->id, $instruction, $v['mode'] ?? 'flex_1', $quote,
        )->afterCommit();

        $session->forceFill(['run_id' => $runId, 'status' => 'producing', 'consent_json' => [
            'owner' => true, 'at' => now()->toIso8601String(), 'user_id' => $request->user()->id,
        ]])->save();

        return response()->json(['data' => [
            'session' => $session, 'run_id' => $runId, 'project_id' => $project->id, 'credits_quoted' => $quote,
        ], 'meta' => []], 201);
    }

    /** Plan passages as produceable segments — reused ones keep the source clip. */
    private function segmentsFor(array $passages, FootageSession $session): array
    {
        return array_map(function ($p) use ($session) {
            $seg = $p['segment'];
            if (($p['treatment'] ?? '') === 'reused' && $session->rights === 'reuse') {
                $seg['source'] = 'upload';
                $seg['asset_id'] = $session->source_asset_id;
            }

            return $seg;
        }, $passages);
    }

    private function find(Request $request, int $id): FootageSession
    {
        $session = FootageSession::query()->whereKey($id)
            ->where('workspace_id', $request->user()->workspace_id)->first();
        if (! $session) {
            abort(response()->json(['error' => ['code' => 'not_found', 'message' => 'Session not found.']], 404));
        }

        return $session;
    }
}
