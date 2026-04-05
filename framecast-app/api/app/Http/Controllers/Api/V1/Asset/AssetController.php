<?php

namespace App\Http\Controllers\Api\V1\Asset;

use App\Http\Controllers\Controller;
use App\Models\Asset;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AssetController extends Controller
{
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
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'video/mp4' => 'mp4',
            default => 'bin',
        };

        return trim((string) $safeTitle, '-').'.'.$extension;
    }
}
