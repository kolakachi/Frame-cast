<?php

namespace App\Http\Controllers\Api\V1\Admin;

use App\Http\Controllers\Controller;
use App\Models\SfxLibrarySound;
use App\Models\User;
use App\Services\Media\StorageService;
use App\Services\SfxLibraryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminSfxController extends Controller
{
    public function __construct(
        private readonly SfxLibraryService $library,
        private readonly StorageService $storage,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $q        = (string) $request->string('q');
        $category = (string) $request->string('category');
        $status   = (string) $request->string('status', 'all');

        $sounds = SfxLibrarySound::query()
            ->when($q !== '', fn ($b) => $b->whereRaw('LOWER(name) LIKE ?', ['%'.mb_strtolower($q).'%']))
            ->when($category !== '', fn ($b) => $b->where('category', $category))
            ->when($status !== 'all', fn ($b) => $b->where('status', $status))
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'data' => [
                'sounds'     => $sounds->map(fn ($s) => $this->serialize($s))->all(),
                'categories' => $this->library->categories(),
                'total'      => $sounds->count(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'category' => 'nullable|string|max:64',
            'source'   => 'nullable|string|max:64',
            'file'     => 'required|file|mimetypes:audio/mpeg,audio/mp3,audio/wav,audio/x-wav,audio/m4a,audio/x-m4a,audio/aac,audio/ogg|max:30720', // 30 MB
        ]);

        $file = $request->file('file');
        $bytes = (string) file_get_contents($file->getRealPath());
        $size  = $file->getSize();
        $mime  = $file->getMimeType() ?: 'audio/mpeg';

        $ext = pathinfo($file->getClientOriginalName(), PATHINFO_EXTENSION) ?: 'mp3';
        $storagePath = sprintf('sfx-library/%s-%s.%s',
            Str::slug($validated['name']),
            Str::random(8),
            strtolower($ext),
        );
        $storageUrl = $this->storage->put($storagePath, $bytes, ['ContentType' => $mime]);

        // Probe duration via ffprobe if available
        $duration = $this->probeDuration($file->getRealPath());

        $sound = SfxLibrarySound::query()->create([
            'name'             => $validated['name'],
            'category'         => $validated['category'] ?? null,
            'storage_url'      => $storageUrl,
            'duration_seconds' => $duration,
            'file_size_bytes'  => $size,
            'mime_type'        => $mime,
            'source'           => $validated['source'] ?? 'user_upload',
            'created_by_user_id' => $user->getKey(),
            'status'           => 'active',
        ]);

        return response()->json(['data' => ['sound' => $this->serialize($sound)]], 201);
    }

    public function update(Request $request, int $soundId): JsonResponse
    {
        $sound = SfxLibrarySound::query()->find($soundId);
        if (! $sound) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Sound not found.']], 404);
        }

        $validated = $request->validate([
            'name'     => 'sometimes|string|max:255',
            'category' => 'sometimes|nullable|string|max:64',
            'status'   => 'sometimes|string|in:active,archived',
        ]);

        $sound->update($validated);

        return response()->json(['data' => ['sound' => $this->serialize($sound->fresh())]]);
    }

    public function destroy(int $soundId): JsonResponse
    {
        $sound = SfxLibrarySound::query()->find($soundId);
        if (! $sound) {
            return response()->json(['error' => ['code' => 'not_found', 'message' => 'Sound not found.']], 404);
        }

        // Delete file from storage
        try { $this->storage->delete($sound->storage_url); } catch (\Throwable) { /* ignore */ }

        $sound->delete();

        return response()->json(['data' => ['deleted' => true]]);
    }

    private function probeDuration(string $absolutePath): ?float
    {
        $cmd = sprintf('ffprobe -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 %s 2>/dev/null', escapeshellarg($absolutePath));
        $output = trim((string) shell_exec($cmd));
        return is_numeric($output) ? (float) $output : null;
    }

    private function serialize(SfxLibrarySound $sound): array
    {
        return [
            'id'               => $sound->getKey(),
            'name'             => $sound->name,
            'category'         => $sound->category,
            'storage_url'      => $sound->storage_url,
            'duration_seconds' => $sound->duration_seconds,
            'file_size_bytes'  => $sound->file_size_bytes,
            'mime_type'        => $sound->mime_type,
            'source'           => $sound->source,
            'status'           => $sound->status,
            'preview_url'      => $this->signedUrl($sound->storage_url),
            'created_at'       => $sound->created_at?->toIso8601String(),
            'updated_at'       => $sound->updated_at?->toIso8601String(),
        ];
    }

    private function signedUrl(string $storageUrl): string
    {
        $path = preg_replace('#^(minio|b2)://#', '', $storageUrl);
        try {
            return Storage::disk('minio')->temporaryUrl($path, now()->addHour());
        } catch (\Throwable) {
            return $storageUrl;
        }
    }
}
