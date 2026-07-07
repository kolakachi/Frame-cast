<?php

namespace App\Services\Generation\Visual;

use RuntimeException;

/**
 * Fans stock lookups across multiple providers for variety. Each scene starts
 * at a rotating "primary" provider (so consecutive scenes pull from different
 * libraries); if that provider only returns a picsum fallback, the next provider
 * is tried before accepting it — so a scene is never left empty. The excludeIds
 * dedup is passed through to each provider unchanged.
 */
class RoundRobinVisualProviderAdapter implements VisualProviderAdapter
{
    /** @var array<int, VisualProviderAdapter> */
    private array $providers;

    private int $cursor = 0;

    public function __construct(VisualProviderAdapter ...$providers)
    {
        $this->providers = array_values($providers);
    }

    public function match(string $query, string $orientation = 'portrait', string $visualType = 'image_montage', array $excludeIds = []): array
    {
        $count = count($this->providers);
        if ($count === 0) {
            throw new RuntimeException('No visual providers configured.');
        }
        if ($count === 1) {
            return $this->providers[0]->match($query, $orientation, $visualType, $excludeIds);
        }

        $start = $this->cursor;
        $this->cursor = ($this->cursor + 1) % $count; // rotate so the next scene leads with a different provider

        $firstResult = null;
        for ($i = 0; $i < $count; $i++) {
            $result = $this->providers[($start + $i) % $count]
                ->match($query, $orientation, $visualType, $excludeIds);
            $firstResult ??= $result;

            // Accept the first provider that returned real footage (not picsum).
            if (! str_contains((string) ($result['provider_key'] ?? ''), 'fallback')) {
                return $result;
            }
        }

        return $firstResult; // every provider fell back — better a fallback than nothing
    }
}
