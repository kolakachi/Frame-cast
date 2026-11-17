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
    /**
     * The input that sets the clip's last frame, per tier (Replicate schemas, read 2026-10-31). Wan 2.5 and Hailuo 2.3
     * take a first frame only.
     */
    public const END_FRAME_FIELDS = [
        'premium'       => 'end_image',
        'veo_fast'      => 'last_frame',
        'seedance_25'   => 'last_frame_image',
        'seedance_lite' => 'last_frame_image',
        'seedance_pro'  => 'last_frame_image',
    ];

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
            $why = "Replicate i2v ({$tier}) failed to start ({$start->status()}): {$body}";
            throw \App\Services\Generation\ProviderFailed::rejectedAtStart($start->status()) ? new \App\Services\Generation\ProviderFailed($why) : new RuntimeException($why);
        }

        $predictionId = $start->json('id');
        if (! $predictionId) {
            throw new RuntimeException('Replicate i2v: prediction id missing from start response.');
        }

        // Hand the prediction id back to the caller IMMEDIATELY (before the
        // long poll) so a worker death mid-poll can resume by re-attaching to
        // this same prediction — no re-charge. See ReapStuckGenerationsJob.
        if (isset($options['on_prediction_created']) && is_callable($options['on_prediction_created'])) {
            ($options['on_prediction_created'])((string) $predictionId, (string) $modelSlug);
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
                $err = (string) ($payload['error'] ?? 'unknown error');
                // Model-side content filters (Seedance 2.5 is the strict one —
                // it declines some realistic-people stills that Veo and Kling
                // accept) return an opaque "flagged as sensitive" ModelError.
                // Users see animation_last_error verbatim, so translate it and
                // say the part that matters: they were refunded.
                if (stripos($err, 'flagged as sensitive') !== false || str_contains($err, '(E005)')) {
                    throw new \App\Services\Generation\ProviderFailed(
                        "This model's content filter declined the image — it is strict, especially with realistic people. "
                        ."You were not charged. Try a different model (Veo 3.1 Fast handles the same images), or a different image."
                    );
                }
                // E005/E006 are provider content moderation dressed up as
                // 'invalid input' — proven A/B on the restyle lane. Say so.
                if (str_contains($err, 'E006') || str_contains($err, 'E005')) {
                    throw new \App\Services\Generation\ProviderFailed('The video model declined this image — its moderation flags some content (skin, shower or bathroom settings, weapons) even in tasteful ads. Rephrase the shot direction to a more covered framing and retry; nothing was charged.');
                }
                throw new \App\Services\Generation\ProviderFailed("Replicate i2v {$status}: {$err}");
            }
            // else: starting | processing — keep polling
        }

        if (! $videoUrl) {
            // Still running at the provider: keep the prediction for the reaper to collect.
            throw new PredictionStillRunning((string) $predictionId, (string) $modelSlug);
        }

        return [
            'provider_key'     => $this->providerKey(),
            'model_slug'       => $modelSlug,
            'video_url'        => (string) $videoUrl,
            'duration_seconds' => (int) $input['duration'],
            'width'            => null,
            'height'           => null,
        ];
    }

    /**
     * Resume path: poll an existing prediction by id. By the time we resume
     * (the reaper waits past the job timeout) it's usually already finished,
     * so a short bounded poll is plenty. Returns the video URL, null if still
     * processing (caller retries on the next sweep), throws on failure/expiry.
     */
    public function pollExisting(string $predictionId): ?string
    {
        $apiToken = config('services.replicate.api_token');
        if (! $apiToken) {
            throw new RuntimeException('Replicate API token is not configured (REPLICATE_API_TOKEN).');
        }

        $deadline = time() + 90;
        while (time() < $deadline) {
            $check = Http::withToken($apiToken)->acceptJson()
                ->get("https://api.replicate.com/v1/predictions/{$predictionId}");

            if ($check->status() === 404) {
                throw new RuntimeException('Replicate prediction is no longer available (expired).');
            }
            if (! $check->successful()) {
                sleep(self::POLL_INTERVAL_SECONDS);
                continue;
            }

            $payload = $check->json();
            $status  = $payload['status'] ?? 'unknown';

            if ($status === 'succeeded') {
                $output = $payload['output'] ?? null;
                $videoUrl = is_array($output) ? ($output[0] ?? null) : $output;
                if (! $videoUrl) {
                    throw new RuntimeException('Replicate prediction succeeded but returned no video URL.');
                }
                return (string) $videoUrl;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException("Replicate i2v {$status}: " . ($payload['error'] ?? 'unknown error'));
            }

            sleep(self::POLL_INTERVAL_SECONDS);
        }

        return null; // still processing — resume again on the next sweep
    }

    /** Stop a prediction we have given up on, so it is not billed further. Best effort. */
    public function cancel(string $predictionId): void
    {
        $apiToken = config('services.replicate.api_token');
        if (! $apiToken) {
            return;
        }
        rescue(fn () => Http::withToken($apiToken)->acceptJson()->post("https://api.replicate.com/v1/predictions/{$predictionId}/cancel"), report: false);
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
    public function buildRequestForTier(
        string $tier,
        string $imageUrl,
        string $prompt,
        int $durationSeconds,
        array $options
    ): array {
        // Each model's own lengths (owner, 2026-11-17: no 5-or-10 cap); see LENGTHS.
        $duration = self::lengthFor($tier, $durationSeconds);
        $withSound = ! empty($options['with_sound']) && self::takesSound($tier);

        $request = match ($tier) {
            // Resolution/mode are PINNED per tier so COGS is predictable and
            // matches CREDIT_CALIBRATION.md §12 — without an explicit value the
            // model picks its own default (Wan 720p, Seedance Pro 1080p, …),
            // which silently shifts our cost.
            'premium' => [
                config('services.replicate.i2v_premium_model'),
                config('services.replicate.i2v_premium_version'),
                [
                    'start_image' => $imageUrl,
                    'prompt'      => $prompt !== '' ? $prompt : 'subtle natural motion, gentle camera drift',
                    'duration'    => $duration,
                    'mode'        => $options['kling_mode'] ?? 'pro', // premium = Kling pro
                ],
            ],
            'veo_fast' => [
                config('services.replicate.i2v_veo_fast_model'),
                config('services.replicate.i2v_veo_fast_version'),
                [
                    'image'          => $imageUrl,
                    'prompt'         => $prompt !== '' ? $prompt : 'subtle natural motion, gentle camera drift',
                    'duration'       => $duration,
                    'resolution'     => $options['resolution'] ?? '720p',
                    // Silent unless asked: $0.10/s silent, $0.15/s with sound (Replicate, 2026-11-17). The voiceover is
                    // mixed at render, so sound is only made when the scene will play it.
                    'generate_audio' => $withSound,
                    // Veo supports only 16:9 / 9:16; 1:1 projects fall back to
                    // 9:16 and the renderer letterboxes as usual.
                    'aspect_ratio'   => in_array($options['aspect_ratio'] ?? '', ['16:9', '9:16'], true)
                        ? $options['aspect_ratio']
                        : '9:16',
                ],
            ],
            'seedance_25' => [
                config('services.replicate.i2v_seedance_25_model'),
                config('services.replicate.i2v_seedance_25_version'),
                [
                    'image'          => $imageUrl,
                    'prompt'         => $prompt !== '' ? $prompt : 'subtle natural motion',
                    'duration'       => $duration,
                    'resolution'     => $options['resolution'] ?? '480p',
                    // Sound costs nothing extra on Seedance 2.5 (non_video_in is one price, 2026-11-17).
                    'generate_audio' => $withSound,
                    'watermark'      => false,
                    // Follow the source image's shape.
                    'aspect_ratio'   => 'adaptive',
                ],
            ],
            'balanced' => [
                config('services.replicate.i2v_balanced_model'),
                config('services.replicate.i2v_balanced_version'),
                [
                    'first_frame_image' => $imageUrl,
                    'prompt'            => $prompt !== '' ? $prompt : 'cinematic gentle motion',
                    'duration'          => $duration,
                    'resolution'        => $options['resolution'] ?? '768p',
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
                    'image'      => $imageUrl,
                    'prompt'     => $prompt !== '' ? $prompt : 'subtle natural motion',
                    'duration'   => $duration,
                    'resolution' => $options['resolution'] ?? '720p',
                ],
            ],
            'seedance_pro' => [
                config('services.replicate.i2v_seedance_pro_model'),
                config('services.replicate.i2v_seedance_pro_version'),
                [
                    'image'      => $imageUrl,
                    'prompt'     => $prompt !== '' ? $prompt : 'cinematic motion, gentle camera move',
                    'duration'   => $duration,
                    'resolution' => $options['resolution'] ?? '1080p',
                ],
            ],
            default => [
                config('services.replicate.i2v_quick_model'),
                config('services.replicate.i2v_quick_version'),
                [
                    'image'      => $imageUrl,
                    'prompt'     => $prompt !== '' ? $prompt : 'subtle natural motion',
                    'duration'   => $duration,
                    'resolution' => $options['resolution'] ?? '480p', // quick default = 480p
                ],
            ],
        };

        // No start frame (owner, 2026-11-16): from words alone, or from reference images it follows. Only the models
        // whose image is optional (NO_IMAGE_TIERS); the frame field goes, and the shape is said outright since there is
        // no picture to follow.
        $refs = array_values(array_filter((array) ($options['reference_image_urls'] ?? []), 'is_string'));
        if ($imageUrl === '' && self::takesNoImage($tier)) {
            unset($request[2]['image']);
            if (str_starts_with($tier, 'seedance')) $request[2]['aspect_ratio'] = self::seedanceShape($options['aspect_ratio'] ?? '9:16');
            if ($refs && self::takesReferences($tier)) {
                $request[2]['reference_images'] = array_slice($refs, 0, 4);
                // Seedance 1 Lite takes references only below 1080p.
                if ($tier === 'seedance_lite' && ($request[2]['resolution'] ?? '') === '1080p') $request[2]['resolution'] = '720p';
                // Speak the scene's voiceover (Beta, 2026-11-17): Seedance 2.5 lip-syncs the referenced person to this audio.
                $audio = (string) ($options['reference_audio_url'] ?? '');
                if ($audio !== '' && $tier === 'seedance_25') {
                    $request[2]['reference_audios'] = [$audio];
                    $request[2]['prompt'] = trim($request[2]['prompt'].' The person in [Image1] speaks the words in [Audio1], lips in sync with it.');
                }
            }
            return $request;
        }

        // A storyboard's end frame (the editor's AI video tab): the clip runs from the start still to this one.
        $endUrl = (string) ($options['end_image_url'] ?? '');
        if ($endUrl !== '' && ($field = self::END_FRAME_FIELDS[$tier] ?? null) !== null) {
            $request[2][$field] = $endUrl;
            if ($tier === 'premium') $request[2]['mode'] = 'pro'; // Kling takes an end image in pro mode only
        }

        return $request;
    }

    /**
     * The lengths each model takes (Replicate schemas, read 2026-11-17): a list, or a min-max range in whole seconds.
     * Seedance 2.5 runs up to 30 s.
     */
    public const LENGTHS = [
        'quick'         => [5, 10],
        'balanced'      => [6, 10],
        'premium'       => [5, 10],
        'veo_fast'      => [4, 6, 8],
        'seedance_lite' => ['min' => 4, 'max' => 12],
        'seedance_pro'  => ['min' => 2, 'max' => 12],
        'seedance_25'   => ['min' => 4, 'max' => 30],
    ];

    /** Models that can make the clip's own sound (speech, effects, ambience). */
    public const SOUND_TIERS = ['veo_fast', 'seedance_25'];

    public static function takesSound(string $tier): bool
    {
        return in_array($tier, self::SOUND_TIERS, true);
    }

    /**
     * The length a model renders for a request. In a range: the request, held inside it. In a list: the request when
     * listed, else the old rule (up to 7 s the shortest, above it the longest), so callers asking 5 or 10 are unchanged.
     */
    public static function lengthFor(string $tier, int|float $requested): int
    {
        $lengths = self::LENGTHS[$tier] ?? [5, 10];
        $want = (int) ceil((float) $requested);
        if (isset($lengths['min'])) return max($lengths['min'], min($lengths['max'], $want));
        if (in_array($want, $lengths, true)) return $want;
        return $want <= 7 ? $lengths[0] : $lengths[count($lengths) - 1];
    }

    /** Models whose start image is optional: they make a clip from words alone (or from reference images). */
    public const NO_IMAGE_TIERS = ['veo_fast', 'seedance_25', 'seedance_lite', 'seedance_pro'];

    /** Models that follow reference images (a person or look to keep, not a frame); Replicate's reference_images. */
    public const REFERENCE_TIERS = ['seedance_25', 'seedance_lite'];

    public static function takesNoImage(string $tier): bool
    {
        return in_array($tier, self::NO_IMAGE_TIERS, true);
    }

    public static function takesReferences(string $tier): bool
    {
        return in_array($tier, self::REFERENCE_TIERS, true);
    }

    /** The project's shape in Seedance's terms (4:5 has no match: its nearest, 3:4). */
    private static function seedanceShape(string $ratio): string
    {
        return ['9:16' => '9:16', '16:9' => '16:9', '1:1' => '1:1', '4:5' => '3:4'][$ratio] ?? '9:16';
    }

    /** Whether a tier's model can end on a given frame. */
    public static function takesEndFrame(string $tier): bool
    {
        return isset(self::END_FRAME_FIELDS[$tier]);
    }
}
