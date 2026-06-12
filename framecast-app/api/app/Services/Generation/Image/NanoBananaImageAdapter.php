<?php

namespace App\Services\Generation\Image;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Google nano-banana (Gemini 2.5 Flash Image) on Replicate.
 *
 * Cheap + fast portrait + product shot model. ~$0.04 per image vs ~$0.17
 * for gpt-image-1 medium. Strong at photoreal portraits, weak at heavy
 * style transfer — so we don't apply the style-prefix prompt prefixing
 * that DALL-E uses. Just pass the prompt through with a "no text"
 * suffix that nano-banana respects.
 *
 * Versionless `/v1/models/{owner}/{name}/predictions` works — nano-banana
 * is in Replicate's "official models" registry.
 */
class NanoBananaImageAdapter implements ImageGenerationAdapter
{
    private const POLL_INTERVAL_SEC = 2;
    private const MAX_POLL_ITERATIONS = 60; // 2 min ceiling

    private const ASPECT_RATIO_HINT = [
        '9:16' => ' Vertical 9:16 portrait composition.',
        '16:9' => ' Horizontal 16:9 landscape composition.',
        '1:1'  => ' Square 1:1 composition.',
        '4:5'  => ' Vertical 4:5 portrait composition.',
    ];

    /** Replicate model slug — overridden by the Pro variant. */
    protected function modelSlug(): string
    {
        return 'google/nano-banana';
    }

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

        $hint = self::ASPECT_RATIO_HINT[$aspectRatio] ?? self::ASPECT_RATIO_HINT['9:16'];
        $fullPrompt = trim($prompt) . $hint
            . ' No text, no watermarks, no captions.';

        // Reference images (character/likeness). nano-banana (Gemini 2.5 Flash
        // Image) accepts an image_input array and preserves identity / skin
        // tone far better than gpt-image-2 under style changes.
        $refs = $options['reference_image_urls']
            ?? (isset($options['reference_image_url']) ? [$options['reference_image_url']] : []);
        $refs = array_slice(array_values(array_filter((array) $refs)), 0, 4);

        $url = 'https://api.replicate.com/v1/models/'.$this->modelSlug().'/predictions';
        $input = [
            'prompt'        => $fullPrompt,
            'output_format' => 'png',
        ];
        if (! empty($refs)) {
            $input['image_input'] = $refs;
        }
        $body = ['input' => $input];

        $start = Http::withToken($apiToken)
            ->withHeaders(['Prefer' => 'wait=10'])
            ->timeout(30)
            ->post($url, $body);

        if (! $start->successful()) {
            throw new RuntimeException("nano-banana failed to start ({$start->status()}): {$start->body()}");
        }

        $prediction = $start->json();
        $id = $prediction['id'] ?? null;

        for ($i = 0; $i < self::MAX_POLL_ITERATIONS; $i++) {
            $status = $prediction['status'] ?? null;
            if ($status === 'succeeded') {
                $output = $prediction['output'] ?? null;
                $imageUrl = is_array($output) ? ($output[0] ?? null) : $output;
                if (! $imageUrl) {
                    throw new RuntimeException('nano-banana succeeded but returned no image URL.');
                }
                return [
                    'provider_key'   => 'replicate:'.$this->modelSlug(),
                    'image_url'      => (string) $imageUrl,
                    'image_b64'      => null,
                    // nano-banana doesn't expose post-gen dims; use the aspect-ratio defaults.
                    'width'          => $aspectRatio === '16:9' ? 1344 : ($aspectRatio === '1:1' ? 1024 : 768),
                    'height'         => $aspectRatio === '16:9' ? 768 : ($aspectRatio === '1:1' ? 1024 : 1344),
                    'seed'           => null,
                    'revised_prompt' => null,
                ];
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException("nano-banana {$status}: " . ($prediction['error'] ?? 'unknown'));
            }
            sleep(self::POLL_INTERVAL_SEC);
            $get = Http::withToken($apiToken)->timeout(10)
                ->get("https://api.replicate.com/v1/predictions/{$id}");
            if (! $get->successful()) {
                throw new RuntimeException("nano-banana poll failed ({$get->status()}).");
            }
            $prediction = $get->json();
        }

        throw new RuntimeException('nano-banana polling timed out.');
    }

    public function providerKey(): string
    {
        return 'replicate:'.$this->modelSlug();
    }
}
