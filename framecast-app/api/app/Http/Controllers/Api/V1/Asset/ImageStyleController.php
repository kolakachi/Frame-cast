<?php

namespace App\Http\Controllers\Api\V1\Asset;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

class ImageStyleController extends Controller
{
    private const STYLES = [
        ['key' => 'cinematic',   'label' => 'Cinematic',   'description' => 'Dramatic lighting, film grain, shallow depth of field'],
        ['key' => 'dark',        'label' => 'Dark',        'description' => 'Moody atmosphere, deep shadows, noir contrast'],
        ['key' => 'anime',       'label' => 'Anime',       'description' => 'Illustrated, cel-shaded, vibrant colors'],
        ['key' => 'documentary', 'label' => 'Documentary', 'description' => 'Natural lighting, photojournalistic, realistic'],
        ['key' => 'minimalist',  'label' => 'Minimalist',  'description' => 'Clean composition, muted tones, negative space'],
        ['key' => 'realistic',   'label' => 'Realistic',   'description' => 'Photorealistic, highly detailed, sharp focus'],
        ['key' => 'vintage',     'label' => 'Vintage',     'description' => 'Film grain, faded colors, retro analog aesthetic'],
        ['key' => 'neon',        'label' => 'Neon',        'description' => 'Cyberpunk, glowing lights, vivid night scene'],
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => self::STYLES,
            'meta' => ['total' => count(self::STYLES)],
        ]);
    }
}
