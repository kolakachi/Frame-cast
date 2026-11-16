<?php

namespace Tests\Unit;

use App\Services\Generation\Visual\PexelsVisualProviderAdapter;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CachesStockSearchTest extends TestCase
{
    public function test_the_same_stock_search_asks_pexels_once_and_still_picks_among_the_results(): void
    {
        // visual-candidates (2026-11-16) asks one search up to twelve times in a row; it took 10-18 seconds.
        config(['cache.default' => 'array', 'services.pexels.api_key' => 'test-key']);
        $videos = array_map(fn ($i) => ['id' => $i, 'duration' => 8, 'width' => 1080, 'height' => 1920, 'image' => "https://img/{$i}.jpg",
            'video_files' => [['link' => "https://vid/{$i}.mp4", 'width' => 1080, 'height' => 1920, 'file_type' => 'video/mp4', 'quality' => 'hd']]], range(1, 6));
        Http::fake(['api.pexels.com/*' => Http::response(['videos' => $videos])]);

        $adapter = app(PexelsVisualProviderAdapter::class);
        $seen = [];
        for ($i = 0; $i < 4; $i++) {
            $seen[] = $adapter->match('cozy living room', 'portrait', 'stock_clip', $seen)['provider_asset_id'];
        }

        Http::assertSentCount(1);
        $this->assertCount(4, array_unique($seen));
    }
}
