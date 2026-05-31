<?php

namespace App\Http\Controllers\Api\V1\Character;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Character;
use App\Models\User;
use App\Services\CreditService;
use App\Services\Generation\Image\CharacterImageAdapter;
use App\Services\Generation\Image\DalleImageAdapter;
use App\Services\Media\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Throwable;

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
     * Generate a test/preview image of a character. Two paths:
     *  - Character has a reference asset → route through CharacterImageAdapter
     *    (gpt-image-2 /edits with the reference photo). The new image preserves
     *    identity from the reference while following the prompt's scene.
     *  - Character has NO reference asset → route through DalleImageAdapter
     *    (gpt-image-1 text-only generation). The user can then optionally
     *    promote the result to the character's reference photo.
     *
     * Charges credits per CreditService::AI_CHARACTER (with ref) or
     * CreditService::AI_MEDIUM (without ref). Stored as an Asset tagged
     * 'character_preview' for later use.
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

        $style        = (string) ($validated['style'] ?? 'photorealistic');
        $aspectRatio  = (string) ($validated['aspect_ratio'] ?? '9:16');
        $quality      = (string) ($validated['quality'] ?? ($hasReference ? 'high' : 'medium'));

        $options = [
            'usage_context' => [
                'workspace_id' => $user->workspace_id,
                'user_id'      => $user->getKey(),
                'character_id' => $character->getKey(),
                'style'        => $style,
            ],
            'quality' => $quality,
        ];

        try {
            if ($hasReference) {
                // Adapter needs a fetchable URL — generate a signed one for the reference asset.
                $character->loadMissing('referenceAsset');
                $referenceUrl = $this->assetUrl($character->referenceAsset);
                if (! $referenceUrl) {
                    return $this->error('invalid_reference', 'Character reference image is missing or unreadable.', 422);
                }
                $options['reference_image_url'] = $referenceUrl;
                $result = app(CharacterImageAdapter::class)->generate(
                    $validated['prompt'],
                    $style,
                    $aspectRatio,
                    $options
                );
            } else {
                // Build a more directed prompt for the no-reference path so DALL-E
                // produces a clean portrait that can serve as a future reference.
                $promptWithCharacter = trim($character->description
                    ? "Portrait of {$character->name}: {$character->description}. {$validated['prompt']}"
                    : "Portrait of {$character->name}. {$validated['prompt']}");
                $result = app(DalleImageAdapter::class)->generate(
                    $promptWithCharacter,
                    $style,
                    $aspectRatio,
                    $options
                );
            }
        } catch (Throwable $e) {
            return $this->error('generation_failed', $e->getMessage(), 502);
        }

        // Persist the generated image to storage and create an Asset row.
        // Anything that throws inside here (storage write, DB insert) used to
        // bubble up as an unhandled 500 — caller saw "Server Error" with no
        // explanation. Catch + log + return a useful message instead.
        try {
            $asset = $this->storeGeneratedImageAsAsset($user, $character, $result, $style);
        } catch (Throwable $e) {
            Log::error('CharacterController::generateImage storage failed', [
                'character_id' => $character->getKey(),
                'error'        => $e->getMessage(),
                'trace'        => $e->getTraceAsString(),
            ]);
            return $this->error('storage_failed', 'Storage failed: '.$e->getMessage(), 500);
        }
        if (! $asset) {
            Log::error('CharacterController::generateImage storage returned null', [
                'character_id' => $character->getKey(),
                'result_keys'  => array_keys($result),
            ]);
            return $this->error('storage_failed', 'Generated image could not be stored. Check api logs for details.', 500);
        }

        // Charge credits only on success — same pattern as scene regeneration.
        $credits->deduct((int) $user->workspace_id, $cost, 'character_preview');

        // Optional: immediately promote to reference.
        try {
            if (! empty($validated['set_as_reference']) && $validated['set_as_reference']) {
                $character->update([
                    'reference_asset_id' => $asset->getKey(),
                    'reference_asset_ids' => array_values(array_unique(array_merge(
                        [$asset->getKey()],
                        is_array($character->reference_asset_ids) ? $character->reference_asset_ids : []
                    ))),
                ]);
            }
        } catch (Throwable $e) {
            Log::warning('CharacterController::generateImage set_as_reference failed', [
                'character_id' => $character->getKey(),
                'asset_id'     => $asset->getKey(),
                'error'        => $e->getMessage(),
            ]);
            // Image is still stored; just the reference promotion failed. Don't
            // 500 the whole request — return the image so the user can promote
            // manually via the edit modal.
        }

        $character->load('referenceAsset')->loadCount('scenes');

        return response()->json([
            'data' => [
                'character' => $this->serialize($character),
                'image' => [
                    'asset_id'      => $asset->getKey(),
                    'storage_url'   => $this->assetUrl($asset),
                    'mime_type'     => $asset->mime_type,
                    'width'         => $result['width']  ?? null,
                    'height'        => $result['height'] ?? null,
                    'provider_key'  => $result['provider_key'] ?? null,
                    'with_reference'=> $hasReference,
                    'set_as_reference' => ! empty($validated['set_as_reference']),
                ],
                'credits_charged' => $cost,
                'balance_after'   => $credits->balance((int) $user->workspace_id),
            ],
            'meta' => [],
        ], 201);
    }

    /**
     * Download (or decode) the adapter's returned image and store it as an Asset
     * in the workspace, tagged so we can find character previews later. Throws
     * a descriptive RuntimeException on any failure so the caller can surface
     * the actual reason; returning null is reserved for "bytes weren't usable."
     */
    private function storeGeneratedImageAsAsset(User $user, Character $character, array $result, string $style): ?Asset
    {
        // The adapter returns either a public image URL or a base64 payload.
        $imageBytes = null;
        if (! empty($result['image_b64'])) {
            $imageBytes = base64_decode($result['image_b64'], true);
        } elseif (! empty($result['image_url'])) {
            $fetched = Http::timeout(60)->get($result['image_url']);
            if (! $fetched->successful()) {
                throw new \RuntimeException("Could not download generated image (HTTP {$fetched->status()}).");
            }
            $imageBytes = $fetched->body();
        }
        if ($imageBytes === null || $imageBytes === false || $imageBytes === '') {
            return null; // caller turns this into a clean 500 message
        }

        $storage  = app(StorageService::class);
        $filename = 'characters/'.$character->getKey().'/preview-'.now()->format('Ymd-His').'-'.bin2hex(random_bytes(4)).'.png';
        // Let exceptions bubble — caller catches Throwable and includes the
        // message in the 500 response so storage misconfig is visible, not
        // swallowed as "Image generation failed."
        $stored = $storage->put($filename, $imageBytes, ['ContentType' => 'image/png']);

        return Asset::query()->create([
            'workspace_id'         => $user->workspace_id,
            'channel_id'           => null,
            'asset_type'           => 'image',
            'title'                => "Character preview — {$character->name}",
            'description'          => 'Generated character preview',
            'storage_url'          => $stored,
            'thumbnail_url'        => $stored,
            'mime_type'            => 'image/png',
            'dimensions_json'      => [
                'width'  => (int) ($result['width']  ?? 1024),
                'height' => (int) ($result['height'] ?? 1536),
            ],
            'tags'                 => ['character_preview', $character->name, $style],
            'transcription_status' => 'not_requested',
            'restriction_scope'    => 'workspace',
            'usage_count'          => 0,
            'status'               => 'active',
            'created_by_user_id'   => $user->getKey(),
        ]);
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
