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

        // Price it the way it will be charged: the passages are UGC segments.
        $raw = array_map(fn ($p) => $p['segment'], $plan['passages']);
        $format = array_filter($raw, fn ($s) => $s['kind'] !== 'b_roll') ? 'story' : 'text_led';
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

        $segments = array_map(fn ($p) => $p['segment'], (array) $plan['passages']);
        // 'Reuse directly' rights: passages marked reused keep the source clip.
        foreach ($segments as $i => $seg) {
            if (($plan['passages'][$i]['treatment'] ?? '') === 'reused' && $session->rights === 'reuse') {
                $segments[$i]['source'] = 'upload';
                $segments[$i]['asset_id'] = $session->source_asset_id;
            }
        }

        $hasPerformance = (bool) array_filter($segments, fn ($s) => $s['kind'] !== 'b_roll');
        if ($hasPerformance && empty($v['character_id'])) {
            throw ValidationException::withMessages(['character_id' => 'Pick the presenter for the new performance first.']);
        }

        $session->forceFill(['consent_json' => [
            'reference' => true, 'presenter' => true,
            'at' => now()->toIso8601String(), 'user_id' => $request->user()->id,
            'answers' => $v['answers'] ?? [],
        ]])->save();

        $format = $hasPerformance ? 'story' : 'text_led';
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
