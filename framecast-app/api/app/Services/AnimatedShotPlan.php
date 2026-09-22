<?php

namespace App\Services;

/** One policy for the wizard quote, scene plan and automatic animation job. */
final class AnimatedShotPlan
{
    public static function requestSeconds(?string $pacing): int
    {
        // Preserve the existing short request for callers without a choice.
        return $pacing === 'long' ? 10 : 5;
    }

    public static function clipSeconds(?string $tier, ?string $pacing): int
    {
        $long = $pacing === 'long';
        return match ($tier) {
            'veo_fast' => $long ? 8 : 4,
            'balanced' => $long ? 10 : 6,
            default => $long ? 10 : 5,
        };
    }
}
