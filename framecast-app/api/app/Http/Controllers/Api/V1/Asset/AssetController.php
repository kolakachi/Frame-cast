<?php

namespace App\Http\Controllers\Api\V1\Asset;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAssetThumbnailJob;
use App\Models\Asset;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'q' => ['nullable', 'string'],
            'asset_type' => ['nullable', 'string', 'max:64'],
            'collection_id' => ['nullable', 'integer'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', Rule::in([6, 12, 18, 24])],
        ]);

        $perPage = (int) ($validated['per_page'] ?? 12);
        $page = (int) ($validated['page'] ?? 1);

        $query = Asset::query()
            ->where('workspace_id', $user->workspace_id)
            ->when($request->filled('q'), function ($builder) use ($request) {
                $term = trim((string) $request->string('q'));

                $builder->where(function ($inner) use ($term): void {
                    $inner
                        ->where('title', 'ilike', '%'.$term.'%')
                        ->orWhere('description', 'ilike', '%'.$term.'%');
                });
            })
            ->when($request->filled('asset_type'), fn ($builder) => $builder->where('asset_type', (string) $request->string('asset_type')))
            ->when($request->filled('collection_id'), function ($builder) use ($request): void {
                $collectionId = (int) $request->integer('collection_id');

                $builder->whereJsonContains('collection_ids', $collectionId);
            })
            ->orderByDesc('updated_at');

        $paginator = $query->paginate($perPage, ['*'], 'page', $page);
        $assets = $paginator->getCollection();

        return response()->json([
            'data' => [
                'assets' => $assets->map(fn (Asset $asset): array => $this->serializeAsset($asset))->all(),
            ],
            'meta' => [
                'pagination' => [
                    'current_page' => $paginator->currentPage(),
                    'last_page' => $paginator->lastPage(),
                    'per_page' => $paginator->perPage(),
                    'total' => $paginator->total(),
                    'from' => $paginator->firstItem(),
                    'to' => $paginator->lastItem(),
                ],
            ],
        ]);
    }

    public function show(Request $request, int $assetId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $asset = $this->findForUser($user, $assetId);

        if (! $asset) {
            return $this->error('not_found', 'Asset not found.', 404);
        }

        return response()->json([
            'data' => [
                'asset' => $this->serializeAsset($asset),
            ],
            'meta' => [],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'asset_type' => ['required', 'string', 'max:64'],
            'channel_id' => ['nullable', 'integer'],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'collection_ids' => ['nullable', 'array'],
            'collection_ids.*' => ['integer'],
            'asset_file' => ['required', 'file', 'max:102400'],
        ]);

        $file = $request->file('asset_file');
        $extension = $file?->getClientOriginalExtension() ?: $file?->extension() ?: 'bin';
        $path = sprintf(
            'workspace-assets/%d/%s.%s',
            $user->workspace_id,
            Str::uuid()->toString(),
            ltrim($extension, '.'),
        );

        Storage::disk('b2')->put($path, file_get_contents($file->getRealPath()), [
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
        ]);

        $asset = Asset::query()->create([
            'workspace_id' => $user->workspace_id,
            'channel_id' => $validated['channel_id'] ?? null,
            'asset_type' => $validated['asset_type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'storage_url' => 'b2://'.$path,
            'thumbnail_url' => null,
            'file_size_bytes' => $file?->getSize(),
            'mime_type' => $file?->getMimeType(),
            'tags' => $validated['tags'] ?? [],
            'collection_ids' => $validated['collection_ids'] ?? [],
            'restriction_scope' => 'workspace',
            'status' => 'active',
            'created_by_user_id' => $user->getKey(),
        ]);

        GenerateAssetThumbnailJob::dispatch($asset->getKey())->onQueue('default');

        return response()->json([
            'data' => [
                'asset' => $this->serializeAsset($asset->fresh()),
            ],
            'meta' => [],
        ], 201);
    }

    public function update(Request $request, int $assetId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $asset = $this->findForUser($user, $assetId);

        if (! $asset) {
            return $this->error('not_found', 'Asset not found.', 404);
        }

        $validated = $request->validate([
            'title' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string'],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:64'],
            'collection_ids' => ['sometimes', 'nullable', 'array'],
            'collection_ids.*' => ['integer'],
            'channel_id' => ['sometimes', 'nullable', 'integer'],
            'status' => ['sometimes', 'in:active,archived'],
        ]);

        $asset->fill($validated)->save();

        return response()->json([
            'data' => [
                'asset' => $this->serializeAsset($asset->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function destroy(Request $request, int $assetId): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $asset = $this->findForUser($user, $assetId);

        if (! $asset) {
            return $this->error('not_found', 'Asset not found.', 404);
        }

        $asset->forceFill([
            'status' => 'archived',
        ])->save();

        return response()->json([
            'data' => [
                'archived' => true,
                'asset' => $this->serializeAsset($asset->fresh()),
            ],
            'meta' => [],
        ]);
    }

    public function content(Request $request, int $assetId): StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $asset = Asset::query()
            ->whereKey($assetId)
            ->first();

        if (! $asset) {
            return response()->json([
                'error' => [
                    'code' => 'not_found',
                    'message' => 'Asset not found.',
                ],
            ], 404);
        }

        $path = $this->extractB2Path((string) $asset->storage_url);

        if ($path === null) {
            return response()->json([
                'error' => [
                    'code' => 'invalid_asset_source',
                    'message' => 'Asset is not stored in the configured B2 bucket.',
                ],
            ], 422);
        }

        if (! Storage::disk('b2')->exists($path)) {
            return response()->json([
                'error' => [
                    'code' => 'asset_missing',
                    'message' => 'Asset file not found in storage.',
                ],
            ], 404);
        }

        $stream = Storage::disk('b2')->readStream($path);

        if (! is_resource($stream)) {
            return response()->json([
                'error' => [
                    'code' => 'stream_failed',
                    'message' => 'Unable to open asset stream.',
                ],
            ], 502);
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type' => $asset->mime_type ?: 'application/octet-stream',
            'Cache-Control' => 'private, max-age=3600',
            'Accept-Ranges' => 'bytes',
            'Content-Disposition' => 'inline; filename="'.$this->downloadName($asset).'"',
        ]);
    }

    private function findForUser(User $user, int $assetId): ?Asset
    {
        return Asset::query()
            ->whereKey($assetId)
            ->where('workspace_id', $user->workspace_id)
            ->first();
    }

    /**
     * @return array{
     *     id:int,
     *     workspace_id:int,
     *     channel_id:?int,
     *     asset_type:string,
     *     title:string,
     *     description:?string,
     *     storage_url:?string,
     *     thumbnail_url:?string,
     *     duration_seconds:?float,
     *     dimensions_json:?array,
     *     file_size_bytes:?int,
     *     mime_type:?string,
     *     tags:array,
     *     collection_ids:array,
     *     usage_count:int,
     *     restriction_scope:?string,
     *     status:string,
     *     created_at:?string,
     *     updated_at:?string
     * }
     */
    private function serializeAsset(Asset $asset): array
    {
        return [
            'id' => $asset->getKey(),
            'workspace_id' => $asset->workspace_id,
            'channel_id' => $asset->channel_id,
            'asset_type' => $asset->asset_type,
            'title' => $asset->title,
            'description' => $asset->description,
            'storage_url' => $asset->storage_url ? $this->signedAssetUrl($asset) : null,
            'thumbnail_url' => $asset->thumbnail_url,
            'duration_seconds' => $asset->duration_seconds !== null ? (float) $asset->duration_seconds : null,
            'dimensions_json' => $asset->dimensions_json,
            'file_size_bytes' => $asset->file_size_bytes !== null ? (int) $asset->file_size_bytes : null,
            'mime_type' => $asset->mime_type,
            'tags' => $asset->tags ?? [],
            'collection_ids' => $asset->collection_ids ?? [],
            'usage_count' => (int) $asset->usage_count,
            'restriction_scope' => $asset->restriction_scope,
            'status' => $asset->status,
            'created_at' => $asset->created_at?->toIso8601String(),
            'updated_at' => $asset->updated_at?->toIso8601String(),
        ];
    }

    private function extractB2Path(string $storageUrl): ?string
    {
        $url = trim($storageUrl);

        if ($url === '') {
            return null;
        }

        if (str_starts_with($url, 'b2://')) {
            return ltrim(substr($url, 5), '/');
        }

        $parts = parse_url($url);
        $path = trim((string) ($parts['path'] ?? ''), '/');
        $bucket = trim((string) config('filesystems.disks.b2.bucket'), '/');

        if ($path === '' || $bucket === '') {
            return null;
        }

        $prefix = $bucket.'/';

        if (! str_starts_with($path, $prefix)) {
            return null;
        }

        return substr($path, strlen($prefix));
    }

    private function downloadName(Asset $asset): string
    {
        $title = trim((string) $asset->title);
        $safeTitle = $title !== '' ? preg_replace('/[^A-Za-z0-9._-]+/', '-', $title) : 'asset';
        $extension = match ($asset->mime_type) {
            'audio/mpeg' => 'mp3',
            'audio/mp4' => 'm4a',
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
            default => 'bin',
        };

        return trim((string) $safeTitle, '-').'.'.$extension;
    }

    private function signedAssetUrl(Asset $asset): string
    {
        return URL::temporarySignedRoute(
            'media.assets.content',
            now()->addMinutes(30),
            ['assetId' => $asset->getKey()],
        );
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
