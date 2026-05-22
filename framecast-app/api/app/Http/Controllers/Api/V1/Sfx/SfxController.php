<?php

namespace App\Http\Controllers\Api\V1\Sfx;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\SfxLibrarySound;
use App\Models\User;
use App\Services\Media\StorageService;
use App\Services\SfxLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SfxController extends Controller
{
    public function __construct(
        private readonly SfxLibraryService $library,
        private readonly StorageService $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $sounds = $this->library->search(
            $request->string('q')->value() ?: null,
            $request->string('category')->value() ?: null,
        );

        return response()->json([
            'data' => [
                'sounds'     => $sounds->map(function ($s) {
                    return [
                        'id'       => $s->id,
                        'name'     => $s->name,
                        'category' => $s->category,
                        'duration' => $s->duration_seconds,
                        'url'      => $this->signedStreamUrl($s),
                    ];
                })->all(),
                'categories' => $this->library->categories(),
            ],
        ]);
    }

    /**
     * Copy a library sound into the user's workspace and return the Asset.
     */
    public function import(Request $request, int $soundId): JsonResponse
    {
        $sound = $this->library->find($soundId);
        if (! $sound || $sound->status !== 'active') {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Sound not found.']], 404);
        }

        /** @var User $user */
        $user = $request->user();

        // Idempotent: if this library sound was already imported, return that asset
        $existing = Asset::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('asset_type', 'sound')
            ->whereJsonContains('metadata_json->sfx_library_id', $sound->id)
            ->first();

        if ($existing) {
            return response()->json(['data' => ['asset' => $this->serializeAsset($existing)]]);
        }

        // Read bytes from the library storage_url and copy to workspace path
        $bytes = $this->storage->get($sound->storage_url);
        $size  = strlen($bytes);

        $workspacePath = sprintf('workspaces/%d/sfx/%d-%s.mp3', $user->workspace_id, $sound->id, Str::random(8));
        $storageUrl    = $this->storage->put($workspacePath, $bytes, ['ContentType' => $sound->mime_type ?? 'audio/mpeg']);

        $asset = Asset::query()->create([
            'workspace_id'   => $user->workspace_id,
            'asset_type'     => 'sound',
            'title'          => $sound->name,
            'description'    => 'From SFX library',
            'storage_url'    => $storageUrl,
            'duration_seconds' => $sound->duration_seconds,
            'file_size_bytes'  => $size,
            'mime_type'        => $sound->mime_type ?? 'audio/mpeg',
            'status'           => 'active',
            'created_by_user_id' => $user->getKey(),
            'metadata_json'    => [
                'sfx_library_id' => $sound->id,
                'sfx_category'   => $sound->category,
            ],
        ]);

        return response()->json(['data' => ['asset' => $this->serializeAsset($asset)]], 201);
    }

    public function stream(Request $request, int $soundId): StreamedResponse|JsonResponse
    {
        $sound = SfxLibrarySound::query()->find($soundId);
        if (! $sound) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Sound not found.']], 404);
        }

        try {
            $stream = $this->storage->readStream($sound->storage_url);
        } catch (\Throwable) {
            return response()->json(['error' => ['code' => 'stream_failed', 'message' => 'Unable to open sound stream.']], 502);
        }

        if (! is_resource($stream)) {
            return response()->json(['error' => ['code' => 'stream_failed', 'message' => 'Unable to open sound stream.']], 502);
        }

        return response()->stream(function () use ($stream): void {
            fpassthru($stream);
            fclose($stream);
        }, 200, [
            'Content-Type'  => $sound->mime_type ?: 'audio/mpeg',
            'Cache-Control' => 'private, max-age=3600',
            'Accept-Ranges' => 'bytes',
        ]);
    }

    private function signedStreamUrl(SfxLibrarySound $sound): string
    {
        return URL::temporarySignedRoute(
            'media.sfx.stream',
            now()->addHour(),
            ['soundId' => $sound->id],
        );
    }

    private function serializeAsset(Asset $asset): array
    {
        return [
            'id'               => $asset->getKey(),
            'asset_type'       => $asset->asset_type,
            'title'            => $asset->title,
            'storage_url'      => $asset->storage_url,
            'duration_seconds' => $asset->duration_seconds,
            'mime_type'        => $asset->mime_type,
            'created_at'       => $asset->created_at?->toIso8601String(),
        ];
    }
}
