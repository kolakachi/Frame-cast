<?php

namespace App\Services\Generation\Image;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * ByteDance SDXL Lightning 4-step on Replicate. Distilled SDXL,
 * ~4 inference steps, ~2-4s per image, ~$0.0028 per image. Sweet spot
 * between cheap (flux-schnell) and quality (nano-banana / DALL-E):
 * strong style-transfer, decent photoreal, very fast iteration.
 *
 * Versionless endpoint works.
 */
class SdxlLightningImageAdapter implements ImageGenerationAdapter
{
    private const POLL_INTERVAL_SEC = 1;
    private const MAX_POLL_ITERATIONS = 40;

    private const ASPECT_RATIO_DIMS = [
        '9:16' => ['width' => 768,  'height' => 1344],
        '16:9' => ['width' => 1344, 'height' => 768],
        '1:1'  => ['width' => 1024, 'height' => 1024],
        '4:5'  => ['width' => 896,  'height' => 1152],
    ];

    public function generate(
        string $prompt,
        string $style,
        string $aspectRatio = '9:16',
        array $options = []
    ): array {
        $apiToken = config('services.replicate.api_token');
        if (! $apiToken) {
            throw new RuntimeException('Replicate API token not configured.');
        }

        $dims = self::ASPECT_RATIO_DIMS[$aspectRatio] ?? self::ASPECT_RATIO_DIMS['9:16'];

        $styleHint = match ($style) {
            'cinematic'     => 'cinematic, dramatic lighting, film grain,',
            'photorealistic'=> 'photorealistic, sharp focus, 8k,',
            'anime'         => 'anime style, cel-shaded, vibrant,',
            'comic'         => 'bold ink comic illustration, halftone,',
            'watercolor'    => 'soft watercolor, paper texture,',
            'minimalist'    => 'minimalist, clean negative space,',
            '3d_animated'   => '3D animated, Pixar Disney style, volumetric lighting,',
            'neon'          => 'neon lights, cyberpunk, vivid colors,',
            'vintage'       => 'vintage film, faded colors, grain,',
            default         => '',
        };
        $fullPrompt = trim("{$styleHint} {$prompt}. No text, no watermarks.");

        $url = 'https://api.replicate.com/v1/models/bytedance/sdxl-lightning-4step/predictions';
        $body = [
            'input' => [
                'prompt'              => $fullPrompt,
                'negative_prompt'     => 'text, watermark, blurry, low quality, deformed',
                'width'               => $dims['width'],
                'height'              => $dims['height'],
                'num_outputs'         => 1,
                'num_inference_steps' => 4,
                'guidance_scale'      => 0,
                'scheduler'           => 'K_EULER',
            ],
        ];

        $start = Http::withToken($apiToken)
            ->withHeaders(['Prefer' => 'wait=10'])
            ->timeout(30)
            ->post($url, $body);

        if (! $start->successful()) {
            throw new RuntimeException("sdxl-lightning failed to start ({$start->status()}): {$start->body()}");
        }

        $prediction = $start->json();
        $id = $prediction['id'] ?? null;

        for ($i = 0; $i < self::MAX_POLL_ITERATIONS; $i++) {
            $status = $prediction['status'] ?? null;
            if ($status === 'succeeded') {
                $output = $prediction['output'] ?? null;
                $imageUrl = is_array($output) ? ($output[0] ?? null) : $output;
                if (! $imageUrl) {
                    throw new RuntimeException('sdxl-lightning succeeded but returned no image URL.');
                }
                return [
                    'provider_key'   => 'replicate:bytedance/sdxl-lightning-4step',
                    'image_url'      => (string) $imageUrl,
                    'image_b64'      => null,
                    'width'          => $dims['width'],
                    'height'         => $dims['height'],
                    'seed'           => $prediction['input']['seed'] ?? null,
                    'revised_prompt' => null,
                ];
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException("sdxl-lightning {$status}: " . ($prediction['error'] ?? 'unknown'));
            }
            sleep(self::POLL_INTERVAL_SEC);
            $get = Http::withToken($apiToken)->timeout(10)
                ->get("https://api.replicate.com/v1/predictions/{$id}");
            if (! $get->successful()) {
                throw new RuntimeException("sdxl-lightning poll failed ({$get->status()}).");
            }
            $prediction = $get->json();
        }

        throw new RuntimeException('sdxl-lightning polling timed out.');
    }

    public function providerKey(): string
    {
        return 'replicate:bytedance/sdxl-lightning-4step';
    }
}
