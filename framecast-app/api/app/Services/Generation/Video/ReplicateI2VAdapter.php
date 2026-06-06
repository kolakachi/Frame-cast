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

        // Two Replicate API patterns:
        //   • Versioned (community models):  POST /v1/predictions  body { version, input }
        //   • Model-versioned (official):    POST /v1/models/{slug}/predictions  body { input }
        // We prefer the model-versioned pattern when no version is configured — it tracks
        // the model's current official version automatically. Falls back to versioned
        // when a hash is pinned in env.
        $url = $version
            ? 'https://api.replicate.com/v1/predictions'
            : "https://api.replicate.com/v1/models/{$modelSlug}/predictions";
        $body = $version
            ? ['version' => $version, 'input' => $input]
            : ['input' => $input];

        // 1. Kick off the prediction. Replicate throttles aggressively when an
        //    account's credit drops below their $5 threshold — 429 means
        //    "wait retry_after seconds, then try again." Retry up to 3 times
        //    with that exact backoff before giving up; if all three fail,
        //    propagate a clean message so the user sees something actionable.
        $maxRetries = 3;
        $attempt    = 0;
        $start      = null;
        while (true) {
            $start = Http::withToken($apiToken)
                ->acceptJson()
                ->post($url, $body);

            if ($start->successful()) {
                break;
            }

            if ($start->status() !== 429 || $attempt >= $maxRetries) {
                break;
            }

            // Respect Replicate's retry_after if present; cap at 30s so we
            // don't hold the worker indefinitely.
            $retryAfter = (int) ($start->json('retry_after') ?? $start->header('Retry-After') ?? 10);
            $retryAfter = min(max($retryAfter, 1), 30);

            Log::warning('Replicate i2v: 429 throttled, retrying', [
                'tier'         => $tier,
                'attempt'      => $attempt + 1,
                'max_retries'  => $maxRetries,
                'retry_after'  => $retryAfter,
                'body_excerpt' => mb_substr($start->body(), 0, 200),
            ]);

            sleep($retryAfter);
            $attempt++;
        }

        if (! $start->successful()) {
            $body = $start->body();
            Log::error('Replicate i2v: prediction start failed', [
                'tier'        => $tier,
                'model'       => $modelSlug,
                'status'      => $start->status(),
                'body'        => $body,
                'attempts'    => $attempt + 1,
            ]);
            // Format 429 specifically so the user gets a useful message.
            if ($start->status() === 429) {
                throw new RuntimeException(
                    "Replicate is rate-limiting your account (it reports 'less than \$5 in credit'). " .
                    "Visit replicate.com/account/billing to check your trial/promo credit balance — " .
                    "the loaded balance and the throttle's tracked credit are separate. " .
                    "We retried {$attempt} times with their suggested backoff. " .
                    "Original response: {$body}"
                );
            }
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
     * Input shapes per the model's default_example on Replicate (verified live):
     *   wan-video/wan-2.5-i2v   → image, prompt, duration, resolution, negative_prompt
     *   minimax/hailuo-2.3-fast → first_frame_image, prompt, duration, resolution, prompt_optimizer
     *   kwaivgi/kling-v2.1      → start_image, prompt, duration, negative_prompt, mode
     *
     * Supported durations differ per model — DON'T assume 5+10 universally:
     *   Wan 2.5         → 5 or 10
     *   Hailuo 2.3-fast → 6 or 10  ← was the bug; 5 here returned 422
     *   Kling 2.1       → 5 or 10
     *
     * Each tier picks the closest valid value to what the user asked for.
     *
     * @return array{0:string,1:?string,2:array<string,mixed>} [modelSlug, version, input]
     */
    private function buildRequestForTier(
        string $tier,
        string $imageUrl,
        string $prompt,
        int $durationSeconds,
        array $options
    ): array {
        // Per-tier valid durations sourced from the actual model schemas.
        // The frontend is also constrained to these, but we re-validate here
        // because the adapter is the source of truth.
        $validDurations = match ($tier) {
            'balanced' => [6, 10],
            'seedance_lite', 'seedance_pro' => [5, 10],
            default    => [5, 10],
        };
        $duration = $durationSeconds <= 7 ? $validDurations[0] : $validDurations[1];

        return match ($tier) {
            'premium' => [
                config('services.replicate.i2v_premium_model'),
                config('services.replicate.i2v_premium_version'),
                [
                    'start_image' => $imageUrl,
                    'prompt'      => $prompt !== '' ? $prompt : 'subtle natural motion, gentle camera drift',
                    'duration'    => $duration,
                    'mode'        => $options['kling_mode'] ?? 'standard',
                ],
            ],
            'balanced' => [
                config('services.replicate.i2v_balanced_model'),
                config('services.replicate.i2v_balanced_version'),
                [
                    'first_frame_image' => $imageUrl,
                    'prompt'            => $prompt !== '' ? $prompt : 'cinematic gentle motion',
                    'duration'          => $duration,
                    'prompt_optimizer'  => true,
                ],
            ],
            // Both Seedance variants share the same input shape: image,
            // prompt, duration. Pro produces higher fidelity; Lite is the
            // cheap iteration path.
            'seedance_lite' => [
                config('services.replicate.i2v_seedance_lite_model'),
                config('services.replicate.i2v_seedance_lite_version'),
                [
                    'image'    => $imageUrl,
                    'prompt'   => $prompt !== '' ? $prompt : 'subtle natural motion',
                    'duration' => $duration,
                ],
            ],
            'seedance_pro' => [
                config('services.replicate.i2v_seedance_pro_model'),
                config('services.replicate.i2v_seedance_pro_version'),
                [
                    'image'    => $imageUrl,
                    'prompt'   => $prompt !== '' ? $prompt : 'cinematic motion, gentle camera move',
                    'duration' => $duration,
                ],
            ],
            default => [
                config('services.replicate.i2v_quick_model'),
                config('services.replicate.i2v_quick_version'),
                [
                    'image'    => $imageUrl,
                    'prompt'   => $prompt !== '' ? $prompt : 'subtle natural motion',
                    'duration' => $duration,
                ],
            ],
        };
    }
}
