<?php

namespace App\Services\Generation\Image;

use App\Services\ApiUsageService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class DalleImageAdapter implements ImageGenerationAdapter
{
    private const ASPECT_RATIO_MAP = [
        '9:16' => '1024x1536',
        '16:9' => '1536x1024',
        '1:1'  => '1024x1024',
    ];

    private const ASPECT_RATIO_COMPOSITION = [
        '9:16' => 'Vertical portrait frame. Compose the scene upright — subjects stand or scene elements fill the frame top-to-bottom, not left-to-right. Do not rotate.',
        '16:9' => 'Horizontal landscape frame. Wide cinematic composition, left-to-right.',
        '1:1'  => 'Square frame. Centered balanced composition.',
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
        'photorealistic' => 'photorealistic cinematic still, natural skin texture, dramatic practical lighting,',
        'cyberpunk_80s' => '1980s cyberpunk film still, neon haze, retro futurist tech,',
        'anime_80s' => '1980s anime style, hand-painted cel animation, soft film grain,',
        'anime_90s' => '1990s anime style, painted backgrounds, expressive cinematic framing,',
        'dark_fantasy' => 'dark fantasy art, gothic atmosphere, ethereal lighting, dramatic shadows,',
        'fantasy_retro' => 'retro fantasy illustration, painterly wizard-core atmosphere, storybook lighting,',
        'comic' => 'dynamic comic book illustration, bold ink, vivid color, dramatic panel composition,',
        'film_noir' => 'black and white film noir, hard shadows, moody cinematic lighting,',
        'line_drawing' => 'clean pencil line drawing, monochrome sketch, minimal shading,',
        'watercolor' => 'soft watercolor illustration, paper texture, delicate color washes,',
        'paper_cutout' => 'paper cutout collage style, layered paper texture, graphic shapes,',
        'cartoon' => 'modern cartoon illustration, clean shapes, expressive character style,',
        '3d_animated' => '3D animated film style, Pixar-quality rendering, volumetric lighting, subsurface scattering, soft rim light, cinematic depth of field, detailed facial features,',
    ];

    public function __construct(
        private readonly ApiUsageService $usage,
    ) {
    }

    public function generate(
        string $prompt,
        string $style,
        string $aspectRatio = '9:16',
        array $options = []
    ): array {
        $apiKey = config('services.openai.api_key');

        $size = self::ASPECT_RATIO_MAP[$aspectRatio] ?? '1024x1792';
        $stylePrefix = self::STYLE_PROMPT_MAP[$style] ?? '';
        $composition = self::ASPECT_RATIO_COMPOSITION[$aspectRatio] ?? self::ASPECT_RATIO_COMPOSITION['9:16'];
        $fullPrompt = trim("{$stylePrefix} {$prompt}. {$composition} No text, no writing, no letters, no words, no watermarks, no captions, no signs with readable text.");
        $model = config('services.openai.image_model', 'gpt-image-1');
        // gpt-image-1 uses low/medium/high; dall-e-3 used standard/hd
        $rawQuality = (string) ($options['quality'] ?? 'medium');
        $quality = match ($rawQuality) {
            'hd', 'high'        => 'high',
            'standard', 'medium' => 'medium',
            'low'               => 'low',
            default             => 'medium',
        };
        $usageContext = $this->usage->contextFromOptions($options);

        if (empty($apiKey)) {
            return $this->localFallback($prompt, $style, $size);
        }

        try {
            $response = Http::withToken($apiKey)
                ->timeout(60)
                ->post('https://api.openai.com/v1/images/generations', [
                    'model'   => $model,
                    'prompt'  => $fullPrompt,
                    'n'       => 1,
                    'size'    => $size,
                    'quality' => $quality,
                ])
                ->throw()
                ->json();

            $image = $response['data'][0];
            [$width, $height] = explode('x', $size);

            // gpt-image-1 may return b64_json instead of url — normalise to a data URI so
            // downstream storeImage() can still Http::get() it or we decode directly.
            $imageUrl = $image['url'] ?? null;
            $imageB64 = ($imageUrl === null && isset($image['b64_json'])) ? $image['b64_json'] : null;

            $this->usage->record([
                ...$usageContext,
                'provider' => 'openai',
                'service' => 'image_generation',
                'operation' => 'image',
                'model' => $model,
                'status' => 'succeeded',
                'units' => 1,
                'estimated_cost_usd' => $this->usage->estimateImageCost($model, $quality, $size),
                'metadata_json' => [
                    ...($usageContext['metadata_json'] ?? []),
                    'style' => $style,
                    'size' => $size,
                    'quality' => $quality,
                ],
            ]);

            return [
                'provider_key'    => 'dalle',
                'image_url'       => $imageUrl,
                'image_b64'       => $imageB64,
                'width'           => (int) $width,
                'height'          => (int) $height,
                'seed'            => null,
                'revised_prompt'  => $image['revised_prompt'] ?? null,
            ];
        } catch (RequestException $e) {
            $message = $this->friendlyExceptionMessage($e);
            $this->usage->record([
                ...$usageContext,
                'provider' => 'openai',
                'service' => 'image_generation',
                'operation' => 'image',
                'model' => $model,
                'status' => 'failed',
                'units' => 1,
                'estimated_cost_usd' => 0,
                'error_code' => (string) $e->response?->status(),
                'error_message' => $message,
                'metadata_json' => [
                    ...($usageContext['metadata_json'] ?? []),
                    'style' => $style,
                    'size' => $size,
                    'quality' => $quality,
                ],
            ]);
            Log::error('DALL-E image generation failed', [
                'prompt' => $fullPrompt,
                'error'  => $e->getMessage(),
                'friendly_error' => $message,
            ]);
            throw new RuntimeException($message, previous: $e);
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

    private function friendlyExceptionMessage(RequestException $exception): string
    {
        $response = $exception->response;
        $status = $response?->status();
        $payload = $response?->json() ?? [];
        $error = is_array($payload['error'] ?? null) ? $payload['error'] : [];
        $code = strtolower((string) ($error['code'] ?? ''));
        $type = strtolower((string) ($error['type'] ?? ''));
        $message = strtolower((string) ($error['message'] ?? $exception->getMessage()));

        if (
            str_contains($code, 'content_policy') ||
            str_contains($type, 'content_policy') ||
            str_contains($message, 'safety') ||
            str_contains($message, 'policy')
        ) {
            return 'This image could not be generated because the prompt may violate the image safety policy. Please revise the prompt and try again.';
        }

        if ($status === 401 || str_contains($code, 'invalid_api_key')) {
            return 'Image generation is not configured correctly. Please contact support.';
        }

        if ($status === 429) {
            return 'Image generation is temporarily busy. Please wait a moment and try again.';
        }

        if ($status !== null && $status >= 500) {
            return 'The image provider is temporarily unavailable. Please try again shortly.';
        }

        return 'Image generation failed. Please revise the prompt and try again.';
    }
}
