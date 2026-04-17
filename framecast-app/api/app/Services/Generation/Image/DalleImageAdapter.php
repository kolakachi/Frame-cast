<?php

namespace App\Services\Generation\Image;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DalleImageAdapter implements ImageGenerationAdapter
{
    private const ASPECT_RATIO_MAP = [
        '9:16' => '1024x1792',
        '16:9' => '1792x1024',
        '1:1'  => '1024x1024',
    ];

    private const STYLE_PROMPT_MAP = [
        'cinematic'    => 'cinematic photography, dramatic lighting, film grain, shallow depth of field,',
        'dark'         => 'dark moody atmosphere, deep shadows, high contrast, noir style,',
        'anime'        => 'anime illustration style, vibrant colors, cel-shaded,',
        'documentary'  => 'documentary photography style, natural lighting, realistic,',
        'minimalist'   => 'minimalist composition, clean background, simple shapes, muted tones,',
        'realistic'    => 'photorealistic, highly detailed, sharp focus, natural lighting,',
        'vintage'      => 'vintage film photography, faded colors, grain texture, retro aesthetic,',
        'neon'         => 'neon lights, cyberpunk aesthetic, glowing colors, night scene,',
    ];

    public function generate(
        string $prompt,
        string $style,
        string $aspectRatio = '9:16',
        array $options = []
    ): array {
        $apiKey = config('services.openai.api_key');

        $size = self::ASPECT_RATIO_MAP[$aspectRatio] ?? '1024x1792';
        $stylePrefix = self::STYLE_PROMPT_MAP[$style] ?? '';
        $fullPrompt = trim("{$stylePrefix} {$prompt}. No text or watermarks.");

        if (empty($apiKey)) {
            return $this->localFallback($prompt, $style, $size);
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model'           => 'dall-e-3',
                    'prompt'          => $fullPrompt,
                    'n'               => 1,
                    'size'            => $size,
                    'quality'         => $options['quality'] ?? 'standard',
                    'response_format' => 'url',
                ])
                ->throw()
                ->json();

            $image = $response['data'][0];
            [$width, $height] = explode('x', $size);

            return [
                'provider_key'    => 'dalle',
                'image_url'       => $image['url'],
                'width'           => (int) $width,
                'height'          => (int) $height,
                'seed'            => null,
                'revised_prompt'  => $image['revised_prompt'] ?? null,
            ];
        } catch (RequestException $e) {
            Log::error('DALL-E image generation failed', [
                'prompt' => $fullPrompt,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function providerKey(): string
    {
        return 'dalle';
    }

    private function localFallback(string $prompt, string $style, string $size): array
    {
        [$width, $height] = explode('x', $size);

        return [
            'provider_key'   => 'dalle_fallback',
            'image_url'      => "https://picsum.photos/{$width}/{$height}?blur=2",
            'width'          => (int) $width,
            'height'         => (int) $height,
            'seed'           => null,
            'revised_prompt' => "[Fallback] {$style}: {$prompt}",
        ];
    }
}
