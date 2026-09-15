<?php

namespace App\Services\Publishing;

use InvalidArgumentException;

class PlatformAdapterFactory
{
    /**
     * Stands in for every platform during tests.
     *
     * The alternative is a test that reaches Google and TikTok for real, which
     * would be slow, flaky, and would spend a customer's refresh token.
     */
    private static ?PlatformAdapter $fake = null;

    public static function fake(?PlatformAdapter $adapter): void
    {
        self::$fake = $adapter;
    }

    public static function make(string $platform): PlatformAdapter
    {
        if (self::$fake !== null) {
            return self::$fake;
        }

        return match ($platform) {
            'youtube'   => new YouTubeAdapter(),
            'tiktok'    => new TikTokAdapter(),
            'instagram' => new InstagramAdapter(),
            'facebook'  => new FacebookAdapter(),
            default     => throw new InvalidArgumentException("Unsupported platform: {$platform}"),
        };
    }

    /** @return list<string> */
    public static function supported(): array
    {
        return ['youtube', 'tiktok', 'instagram', 'facebook'];
    }
}
