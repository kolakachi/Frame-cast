<?php

namespace App\Services\Generation\Image;

use App\Services\ApiUsageService;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Character / reference-image adapter, backed by OpenAI gpt-image-2.
 *
 * gpt-image-2's /v1/images/edits endpoint accepts one or more reference images
 * via `image[]` and produces a new image that respects the reference identity
 * while following the prompt's scene + style instructions. Used for any scene
 * bound to a Character with a reference photo, so faces stay consistent across
 * episodes.
 *
 * History (kept in comments so the why-this-vendor decision survives):
 *   - flux-pulid (Replicate): bimodal id_weight, drift OR plastic.
 *   - ideogram-character (Replicate): better identity, weaker scene control.
 *   - gpt-image-2 (OpenAI, current): strongest scene/style adherence while
 *     preserving identity from the reference image; same vendor as our text-only
 *     image path, so prompt phrasing transfers cleanly.
 *
 * The legacy class ReplicatePulidAdapter is gone; jobs now request this class
 * directly.
 */
class CharacterImageAdapter implements ImageGenerationAdapter
{
    private const SIZE_MAP = [
        '9:16' => '1024x1536',
        '16:9' => '1536x1024',
        '1:1'  => '1024x1024',
    ];

    public function __construct(
        private readonly ApiUsageService $usage,
    ) {
    }

    public function providerKey(): string
    {
        return 'openai:gpt-image-2';
    }

    /**
     * @param array<string,mixed> $options
     *   reference_image_url (required) — public/signed URL of the reference photo
     *   quality (optional)             — low|medium|high (default high for character work)
     *
     * @return array{provider_key:string,image_url:?string,image_b64:?string,width:int,height:int,seed:?int,revised_prompt:?string}
     */
    public function generate(
        string $prompt,
        string $style,
        string $aspectRatio = '9:16',
        array $options = []
    ): array {
        $apiKey = config('services.openai.api_key');
        if (! $apiKey) {
            throw new RuntimeException('OpenAI API key is not configured (OPENAI_API_KEY).');
        }

        $referenceUrl = $options['reference_image_url'] ?? null;
        if (! $referenceUrl) {
            throw new RuntimeException('Character adapter requires reference_image_url in options.');
        }

        $model   = (string) config('services.openai.character_model', 'gpt-image-2');
        $size    = self::SIZE_MAP[$aspectRatio] ?? self::SIZE_MAP['9:16'];
        $quality = match ((string) ($options['quality'] ?? 'high')) {
            'low'                => 'low',
            'standard', 'medium' => 'medium',
            default              => 'high',
        };

        // Style descriptor comes from the shared map so editor styles never silently drop.
        // Explicit identity-match clause helps gpt-image-2 weight the reference correctly
        // even when the prompt is heavy on scene/action.
        $styleDesc = ImageStyleDescriptors::for($style);
        $idClause  = 'Match the identity (face, hair, distinguishing features) of the provided reference image.';
        $fullPrompt = trim($styleDesc !== ''
            ? "{$prompt}. {$styleDesc}. {$idClause}"
            : "{$prompt}. {$idClause}");

        $usageContext = $this->usage->contextFromOptions($options);

        // Fetch the reference image bytes; gpt-image-2 /edits is multipart-only.
        $refResponse = Http::timeout(30)->get($referenceUrl);
        if (! $refResponse->successful()) {
            throw new RuntimeException("Could not fetch character reference image ({$refResponse->status()}).");
        }
        $refBytes    = $refResponse->body();
        $refFilename = 'reference.' . $this->guessExtension($refResponse->header('Content-Type'));

        try {
            $response = Http::withToken($apiKey)
                ->timeout(180)
                ->attach('image[]', $refBytes, $refFilename)
                ->post('https://api.openai.com/v1/images/edits', [
                    'model'   => $model,
                    'prompt'  => $fullPrompt,
                    'size'    => $size,
                    'quality' => $quality,
                    'n'       => 1,
                ])
                ->throw()
                ->json();

            $image    = $response['data'][0] ?? [];
            $imageUrl = $image['url']      ?? null;
            $imageB64 = $image['b64_json'] ?? null;
            if (! $imageUrl && ! $imageB64) {
                throw new RuntimeException('OpenAI character returned no image data.');
            }

            [$width, $height] = explode('x', $size);

            $this->usage->record([
                ...$usageContext,
                'provider' => 'openai',
                'service'  => 'image_generation',
                'operation' => 'image_edit',
                'model'    => $model,
                'status'   => 'succeeded',
                'units'    => 1,
                'estimated_cost_usd' => $this->usage->estimateImageCost($model, $quality, $size),
                'metadata_json' => [
                    ...($usageContext['metadata_json'] ?? []),
                    'style'    => $style,
                    'size'     => $size,
                    'quality'  => $quality,
                    'with_reference' => true,
                ],
            ]);

            return [
                'provider_key'   => $this->providerKey(),
                'image_url'      => $imageUrl,
                'image_b64'      => $imageB64,
                'width'          => (int) $width,
                'height'         => (int) $height,
                'seed'           => null,
                'revised_prompt' => $image['revised_prompt'] ?? null,
            ];
        } catch (RequestException $e) {
            $status = $e->response?->status();
            $body   = $e->response?->body() ?? $e->getMessage();
            Log::error('gpt-image-2 character generation failed', [
                'model'  => $model,
                'status' => $status,
                'body'   => $body,
            ]);
            $this->usage->record([
                ...$usageContext,
                'provider' => 'openai',
                'service'  => 'image_generation',
                'operation' => 'image_edit',
                'model'    => $model,
                'status'   => 'failed',
                'units'    => 1,
                'estimated_cost_usd' => 0,
                'error_code'    => (string) $status,
                'error_message' => $body,
                'metadata_json' => [
                    ...($usageContext['metadata_json'] ?? []),
                    'style'   => $style,
                    'size'    => $size,
                    'quality' => $quality,
                    'with_reference' => true,
                ],
            ]);
            throw new RuntimeException("OpenAI character ({$model}) failed ({$status}): {$body}", previous: $e);
        }
    }

    private function guessExtension(?string $contentType): string
    {
        return match (true) {
            str_contains((string) $contentType, 'png')  => 'png',
            str_contains((string) $contentType, 'webp') => 'webp',
            default                                     => 'jpg',
        };
    }
}
