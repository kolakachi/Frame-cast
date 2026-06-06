<?php

namespace App\Services\Generation\Image;

/**
 * Single source of truth for image-generation style descriptors.
 *
 * Every adapter (DALL-E, Replicate flux-pulid, future Flux text2img, etc.) reads
 * its style modifier from this one map, so the editor's style picker behaves
 * identically regardless of which model handles the request.
 *
 * Keep keys aligned with web/src/views/EditorView.vue → AI_IMAGE_STYLES.
 */
final class ImageStyleDescriptors
{
    /**
     * @var array<string,string>
     */
    public const MAP = [
        'cinematic'      => 'cinematic photography, dramatic lighting, film grain, shallow depth of field',
        'dark'           => 'dark moody atmosphere, deep shadows, high contrast, noir style',
        'anime'          => 'anime illustration style, vibrant colors, cel-shaded',
        'documentary'    => 'documentary photography style, natural lighting, realistic',
        'minimalist'     => 'minimalist composition, clean background, simple shapes, muted tones',
        'realistic'      => 'photorealistic, highly detailed, sharp focus, natural lighting',
        'vintage'        => 'vintage film photography, faded colors, grain texture, retro aesthetic',
        'neon'           => 'neon lights, cyberpunk aesthetic, glowing colors, night scene',
        'photorealistic' => 'photorealistic cinematic still, natural skin texture, dramatic practical lighting',
        'cyberpunk_80s'  => '1980s cyberpunk film still, neon haze, retro futurist tech',
        'anime_80s'      => '1980s anime style, hand-painted cel animation, soft film grain',
        'anime_90s'      => '1990s anime style, painted backgrounds, expressive cinematic framing',
        'dark_fantasy'   => 'dark fantasy art, gothic atmosphere, ethereal lighting, dramatic shadows',
        'fantasy_retro'  => 'retro fantasy illustration, painterly wizard-core atmosphere, storybook lighting',
        'comic'          => 'dynamic comic book illustration, bold ink, vivid color, dramatic panel composition',
        'film_noir'      => 'black and white film noir, hard shadows, moody cinematic lighting',
        'line_drawing'   => 'clean pencil line drawing, monochrome sketch, minimal shading',
        'watercolor'     => 'soft watercolor illustration, paper texture, delicate color washes',
        'paper_cutout'   => 'paper cutout collage style, layered paper texture, graphic shapes',
        'cartoon'        => 'modern cartoon illustration, clean shapes, expressive character style',
        '3d_animated'    => '3D animated film style, Pixar-quality rendering, volumetric lighting, subsurface scattering, soft rim light, cinematic depth of field, detailed facial features',
    ];

    /**
     * Human-readable label + one-line use-case description per style. Drives
     * the editor's style picker rows. Keep keys aligned with MAP.
     * @var array<string,array{label:string,description:string}>
     */
    public const META = [
        'photorealistic' => ['label' => 'Photorealistic',     'description' => 'Sharp DSLR-quality. Product shots, lifestyle, founders to camera.'],
        'realistic'      => ['label' => 'Realistic',          'description' => 'Documentary-soft photography. Real moments, less retouching.'],
        'cinematic'      => ['label' => 'Cinematic',          'description' => 'Color-graded film still. Trailers, ads, hero shots.'],
        'documentary'    => ['label' => 'Documentary',        'description' => 'Handheld, natural light, candid. Journalism, real stories.'],
        'dark'           => ['label' => 'Dark',               'description' => 'Moody low-key shadows. Horror, thriller, drama.'],
        'film_noir'      => ['label' => 'Film Noir',          'description' => 'High-contrast B&W, 1940s detective aesthetic.'],
        'vintage'        => ['label' => 'Vintage',            'description' => 'Faded color, light grain. 70s/80s nostalgia.'],
        'minimalist'     => ['label' => 'Minimalist',         'description' => 'Clean negative space, muted palette. Tech, design, brand.'],
        'neon'           => ['label' => 'Neon',               'description' => 'Vivid neon, rim lighting, night urban. Gaming, nightlife.'],
        'cyberpunk_80s'  => ['label' => 'Cyberpunk 80s',      'description' => 'Neon + retro tech, chrome, scan lines. Tech futurism.'],
        'anime'          => ['label' => 'Anime',              'description' => 'Modern Japanese animation, clean line, saturated color.'],
        'anime_80s'      => ['label' => 'Anime 80s',          'description' => 'Akira / Bubblegum era. Cel shading, painterly backgrounds.'],
        'anime_90s'      => ['label' => 'Anime 90s',          'description' => 'Ghost in the Shell era. Gritty, mature.'],
        'dark_fantasy'   => ['label' => 'Dark Fantasy',       'description' => 'Witcher / dark Souls. Painterly, mythic horror, lore.'],
        'fantasy_retro'  => ['label' => 'Fantasy Retro',      'description' => '80s pulp fantasy. Frank Frazetta vibes, painterly.'],
        'comic'          => ['label' => 'Comic',              'description' => 'Bold ink, halftone dots. Western comics, action.'],
        'line_drawing'   => ['label' => 'Line Drawing',       'description' => 'Pen sketch on white. Explainer, technical, editorial.'],
        'watercolor'     => ['label' => 'Watercolor',         'description' => 'Soft washes, paper texture. Children\'s books, gentle stories.'],
        'paper_cutout'   => ['label' => 'Paper Cutout',       'description' => 'Layered paper, drop shadow. Quirky, indie, hand-made.'],
        'cartoon'        => ['label' => 'Cartoon',            'description' => 'Modern 2D cartoon. Bright outlines, Saturday-morning vibe.'],
        '3d_animated'    => ['label' => '3D Animated',        'description' => 'Pixar / Disney render. Volumetric light, family content.'],
    ];

    /**
     * Sentinel key for the user-defined style. When the editor / wizard
     * picks "Custom", the actual descriptor text lives on the scene/project
     * as `custom_visual_style` and is passed in here as the second arg.
     */
    public const CUSTOM = 'custom';

    /**
     * @param  string|null  $custom  free-text descriptor used when $key === 'custom'
     */
    public static function for(string $key, ?string $custom = null): string
    {
        if ($key === self::CUSTOM) {
            $custom = trim((string) $custom);
            // Empty custom string → return empty; the adapter / prompt builder
            // will fall back to whatever default it has (often nothing extra).
            return $custom;
        }

        return self::MAP[$key] ?? '';
    }
}
