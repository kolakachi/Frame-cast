<?php

namespace App\Services\Generation\Image;

interface ImageGenerationAdapter
{
    /**
     * Generate an image from a prompt.
     *
     * @return array{
     *   provider_key: string,
     *   image_url: string|null,
     *   image_b64: string|null,
     *   width: int,
     *   height: int,
     *   seed: int|null,
     *   revised_prompt: string|null
     * }
     * Adapters MUST set either image_url (HTTP/HTTPS) or image_b64 (raw base64 PNG).
     * Never put a data URI in image_url — use image_b64 instead.
     */
    public function generate(
        string $prompt,
        string $style,
        string $aspectRatio = '9:16',
        array $options = []
    ): array;

    public function providerKey(): string;
}
