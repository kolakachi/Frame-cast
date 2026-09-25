<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\Ugc\UgcController as AppUgcController;
use App\Models\ApiQuote;
use App\Models\Asset;
use App\Models\Character;
use App\Models\Project;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Ugc\UgcOneShotPricing;
use App\Services\Ugc\UgcPlan;
use App\Services\Ugc\UgcShotPlanner;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * UGC ads through the developer API: plan → quote → create, over the same
 * controller the dashboard uses. Every paid step is delegated to
 * App\Http\Controllers\Api\V1\Ugc\UgcController with an internal request,
 * so the UGC gate, take reservation, Test Pass rules, fingerprint idempotency
 * and the credit cross-check are exactly the dashboard's. This class only
 * shapes the input, prices the quote, and attributes the result to the key.
 */
class UgcController extends DeveloperController
{
    use ClaimsQuotes;

    public function __construct(private readonly CreditService $credits)
    {
    }

    /** Free: run the shot planner (and optional alternates) for a brief. */
    public function plans(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'script' => ['nullable', 'string', 'max:1500', 'required_without:context'],
            'context' => ['nullable', 'string', 'max:1500', 'required_without:script'],
            'product' => ['nullable', 'string', 'max:200'],
            'format' => ['required', Rule::in(['auto', ...UgcPlan::FORMATS])],
            'duration_seconds' => ['required', 'integer', 'min:5', 'max:180'],
            'language' => ['nullable', Rule::in(LookupController::LANGUAGES)],
            'footage_asset_ids' => ['nullable', 'array', 'max:40'],
            'footage_asset_ids.*' => ['integer'],
            'variants_count' => ['nullable', 'integer', 'min:2', 'max:6'],
        ]);

        $payload = array_filter([
            'script' => $input['script'] ?? null, 'context' => $input['context'] ?? null, 'product' => $input['product'] ?? null,
            'format' => $input['format'], 'duration_seconds' => $input['duration_seconds'], 'language' => $input['language'] ?? null,
            'footage_asset_ids' => $input['footage_asset_ids'] ?? null,
        ], fn ($v) => $v !== null);

        $plan = $this->delegate($request, fn (AppUgcController $c, Request $r) => $c->plan($r, app(UgcShotPlanner::class)), $payload);
        if ($plan instanceof JsonResponse) {
            return $plan;
        }
        $out = ['plan' => $plan];

        if (! empty($input['variants_count']) && ! empty($plan['segments'])) {
            $variants = $this->delegate($request, fn (AppUgcController $c, Request $r) => $c->variants($r, app(UgcShotPlanner::class)), [
                'format' => $plan['format'] ?? $input['format'], 'segments' => $plan['segments'], 'count' => $input['variants_count'],
                'product' => $input['product'] ?? null, 'context' => $input['context'] ?? null,
            ]);
            if ($variants instanceof JsonResponse) {
                return $variants;
            }
            $out['variants'] = $variants;
        }

        return response()->json(['data' => $out, 'meta' => []]);
    }

    /** Free: price a plan (composed takes or a one-take ad) and freeze it into a quote. */
    public function quotes(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;

        $input = $this->validated($request, [
            'mode' => ['required', Rule::in(['composed', 'one_shot'])],
            'format' => ['required', Rule::in(UgcPlan::FORMATS)],
            'segments' => ['required', 'array', 'min:1', 'max:12'],
            'variants' => ['nullable', 'array', 'max:5'],
            'variants.*.label' => ['nullable', 'string', 'max:40'],
            'variants.*.segments' => ['required_with:variants', 'array', 'min:1', 'max:12'],
            'character_ids' => ['nullable', 'array', 'max:5'],
            'character_ids.*' => ['integer', 'distinct'],
            'character_id' => ['nullable', 'integer'],
            'cast_style' => ['nullable', Rule::in(['exact', 'variant'])],
            'fidelity' => ['nullable', Rule::in(['standard', 'high'])],
            'quality' => ['nullable', Rule::in(['draft', 'full'])],
            'presenter_description' => ['nullable', 'string', 'max:400'],
            'product_asset_id' => ['nullable', 'integer'],
            'product_asset_ids' => ['nullable', 'array', 'max:5'],
            'product_asset_ids.*' => ['integer'],
            'demo_asset_id' => ['nullable', 'integer'],
            'setting' => ['nullable', 'string', 'max:300'],
            'product' => ['nullable', 'string', 'max:200'],
            'tone' => ['nullable', 'string', 'max:200'],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'language' => ['nullable', Rule::in(LookupController::LANGUAGES)],
            'voice_key' => ['nullable', 'string', 'max:64'],
            'title' => ['nullable', 'string', 'max:120'],
            'consent' => ['required', 'boolean'],
        ]);

        if ($input['consent'] !== true) {
            return $this->fail('consent_required',
                'The user must confirm they have the right to use any real person\'s likeness or voice in this ad. Ask them, then quote again with consent: true.', 422);
        }

        try {
            $segments = UgcPlan::normalise($input['segments'], $input['format']);
        } catch (ValidationException $e) {
            return $this->fail('invalid_plan', collect($e->errors())->flatten()->first() ?? 'The plan could not be read.', 422, ['errors' => $e->errors()]);
        } catch (\Throwable $e) {
            return $this->fail('invalid_plan', 'The plan could not be read: '.$e->getMessage(), 422);
        }

        foreach (['product_asset_id' => 'image', 'demo_asset_id' => 'video'] as $field => $type) {
            if (! empty($input[$field]) && ! Asset::query()->whereKey($input[$field])->where('workspace_id', $workspaceId)->where('asset_type', $type)->exists()) {
                return $this->fail('invalid_asset', "No such {$type} in this workspace's library.", 422, [$field => $input[$field]]);
            }
        }
        foreach (array_filter(array_merge($input['character_ids'] ?? [], [$input['character_id'] ?? null])) as $cid) {
            $ok = Character::query()->whereKey($cid)->where('status', 'active')
                ->where(fn ($q) => $q->where('workspace_id', $workspaceId)->orWhere(fn ($sq) => $sq->whereNull('workspace_id')->where('is_stock', true)))->exists();
            if (! $ok) {
                return $this->fail('invalid_character', 'No such active character available to this workspace.', 422, ['character_id' => $cid]);
            }
        }

        $chosen = [];
        if ($input['mode'] === 'composed') {
            $plans = [$segments];
            foreach ($input['variants'] ?? [] as $variant) {
                $plans[] = UgcPlan::normalise($variant['segments'], $input['format']);
            }
            $castCount = max(1, count($input['character_ids'] ?? []));
            $perCharacter = UgcPlan::quote($segments);
            $total = 0;
            foreach ($plans as $p) {
                $total += UgcPlan::quote($p) * $castCount;
            }
            $takes = count($plans) * $castCount;
            $pricing = ['credits_per_character' => $perCharacter, 'takes' => $takes, 'plans' => count($plans), 'cast' => $castCount, 'warnings' => UgcPlan::warnings($segments, $input['format'])];
        } else {
            try {
                $one = UgcOneShotPricing::price($user, $input, $segments, $this->credits);
            } catch (ValidationException $e) {
                return $this->fail('invalid_plan', collect($e->errors())->flatten()->first() ?? 'Invalid plan.', 422, ['errors' => $e->errors()]);
            }
            $total = $one['quote'];
            $takes = 1;
            $pricing = ['engine' => $one['engine'], 'plan_seconds' => $one['plan_seconds'], 'draft' => $one['draft'], 'takes' => 1, 'presenter_reference_used' => $one['presenter_attached']];
        }

        $allowance = $this->allowanceFor($workspaceId);
        if ($allowance['remaining'] !== null && $takes > $allowance['remaining']) {
            return $this->fail('takes_exhausted',
                "This run needs {$takes} take(s) and {$allowance['remaining']} remain this month on the {$allowance['plan']} plan.",
                402, $allowance + ['takes_needed' => $takes]);
        }

        $frozen = $input + ['__kind' => 'ugc', 'segments_normalised' => $segments, 'request_id' => (string) Str::uuid(), 'pricing' => $pricing];
        $quote = ApiQuote::query()->create([
            'id' => ApiQuote::newId(), 'workspace_id' => $workspaceId, 'api_key_id' => $request->attributes->get('api_key_id'),
            'created_by_user_id' => $user->getKey(), 'payload_json' => $frozen,
            'credits_min' => $total, 'credits_max' => $total, 'expires_at' => now()->addMinutes(ApiQuote::TTL_MINUTES),
        ]);
        $balance = $this->credits->balance($workspaceId);

        return response()->json(['data' => [
            'quote_id' => $quote->getKey(), 'mode' => $input['mode'], 'format' => $input['format'],
            'credits' => ['min' => $total, 'max' => $total] + $pricing,
            'script' => UgcPlan::script($segments), 'takes' => $takes, 'allowance' => $allowance,
            'balance' => $balance, 'can_afford' => $balance >= $total, 'shortage' => max(0, $total - $balance),
            'expires_at' => $quote->expires_at->toIso8601String(),
        ], 'meta' => []], 201);
    }

    /** Spends credits: generate the quoted takes through the dashboard's own path. */
    public function videos(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;
        $input = $this->validated($request, ['quote_id' => ['required', 'string', 'max:32'], 'idempotency_key' => ['nullable', 'string', 'max:128']]);
        $idempotencyKey = $this->idempotencyKeyFrom($request, $input);
        if ($idempotencyKey === null) {
            return $this->fail('idempotency_key_required', 'Send an idempotency_key (or Idempotency-Key header).', 422);
        }
        $apiKeyId = $request->attributes->get('api_key_id');
        $claim = $this->claimQuote((string) $input['quote_id'], $workspaceId, $idempotencyKey, $apiKeyId, 'ugc', $this->credits);
        if ($claim instanceof JsonResponse) {
            return $claim;
        }
        /** @var ApiQuote $quote */
        $quote = $claim['quote'];
        if (array_key_exists('replay', $claim)) {
            return $this->takesResponse($quote, 200);
        }

        $f = $quote->payload_json;
        $common = [
            'format' => $f['format'], 'segments' => $f['segments_normalised'], 'request_id' => $f['request_id'],
            'script' => UgcPlan::script($f['segments_normalised']), 'language' => $f['language'] ?? 'en',
            'title' => $f['title'] ?? null, 'consent' => true, 'reviewed' => true,
            'product_asset_id' => $f['product_asset_id'] ?? null,
        ];
        if ($f['mode'] === 'composed') {
            $payload = $common + [
                'character_ids' => $f['character_ids'] ?? [], 'variants' => $f['variants'] ?? [],
                'aspect_ratio' => $f['aspect_ratio'] ?? '9:16', 'voice_key' => $f['voice_key'] ?? null,
                'credits_per_character' => $f['pricing']['credits_per_character'],
            ];
            $result = $this->delegate($request, fn (AppUgcController $c, Request $r) => $c->generate($r), $payload);
        } else {
            $payload = $common + [
                'character_id' => $f['character_id'] ?? null, 'cast_style' => $f['cast_style'] ?? 'exact', 'fidelity' => $f['fidelity'] ?? 'standard',
                'quality' => $f['quality'] ?? 'full', 'presenter_description' => $f['presenter_description'] ?? null,
                'product_asset_ids' => $f['product_asset_ids'] ?? [], 'demo_asset_id' => $f['demo_asset_id'] ?? null,
                'setting' => $f['setting'] ?? null, 'product' => $f['product'] ?? null, 'tone' => $f['tone'] ?? null,
                'credits' => $quote->credits_max,
            ];
            $result = $this->delegate($request, fn (AppUgcController $c, Request $r) => $c->generateOneShot($r, $this->credits), $payload);
        }

        if ($result instanceof JsonResponse) {
            // The dashboard path refused (gate, takes, changed estimate…):
            // nothing was made, the quote is the user's again.
            $this->releaseQuote($quote);

            return $result;
        }

        $ids = array_values(array_filter(array_map(fn ($t) => (int) ($t['id'] ?? 0), $result['takes'] ?? [])));
        if ($ids) {
            Project::query()->whereIn('id', $ids)->where('workspace_id', $workspaceId)->update(['api_key_id' => $apiKeyId]);
            $quote->forceFill(['project_id' => $ids[0]])->save();
        }
        $quote->forceFill(['payload_json' => $f + ['project_ids' => $ids, 'run_id' => $result['run_id'] ?? null]])->save();

        return $this->takesResponse($quote->fresh(), 202);
    }

    /** Takes used and remaining this month, and Test Pass reservations. */
    public function allowance(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        return response()->json(['data' => $this->allowanceFor((int) $user->workspace_id), 'meta' => []]);
    }

    // ── internals ─────────────────────────────────────────────────────────

    /** @return array{plan: string, cap: ?int, used: int, remaining: ?int, max_per_run: int} */
    private function allowanceFor(int $workspaceId): array
    {
        $tier = $this->credits->planTier($workspaceId);
        $cap = $this->credits->limitFor($workspaceId, 'ugc_takes_month');
        $used = $tier === 'ugc_pass'
            ? AppUgcController::passTakesUsed($workspaceId)
            : Project::query()->where('workspace_id', $workspaceId)->whereNotNull('visual_brief->ugc_format')->where('created_at', '>=', now()->startOfMonth())->count();
        $cap = $cap !== null ? (int) $cap : null;

        return ['plan' => $tier, 'ugc_enabled' => (bool) $this->credits->limitFor($workspaceId, 'ugc_ads'), 'cap' => $cap, 'used' => $used,
            'remaining' => $cap === null ? null : max(0, $cap - $used), 'max_per_run' => 10, 'max_seconds_per_take' => $tier === 'ugc_pass' ? 15 : 180];
    }

    private function takesResponse(ApiQuote $quote, int $status): JsonResponse
    {
        $f = $quote->payload_json;
        $ids = $f['project_ids'] ?? ($quote->project_id ? [(int) $quote->project_id] : []);
        $videos = Project::query()->whereIn('id', $ids)->orderBy('id')->get()->map(fn (Project $p) => [
            'id' => $p->getKey(), 'status' => $p->status === 'failed' ? 'failed' : ($p->status === 'ready_for_review' ? 'exporting' : 'generating'),
            'title' => $p->title, 'project_url' => $this->projectUrl($p),
        ])->values();

        return response()->json(['data' => [
            'quote_id' => $quote->getKey(), 'run_id' => $f['run_id'] ?? null, 'videos' => $videos,
            'credits' => ['authorized_max' => $quote->credits_max],
            'next' => 'Poll get_video_status for each video id; fetch each with get_video_result when completed.',
        ], 'meta' => []], $status);
    }

    /**
     * Run a dashboard UGC action as this user with the given payload.
     * Returns the decoded `data` on success, or the dashboard's own error
     * response (status kept) so nothing is lost in translation.
     *
     * @return array<string, mixed>|JsonResponse
     */
    private function delegate(Request $outer, callable $action, array $payload): array|JsonResponse
    {
        $inner = Request::create('/internal/ugc', 'POST', $payload);
        $inner->headers->set('Accept', 'application/json');
        $inner->setUserResolver(fn () => $outer->user());
        $inner->attributes->set('api_key_id', $outer->attributes->get('api_key_id'));
        try {
            $response = $action(app(AppUgcController::class), $inner);
        } catch (ValidationException $e) {
            return $this->fail('validation_failed', collect($e->errors())->flatten()->first() ?? 'Invalid request.', 422, ['errors' => $e->errors()]);
        }
        $status = $response->getStatusCode();
        $body = $response->getData(true);
        if ($status >= 400) {
            $err = $body['error'] ?? ['code' => 'ugc_refused', 'message' => 'The request was refused.'];

            return $this->fail((string) ($err['code'] ?? 'ugc_refused'), (string) ($err['message'] ?? ''), $status, array_filter(['context' => $err['context'] ?? null]));
        }

        return $body['data'] ?? [];
    }
}
