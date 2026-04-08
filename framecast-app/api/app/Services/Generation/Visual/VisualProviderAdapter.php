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
     */
    public function match(string $query, string $orientation = 'portrait', string $visualType = 'image_montage'): array;
}
