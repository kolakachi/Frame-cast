<?php

namespace App\Services\Generation\Video;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Talking-spokesperson generation via VEED Fabric 1.0 on fal.ai: a still
 * character image + a voice audio track -> a lip-synced talking video (mouth,
 * micro-expressions, head motion synced to the audio).
 *
 * fal.ai queue API: POST submits, returns a request id; poll status until
 * COMPLETED, then read the video url from the result. Auth header is
 * `Authorization: Key <FAL_API_KEY>`. Returns a public mp4 URL the caller
 * downloads + stores. Throws on failure / timeout.
 */
class FalFabricAdapter
{
    private const BASE = 'https://queue.fal.run';

    public function providerKey(): string
    {
        return 'fal:'.(string) config('services.fal.fabric_model', 'veed/fabric-1.0');
    }

    /**
     * @param  string  $imageUrl  publicly fetchable still image (the spokesperson)
     * @param  string  $audioUrl  publicly fetchable voice audio
     * @return string  public mp4 URL of the talking video
     */
    public function generate(string $imageUrl, string $audioUrl): string
    {
        $apiKey = (string) config('services.fal.api_key', '');
        if ($apiKey === '') {
            throw new RuntimeException('FAL_API_KEY is not configured — talking spokesperson is unavailable.');
        }
        $model = (string) config('services.fal.fabric_model', 'veed/fabric-1.0');
        $resolution = (string) config('services.fal.fabric_resolution', '480p');

        $submit = Http::withHeaders(['Authorization' => 'Key '.$apiKey])
            ->timeout(30)
            ->post(self::BASE.'/'.$model, [
                'image_url'  => $imageUrl,
                'audio_url'  => $audioUrl,
                'resolution' => $resolution,
            ]);

        if (! $submit->successful()) {
            throw new RuntimeException("Fabric submit failed ({$submit->status()}): ".mb_substr((string) $submit->body(), 0, 300));
        }

        $requestId = (string) $submit->json('request_id');
        $statusUrl = (string) ($submit->json('status_url') ?: self::BASE."/{$model}/requests/{$requestId}/status");
        $resultUrl = (string) ($submit->json('response_url') ?: self::BASE."/{$model}/requests/{$requestId}");
        if ($requestId === '') {
            throw new RuntimeException('Fabric submit returned no request id.');
        }

        // Poll. Lip-sync renders take a while; cap well under the worker grace.
        $deadline = time() + 280;
        while (time() < $deadline) {
            sleep(6);
            $check = Http::withHeaders(['Authorization' => 'Key '.$apiKey])->acceptJson()->timeout(20)->get($statusUrl);
            $status = (string) $check->json('status');

            if ($status === 'COMPLETED') {
                $result = Http::withHeaders(['Authorization' => 'Key '.$apiKey])->acceptJson()->timeout(30)->get($resultUrl);
                $videoUrl = (string) (
                    $result->json('video.url')
                    ?? $result->json('video_url')
                    ?? $result->json('output.url')
                    ?? ''
                );
                if ($videoUrl === '') {
                    throw new RuntimeException('Fabric completed but returned no video url: '.mb_substr((string) $result->body(), 0, 300));
                }

                return $videoUrl;
            }
            if (in_array($status, ['FAILED', 'ERROR', 'CANCELLED'], true)) {
                throw new RuntimeException("Fabric {$status}: ".mb_substr((string) $check->body(), 0, 300));
            }
            // IN_QUEUE / IN_PROGRESS -> keep polling
        }

        throw new RuntimeException('Fabric timed out while rendering the talking video.');
    }
}
