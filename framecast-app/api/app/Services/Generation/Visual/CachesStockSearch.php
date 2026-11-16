<?php

namespace App\Services\Generation\Visual;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A stock search's results, kept for ten minutes. A scene offered several matches (visual-candidates) asks the same
 * search up to twelve times in a row, and a project's scenes often share one; the pick from the results stays random,
 * so the same search still brings different clips. Failures are not kept.
 */
trait CachesStockSearch
{
    private function stockSearch(string $url, array $params, array $headers, string $key, string $failure): array
    {
        $cacheKey = 'stock-search:'.md5($url.'|'.json_encode($params).'|'.json_encode($headers));

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($url, $params, $headers, $key, $failure) {
            $response = Http::timeout(15)->withHeaders($headers)->get($url, $params);
            if (! $response->ok()) {
                throw new RuntimeException($failure.': '.$response->status());
            }

            return $response->json($key) ?? [];
        });
    }
}
