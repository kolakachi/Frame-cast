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
        ['key' => 'photorealistic', 'label' => 'Photorealistic', 'description' => 'Cinema-grade realism, glossy detail, lifelike faces'],
        ['key' => 'cyberpunk_80s',  'label' => '80s Cyberpunk', 'description' => 'Retro-futurist neon haze, synthwave mood, chrome detail'],
        ['key' => 'anime_80s',      'label' => '80s Anime',     'description' => 'Vintage cel animation, soft bloom, classic color blocking'],
        ['key' => 'anime_90s',      'label' => '90s Anime',     'description' => 'Painted backgrounds, dramatic framing, nostalgic anime energy'],
        ['key' => 'dark_fantasy',   'label' => 'Dark Fantasy',  'description' => 'Gothic worlds, ominous light, mythic atmosphere'],
        ['key' => 'fantasy_retro',  'label' => 'Fantasy Retro', 'description' => 'Storybook fantasy, painterly textures, retro adventure tone'],
        ['key' => 'comic',          'label' => 'Comic',         'description' => 'Bold ink, halftones, graphic panel-style action'],
        ['key' => 'film_noir',      'label' => 'Film Noir',     'description' => 'Black-and-white tension, hard shadows, detective mood'],
        ['key' => 'line_drawing',   'label' => 'Line Drawing',  'description' => 'Monochrome sketch lines, minimal shading, crisp outlines'],
        ['key' => 'watercolor',     'label' => 'Watercolor',    'description' => 'Soft pigment washes, airy detail, painterly gradients'],
        ['key' => 'paper_cutout',   'label' => 'Paper Cutout',  'description' => 'Layered collage shapes, tactile edges, handcrafted depth'],
        ['key' => 'cartoon',        'label' => 'Cartoon',       'description' => 'Playful proportions, bright colors, expressive silhouettes'],
        ['key' => '3d_animated',    'label' => '3D Animated',   'description' => 'Stylized 3D characters, cinematic lighting, animated-film polish'],
    ];

    public function index(): JsonResponse
    {
        return response()->json([
            'data' => self::STYLES,
            'meta' => ['total' => count(self::STYLES)],
        ]);
    }
}
