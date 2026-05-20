<?php

namespace App\Http\Controllers\Api\V1\Sfx;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use App\Models\User;
use App\Services\Media\StorageService;
use App\Services\SfxLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

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
                'sounds'     => $sounds,
                'categories' => $this->categories(),
            ],
        ]);
    }

    /**
     * Download a library sound to the workspace's storage and create an Asset row.
     * Idempotent — if the sound was already imported, returns the existing asset.
     */
    public function import(Request $request, string $soundId): JsonResponse
    {
        $sound = $this->library->find($soundId);
        if (! $sound) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Sound not found in library.']], 404);
        }

        /** @var User $user */
        $user = $request->user();

        // Idempotent: check if this exact library sound was already imported
        $existing = Asset::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('asset_type', 'sound')
            ->whereJsonContains('metadata_json->sfx_library_id', $soundId)
            ->first();

        if ($existing) {
            return response()->json(['data' => ['asset' => $this->serialize($existing)]]);
        }

        // Download from the source URL
        $response = Http::timeout(30)->get($sound['url']);
        if (! $response->successful()) {
            return response()->json(['error' => ['code' => 'download_failed', 'message' => 'Could not download sound.']], 502);
        }

        $bytes = $response->body();
        $size  = strlen($bytes);

        $storagePath = sprintf('workspaces/%d/sfx/%s-%s.mp3', $user->workspace_id, $soundId, Str::random(8));
        $storageUrl  = $this->storage->put($storagePath, $bytes, ['ContentType' => 'audio/mpeg']);

        $asset = Asset::query()->create([
            'workspace_id'   => $user->workspace_id,
            'asset_type'     => 'sound',
            'title'          => $sound['name'],
            'description'    => 'From bundled SFX library',
            'storage_url'    => $storageUrl,
            'duration_seconds' => $sound['duration'] ?? null,
            'file_size_bytes'  => $size,
            'mime_type'      => 'audio/mpeg',
            'status'         => 'active',
            'created_by_user_id' => $user->getKey(),
            'metadata_json'  => [
                'sfx_library_id' => $soundId,
                'sfx_library_url'=> $sound['url'],
                'sfx_category'   => $sound['category'] ?? null,
            ],
        ]);

        return response()->json(['data' => ['asset' => $this->serialize($asset)]], 201);
    }

    private function categories(): array
    {
        return [
            ['key' => 'transition',   'label' => 'Transitions'],
            ['key' => 'ui',           'label' => 'UI / Clicks'],
            ['key' => 'notification', 'label' => 'Notifications'],
            ['key' => 'impact',       'label' => 'Impacts'],
            ['key' => 'ambient',      'label' => 'Ambient'],
            ['key' => 'fx',           'label' => 'FX'],
        ];
    }

    private function serialize(Asset $asset): array
    {
        return [
            'id'             => $asset->getKey(),
            'asset_type'     => $asset->asset_type,
            'title'          => $asset->title,
            'storage_url'    => $asset->storage_url,
            'duration_seconds' => $asset->duration_seconds,
            'mime_type'      => $asset->mime_type,
            'created_at'     => $asset->created_at?->toIso8601String(),
            'updated_at'     => $asset->updated_at?->toIso8601String(),
        ];
    }
}
