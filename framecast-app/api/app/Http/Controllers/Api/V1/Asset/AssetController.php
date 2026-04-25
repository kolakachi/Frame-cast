<?php

namespace App\Http\Controllers\Api\V1\Asset;

use App\Http\Controllers\Controller;
use App\Jobs\GenerateAssetThumbnailJob;
use App\Jobs\TranscribeAssetJob;
use App\Models\Asset;
use App\Models\Collection;
use App\Models\User;
use App\Services\Media\StorageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\RedirectResponse;
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
            'per_page' => ['nullable', 'integer', 'min:1', 'max:200'],
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
        $collectionIds = $this->normalizeCollectionIds($user, $validated['collection_ids'] ?? []);

        if ($collectionIds === null) {
            return $this->error('invalid_collection', 'One or more selected collections do not exist in this workspace.', 422);
        }

        $extension = $file?->getClientOriginalExtension() ?: $file?->extension() ?: 'bin';
        $path = sprintf(
            'workspace-assets/%d/%s.%s',
            $user->workspace_id,
            Str::uuid()->toString(),
            ltrim($extension, '.'),
        );

        $storageUrl = app(StorageService::class)->put($path, file_get_contents($file->getRealPath()), [
            'ContentType' => $file->getMimeType() ?: 'application/octet-stream',
        ]);

        $asset = Asset::query()->create([
            'workspace_id' => $user->workspace_id,
            'channel_id' => $validated['channel_id'] ?? null,
            'asset_type' => $validated['asset_type'],
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'storage_url' => $storageUrl,
            'thumbnail_url' => null,
            'file_size_bytes' => $file?->getSize(),
            'mime_type' => $file?->getMimeType(),
            'transcription_status' => $this->isTranscribable((string) $validated['asset_type'], (string) $file?->getMimeType())
                ? 'queued'
                : 'not_requested',
            'tags' => $validated['tags'] ?? [],
            'collection_ids' => $collectionIds,
            'restriction_scope' => 'workspace',
            'status' => 'active',
            'created_by_user_id' => $user->getKey(),
        ]);

        GenerateAssetThumbnailJob::dispatch($asset->getKey())->onQueue('default');

        if ($this->isTranscribable((string) $asset->asset_type, (string) $asset->mime_type)) {
            TranscribeAssetJob::dispatch($asset->getKey())->onQueue('generation');
        }

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

        if (array_key_exists('collection_ids', $validated)) {
            $collectionIds = $this->normalizeCollectionIds($user, $validated['collection_ids'] ?? []);

            if ($collectionIds === null) {
                return $this->error('invalid_collection', 'One or more selected collections do not exist in this workspace.', 422);
            }

            $validated['collection_ids'] = $collectionIds;
        }

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

    public function content(Request $request, int $assetId): StreamedResponse|RedirectResponse|\Illuminate\Http\JsonResponse
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

        $storageService = app(StorageService::class);
        $rawStorageUrl  = (string) $asset->storage_url;
        $path           = $storageService->extractPath($rawStorageUrl);

        if ($path === null) {
            $externalUrl = trim($rawStorageUrl);
            if (filter_var($externalUrl, FILTER_VALIDATE_URL)) {
                return redirect()->away($externalUrl);
            }

            return response()->json([
                'error' => [
                    'code' => 'invalid_asset_source',
                    'message' => 'Asset is not stored in a recognised storage location.',
                ],
            ], 422);
        }

        // For large media redirect to a direct storage URL to avoid streaming overhead.
        $isLargeMedia = in_array($asset->asset_type ?? '', ['video', 'audio'], true)
            || str_starts_with((string) ($asset->mime_type ?? ''), 'video/')
            || str_starts_with((string) ($asset->mime_type ?? ''), 'audio/');

        if ($isLargeMedia) {
            try {
                return redirect()->away($storageService->url($rawStorageUrl));
            } catch (\Throwable) {
                // Fall through to stream.
            }
        }

        try {
            $stream = $storageService->readStream($rawStorageUrl);
        } catch (\Throwable) {
            return response()->json([
                'error' => [
                    'code' => 'asset_missing',
                    'message' => 'Asset file could not be retrieved from storage.',
                ],
            ], 404);
        }

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
     *     transcript_text:?string,
     *     transcription_status:string,
     *     transcription_error:?string,
     *     metadata_json:?array,
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
            'thumbnail_url' => $this->safeThumbnailUrl($asset),
            'duration_seconds' => $asset->duration_seconds !== null ? (float) $asset->duration_seconds : null,
            'dimensions_json' => $asset->dimensions_json,
            'file_size_bytes' => $asset->file_size_bytes !== null ? (int) $asset->file_size_bytes : null,
            'mime_type' => $asset->mime_type,
            'transcript_text' => $asset->transcript_text,
            'transcription_status' => $asset->transcription_status,
            'transcription_error' => $asset->transcription_error,
            'metadata_json' => $asset->metadata_json,
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

        if (! str_contains($url, '://') && ! str_starts_with($url, '/')) {
            return ltrim($url, '/');
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

    private function safeThumbnailUrl(Asset $asset): ?string
    {
        $url = trim((string) $asset->thumbnail_url);
        if ($url === '') return null;
        // data: URIs (SVG placeholders) are fine for the browser
        if (str_starts_with($url, 'data:')) return $url;
        // Managed storage URLs (minio://, b2://, bare paths) cannot be used directly
        if (app(StorageService::class)->isManagedUrl($url)) return null;
        return $url;
    }

    private function signedAssetUrl(Asset $asset): string
    {
        if (app(StorageService::class)->extractPath((string) $asset->storage_url) === null) {
            return (string) $asset->storage_url;
        }

        return URL::temporarySignedRoute(
            'media.assets.content',
            now()->addMinutes(30),
            ['assetId' => $asset->getKey()],
        );
    }

    private function isTranscribable(string $assetType, string $mimeType): bool
    {
        return in_array($assetType, ['audio', 'video'], true)
            || str_starts_with($mimeType, 'audio/')
            || str_starts_with($mimeType, 'video/');
    }

    /**
     * @param  array<int|string>  $collectionIds
     * @return array<int>|null
     */
    private function normalizeCollectionIds(User $user, array $collectionIds): ?array
    {
        $ids = collect($collectionIds)
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($ids->isEmpty()) {
            return [];
        }

        $found = Collection::query()
            ->where('workspace_id', $user->workspace_id)
            ->whereIn('id', $ids->all())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->sort()
            ->values()
            ->all();

        $expected = $ids->sort()->values()->all();

        return $found === $expected ? $ids->all() : null;
    }

    protected function error(string $code, string $message, int $status = 422): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => $code,
                'message' => $message,
            ],
        ], $status);
    }
}
