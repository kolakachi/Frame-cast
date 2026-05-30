<?php

namespace App\Services\Generation\Image;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Replicate character-reference image adapter.
 *
 * Originally backed by zsxkib/flux-pulid (hence the legacy class name). As of May 2026
 * the underlying model is ideogram-ai/ideogram-character — purpose-built for "consistent
 * characters from a single reference image" with much better natural-looking identity
 * preservation than flux-pulid (which had a bimodal id_weight that either drifted or
 * went plastic). Set REPLICATE_CHARACTER_MODEL in env to swap to another model.
 *
 * Class name kept to avoid touching every job + binding; only the implementation changed.
 */
class ReplicatePulidAdapter implements ImageGenerationAdapter
{
    private const POLL_INTERVAL_SECONDS = 2;
    private const POLL_TIMEOUT_SECONDS  = 240;

    /**
     * Map our editor style keys → Ideogram's style_type enum. Keeps style consistency
     * across both the DALL-E (text-only) path and the character (referenced) path.
     */
    private const STYLE_TYPE_MAP = [
        'cinematic'      => 'REALISTIC',
        'dark'           => 'REALISTIC',
        'documentary'    => 'REALISTIC',
        'realistic'      => 'REALISTIC',
        'photorealistic' => 'REALISTIC',
        'film_noir'      => 'REALISTIC',
        'vintage'        => 'REALISTIC',
        'anime'          => 'ANIME',
        'anime_80s'      => 'ANIME',
        'anime_90s'      => 'ANIME',
        '3d_animated'    => 'RENDER_3D',
        'dark_fantasy'   => 'FICTION',
        'fantasy_retro'  => 'FICTION',
        'comic'          => 'DESIGN',
        'paper_cutout'   => 'DESIGN',
        'cartoon'        => 'DESIGN',
        'line_drawing'   => 'DESIGN',
        'watercolor'     => 'DESIGN',
        'minimalist'     => 'DESIGN',
        'neon'           => 'GENERAL',
        'cyberpunk_80s'  => 'GENERAL',
    ];

    public function providerKey(): string
    {
        return 'replicate:ideogram-character';
    }

    /**
     * @param array<string,mixed> $options
     *   reference_image_url (required) — public URL of the character's reference photo
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
            throw new RuntimeException('Character adapter requires reference_image_url in options.');
        }

        $modelSlug = (string) config('services.replicate.character_model', 'ideogram-ai/ideogram-character');
        $version   = config('services.replicate.character_version'); // empty → use model endpoint

        $styleDesc = ImageStyleDescriptors::for($style);
        $styleType = self::STYLE_TYPE_MAP[$style] ?? 'AUTO';

        // Style descriptor still goes in the prompt — Ideogram's style_type is a coarse
        // bucket (REALISTIC/ANIME/etc.); the descriptor adds the specific keywords.
        $fullPrompt = trim($styleDesc !== '' ? "{$prompt}. {$styleDesc}" : $prompt);

        $input = [
            'prompt'                     => $fullPrompt,
            'character_reference_image'  => $referenceUrl,
            'aspect_ratio'               => $aspectRatio,
            'style_type'                 => $styleType,
            'magic_prompt_option'        => 'AUTO',
        ];

        // Prefer the official-model endpoint (no version hash needed) when version is empty.
        $url  = $version ? 'https://api.replicate.com/v1/predictions' : "https://api.replicate.com/v1/models/{$modelSlug}/predictions";
        $body = $version ? ['version' => $version, 'input' => $input] : ['input' => $input];

        // 1. Kick off the prediction.
        $start = Http::withToken($apiToken)->acceptJson()->post($url, $body);

        if (! $start->successful()) {
            $rawBody = $start->body();
            Log::error('Replicate character: prediction start failed', [
                'model'  => $modelSlug,
                'status' => $start->status(),
                'body'   => $rawBody,
            ]);
            throw new RuntimeException("Replicate character ({$modelSlug}) failed to start ({$start->status()}): {$rawBody}");
        }

        $predictionId = $start->json('id');
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
            $status  = $payload['status'] ?? 'unknown';

            if ($status === 'succeeded') {
                $output   = $payload['output'] ?? null;
                $imageUrl = is_array($output) ? ($output[0] ?? null) : $output;
                break;
            }

            if (in_array($status, ['failed', 'canceled'], true)) {
                $err = $payload['error'] ?? 'unknown error';
                throw new RuntimeException("Replicate character {$status}: {$err}");
            }
            // else: starting | processing → keep polling
        }

        if (! $imageUrl) {
            throw new RuntimeException('Replicate character did not return an image within the polling window.');
        }

        // Ideogram returns its rendered resolution implicitly; we don't get it back, so
        // we approximate from the aspect ratio for downstream storage metadata.
        $dimsApprox = match ($aspectRatio) {
            '16:9'  => ['width' => 1792, 'height' => 1024],
            '1:1'   => ['width' => 1024, 'height' => 1024],
            default => ['width' => 1024, 'height' => 1792], // 9:16
        };

        return [
            'provider_key'   => $this->providerKey(),
            'image_url'      => (string) $imageUrl,
            'image_b64'      => null,
            'width'          => $dimsApprox['width'],
            'height'         => $dimsApprox['height'],
            'seed'           => null,
            'revised_prompt' => null,
        ];
    }
}
