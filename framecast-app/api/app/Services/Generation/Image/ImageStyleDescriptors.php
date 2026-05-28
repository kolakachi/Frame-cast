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

    public static function for(string $key): string
    {
        return self::MAP[$key] ?? '';
    }
}
