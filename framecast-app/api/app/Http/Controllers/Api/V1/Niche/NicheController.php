<?php

namespace App\Http\Controllers\Api\V1\Niche;

use App\Http\Controllers\Controller;
use App\Models\Niche;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class NicheController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $niches = Niche::query()->orderBy('id')->get();

        return response()->json([
            'data' => [
                'niches' => $niches->map(fn (Niche $niche): array => [
                    'id' => $niche->getKey(),
                    'name' => $niche->name,
                    'slug' => $niche->slug,
                    'description' => $niche->description,
                    'icon_emoji' => $niche->icon_emoji,
                    'default_template_type' => $niche->default_template_type,
                    'default_visual_style' => $niche->default_visual_style,
                    'default_caption_preset_name' => $niche->default_caption_preset_name,
                    'default_voice_tone' => $niche->default_voice_tone,
                    'default_music_mood' => $niche->default_music_mood,
                ])->all(),
            ],
            'meta' => [],
        ]);
    }
}
