<?php

namespace App\Services\Generation\Music;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Replicate MusicGen adapter.
 *
 * Wraps Meta's `meta/musicgen` on Replicate. Cheapest commercial music
 * generation in the stack — ~$0.01 per 30s clip on the small variant,
 * ~$0.05 on the melody-large variant. Output is a WAV/MP3 URL we then
 * download and store via StorageService just like every other AI asset.
 *
 * Pattern mirrors ReplicateI2VAdapter: build request, POST to /v1/models/
 * <slug>/predictions, poll status, return result envelope.
 */
class ReplicateMusicAdapter
{
    private const POLL_INTERVAL_SEC = 2;
    private const MAX_POLL_ITERATIONS = 60; // 2 min ceiling

    public function generate(
        string $prompt,
        int $durationSeconds = 8,
        ?string $genre = null,
    ): array {
        $apiToken = config('services.replicate.api_token');
        if (! $apiToken) {
            throw new RuntimeException('Replicate API token not configured.');
        }

        $modelSlug = config('services.replicate.musicgen_model', 'meta/musicgen');
        $version   = config('services.replicate.musicgen_version');

        // Genre seed appended to the prompt so the model has a strong style
        // anchor. Empty when the caller didn't classify a mood.
        $fullPrompt = trim(($genre ? "{$genre}, " : '') . $prompt);

        $input = [
            'prompt'              => $fullPrompt,
            'duration'            => max(3, min(30, $durationSeconds)),
            'model_version'       => 'stereo-large',
            'output_format'       => 'mp3',
            'normalization_strategy' => 'peak',
        ];

        $url = $version
            ? 'https://api.replicate.com/v1/predictions'
            : "https://api.replicate.com/v1/models/{$modelSlug}/predictions";

        $body = $version ? ['version' => $version, 'input' => $input] : ['input' => $input];

        $start = Http::withToken($apiToken)
            ->withHeaders(['Prefer' => 'wait=10'])
            ->timeout(30)
            ->post($url, $body);

        if (! $start->successful()) {
            throw new RuntimeException("Replicate MusicGen failed to start ({$start->status()}): {$start->body()}");
        }

        $prediction = $start->json();
        $predictionId = $prediction['id'] ?? null;

        // Poll until succeeded or failed.
        for ($i = 0; $i < self::MAX_POLL_ITERATIONS; $i++) {
            $status = $prediction['status'] ?? null;
            if ($status === 'succeeded') {
                $output = $prediction['output'] ?? null;
                $audioUrl = is_array($output) ? ($output[0] ?? null) : $output;
                if (! $audioUrl) {
                    throw new RuntimeException('Replicate MusicGen succeeded but returned no audio URL.');
                }
                return [
                    'provider_key' => 'replicate:musicgen',
                    'audio_url'    => (string) $audioUrl,
                    'duration'     => $input['duration'],
                    'genre'        => $genre,
                ];
            }
            if (in_array($status, ['failed', 'canceled'], true)) {
                $error = $prediction['error'] ?? 'unknown error';
                throw new RuntimeException("Replicate MusicGen {$status}: {$error}");
            }
            sleep(self::POLL_INTERVAL_SEC);
            $get = Http::withToken($apiToken)->timeout(10)
                ->get("https://api.replicate.com/v1/predictions/{$predictionId}");
            if (! $get->successful()) {
                throw new RuntimeException("Replicate MusicGen poll failed ({$get->status()}).");
            }
            $prediction = $get->json();
        }

        throw new RuntimeException('Replicate MusicGen polling timed out after ' . (self::MAX_POLL_ITERATIONS * self::POLL_INTERVAL_SEC) . 's.');
    }
}
