<?php

namespace App\Services\Generation\Image;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Black Forest Labs Flux Schnell on Replicate. 4-step distilled FLUX.1
 * model — extremely fast (~3-5s), ~$0.003 per image. Style-flexible
 * (handles anime, illustration, photoreal). Lower fidelity than
 * Flux Pro / SDXL Lightning but cheap enough to be the throwaway default
 * for users iterating on prompts.
 *
 * Versionless endpoint works — flux-schnell is in the official models list.
 */
class FluxSchnellImageAdapter implements ImageGenerationAdapter
{
    private const POLL_INTERVAL_SEC = 1;
    private const MAX_POLL_ITERATIONS = 40;

    private const ASPECT_RATIO_MAP = [
        '9:16' => '9:16',
        '16:9' => '16:9',
        '1:1'  => '1:1',
        '4:5'  => '4:5',
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

        $ratio = self::ASPECT_RATIO_MAP[$aspectRatio] ?? '9:16';

        // Style hint as a simple keyword block — Flux doesn't need verbose
        // style prefixing the way DALL-E does, the prompt encoder is strong.
        $styleHint = match ($style) {
            'cinematic'     => 'cinematic, dramatic lighting,',
            'photorealistic'=> 'photorealistic,',
            'anime'         => 'anime style,',
            'comic'         => 'comic book illustration,',
            'watercolor'    => 'watercolor,',
            'minimalist'    => 'minimalist,',
            '3d_animated'   => '3D animated, Pixar style,',
            default         => '',
        };
        $fullPrompt = trim("{$styleHint} {$prompt}. No text, no watermarks.");

        $url = 'https://api.replicate.com/v1/models/black-forest-labs/flux-schnell/predictions';
        $body = [
            'input' => [
                'prompt'        => $fullPrompt,
                'aspect_ratio'  => $ratio,
                'num_outputs'   => 1,
                'output_format' => 'png',
                'output_quality'=> 90,
            ],
        ];

        $start = Http::withToken($apiToken)
            ->withHeaders(['Prefer' => 'wait=10'])
            ->timeout(30)
            ->post($url, $body);

        if (! $start->successful()) {
            throw new RuntimeException("flux-schnell failed to start ({$start->status()}): {$start->body()}");
        }

        $prediction = $start->json();
        $id = $prediction['id'] ?? null;

        for ($i = 0; $i < self::MAX_POLL_ITERATIONS; $i++) {
            $status = $prediction['status'] ?? null;
            if ($status === 'succeeded') {
                $output = $prediction['output'] ?? null;
                $imageUrl = is_array($output) ? ($output[0] ?? null) : $output;
                if (! $imageUrl) {
                    throw new RuntimeException('flux-schnell succeeded but returned no image URL.');
                }
                return [
                    'provider_key'   => 'replicate:black-forest-labs/flux-schnell',
                    'image_url'      => (string) $imageUrl,
                    'image_b64'      => null,
                    'width'          => $aspectRatio === '16:9' ? 1344 : ($aspectRatio === '1:1' ? 1024 : 768),
                    'height'         => $aspectRatio === '16:9' ? 768 : ($aspectRatio === '1:1' ? 1024 : 1344),
                    'seed'           => $prediction['input']['seed'] ?? null,
                    'revised_prompt' => null,
                ];
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException("flux-schnell {$status}: " . ($prediction['error'] ?? 'unknown'));
            }
            sleep(self::POLL_INTERVAL_SEC);
            $get = Http::withToken($apiToken)->timeout(10)
                ->get("https://api.replicate.com/v1/predictions/{$id}");
            if (! $get->successful()) {
                throw new RuntimeException("flux-schnell poll failed ({$get->status()}).");
            }
            $prediction = $get->json();
        }

        throw new RuntimeException('flux-schnell polling timed out.');
    }

    public function providerKey(): string
    {
        return 'replicate:black-forest-labs/flux-schnell';
    }
}
