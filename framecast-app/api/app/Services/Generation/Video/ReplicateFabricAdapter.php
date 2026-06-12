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
        return 'replicate:'.(string) config('services.fabric.model', 'veed/fabric-1.0');
    }

    public function configured(): bool
    {
        return (string) config('services.replicate.api_token', '') !== '';
    }

    /**
     * Submit the prediction and return its id immediately (so the caller can
     * stash it for resume). Throws on a failed submit.
     */
    public function start(string $imageUrl, string $audioUrl): string
    {
        $token = (string) config('services.replicate.api_token', '');
        if ($token === '') {
            throw new RuntimeException('Replicate is not configured — talking spokesperson is unavailable.');
        }
        $model = (string) config('services.fabric.model', 'veed/fabric-1.0');
        $resolution = (string) config('services.fabric.resolution', '480p');

        $start = Http::withToken($token)
            ->timeout(30)
            ->post("https://api.replicate.com/v1/models/{$model}/predictions", [
                'input' => [
                    'image'      => $imageUrl,
                    'audio'      => $audioUrl,
                    'resolution' => $resolution,
                ],
            ]);

        if (! $start->successful()) {
            throw new RuntimeException("Fabric submit failed ({$start->status()}): ".mb_substr((string) $start->body(), 0, 300));
        }
        $id = (string) $start->json('id');
        if ($id === '') {
            throw new RuntimeException('Fabric submit returned no prediction id.');
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
