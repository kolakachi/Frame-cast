<?php

namespace App\Services\Publishing;

use InvalidArgumentException;

class PlatformAdapterFactory
{
    public static function make(string $platform): PlatformAdapter
    {
        return match ($platform) {
            'youtube'   => new YouTubeAdapter(),
            'tiktok'    => new TikTokAdapter(),
            default     => throw new InvalidArgumentException("Unsupported platform: {$platform}"),
        };
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return ['youtube', 'tiktok'];
    }
}
