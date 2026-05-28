<?php

namespace App\Services\Generation\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Replicate flux-pulid adapter for character / face-consistency image generation.
 *
 * Used when a scene is bound to a Character that has a reference image. The reference
 * photo carries the identity; the prompt describes the scene/action. This is what gives
 * recurring characters a consistent face across episodes.
 *
 * For text-only generation we still use DalleImageAdapter — that's the default binding.
 */
class ReplicatePulidAdapter implements ImageGenerationAdapter
{
    private const POLL_INTERVAL_SECONDS = 2;
    private const POLL_TIMEOUT_SECONDS  = 180;

    private const ASPECT_RATIO_DIMENSIONS = [
        '9:16' => ['width' => 768,  'height' => 1344],
        '16:9' => ['width' => 1344, 'height' => 768],
        '1:1'  => ['width' => 1024, 'height' => 1024],
    ];

    // Style modifiers come from ImageStyleDescriptors — same source the editor's
    // picker and every other adapter share, so styles never silently drop.

    public function providerKey(): string
    {
        return 'replicate:flux-pulid';
    }

    /**
     * @param array<string,mixed> $options
     *   reference_image_url (required) — public URL of the character's reference photo
     *   identity_scale (optional)      — 0.0–1.0, default 0.8; how strongly the reference constrains identity
     *
     * @return array{provider_key:string,image_url:?string,image_b64:?string,width:int,height:int,seed:?int,revised_prompt:?string}
     */
    public function generate(
        string $prompt,
        string $style,
        string $aspectRatio = '9:16',
        array $options = []
    ): array {
        $apiToken = config('services.replicate.api_token');
        if (! $apiToken) {
            throw new RuntimeException('Replicate API token is not configured (REPLICATE_API_TOKEN).');
        }

        $referenceUrl = $options['reference_image_url'] ?? null;
        if (! $referenceUrl) {
            throw new RuntimeException('ReplicatePulidAdapter requires reference_image_url in options.');
        }

        $dims        = self::ASPECT_RATIO_DIMENSIONS[$aspectRatio] ?? self::ASPECT_RATIO_DIMENSIONS['9:16'];
        $styleDesc   = ImageStyleDescriptors::for($style);
        $version     = config('services.replicate.pulid_version');

        // Three identity tuning knobs — defaults from services.replicate config,
        // each overridable per-call via options for future per-character/per-scene control.
        $idWeight      = (float) ($options['identity_scale'] ?? config('services.replicate.pulid_id_weight', 1.2));
        $guidanceScale = (float) ($options['guidance_scale'] ?? config('services.replicate.pulid_guidance_scale', 3));
        $numSteps      = (int)   ($options['num_steps']      ?? config('services.replicate.pulid_num_steps', 25));

        $fullPrompt = trim($styleDesc !== '' ? "{$prompt}. {$styleDesc}" : $prompt);

        // 1. Kick off the prediction.
        $start = Http::withToken($apiToken)
            ->acceptJson()
            ->post('https://api.replicate.com/v1/predictions', [
                'version' => $version,
                'input'   => [
                    'prompt'           => $fullPrompt,
                    'main_face_image'  => $referenceUrl,
                    'width'            => $dims['width'],
                    'height'           => $dims['height'],
                    'id_weight'        => $idWeight,
                    'num_outputs'      => 1,
                    'guidance_scale'   => $guidanceScale,
                    'num_steps'        => $numSteps,
                ],
            ]);

        if (! $start->successful()) {
            $body = $start->body();
            Log::error('Replicate flux-pulid: prediction start failed', ['status' => $start->status(), 'body' => $body]);
            throw new RuntimeException("Replicate prediction failed to start ({$start->status()}): {$body}");
        }

        $prediction = $start->json();
        $predictionId = $prediction['id'] ?? null;
        if (! $predictionId) {
            throw new RuntimeException('Replicate returned no prediction id.');
        }

        // 2. Poll until completion (succeeded | failed | canceled).
        $deadline = time() + self::POLL_TIMEOUT_SECONDS;
        $imageUrl = null;
        while (time() < $deadline) {
            sleep(self::POLL_INTERVAL_SECONDS);
            $check = Http::withToken($apiToken)->acceptJson()
                ->get("https://api.replicate.com/v1/predictions/{$predictionId}");
            if (! $check->successful()) {
                continue;
            }
            $payload = $check->json();
            $status = $payload['status'] ?? 'unknown';

            if ($status === 'succeeded') {
                $output = $payload['output'] ?? null;
                $imageUrl = is_array($output) ? ($output[0] ?? null) : $output;
                break;
            }

            if (in_array($status, ['failed', 'canceled'], true)) {
                $err = $payload['error'] ?? 'unknown error';
                throw new RuntimeException("Replicate flux-pulid {$status}: {$err}");
            }
            // else: starting | processing → keep polling
        }

        if (! $imageUrl) {
            throw new RuntimeException('Replicate flux-pulid did not return an image within the polling window.');
        }

        return [
            'provider_key'   => $this->providerKey(),
            'image_url'      => (string) $imageUrl,
            'image_b64'      => null,
            'width'          => $dims['width'],
            'height'         => $dims['height'],
            'seed'           => null,
            'revised_prompt' => null,
        ];
    }
}
