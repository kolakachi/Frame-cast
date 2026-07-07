<?php

namespace App\Services\Generation\Visual;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Pixabay stock provider — free API, no approval. Same contract + dedup
 * (excludeIds) as the Pexels adapter so it drops into the round-robin router.
 * Falls back to a picsum seed if the key is missing or a search comes up empty.
 */
class PixabayVisualProviderAdapter implements VisualProviderAdapter
{
    private const VIDEO_TYPES = ['stock_clip', 'background_loop'];

    public function match(string $query, string $orientation = 'portrait', string $visualType = 'image_montage', array $excludeIds = []): array
    {
        $apiKey = (string) config('services.pixabay.api_key');
        $normalizedQuery = $this->queryForVisualType($query, $visualType);
        $excludeIds = array_map('strval', $excludeIds);

        if ($apiKey === '') {
            return $this->fallback($normalizedQuery, $orientation);
        }

        try {
            if (in_array($visualType, self::VIDEO_TYPES, true)) {
                return $this->matchVideo($normalizedQuery, $orientation, $apiKey, $excludeIds);
            }

            return $this->matchPhoto($normalizedQuery, $orientation, $apiKey, $excludeIds);
        } catch (\Throwable $exception) {
            report($exception);
            return $this->fallback($normalizedQuery, $orientation);
        }
    }

    private function matchPhoto(string $query, string $orientation, string $apiKey, array $excludeIds = []): array
    {
        $response = Http::timeout(15)->get('https://pixabay.com/api/', [
            'key' => $apiKey,
            'q' => $query ?: 'nature',
            'image_type' => 'photo',
            'orientation' => $orientation === 'landscape' ? 'horizontal' : 'vertical',
            'safesearch' => 'true',
            'per_page' => 30,
        ]);

        if (! $response->ok()) {
            throw new RuntimeException('Pixabay photo search failed: '.$response->status());
        }

        $hits = $response->json('hits') ?? [];
        if (empty($hits)) {
            return $this->fallback($query, $orientation);
        }

        $pool = $this->preferUnused($hits, $excludeIds);
        $photo = $pool[array_rand($pool)];
        $assetUrl = $photo['largeImageURL'] ?? $photo['webformatURL'] ?? '';

        if ($assetUrl === '') {
            return $this->fallback($query, $orientation);
        }

        return [
            'provider_key' => 'pixabay',
            'provider_asset_id' => (string) ($photo['id'] ?? Str::uuid()),
            'asset_url' => $assetUrl,
            'thumbnail_url' => $photo['webformatURL'] ?? $photo['previewURL'] ?? $assetUrl,
            'asset_type' => 'image',
            'mime_type' => 'image/jpeg',
            'duration_seconds' => null,
            'width' => (int) ($photo['imageWidth'] ?? 0) ?: null,
            'height' => (int) ($photo['imageHeight'] ?? 0) ?: null,
        ];
    }

    private function matchVideo(string $query, string $orientation, string $apiKey, array $excludeIds = []): array
    {
        $response = Http::timeout(15)->get('https://pixabay.com/api/videos/', [
            'key' => $apiKey,
            'q' => $query ?: 'nature',
            'safesearch' => 'true',
            'per_page' => 30,
        ]);

        if (! $response->ok()) {
            throw new RuntimeException('Pixabay video search failed: '.$response->status());
        }

        $videos = $response->json('hits') ?? [];
        if (empty($videos)) {
            return $this->fallback($query, $orientation);
        }

        // The videos API has no orientation filter — prefer clips whose aspect
        // matches the target, then drop already-used ones.
        $wantPortrait = $orientation !== 'landscape';
        $oriented = array_values(array_filter($videos, function (array $v) use ($wantPortrait): bool {
            $file = $v['videos']['large'] ?? $v['videos']['medium'] ?? [];
            $w = (int) ($file['width'] ?? 0);
            $h = (int) ($file['height'] ?? 0);
            if ($w === 0 || $h === 0) {
                return true; // unknown dims — keep as a candidate
            }
            return $wantPortrait ? $h >= $w : $w >= $h;
        }));
        $videos = $oriented !== [] ? $oriented : $videos;
        $pool = $this->preferUnused($videos, $excludeIds);

        $candidates = array_slice($pool, 0, min(6, count($pool)));
        $video = $candidates[array_rand($candidates)] ?? $pool[0];

        $file = $video['videos']['large'] ?? $video['videos']['medium'] ?? $video['videos']['small'] ?? [];
        if (empty($file['url'])) {
            return $this->fallback($query, $orientation);
        }

        $targetWidth = $orientation === 'landscape' ? 1920 : 1080;
        $targetHeight = $orientation === 'landscape' ? 1080 : 1920;

        return [
            'provider_key' => 'pixabay',
            'provider_asset_id' => (string) ($video['id'] ?? Str::uuid()),
            'asset_url' => $file['url'],
            'thumbnail_url' => $file['thumbnail'] ?? '',
            'asset_type' => 'video',
            'mime_type' => 'video/mp4',
            'duration_seconds' => (float) ($video['duration'] ?? 6),
            'width' => (int) ($file['width'] ?? $targetWidth),
            'height' => (int) ($file['height'] ?? $targetHeight),
        ];
    }

    private function fallback(string $query, string $orientation): array
    {
        $seed = rawurlencode(Str::slug($query).'-'.Str::random(6));
        $width = $orientation === 'portrait' ? 1080 : 1920;
        $height = $orientation === 'portrait' ? 1920 : 1080;

        return [
            'provider_key' => 'pixabay_fallback',
            'provider_asset_id' => (string) Str::uuid(),
            'asset_url' => "https://picsum.photos/seed/{$seed}/{$width}/{$height}",
            'thumbnail_url' => "https://picsum.photos/seed/{$seed}/400/710",
            'asset_type' => 'image',
            'mime_type' => 'image/jpeg',
            'duration_seconds' => null,
            'width' => $width,
            'height' => $height,
        ];
    }

    private function queryForVisualType(string $query, string $visualType): string
    {
        $base = trim($query) !== '' ? trim($query) : 'nature';

        return match ($visualType) {
            'background_loop' => trim($base.' abstract background loop seamless motion'),
            'stock_clip' => trim($base.' cinematic stock footage'),
            'ai_image' => trim($base.' concept art illustration'),
            default => $base,
        };
    }

    /**
     * Prefer candidates whose id isn't already used this run; fall back to the
     * full list if all are used. Re-indexed so array_rand keys line up.
     *
     * @param  array<int, array<string, mixed>> $items
     * @param  array<int, string>               $excludeIds
     * @return array<int, array<string, mixed>>
     */
    private function preferUnused(array $items, array $excludeIds): array
    {
        if ($excludeIds === []) {
            return array_values($items);
        }

        $unused = array_values(array_filter(
            $items,
            static fn (array $item): bool => ! in_array((string) ($item['id'] ?? ''), $excludeIds, true),
        ));

        return $unused !== [] ? $unused : array_values($items);
    }
}
