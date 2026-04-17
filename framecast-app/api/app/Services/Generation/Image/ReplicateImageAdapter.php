<?php

namespace App\Services\Generation\Image;

use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ReplicateImageAdapter implements ImageGenerationAdapter
{
    // SDXL model on Replicate
    private const MODEL = 'stability-ai/sdxl:39ed52f2319f9412f4251a9d5c09c36a1adbbfacdbd90c85e73e5e3d25f2a6c6';

    private const ASPECT_RATIO_DIMENSIONS = [
        '9:16' => ['width' => 768, 'height' => 1344],
        '16:9' => ['width' => 1344, 'height' => 768],
        '1:1'  => ['width' => 1024, 'height' => 1024],
    ];

    private const STYLE_LORAS = [
        'cinematic'   => 'cinematic photography, dramatic lighting, film grain',
        'dark'        => 'dark moody, deep shadows, noir, high contrast',
        'anime'       => 'anime style, cel-shaded, vibrant, illustrated',
        'documentary' => 'documentary photo, natural light, realistic, candid',
        'minimalist'  => 'minimalist, clean, simple, muted colors, negative space',
        'realistic'   => 'photorealistic, highly detailed, 8k, sharp focus',
        'vintage'     => 'vintage film, grain, faded, retro, analog photography',
        'neon'        => 'neon lights, cyberpunk, glowing, night city, vivid',
    ];

    public function generate(
        string $prompt,
        string $style,
        string $aspectRatio = '9:16',
        array $options = []
    ): array {
        $apiToken = config('services.replicate.api_token');

        $dims = self::ASPECT_RATIO_DIMENSIONS[$aspectRatio] ?? self::ASPECT_RATIO_DIMENSIONS['9:16'];
        $stylePrompt = self::STYLE_LORAS[$style] ?? '';
        $fullPrompt = trim("{$stylePrompt}, {$prompt}");

        if (empty($apiToken)) {
            return $this->localFallback($dims);
        }

        try {
            // Kick off prediction
            $prediction = Http::withToken($apiToken)
                ->timeout(10)
                ->post('https://api.replicate.com/v1/predictions', [
                    'version' => self::MODEL,
                    'input'   => [
                        'prompt'          => $fullPrompt,
                        'negative_prompt' => 'text, watermark, blurry, nsfw',
                        'width'           => $dims['width'],
                        'height'          => $dims['height'],
                        'num_outputs'     => 1,
                        'num_inference_steps' => 30,
                        'guidance_scale'  => 7.5,
                        'seed'            => $options['seed'] ?? null,
                    ],
                ])
                ->throw()
                ->json();

            // Poll for completion (max 90s)
            $pollUrl = $prediction['urls']['get'];
            $output = $this->pollUntilDone($apiToken, $pollUrl);

            return [
                'provider_key'   => 'replicate',
                'image_url'      => $output['output'][0],
                'width'          => $dims['width'],
                'height'         => $dims['height'],
                'seed'           => $output['input']['seed'] ?? null,
                'revised_prompt' => null,
            ];
        } catch (RequestException $e) {
            Log::error('Replicate image generation failed', [
                'prompt' => $fullPrompt,
                'error'  => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function providerKey(): string
    {
        return 'replicate';
    }

    private function pollUntilDone(string $token, string $url, int $maxSeconds = 90): array
    {
        $waited = 0;

        while ($waited < $maxSeconds) {
            sleep(3);
            $waited += 3;

            $result = Http::withToken($token)->get($url)->json();

            if (in_array($result['status'], ['succeeded', 'failed', 'canceled'], true)) {
                if ($result['status'] !== 'succeeded') {
                    throw new \RuntimeException('Replicate prediction failed: '.($result['error'] ?? 'unknown'));
                }
                return $result;
            }
        }

        throw new \RuntimeException('Replicate prediction timed out after '.$maxSeconds.'s');
    }

    private function localFallback(array $dims): array
    {
        return [
            'provider_key'   => 'replicate_fallback',
            'image_url'      => "https://picsum.photos/{$dims['width']}/{$dims['height']}",
            'width'          => $dims['width'],
            'height'         => $dims['height'],
            'seed'           => null,
            'revised_prompt' => null,
        ];
    }
}
