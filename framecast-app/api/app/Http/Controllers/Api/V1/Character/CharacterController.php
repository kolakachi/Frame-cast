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
            'name'               => ['required', 'string', 'max:120'],
            'description'        => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reference_asset_id' => ['sometimes', 'nullable', 'integer'],
            'consistency_method' => ['sometimes', Rule::in(['quick', 'lora'])],
        ]);

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
            'consistency_method'  => $validated['consistency_method'] ?? 'quick',
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
            'name'               => ['sometimes', 'string', 'max:120'],
            'description'        => ['sometimes', 'nullable', 'string', 'max:2000'],
            'reference_asset_id' => ['sometimes', 'nullable', 'integer'],
            'consistency_method' => ['sometimes', Rule::in(['quick', 'lora'])],
        ]);

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
        return [
            'id'                 => $c->getKey(),
            'workspace_id'       => $c->workspace_id,
            'name'               => $c->name,
            'description'        => $c->description,
            'consistency_method' => $c->consistency_method,
            'status'             => $c->status,
            'scenes_count'       => (int) ($c->scenes_count ?? 0),
            'reference_asset'    => $c->referenceAsset ? [
                'id'            => $c->referenceAsset->getKey(),
                // Browser-accessible URLs: signed Laravel route for stored paths, pass-through for external HTTP URLs.
                'storage_url'   => $this->assetUrl($c->referenceAsset),
                'thumbnail_url' => $this->assetUrl($c->referenceAsset),
                'mime_type'     => $c->referenceAsset->mime_type ?? null,
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
