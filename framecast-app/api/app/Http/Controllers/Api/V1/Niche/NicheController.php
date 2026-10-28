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
        // Faceless formats first, in their own order (the composer's main audience), then the commercial ones.
        $rank = array_flip(Niche::FACELESS);
        $niches = Niche::query()->orderBy('id')->get()
            ->sortBy(fn (Niche $n) => [isset($rank[$n->slug]) ? 0 : 1, $rank[$n->slug] ?? (int) $n->getKey()])->values();

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
                    'faceless' => in_array($niche->slug, Niche::FACELESS, true),
                    // The narrator, caption look and pace this niche starts from (Niche::DEFAULTS).
                    'default_voice' => Niche::defaultsFor($niche->slug)['voice'] ?? null,
                    'default_voice_prompt' => Niche::defaultsFor($niche->slug)['voice_prompt'] ?? null,
                    'default_captions' => Niche::defaultsFor($niche->slug)['captions'] ?? null,
                    'seconds_per_scene' => Niche::secondsFor($niche->slug),
                ])->all(),
            ],
            'meta' => [],
        ]);
    }
}
