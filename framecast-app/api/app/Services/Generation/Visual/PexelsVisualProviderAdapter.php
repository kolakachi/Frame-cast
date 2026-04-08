<?php

namespace App\Services\Generation\Visual;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use RuntimeException;

class PexelsVisualProviderAdapter implements VisualProviderAdapter
{
    private const VIDEO_TYPES = ['stock_clip', 'background_loop'];

    public function match(string $query, string $orientation = 'portrait', string $visualType = 'image_montage'): array
    {
        $apiKey = (string) config('services.pexels.api_key');
        $normalizedQuery = $this->queryForVisualType($query, $visualType);

        if ($apiKey === '') {
            return $this->fallback($normalizedQuery, $orientation);
        }

        if (in_array($visualType, self::VIDEO_TYPES, true)) {
            return $this->matchVideo($normalizedQuery, $orientation, $apiKey, $visualType);
        }

        return $this->matchPhoto($normalizedQuery, $orientation, $apiKey);
    }

    private function matchPhoto(string $query, string $orientation, string $apiKey): array
    {
        $pexelsOrientation = match ($orientation) {
            'landscape' => 'landscape',
            'square' => 'square',
            default => 'portrait',
        };

        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => $apiKey])
            ->get('https://api.pexels.com/v1/search', [
                'query' => $query ?: 'nature',
                'orientation' => $pexelsOrientation,
                'per_page' => 15,
                'page' => 1,
            ]);

        if (! $response->ok()) {
            throw new RuntimeException('Pexels photo search failed: '.$response->status());
        }

        $photos = $response->json('photos') ?? [];

        if (empty($photos)) {
            return $this->fallback($query, $orientation);
        }

        $photo = $photos[array_rand($photos)];
        $src = $photo['src'] ?? [];
        $assetUrl = $src['large2x'] ?? $src['large'] ?? $src['original'] ?? '';

        if ($assetUrl === '') {
            return $this->fallback($query, $orientation);
        }

        return [
            'provider_key' => 'pexels',
            'provider_asset_id' => (string) ($photo['id'] ?? Str::uuid()),
            'asset_url' => $assetUrl,
            'thumbnail_url' => $src['medium'] ?? $src['small'] ?? $assetUrl,
            'asset_type' => 'image',
            'mime_type' => 'image/jpeg',
            'duration_seconds' => null,
            'width' => (int) ($photo['width'] ?? 0) ?: null,
            'height' => (int) ($photo['height'] ?? 0) ?: null,
        ];
    }

    private function matchVideo(string $query, string $orientation, string $apiKey, string $visualType): array
    {
        $pexelsOrientation = match ($orientation) {
            'landscape' => 'landscape',
            'square' => 'square',
            default => 'portrait',
        };

        $response = Http::timeout(15)
            ->withHeaders(['Authorization' => $apiKey])
            ->get('https://api.pexels.com/videos/search', [
                'query' => $query ?: 'nature',
                'orientation' => $pexelsOrientation,
                'per_page' => 10,
                'page' => 1,
            ]);

        if (! $response->ok()) {
            throw new RuntimeException('Pexels video search failed: '.$response->status());
        }

        $videos = $response->json('videos') ?? [];

        if (empty($videos)) {
            return $this->fallback($query, $orientation);
        }

        if ($visualType === 'background_loop') {
            usort($videos, static function (array $a, array $b): int {
                $aDuration = (float) ($a['duration'] ?? 999);
                $bDuration = (float) ($b['duration'] ?? 999);
                return $aDuration <=> $bDuration;
            });
        }

        $candidateVideos = array_slice($videos, 0, min(5, count($videos)));
        $video = $candidateVideos[array_rand($candidateVideos)] ?? $videos[0];
        $videoFiles = $video['video_files'] ?? [];

        // Pick the best-quality file that matches orientation
        $targetWidth = $orientation === 'landscape' ? 1920 : 1080;
        $targetHeight = $orientation === 'landscape' ? 1080 : 1920;

        usort($videoFiles, static function (array $a, array $b) use ($targetWidth): int {
            $aDiff = abs(($a['width'] ?? 0) - $targetWidth);
            $bDiff = abs(($b['width'] ?? 0) - $targetWidth);
            return $aDiff <=> $bDiff;
        });

        $bestFile = null;
        foreach ($videoFiles as $file) {
            if (str_contains((string) ($file['file_type'] ?? ''), 'mp4')) {
                $bestFile = $file;
                break;
            }
        }

        if ($bestFile === null || empty($bestFile['link'])) {
            return $this->fallback($query, $orientation);
        }

        $thumbnail = ($video['image'] ?? '') ?: '';

        return [
            'provider_key' => 'pexels',
            'provider_asset_id' => (string) ($video['id'] ?? Str::uuid()),
            'asset_url' => $bestFile['link'],
            'thumbnail_url' => $thumbnail,
            'asset_type' => 'video',
            'mime_type' => 'video/mp4',
            'duration_seconds' => (float) ($video['duration'] ?? 6),
            'width' => (int) ($bestFile['width'] ?? $targetWidth),
            'height' => (int) ($bestFile['height'] ?? $targetHeight),
        ];
    }

    private function fallback(string $query, string $orientation): array
    {
        $seed = rawurlencode(Str::slug($query).'-'.Str::random(6));
        $width = $orientation === 'portrait' ? 1080 : 1920;
        $height = $orientation === 'portrait' ? 1920 : 1080;

        return [
            'provider_key' => 'pexels_fallback',
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
}
