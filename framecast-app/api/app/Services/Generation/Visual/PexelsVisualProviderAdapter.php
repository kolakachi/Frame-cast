<?php

namespace App\Services\Generation\Visual;

use Illuminate\Support\Str;

class PexelsVisualProviderAdapter implements VisualProviderAdapter
{
    public function match(string $query, string $orientation = 'portrait'): array
    {
        $seed = rawurlencode(Str::slug($query).'-'.Str::random(6));
        $width = $orientation === 'portrait' ? 1080 : 1920;
        $height = $orientation === 'portrait' ? 1920 : 1080;
        $assetUrl = "https://picsum.photos/seed/{$seed}/{$width}/{$height}";
        $thumbnailUrl = "https://picsum.photos/seed/{$seed}/400/710";

        return [
            'provider_key' => 'pexels',
            'provider_asset_id' => (string) Str::uuid(),
            'asset_url' => $assetUrl,
            'thumbnail_url' => $thumbnailUrl,
            'duration_seconds' => 6.0,
            'width' => $width,
            'height' => $height,
        ];
    }
}
