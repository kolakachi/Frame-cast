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
            'reference' => ['nullable', 'array'],
            'reference.shape' => ['nullable', 'string', 'max:300'],
            'reference.beats' => ['nullable', 'array', 'max:8'],
            'reference.beats.*.role' => ['required', 'string', 'max:24'],
            'reference.beats.*.does' => ['nullable', 'string', 'max:300'],
            'reference.beats.*.on_screen' => ['nullable', 'string', 'max:300'],
            'reference.beats.*.start' => ['numeric'],
            'reference.beats.*.end' => ['numeric'],
            'variants_count' => ['nullable', 'integer', 'min:2', 'max:6'],
        ]);

        $payload = array_filter([
            'script' => $input['script'] ?? null, 'context' => $input['context'] ?? null, 'product' => $input['product'] ?? null,
            'format' => $input['format'], 'duration_seconds' => $input['duration_seconds'], 'language' => $input['language'] ?? null,
            'footage_asset_ids' => $input['footage_asset_ids'] ?? null, 'reference' => $input['reference'] ?? null,
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

    public function reference(Request $request): JsonResponse
    {
        $input = $this->validated($request, ['asset_id' => ['required', 'integer', 'min:1']]);
        $result = $this->delegate($request, fn (AppUgcController $c, Request $r) => $c->reference($r, app(\App\Services\Ugc\UgcReference::class)), $input);
        return $result instanceof JsonResponse ? $result : response()->json(['data' => $result, 'meta' => ['scope' => 'UGC planning inspiration; not My Footage recreation']]);
    }

    public function previewQuote(Request $request, int $characterId): JsonResponse
    {
        $input = $this->validated($request, ['consent' => ['required', 'boolean', 'accepted']]);
        $character = $this->previewCharacter($request, $characterId);
        if (! $character) return $this->fail('not_found', 'Character not found.', 404);
        if (! $this->credits->limitFor((int) $request->user()->workspace_id, 'ugc_ads')) return $this->fail('upgrade_required', 'UGC requires a paid plan.', 402);
        $cost = app(\App\Services\Generation\Image\ImageAdapterFactory::class)->generationCost('gpt-image-2');
        $quote = ApiQuote::query()->create([
            'id' => ApiQuote::newId(), 'workspace_id' => $request->user()->workspace_id,
            'api_key_id' => $request->attributes->get('api_key_id'), 'created_by_user_id' => $request->user()->id,
            'payload_json' => ['__kind' => 'ugc_preview', 'character_id' => $characterId, 'consent' => true, 'character_revision' => $this->previewRevision($character)],
            'credits_min' => $cost, 'credits_max' => $cost, 'expires_at' => now()->addMinutes(ApiQuote::TTL_MINUTES),
        ]);
        return response()->json(['data' => ['quote_id' => $quote->id, 'character_id' => $characterId,
            'credits' => ['min' => $cost, 'max' => $cost], 'expires_at' => $quote->expires_at->toIso8601String(),
            'disclosure' => 'A generated presenter inspired by the character description. This is not a final video frame or guaranteed identity match.'], 'meta' => []], 201);
    }

    public function previewCreate(Request $request, int $characterId): JsonResponse
    {
        $input = $this->validated($request, ['quote_id' => ['required', 'string', 'max:32'], 'idempotency_key' => ['nullable', 'string', 'max:128']]);
        $key = $this->idempotencyKeyFrom($request, $input);
        if (! $key) return $this->fail('idempotency_key_required', 'Send an idempotency key.', 422);
        $claim = $this->claimQuote($input['quote_id'], (int) $request->user()->workspace_id, $key,
            $request->attributes->get('api_key_id'), 'ugc_preview', $this->credits, ['character_id' => $characterId]);
        if ($claim instanceof JsonResponse) return $claim;
        $quote = $claim['quote'];
        if (array_key_exists('replay', $claim)) return $this->previewResult($request, $quote);
        $character = $this->previewCharacter($request, $characterId);
        if (! $character || $this->previewRevision($character) !== $quote->payload_json['character_revision']) {
            $this->releaseQuote($quote);
            return $this->fail('character_changed', 'Character changed after approval. Request a new preview quote.', 409);
        }
        if (! $this->credits->limitFor((int) $request->user()->workspace_id, 'ugc_ads')) {
            $this->releaseQuote($quote);
            return $this->fail('upgrade_required', 'UGC requires a paid plan.', 402);
        }
        $result = $this->delegate($request, fn (AppUgcController $c, Request $r) => $c->variantPreview($r, $characterId), []);
        if ($result instanceof JsonResponse) { $this->releaseQuote($quote); return $result; }
        $quote->forceFill(['project_id' => $result['asset_id'], 'payload_json' => $quote->payload_json + ['result' => $result]])->save();
        return $this->previewResult($request, $quote);
    }

    private function previewResult(Request $request, ApiQuote $quote): JsonResponse
    {
        $result = $quote->payload_json['result'];
        $asset = Asset::query()->whereKey($result['asset_id'])->where('workspace_id', $request->user()->workspace_id)->first();
        if (! $asset) return $this->fail('preview_unavailable', 'The preview asset is no longer available; it will not be regenerated automatically.', 410);
        $result['preview_url'] = \Illuminate\Support\Facades\URL::temporarySignedRoute('media.assets.content', now()->addMinutes(60), ['assetId' => $asset->id]);
        return response()->json(['data' => $result + ['identity_match_guaranteed' => false, 'quote_id' => $quote->id], 'meta' => []]);
    }

    private function previewCharacter(Request $request, int $id): ?Character
    {
        return Character::query()->whereKey($id)->where('status', 'active')->where(fn ($q) => $q->where('workspace_id', $request->user()->workspace_id)->orWhere(fn ($s) => $s->whereNull('workspace_id')->where('is_stock', true)))->first();
    }

    private function previewRevision(Character $character): string
    {
        return hash('sha256', json_encode([$character->name, $character->description, $character->reference_asset_id, $character->reference_asset_ids, $character->appearance_json]));
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

        $unsupported = $input['mode'] === 'composed'
            ? ['character_id', 'cast_style', 'fidelity', 'quality', 'presenter_description', 'product_asset_ids', 'demo_asset_id', 'setting', 'product', 'tone']
            : ['character_ids', 'variants', 'voice_key'];
        foreach ($unsupported as $field) {
            if (isset($input[$field]) && $input[$field] !== []) return $this->fail('unsupported_mode_setting', "{$field} is not used by {$input['mode']} UGC. Remove it.", 422);
        }
        if (isset($input['fidelity'])) return $this->fail('unsupported_mode_setting', 'Fidelity selection is not implemented by the current one-shot renderer. The quote reports its resolved engine.', 422);
        if ($input['mode'] === 'one_shot' && isset($input['aspect_ratio']) && $input['aspect_ratio'] !== '9:16') return $this->fail('unsupported_mode_setting', 'One-shot UGC currently renders 9:16 only.', 422);
        if (isset($input['voice_key']) && ! array_key_exists($input['voice_key'], \App\Services\Generation\TTS\GeminiVoices::VOICES)) return $this->fail('unsupported_voice', 'Composed UGC supports Gemini catalogue voices only; cloned voices are not supported in this workflow.', 422);
        foreach ($input['product_asset_ids'] ?? [] as $aid) {
            if (! Asset::query()->whereKey($aid)->where('workspace_id', $workspaceId)->where('asset_type', 'image')->exists()) return $this->fail('invalid_asset', 'Product references must be workspace images.', 422);
        }

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

        $chosen = ['mode' => $input['mode'], 'aspect_ratio' => $input['aspect_ratio'] ?? '9:16', 'language' => $input['language'] ?? 'en', 'product_asset_ids' => array_values(array_unique(array_filter(array_merge($input['product_asset_ids'] ?? [], [$input['product_asset_id'] ?? null]))))];
        if ($input['mode'] === 'composed') {
            $chosen['voices_by_character'] = Character::query()->whereIn('id', $input['character_ids'] ?? [])->get()->map(fn (Character $c) => ['character_id' => $c->id, 'voice_key' => $input['voice_key'] ?? \App\Services\Generation\TTS\GeminiVoices::defaultForGender($c->gender)])->all();
            $chosen['voice'] = ['type' => 'gemini_tts', 'key' => $input['voice_key'] ?? null, 'default' => 'Per-character gender default when no key is supplied', 'clone_supported' => false];
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
            if (($input['quality'] ?? 'full') === 'draft' && ! $one['draft']) return $this->fail('unsupported_mode_setting', 'Draft quality is unavailable for the resolved engine or demo-embed route.', 422);
            $chosen['voice'] = ['type' => 'native_speech', 'clone_supported' => false];
            $chosen['engine'] = $one['engine'];
            $chosen['quality'] = $one['draft'] ? 'draft' : 'full';
            $chosen['presenter_reference_used'] = $one['presenter_attached'];
            $total = $one['quote'];
            $takes = 1;
            $pricing = ['engine' => $one['engine'], 'plan_seconds' => $one['plan_seconds'], 'draft' => $one['draft'], 'takes' => 1, 'presenter_reference_used' => $one['presenter_attached']];
        }

        $allowance = $this->allowanceFor($workspaceId);
        if (! $allowance['ugc_enabled']) return $this->fail('upgrade_required', 'UGC requires a paid plan.', 402);
        if ($takes > $allowance['max_per_run']) return $this->fail('too_many_takes', 'A run supports at most ten takes. Reduce cast or variants.', 422);
        if ($allowance['remaining'] !== null && $takes > $allowance['remaining']) {
            return $this->fail('takes_exhausted',
                "This run needs {$takes} take(s) and {$allowance['remaining']} remain this month on the {$allowance['plan']} plan.",
                402, $allowance + ['takes_needed' => $takes]);
        }

        $frozen = $input + ['__kind' => 'ugc', 'segments_normalised' => $segments, 'request_id' => (string) Str::uuid(), 'pricing' => $pricing, 'chosen' => $chosen];
        $quote = ApiQuote::query()->create([
            'id' => ApiQuote::newId(), 'workspace_id' => $workspaceId, 'api_key_id' => $request->attributes->get('api_key_id'),
            'created_by_user_id' => $user->getKey(), 'payload_json' => $frozen,
            'credits_min' => $total, 'credits_max' => $total, 'expires_at' => now()->addMinutes(ApiQuote::TTL_MINUTES),
        ]);
        $balance = $this->credits->balance($workspaceId);

        return response()->json(['data' => [
            'quote_id' => $quote->getKey(), 'mode' => $input['mode'], 'format' => $input['format'],
            'credits' => ['min' => $total, 'max' => $total] + $pricing,
            'chosen' => $chosen, 'script' => UgcPlan::script($segments), 'takes' => $takes, 'allowance' => $allowance,
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
            return $this->takesResponse($request, $quote, 200);
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

        return $this->takesResponse($request, $quote->fresh(), 202);
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

    private function takesResponse(Request $request, ApiQuote $quote, int $status): JsonResponse
    {
        $f = $quote->payload_json;
        $ids = $f['project_ids'] ?? ($quote->project_id ? [(int) $quote->project_id] : []);
        $videos = Project::query()->where('workspace_id', $quote->workspace_id)->whereIn('id', $ids)->orderBy('id')->get()
            ->map(fn (Project $p) => app(VideoController::class)->show($request, (int) $p->id)->getData(true)['data']['video'])->values();

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
