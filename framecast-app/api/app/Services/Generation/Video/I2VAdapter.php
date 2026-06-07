<?php

namespace App\Services\Generation\Video;

interface I2VAdapter
{
    /**
     * Animate a still image into a short video clip.
     *
     * @param string $imageUrl Public URL of the source still (Replicate/fal must be able to fetch it).
     * @param string $prompt Free-form motion description. Empty string is acceptable.
     * @param string $tier One of: 'quick' | 'balanced' | 'premium'. Routes to the matching upstream model.
     * @param int $durationSeconds 3–10. Some models ignore this; the adapter clamps to model limits.
     * @param array<string,mixed> $options Per-call overrides — see implementations for accepted keys.
     *
     * @return array{
     *   provider_key: string,
     *   model_slug: string,
     *   video_url: string,
     *   duration_seconds: int,
     *   width: int|null,
     *   height: int|null
     * }
     */
    public function animate(
        string $imageUrl,
        string $prompt,
        string $tier = 'quick',
        int $durationSeconds = 6,
        array $options = []
    ): array;

    public function providerKey(): string;

    /**
     * Resume by polling an EXISTING prediction id (the job that started it
     * died mid-poll). Returns the video URL when ready, null if still
     * processing (retry later), throws on failure/expiry.
     */
    public function pollExisting(string $predictionId): ?string;
}
