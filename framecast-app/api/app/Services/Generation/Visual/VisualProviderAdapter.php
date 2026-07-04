<?php

namespace App\Services\Generation\Visual;

interface VisualProviderAdapter
{
    /**
     * @return array{
     *   provider_key:string,
     *   provider_asset_id:string,
     *   asset_url:string,
     *   thumbnail_url:string,
     *   asset_type:string,
     *   mime_type:string,
     *   duration_seconds:float|null,
     *   width:int|null,
     *   height:int|null
     * }
     *
     * @param array<int, string> $excludeIds provider_asset_ids already used in this
     *        run — the adapter avoids returning them so scenes don't repeat visuals.
     */
    public function match(string $query, string $orientation = 'portrait', string $visualType = 'image_montage', array $excludeIds = []): array;
}
