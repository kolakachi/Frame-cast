<?php

namespace App\Http\Controllers\Api\V1\Character;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateCharacterImageJob;
use App\Models\Asset;
use App\Models\Character;
use App\Models\CharacterImageGeneration;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Media\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;

class CharacterController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $characters = Character::query()
            ->with('referenceAsset')
            ->withCount('scenes')
            ->where('workspace_id', $user->workspace_id)
            ->where('status', 'active')
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn (Character $c) => $this->serialize($c))
            ->all();

        return response()->json([
            'data' => ['characters' => $characters],
            'meta' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        // ── Plan cap guard ────────────────────────────────────────────────
        // Free tier = 1 character, Starter = 3, etc. (see CreditService::PLAN_LIMITS).
        $maxCharacters = app(CreditService::class)->limitFor((int) $user->workspace_id, 'max_characters');
        if ($maxCharacters !== null) {
            $currentCount = Character::query()
                ->where('workspace_id', $user->workspace_id)
                ->where('status', 'active')
                ->count();
            if ($currentCount >= (int) $maxCharacters) {
                $planTier = app(CreditService::class)->planTier((int) $user->workspace_id);
                return response()->json([
                    'error' => [
                        'code'    => 'plan_resource_cap',
                        'message' => "Your {$planTier} plan supports {$maxCharacters} active character" . ($maxCharacters === 1 ? '' : 's') . ". Delete an existing character or upgrade for more.",
                        'context' => ['plan' => $planTier, 'resource' => 'characters', 'limit' => (int) $maxCharacters, 'current' => $currentCount],
                    ],
                ], 422);
            }
        }

        $validated = $request->validate([
            'name'                  => ['required', 'string', 'max:120'],
            'description'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reference_asset_id'    => ['sometimes', 'nullable', 'integer'],
            'reference_asset_ids'   => ['sometimes', 'nullable', 'array', 'max:8'],
            'reference_asset_ids.*' => ['integer'],
            'consistency_method'    => ['sometimes', Rule::in(['quick', 'lora'])],
            'identity_strength'     => ['sometimes', Rule::in(['subtle', 'balanced', 'strong', 'locked'])],
        ]);

        // Validate every asset id belongs to the workspace.
        $allIds = array_filter(array_unique(array_merge(
            $validated['reference_asset_ids'] ?? [],
            [$validated['reference_asset_id'] ?? null]
        )));
        if (! empty($allIds)) {
            $ownedCount = Asset::query()->whereIn('id', $allIds)
                ->where('workspace_id', $user->workspace_id)->count();
            if ($ownedCount !== count($allIds)) {
                return $this->error('invalid_asset', 'One or more reference images do not belong to this workspace.', 422);
            }
        }
        // Primary defaults to first in the ids array if not explicitly set.
        if (empty($validated['reference_asset_id']) && ! empty($validated['reference_asset_ids'])) {
            $validated['reference_asset_id'] = (int) $validated['reference_asset_ids'][0];
        }

        if (! empty($validated['reference_asset_id'])) {
            $assetOwned = Asset::query()
                ->whereKey($validated['reference_asset_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();

            if (! $assetOwned) {
                return $this->error('invalid_asset', 'Reference image not found in this workspace.', 422);
            }
        }

        $character = Character::query()->create([
            'workspace_id'        => $user->workspace_id,
            'name'                => $validated['name'],
            'description'         => $validated['description'] ?? null,
            'reference_asset_id'  => $validated['reference_asset_id'] ?? null,
            'reference_asset_ids' => $validated['reference_asset_ids'] ?? null,
            'consistency_method'  => $validated['consistency_method'] ?? 'quick',
            'identity_strength'   => $validated['identity_strength'] ?? 'balanced',
            'status'              => 'active',
            'created_by_user_id'  => $user->getKey(),
        ]);

        $character->load('referenceAsset')->loadCount('scenes');

        return response()->json([
            'data' => ['character' => $this->serialize($character)],
            'meta' => [],
        ], 201);
    }

    public function show(Request $request, int $characterId): JsonResponse
    {
        $character = $this->resolve($request, $characterId);
        if (! $character) {
            return $this->error('not_found', 'Character not found.', 404);
        }

        return response()->json([
            'data' => ['character' => $this->serialize($character)],
            'meta' => [],
        ]);
    }

    public function update(Request $request, int $characterId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $character = $this->resolve($request, $characterId);
        if (! $character) {
            return $this->error('not_found', 'Character not found.', 404);
        }

        $validated = $request->validate([
            'name'                  => ['sometimes', 'string', 'max:120'],
            'description'           => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reference_asset_id'    => ['sometimes', 'nullable', 'integer'],
            'reference_asset_ids'   => ['sometimes', 'nullable', 'array', 'max:8'],
            'reference_asset_ids.*' => ['integer'],
            'consistency_method'    => ['sometimes', Rule::in(['quick', 'lora'])],
            'identity_strength'     => ['sometimes', Rule::in(['subtle', 'balanced', 'strong', 'locked'])],
        ]);

        // Validate ownership of any asset ids passed.
        $allIds = array_filter(array_unique(array_merge(
            $validated['reference_asset_ids'] ?? [],
            [$validated['reference_asset_id'] ?? null]
        )));
        if (! empty($allIds)) {
            $ownedCount = Asset::query()->whereIn('id', $allIds)
                ->where('workspace_id', $user->workspace_id)->count();
            if ($ownedCount !== count($allIds)) {
                return $this->error('invalid_asset', 'One or more reference images do not belong to this workspace.', 422);
            }
        }
        // If only ids array changed, keep primary as the first one when not explicitly set.
        if (array_key_exists('reference_asset_ids', $validated)
            && ! array_key_exists('reference_asset_id', $validated)
            && ! empty($validated['reference_asset_ids'])
        ) {
            $validated['reference_asset_id'] = (int) $validated['reference_asset_ids'][0];
        }

        if (array_key_exists('reference_asset_id', $validated) && $validated['reference_asset_id'] !== null) {
            $assetOwned = Asset::query()
                ->whereKey($validated['reference_asset_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();

            if (! $assetOwned) {
                return $this->error('invalid_asset', 'Reference image not found in this workspace.', 422);
            }
        }

        $character->fill($validated);
        $character->save();
        $character->load('referenceAsset')->loadCount('scenes');

        return response()->json([
            'data' => ['character' => $this->serialize($character)],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $characterId): JsonResponse
    {
        $character = $this->resolve($request, $characterId);
        if (! $character) {
            return $this->error('not_found', 'Character not found.', 404);
        }

        // Soft-archive so scenes referencing this character keep working.
        $character->update(['status' => 'archived']);

        return response()->json([
            'data' => ['character' => $this->serialize($character)],
            'meta' => [],
        ]);
    }

    /**
     * Kick off a character image generation. The actual OpenAI call (30-90s
     * for gpt-image-2 /edits at high quality) runs on the generation queue
     * worker — the previous synchronous version exceeded Cloudflare's request
     * timeout. The HTTP request returns immediately with a generation id; the
     * frontend polls `generationStatus()` until it reports succeeded|failed.
     *
     * Adapter routing + credit pricing live in GenerateCharacterImageJob.
     */
    public function generateImage(Request $request, int $characterId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $character = $this->resolve($request, $characterId);
        if (! $character) {
            return $this->error('not_found', 'Character not found.', 404);
        }

        $validated = $request->validate([
            'prompt'        => ['required', 'string', 'max:2000'],
            'style'         => ['nullable', 'string', 'max:64'],
            'aspect_ratio'  => ['nullable', Rule::in(['9:16', '1:1', '16:9'])],
            'quality'       => ['nullable', Rule::in(['low', 'medium', 'high'])],
            'set_as_reference' => ['sometimes', 'boolean'],
        ]);

        $hasReference = (bool) $character->reference_asset_id;
        $cost = $hasReference ? CreditService::AI_CHARACTER : CreditService::AI_MEDIUM;
        $credits = app(CreditService::class);

        // Upfront check so the user sees insufficient_credits immediately rather
        // than after the worker picks up the job.
        $balance = $credits->balance((int) $user->workspace_id);
        if ($balance < $cost) {
            return response()->json([
                'error' => [
                    'code'    => 'insufficient_credits',
                    'message' => "You need {$cost} credits to generate a character preview. Your balance is {$balance}.",
                    'context' => ['balance' => $balance, 'required' => $cost, 'shortage' => $cost - $balance],
                ],
            ], 402);
        }

        $generation = CharacterImageGeneration::query()->create([
            'workspace_id'     => $user->workspace_id,
            'character_id'     => $character->getKey(),
            'user_id'          => $user->getKey(),
            'prompt'           => $validated['prompt'],
            'style'            => $validated['style'] ?? 'photorealistic',
            'aspect_ratio'     => $validated['aspect_ratio'] ?? '9:16',
            'quality'          => $validated['quality'] ?? ($hasReference ? 'high' : 'medium'),
            'set_as_reference' => (bool) ($validated['set_as_reference'] ?? false),
            'status'           => 'queued',
        ]);

        GenerateCharacterImageJob::dispatch($generation->getKey())->onQueue('generation');

        return response()->json([
            'data' => [
                'generation' => $this->serializeGeneration($generation),
            ],
            'meta' => [],
        ], 202);
    }

    /**
     * Poll for the status of a queued character image generation. Frontend
     * hits this every ~2s after POST returns 202.
     */
    public function generationStatus(Request $request, int $generationId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $gen = CharacterImageGeneration::query()
            ->whereKey($generationId)
            ->where('workspace_id', $user->workspace_id)
            ->first();

        if (! $gen) {
            return $this->error('not_found', 'Generation not found.', 404);
        }

        $payload = $this->serializeGeneration($gen);

        // When succeeded, include the result asset details so the frontend can
        // render the image and offer "set as reference" follow-ups without
        // another round-trip.
        if ($gen->status === 'succeeded' && $gen->result_asset_id) {
            $asset = Asset::query()->find($gen->result_asset_id);
            if ($asset) {
                $payload['image'] = [
                    'asset_id'      => $asset->getKey(),
                    'storage_url'   => $this->assetUrl($asset),
                    'mime_type'     => $asset->mime_type,
                    'width'         => $asset->dimensions_json['width']  ?? null,
                    'height'        => $asset->dimensions_json['height'] ?? null,
                    'with_reference'=> (bool) $gen->used_reference,
                    'set_as_reference' => (bool) $gen->set_as_reference,
                ];
            }
        }

        return response()->json([
            'data' => ['generation' => $payload],
            'meta' => [],
        ]);
    }

    private function serializeGeneration(CharacterImageGeneration $gen): array
    {
        return [
            'id'                => $gen->getKey(),
            'character_id'      => $gen->character_id,
            'status'            => $gen->status,
            'prompt'            => $gen->prompt,
            'style'             => $gen->style,
            'aspect_ratio'      => $gen->aspect_ratio,
            'quality'           => $gen->quality,
            'set_as_reference'  => (bool) $gen->set_as_reference,
            'used_reference'    => $gen->used_reference,
            'result_asset_id'   => $gen->result_asset_id,
            'error_message'     => $gen->error_message,
            'credits_charged'   => (int) $gen->credits_charged,
            'started_at'        => $gen->started_at?->toIso8601String(),
            'completed_at'      => $gen->completed_at?->toIso8601String(),
            'created_at'        => $gen->created_at?->toIso8601String(),
        ];
    }

    private function resolve(Request $request, int $characterId): ?Character
    {
        /** @var User $user */
        $user = $request->user();

        return Character::query()
            ->with('referenceAsset')
            ->withCount('scenes')
            ->whereKey($characterId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
    }

    private function serialize(Character $c): array
    {
        // Latest in-progress generation for this character — surfaces on the
        // card so users who close the modal / leave the page can see that a
        // queued or processing image is still on the way, and resume the
        // modal from there. Cheap query: indexed on (character_id, created_at).
        $pendingGen = CharacterImageGeneration::query()
            ->where('character_id', $c->getKey())
            ->whereIn('status', ['queued', 'processing'])
            ->orderByDesc('id')
            ->first();

        // Build the multi-reference list. reference_asset_ids is the ordered set
        // (first = primary). Fall back to the legacy single reference_asset_id when
        // the array hasn't been populated yet.
        $ids = $c->reference_asset_ids ?? [];
        if (empty($ids) && $c->reference_asset_id) {
            $ids = [$c->reference_asset_id];
        }
        $refs = [];
        if (! empty($ids)) {
            $assets = Asset::query()->whereIn('id', $ids)->get()->keyBy('id');
            foreach ($ids as $id) {
                $asset = $assets->get((int) $id);
                if ($asset) {
                    $refs[] = [
                        'id'            => $asset->getKey(),
                        'storage_url'   => $this->assetUrl($asset),
                        'thumbnail_url' => $this->assetUrl($asset),
                        'mime_type'     => $asset->mime_type ?? null,
                    ];
                }
            }
        }

        return [
            'id'                 => $c->getKey(),
            'workspace_id'       => $c->workspace_id,
            'name'               => $c->name,
            'description'        => $c->description,
            'consistency_method' => $c->consistency_method,
            'identity_strength'  => $c->identity_strength ?? 'balanced',
            'status'             => $c->status,
            'scenes_count'       => (int) ($c->scenes_count ?? 0),
            // Primary kept for backward-compat with the editor chip + existing consumers.
            'reference_asset'    => $refs[0] ?? null,
            // Full ordered list of references — first is primary.
            'reference_assets'   => $refs,
            'pending_generation' => $pendingGen ? [
                'id'           => $pendingGen->getKey(),
                'status'       => $pendingGen->status,
                'prompt'       => $pendingGen->prompt,
                'started_at'   => $pendingGen->started_at?->toIso8601String(),
                'created_at'   => $pendingGen->created_at?->toIso8601String(),
            ] : null,
            'created_at'         => $c->created_at?->toIso8601String(),
            'updated_at'         => $c->updated_at?->toIso8601String(),
        ];
    }

    private function assetUrl(?Asset $asset): ?string
    {
        if (! $asset || ! $asset->storage_url) {
            return null;
        }
        // If the stored value is an internal storage path (MinIO/B2), serve through
        // the signed `media.assets.content` route. Plain HTTP URLs pass through.
        $isStoredPath = app(StorageService::class)->extractPath((string) $asset->storage_url) !== null;
        if (! $isStoredPath) {
            return (string) $asset->storage_url;
        }
        return URL::temporarySignedRoute(
            'media.assets.content',
            now()->addMinutes(30),
            ['assetId' => $asset->getKey()],
        );
    }
}
