<?php

namespace App\Http\Controllers\Api\V1\Asset;

use App\Http\Controllers\Controller;
use App\Models\Collection;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $collections = Collection::query()
            ->where('workspace_id', $user->workspace_id)
            ->orderBy('name')
            ->get();

        return response()->json([
            'data' => [
                'collections' => $collections->map(fn (Collection $collection): array => $this->serializeCollection($collection))->all(),
            ],
            'meta' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
        ]);

        $collection = Collection::query()->create([
            'workspace_id' => $user->workspace_id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
        ]);

        return response()->json([
            'data' => [
                'collection' => $this->serializeCollection($collection),
            ],
            'meta' => [],
        ], 201);
    }

    public function update(Request $request, int $collectionId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $collection = $this->findForUser($user, $collectionId);

        if (! $collection) {
            return $this->error('not_found', 'Collection not found.', 404);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
        ]);

        $collection->fill($validated)->save();

        return response()->json([
            'data' => [
                'collection' => $this->serializeCollection($collection->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $collectionId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $collection = $this->findForUser($user, $collectionId);

        if (! $collection) {
            return $this->error('not_found', 'Collection not found.', 404);
        }

        $collection->delete();

        return response()->json([
            'data' => [
                'deleted' => true,
            ],
            'meta' => [],
        ]);
    }

    private function findForUser(User $user, int $collectionId): ?Collection
    {
        return Collection::query()
            ->whereKey($collectionId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
    }

    /**
     * @return array{id:int,workspace_id:int,name:string,description:?string,created_at:?string,updated_at:?string}
     */
    private function serializeCollection(Collection $collection): array
    {
        return [
            'id' => $collection->getKey(),
            'workspace_id' => $collection->workspace_id,
            'name' => $collection->name,
            'description' => $collection->description,
            'created_at' => $collection->created_at?->toIso8601String(),
            'updated_at' => $collection->updated_at?->toIso8601String(),
        ];
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
