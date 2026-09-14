<?php

namespace App\Services\Generation\Video;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talking-spokesperson generation via VEED Fabric 1.0 on Replicate: a still
 * character image + a voice audio track -> a lip-synced talking video.
 * Reuses our existing Replicate token (no new provider key). Inputs: image,
 * audio (both URLs), resolution (480p|720p). Renders are SLOW — a few seconds
 * of audio can take a few minutes — so the poll window is generous and the
 * prediction id is surfaced for resume.
 */
class ReplicateFabricAdapter
{
    public function providerKey(): string
    {
        return 'replicate:'.(string) self::engine()['model'];
    }

    public function configured(): bool
    {
        return (string) config('services.replicate.api_token', '') !== '';
    }

    /**
     * Submit the prediction and return its id immediately (so the caller can
     * stash it for resume). Throws on a failed submit.
     */
    /** Settings for an engine key, falling back to the configured default. */
    public static function engine(?string $key = null): array
    {
        $engines = (array) config('services.lipsync.engines', []);
        $key = $key && isset($engines[$key]) ? $key : (string) config('services.lipsync.default', 'omni_human');

        return ($engines[$key] ?? null)
            ?: ($engines['fabric'] ?? ['model' => 'veed/fabric-1.0', 'resolution_key' => 'resolution', 'resolution' => '480p']);
    }

    /**
     * @param  string|null  $engineKey  which lip-sync model to run; null uses the default
     */
    public function start(string $imageUrl, string $audioUrl, ?string $engineKey = null): string
    {
        $token = (string) config('services.replicate.api_token', '');
        if ($token === '') {
            throw new RuntimeException('Replicate is not configured — talking spokesperson is unavailable.');
        }

        $engine = self::engine($engineKey);
        $model = (string) $engine['model'];

        // Engines differ in whether they take a size at all, so the input is
        // built from the engine rather than assumed. Sending Fabric's
        // `resolution` to one that does not accept it is a 422.
        $input = ['image' => $imageUrl, 'audio' => $audioUrl];
        if (! empty($engine['resolution_key']) && ! empty($engine['resolution'])) {
            $input[$engine['resolution_key']] = $engine['resolution'];
        }

        $start = Http::withToken($token)
            ->timeout(30)
            ->post("https://api.replicate.com/v1/models/{$model}/predictions", ['input' => $input]);

        if (! $start->successful()) {
            throw new RuntimeException("Lip-sync submit failed ({$start->status()}): ".mb_substr((string) $start->body(), 0, 300));
        }
        $id = (string) $start->json('id');
        if ($id === '') {
            throw new RuntimeException('Lip-sync submit returned no prediction id.');
        }

        return $id;
    }

    /**
     * Poll an existing prediction until done, up to $maxSeconds. Returns the
     * video URL when succeeded, or null if still running (so the caller can
     * leave it in-progress for a later resume). Throws on terminal failure.
     */
    public function pollUntilDone(string $predictionId, int $maxSeconds = 850): ?string
    {
        $token = (string) config('services.replicate.api_token', '');
        $deadline = time() + $maxSeconds;

        while (time() < $deadline) {
            sleep(8);
            $check = Http::withToken($token)->acceptJson()->timeout(20)
                ->get("https://api.replicate.com/v1/predictions/{$predictionId}");
            $status = (string) $check->json('status');

            if ($status === 'succeeded') {
                $output = $check->json('output');
                $url = is_array($output) ? ($output[0] ?? null) : $output;
                if (! is_string($url) || $url === '') {
                    throw new RuntimeException('Fabric succeeded but returned no video URL.');
                }

                return $url;
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                throw new RuntimeException('Fabric '.$status.': '.mb_substr((string) ($check->json('error') ?? ''), 0, 300));
            }
            // starting / processing -> keep polling
        }

        return null; // still running — caller leaves it for resume
    }
}
