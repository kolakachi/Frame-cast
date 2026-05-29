<?php

namespace App\Services\Generation\Video;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Image-to-video adapter routed through Replicate.
 *
 * One adapter, three tiers, each mapped to a different upstream model so the user-facing
 * choice stays simple (Quick / Balanced / Premium) while we keep flexibility to swap
 * specific models via env without touching code.
 */
class ReplicateI2VAdapter implements I2VAdapter
{
    private const POLL_INTERVAL_SECONDS = 3;
    private const POLL_TIMEOUT_SECONDS  = 360; // i2v can take 3–6 min on premium models

    public function providerKey(): string
    {
        return 'replicate:i2v';
    }

    public function animate(
        string $imageUrl,
        string $prompt,
        string $tier = 'quick',
        int $durationSeconds = 6,
        array $options = []
    ): array {
        $apiToken = config('services.replicate.api_token');
        if (! $apiToken) {
            throw new RuntimeException('Replicate API token is not configured (REPLICATE_API_TOKEN).');
        }

        [$modelSlug, $version, $input] = $this->buildRequestForTier(
            $tier, $imageUrl, $prompt, $durationSeconds, $options
        );
        if (! $version) {
            throw new RuntimeException("No Replicate version configured for i2v tier '{$tier}'.");
        }

        // 1. Kick off the prediction.
        $start = Http::withToken($apiToken)
            ->acceptJson()
            ->post('https://api.replicate.com/v1/predictions', [
                'version' => $version,
                'input'   => $input,
            ]);

        if (! $start->successful()) {
            $body = $start->body();
            Log::error('Replicate i2v: prediction start failed', [
                'tier'   => $tier,
                'model'  => $modelSlug,
                'status' => $start->status(),
                'body'   => $body,
            ]);
            throw new RuntimeException("Replicate i2v ({$tier}) failed to start ({$start->status()}): {$body}");
        }

        $predictionId = $start->json('id');
        if (! $predictionId) {
            throw new RuntimeException('Replicate i2v: prediction id missing from start response.');
        }

        // 2. Poll until succeeded / failed / canceled.
        $videoUrl = null;
        $deadline = time() + self::POLL_TIMEOUT_SECONDS;
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
                // Most i2v models return a single URL; some return an array.
                $videoUrl = is_array($output) ? ($output[0] ?? null) : $output;
                break;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                $err = $payload['error'] ?? 'unknown error';
                throw new RuntimeException("Replicate i2v {$status}: {$err}");
            }
            // else: starting | processing — keep polling
        }

        if (! $videoUrl) {
            throw new RuntimeException('Replicate i2v did not return a video within the polling window.');
        }

        return [
            'provider_key'     => $this->providerKey(),
            'model_slug'       => $modelSlug,
            'video_url'        => (string) $videoUrl,
            'duration_seconds' => $durationSeconds,
            'width'            => null,
            'height'           => null,
        ];
    }

    /**
     * @return array{0:string,1:?string,2:array<string,mixed>} [modelSlug, version, input]
     */
    private function buildRequestForTier(
        string $tier,
        string $imageUrl,
        string $prompt,
        int $durationSeconds,
        array $options
    ): array {
        $clamped = max(3, min(10, $durationSeconds));

        return match ($tier) {
            'premium' => [
                config('services.replicate.i2v_premium_model'),
                config('services.replicate.i2v_premium_version'),
                [
                    'start_image' => $imageUrl,
                    'prompt'      => $prompt !== '' ? $prompt : 'subtle natural motion, gentle camera drift',
                    // Kling accepts duration 5 or 10
                    'duration'    => $clamped <= 7 ? 5 : 10,
                    'aspect_ratio' => $options['aspect_ratio'] ?? '9:16',
                ],
            ],
            'balanced' => [
                config('services.replicate.i2v_balanced_model'),
                config('services.replicate.i2v_balanced_version'),
                [
                    'first_frame_image' => $imageUrl,
                    'prompt'            => $prompt !== '' ? $prompt : 'cinematic gentle motion',
                    // Hailuo accepts duration 6 or 10
                    'duration'          => $clamped <= 7 ? 6 : 10,
                    'prompt_optimizer'  => true,
                ],
            ],
            default => [
                config('services.replicate.i2v_quick_model'),
                config('services.replicate.i2v_quick_version'),
                [
                    'image'         => $imageUrl,
                    'prompt'        => $prompt !== '' ? $prompt : 'subtle natural motion',
                    // Wan 2.1 expects num_frames; 81 frames at 16fps ≈ 5s
                    'num_frames'    => min(81, max(33, $clamped * 16)),
                    'fps'           => 16,
                    'guide_scale'   => 5.0,
                ],
            ],
        };
    }
}
