<?php

namespace App\Services\Generation\Video;

use Illuminate\Support\Facades\Http;

/**
 * Veo 3.1 Fast on Replicate — the one-full-video engine: dialogue in the
 * prompt comes back spoken, acted and lip-synced with native audio. An
 * optional start image anchors identity, which is how chained segments
 * keep the same presenter (probe-proven before this was written).
 */
class ReplicateVeoAdapter
{
    public const MODEL = 'google/veo-3.1-fast';

    /** One-shot engines. Seedance 2.5 generates up to 30s in one pass. */
    public const ENGINES = [
        'seedance25' => 'bytedance/seedance-2.5',
        'veo' => 'google/veo-3.1-fast',
        // Google's top renderer — and unlike fast it takes reference_images
        // natively, so HQ exact takes carry face AND product photos.
        'veo_hq' => 'google/veo-3.1',
        // Gemini Omni 1.1 — takes reference_images natively and, unlike
        // Seedance, does not decline a photoreal face, so a cast presenter
        // runs here at 22cr/s instead of veo_hq's 58. UGC only for now.
        'omni' => 'google/gemini-omni-1.1',
    ];

    /**
     * Omni exposes no duration input: length is the model's to choose and a
     * target in the prompt only nudges it (probe: asked 2s, got 3.01s). This
     * is the ceiling we quote and reserve against; the actual seconds come
     * back in metrics.video_output_duration_seconds and the rest is refunded.
     */
    public const OMNI_MAX_SECONDS = 10;

    public function configured(): bool
    {
        return (string) config('services.replicate.api_token', '') !== '';
    }

    /** Seedance through OpenRouter for the week's trial (owner, 2026-10-18); Replicate's otherwise. */
    public static function seedanceViaOpenRouter(): bool
    {
        return (bool) config('services.ugc.seedance_openrouter') && app(OpenRouterVideoAdapter::class)->configured();
    }

    /**
     * @param array<int, string> $referenceImages data URIs or URLs; Seedance-only, exclusive with a start frame
     * @param ?string $audioUrl the presenter's read as an audio reference (Seedance through OpenRouter only)
     */
    public function start(string $prompt, int $seconds, ?string $imageDataUri = null, string $engine = 'veo', array $referenceImages = [], ?int $seed = null, string $resolution = '720p', array $referenceVideos = [], string $aspectRatio = '9:16', ?string $audioUrl = null): string
    {
        // The UGC models offered through OpenRouter (UgcVideoModels, 2026-10-21) go there, with their own shapes.
        if (\App\Services\Ugc\UgcVideoModels::has($engine)) {
            if (! in_array($aspectRatio, \App\Services\Ugc\UgcVideoModels::MODELS[$engine]['aspects'], true)) throw new \InvalidArgumentException(\App\Services\Ugc\UgcVideoModels::MODELS[$engine]['label'].' does not make '.$aspectRatio.' videos.');
            return 'or:'.app(OpenRouterVideoAdapter::class)->start($engine, $prompt, $seconds, $imageDataUri, $referenceImages, $seed, $resolution, $aspectRatio, $audioUrl);
        }
        // Seedance 2.5 also makes square and 4:3 shapes (a split-screen half); the others are portrait or landscape.
        if (! in_array($aspectRatio, $engine === 'seedance25' ? ['9:16', '16:9', '1:1', '4:3', '3:4', '21:9'] : ['9:16', '16:9'], true)) throw new \InvalidArgumentException('This video model supports portrait or landscape.');
        if ($engine === 'seedance25' && self::seedanceViaOpenRouter()) {
            return 'or:'.app(OpenRouterVideoAdapter::class)->start($engine, $prompt, $seconds, $imageDataUri, $referenceImages, $seed, $resolution, $aspectRatio, $audioUrl);
        }
        $model = self::ENGINES[$engine] ?? self::ENGINES['veo'];

        // Gemini Omni 1.1 accepts only prompt, image, last_frame,
        // reference_images, video, aspect_ratio and resolution. It has no
        // duration, no seed and no generate_audio — audio is always native —
        // and the schema rejects what it does not know, so its input is built
        // on its own rather than layered onto the shared one.
        if ($engine === 'omni') {
            $target = max(1, min(self::OMNI_MAX_SECONDS, $seconds));
            $input = [
                'prompt' => rtrim($prompt, " \t\n")." Keep the clip to about {$target} seconds.",
                'aspect_ratio' => $aspectRatio,
                // No 480p on this model; the draft tier never reaches it.
                'resolution' => in_array($resolution, ['360p', '720p', '1080p', '4k'], true) ? $resolution : '720p',
            ];
            if ($imageDataUri !== null) {
                $input['image'] = $imageDataUri;
            }
            if ($referenceImages !== [] && $imageDataUri === null) {
                // With no seed to lean on, the reference set is the only thing
                // holding the presenter's identity steady across chunks, so it
                // rides on every one rather than just the first. Omni refuses
                // references alongside a start frame (2026-10-05), so a start
                // frame, which already shows the person, wins.
                $input['reference_images'] = array_slice(array_values($referenceImages), 0, 3);
            }
        } else {
            $input = [
                'prompt' => $prompt,
                'aspect_ratio' => $aspectRatio,
                // Seedance offers 480p (draft) and 720p; Veo stays 720p.
                'resolution' => in_array($resolution, ['480p', '720p'], true) ? $resolution : '720p',
                'generate_audio' => true,
            ];
            if ($engine === 'seedance25') {
                $input['duration'] = max(4, min(30, $seconds));
                $input['watermark'] = false;
                // A per-character seed nudges repeat variant takes toward the
                // same rendition of the casting sheet (probe: small but free).
                if ($seed !== null) {
                    $input['seed'] = $seed;
                }
            } else {
                // Veo accepts exactly 4, 6 or 8 seconds.
                $input['duration'] = in_array($seconds, [4, 6, 8], true) ? $seconds : (($seconds <= 4) ? 4 : ($seconds <= 6 ? 6 : 8));
            }
            if ($imageDataUri !== null) {
                $input['image'] = $imageDataUri;
                // veo-3.1 (non-fast) also accepts reference stills alongside the
                // start frame — product photos ride natively on the HQ tier.
                if ($engine === 'veo_hq' && $referenceImages !== []) {
                    $input['reference_images'] = array_slice(array_values($referenceImages), 0, 3);
                }
            } elseif ($engine === 'veo_hq' && $referenceImages !== []) {
                // Character face (and product) as reference WITHOUT a start frame:
                // the model keeps the face but invents the scene from the prompt,
                // so the take is creative rather than locked to a still's setting.
                $input['reference_images'] = array_slice(array_values($referenceImages), 0, 3);
            } elseif ($engine === 'seedance25' && $referenceImages !== []) {
                // The pose sheet: character and product stills the presenter and
                // packaging must match. Exclusive with a start frame by schema.
                $input['reference_images'] = array_slice(array_values($referenceImages), 0, 30);
            }
            // A real demo/screen-recording clip embedded as [Video1..]. This is
            // the video_in path — reference videos moderate cleanly when they
            // carry no face — and Seedance forces an adaptive output ratio for
            // any task that takes a video input (fixed ratios 422).
            if ($engine === 'seedance25' && $referenceVideos !== []) {
                $input['reference_videos'] = array_slice(array_values($referenceVideos), 0, 10);
                $input['aspect_ratio'] = 'adaptive';
            }
        }

        $response = Http::withToken((string) config('services.replicate.api_token'))
            ->timeout(60)
            ->post('https://api.replicate.com/v1/models/'.$model.'/predictions', ['input' => $input]);

        $id = (string) data_get($response->json(), 'id');
        if (! $response->successful() || $id === '') {
            $why = 'Veo submit failed: '.mb_substr($response->body(), 0, 200);
            // Our Replicate account running dry or misconfigured alerts the team at once (2026-10-10: it ran out with
            // nobody told, and every UGC video failed).
            \App\Services\Vendors\VendorAlerts::observe('replicate', $why, $response->status(), ['kind' => 'video:'.$engine]);
            throw \App\Services\Generation\ProviderFailed::rejectedAtStart($response->status()) ? new \App\Services\Generation\ProviderFailed($why) : new \RuntimeException($why);
        }

        \App\Services\Vendors\VendorAlerts::recovered('replicate');

        return $id;
    }

    /** Output URL on success, null on timeout; throws (honestly) on failure. */
    /** Stop a prediction we no longer wait for (one that hung), so it neither runs on nor bills. */
    public function cancel(string $predictionId): void
    {
        if (str_starts_with($predictionId, 'or:')) return; // OpenRouter's video API has no cancel
        rescue(fn () => Http::withToken((string) config('services.replicate.api_token'))->acceptJson()->timeout(20)
            ->post('https://api.replicate.com/v1/predictions/'.$predictionId.'/cancel'), report: false);
    }

    public function pollUntilDone(string $predictionId, int $maxSeconds = 600): ?string
    {
        if (str_starts_with($predictionId, 'or:')) return app(OpenRouterVideoAdapter::class)->pollUntilDone(substr($predictionId, 3), max($maxSeconds, 900));
        $deadline = time() + $maxSeconds;
        while (time() < $deadline) {
            $p = Http::withToken((string) config('services.replicate.api_token'))
                ->timeout(30)
                ->get('https://api.replicate.com/v1/predictions/'.$predictionId)
                ->json();
            $status = (string) ($p['status'] ?? '');
            if ($status === 'succeeded') {
                $output = $p['output'] ?? null;

                return is_array($output) ? (string) ($output[0] ?? '') : (string) $output;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                $error = (string) ($p['error'] ?? 'no detail');
                // The raw provider error carries the E-code that diagnosis
                // needs; the user-facing copy below deliberately does not.
                \Illuminate\Support\Facades\Log::info('video generation declined/failed', [
                    'prediction_id' => $predictionId, 'error' => mb_substr($error, 0, 500),
                ]);
                if (str_contains($error, 'E006') || str_contains($error, 'E005') || stripos($error, 'sensitive') !== false) {
                    throw new \App\Services\Generation\ProviderFailed('The video model declined this segment — its moderation flags some content even in tasteful ads. Nothing was charged; rephrase the framing and retry.');
                }
                throw new \App\Services\Generation\ProviderFailed('Veo generation failed: '.mb_substr($error, 0, 300));
            }
            sleep(10);
        }

        return null;
    }
}
