<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Http\Controllers\Api\V1\Character\CharacterController as AppCharacterController;
use App\Models\ApiQuote;
use App\Models\Character;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Characters through the developer API: create, update, and quote-bound
 * image generation with polling. Delegated to the app's CharacterController
 * with an internal request, so plan limits, the custom-characters gate,
 * consent and reference ownership are exactly the dashboard's. Listing is
 * LookupController; deletion stays out.
 */
class CharacterController extends DeveloperController
{
    use ClaimsQuotes;

    public function __construct(private readonly CreditService $credits)
    {
    }

    public function store(Request $request): JsonResponse
    {
        $input = $this->validated($request, [
            'name' => ['required', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reference_asset_ids' => ['nullable', 'array', 'max:8'],
            'reference_asset_ids.*' => ['integer'],
            'consistency_method' => ['nullable', Rule::in(['quick', 'lora'])],
            'identity_strength' => ['nullable', Rule::in(['subtle', 'balanced', 'strong', 'locked'])],
            'consent' => ['nullable', 'boolean'],
        ]);
        if (! empty($input['reference_asset_ids']) && empty($input['consent'])) {
            return $this->fail('consent_required',
                'Creating a character from reference photos needs the user to confirm they have the rights and consent to use that person\'s likeness. Ask, then pass consent: true.', 422);
        }
        $result = $this->delegate($request, fn (AppCharacterController $c, Request $r) => $c->store($r), array_filter($input, fn ($v) => $v !== null));

        return $result instanceof JsonResponse ? $result : response()->json(['data' => ['character' => $this->shape($result['character'] ?? [])], 'meta' => []], 201);
    }

    public function update(Request $request, int $characterId): JsonResponse
    {
        $input = $this->validated($request, [
            'consent' => ['nullable', 'boolean'],
            'name' => ['nullable', 'string', 'max:120'],
            'description' => ['nullable', 'string', 'max:2000'],
            'reference_asset_ids' => ['nullable', 'array', 'max:8'],
            'reference_asset_ids.*' => ['integer'],
            'consistency_method' => ['nullable', Rule::in(['quick', 'lora'])],
            'identity_strength' => ['nullable', Rule::in(['subtle', 'balanced', 'strong', 'locked'])],
        ]);
        $result = $this->delegate($request, fn (AppCharacterController $c, Request $r) => $c->update($r, $characterId), array_filter($input, fn ($v) => $v !== null));

        return $result instanceof JsonResponse ? $result : response()->json(['data' => ['character' => $this->shape($result['character'] ?? [])], 'meta' => []]);
    }

    /** Free: price an image for this character and freeze the request. */
    public function imageQuote(Request $request, int $characterId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;
        $character = Character::query()->whereKey($characterId)->where('workspace_id', $workspaceId)->first();
        if (! $character) {
            return $this->fail('not_found', 'Character not found in this workspace.', 404);
        }
        $input = $this->validated($request, [
            'prompt' => ['required', 'string', 'max:2000'],
            'style' => ['nullable', Rule::in(LookupController::visualStyles())],
            'model_key' => ['nullable', Rule::in(['nano-banana-pro', 'nano-banana', 'gpt-image-2', 'gpt-image-1'])],
            'aspect_ratio' => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'quality' => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'set_as_reference' => ['nullable', 'boolean'],
        ]);
        // Same arithmetic as CharacterController::generateImage.
        $hasReference = (bool) $character->reference_asset_id;
        $cost = $hasReference ? app(ImageAdapterFactory::class)->referenceGenerationCost($input['model_key'] ?? null) : CreditService::AI_MEDIUM;

        $quote = ApiQuote::query()->create([
            'id' => ApiQuote::newId(), 'workspace_id' => $workspaceId, 'api_key_id' => $request->attributes->get('api_key_id'),
            'created_by_user_id' => $user->getKey(),
            'payload_json' => array_filter($input, fn ($v) => $v !== null) + ['__kind' => 'character_image', 'character_id' => $character->getKey()],
            'credits_min' => $cost, 'credits_max' => $cost, 'expires_at' => now()->addMinutes(ApiQuote::TTL_MINUTES),
        ]);
        $balance = $this->credits->balance($workspaceId);

        return response()->json(['data' => [
            'quote_id' => $quote->getKey(), 'character' => ['id' => $character->getKey(), 'name' => $character->name],
            'credits' => ['min' => $cost, 'max' => $cost, 'with_reference' => $hasReference],
            'balance' => $balance, 'can_afford' => $balance >= $cost, 'shortage' => max(0, $cost - $balance),
            'expires_at' => $quote->expires_at->toIso8601String(), 'request' => $input,
        ], 'meta' => []], 201);
    }

    /** Spends credits: generate the quoted image. */
    public function imageCreate(Request $request, int $characterId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;
        $input = $this->validated($request, ['quote_id' => ['required', 'string', 'max:32'], 'idempotency_key' => ['nullable', 'string', 'max:128']]);
        $idempotencyKey = $this->idempotencyKeyFrom($request, $input);
        if ($idempotencyKey === null) {
            return $this->fail('idempotency_key_required', 'Send an idempotency_key (or Idempotency-Key header).', 422);
        }
        $claim = $this->claimQuote((string) $input['quote_id'], $workspaceId, $idempotencyKey, $request->attributes->get('api_key_id'), 'character_image', $this->credits, ['character_id' => $characterId]);
        if ($claim instanceof JsonResponse) {
            return $claim;
        }
        /** @var ApiQuote $quote */
        $quote = $claim['quote'];
        $f = $quote->payload_json;
        if (array_key_exists('replay', $claim)) {
            return $this->generationResponse($request, (int) ($f['generation_id'] ?? 0), 200);
        }

        $payload = array_intersect_key($f, array_flip(['prompt', 'style', 'model_key', 'aspect_ratio', 'quality', 'set_as_reference']));
        $result = $this->delegate($request, fn (AppCharacterController $c, Request $r) => $c->generateImage($r, $characterId), $payload);
        if ($result instanceof JsonResponse) {
            $this->releaseQuote($quote);

            return $result;
        }
        $generationId = (int) ($result['generation']['id'] ?? 0);
        // The quote's "project_id" slot points at the generation, so a replay can find it.
        $quote->forceFill(['project_id' => $generationId, 'payload_json' => $f + ['generation_id' => $generationId]])->save();

        return $this->generationResponse($request, $generationId, 202);
    }

    public function imageStatus(Request $request, int $characterId, int $generationId): JsonResponse
    {
        return $this->generationResponse($request, $generationId, 200);
    }

    // ── internals ─────────────────────────────────────────────────────────

    private function generationResponse(Request $request, int $generationId, int $status): JsonResponse
    {
        $result = $this->delegate($request, fn (AppCharacterController $c, Request $r) => $c->generationStatus($r, $generationId), []);
        if ($result instanceof JsonResponse) {
            return $result;
        }
        $g = $result['generation'] ?? [];
        $state = match ($g['status'] ?? '') { 'succeeded' => 'completed', 'failed' => 'failed', default => 'generating' };

        return response()->json(['data' => ['generation' => [
            'id' => $g['id'] ?? $generationId, 'character_id' => $g['character_id'] ?? null, 'status' => $state,
            'prompt' => $g['prompt'] ?? null, 'style' => $g['style'] ?? null, 'aspect_ratio' => $g['aspect_ratio'] ?? null,
            'set_as_reference' => (bool) ($g['set_as_reference'] ?? false), 'credits_charged' => $g['credits_charged'] ?? null,
            'failure' => $state === 'failed' ? ['message' => $g['error_message'] ?? 'Generation failed.'] : null,
            'image' => $g['image'] ?? null,
            'retry_after_seconds' => $state === 'generating' ? 10 : null,
        ]], 'meta' => []], $status);
    }

    /** @param array<string, mixed> $c */
    private function shape(array $c): array
    {
        return array_intersect_key($c, array_flip(['id', 'name', 'description', 'gender', 'age_group', 'situations', 'consistency_method', 'identity_strength', 'status', 'reference_asset_id', 'reference_asset_ids', 'reference_asset']));
    }

    /** @return array<string, mixed>|JsonResponse */
    private function delegate(Request $outer, callable $action, array $payload): array|JsonResponse
    {
        $inner = Request::create('/internal/characters', 'POST', $payload);
        $inner->headers->set('Accept', 'application/json');
        $inner->setUserResolver(fn () => $outer->user());
        $inner->attributes->set('api_key_id', $outer->attributes->get('api_key_id'));
        try {
            $response = $action(app(AppCharacterController::class), $inner);
        } catch (ValidationException $e) {
            return $this->fail('validation_failed', collect($e->errors())->flatten()->first() ?? 'Invalid request.', 422, ['errors' => $e->errors()]);
        }
        $status = $response->getStatusCode();
        $body = $response->getData(true);
        if ($status >= 400) {
            $err = $body['error'] ?? ['code' => 'refused', 'message' => 'The request was refused.'];

            return $this->fail((string) ($err['code'] ?? 'refused'), (string) ($err['message'] ?? ''), $status, array_filter(['context' => $err['context'] ?? null]));
        }

        return $body['data'] ?? [];
    }
}
