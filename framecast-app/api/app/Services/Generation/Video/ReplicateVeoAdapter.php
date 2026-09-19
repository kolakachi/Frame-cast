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
    ];

    public function configured(): bool
    {
        return (string) config('services.replicate.api_token', '') !== '';
    }

    /** @param array<int, string> $referenceImages data URIs or URLs; Seedance-only, exclusive with a start frame */
    public function start(string $prompt, int $seconds, ?string $imageDataUri = null, string $engine = 'veo', array $referenceImages = []): string
    {
        $model = self::ENGINES[$engine] ?? self::ENGINES['veo'];
        $input = [
            'prompt' => $prompt,
            'aspect_ratio' => '9:16',
            'resolution' => '720p',
            'generate_audio' => true,
        ];
        if ($engine === 'seedance25') {
            $input['duration'] = max(4, min(30, $seconds));
            $input['watermark'] = false;
        } else {
            // Veo accepts exactly 4, 6 or 8 seconds.
            $input['duration'] = in_array($seconds, [4, 6, 8], true) ? $seconds : (($seconds <= 4) ? 4 : ($seconds <= 6 ? 6 : 8));
        }
        if ($imageDataUri !== null) {
            $input['image'] = $imageDataUri;
        } elseif ($engine === 'seedance25' && $referenceImages !== []) {
            // The pose sheet: character and product stills the presenter and
            // packaging must match. Exclusive with a start frame by schema.
            $input['reference_images'] = array_slice(array_values($referenceImages), 0, 30);
        }

        $response = Http::withToken((string) config('services.replicate.api_token'))
            ->timeout(60)
            ->post('https://api.replicate.com/v1/models/'.$model.'/predictions', ['input' => $input]);

        $id = (string) data_get($response->json(), 'id');
        if (! $response->successful() || $id === '') {
            throw new \RuntimeException('Veo submit failed: '.mb_substr($response->body(), 0, 200));
        }

        return $id;
    }

    /** Output URL on success, null on timeout; throws (honestly) on failure. */
    public function pollUntilDone(string $predictionId, int $maxSeconds = 600): ?string
    {
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
                if (str_contains($error, 'E006') || str_contains($error, 'E005') || stripos($error, 'sensitive') !== false) {
                    throw new \RuntimeException('The video model declined this segment — its moderation flags some content even in tasteful ads. Nothing was charged; rephrase the framing and retry.');
                }
                throw new \RuntimeException('Veo generation failed: '.mb_substr($error, 0, 300));
            }
            sleep(10);
        }

        return null;
    }
}
