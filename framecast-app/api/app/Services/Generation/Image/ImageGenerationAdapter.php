<?php

namespace App\Services\Generation\Image;

interface ImageGenerationAdapter
{
    /**
     * Generate an image from a prompt.
     *
     * @return array{
     *   provider_key: string,
     *   image_url: string,
     *   width: int,
     *   height: int,
     *   seed: int|null,
     *   revised_prompt: string|null
     * }
     */
    public function generate(
        string $prompt,
        string $style,
        string $aspectRatio = '9:16',
        array $options = []
    ): array;

    public function providerKey(): string;
}
