<?php

namespace App\Http\Controllers\Api\V1\Character;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\Character;
use App\Models\User;
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
