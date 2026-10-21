<?php

namespace App\Services\Ugc;

use App\Models\User;

/**
 * More video models for a UGC take, through OpenRouter's video API (owner, 2026-10-21: "give users the option to pick
 * the models in UGC"). Each one, from OpenRouter's own model list (/api/v1/videos/models, read 2026-10-21): its id, the
 * clip lengths it makes, the size it makes at 720p or nearest, whether it takes reference photos besides a first frame,
 * and its price.
 *
 * Price: credits a second = its cost a second at 720p with sound ÷ 0.7¢, rounded up: the same margin Seedance 2.5 has
 * (cost $0.231 a second, 33 credits).
 *
 * They behave like Veo 3.1 Fast in a take: a presenter reaches them as the first frame (the dressed look), each speaks
 * in its own audio, and a longer plan is split into clips no longer than the model makes. A model is offered to
 * customers only once its test take passed (services.ugc.openrouter_models); staff see every one, marked "testing".
 */
class UgcVideoModels
{
    public const MODELS = [
        'kling3_pro' => ['label' => 'Kling 3.0 Pro', 'id' => 'kwaivgi/kling-v3.0-pro', 'per_second' => 24, 'durations' => [3, 15], 'resolution' => '720p', 'refs' => false, 'aspects' => ['9:16', '16:9', '1:1']],
        'kling3_std' => ['label' => 'Kling 3.0 Standard', 'id' => 'kwaivgi/kling-v3.0-std', 'per_second' => 18, 'durations' => [3, 15], 'resolution' => '720p', 'refs' => false, 'aspects' => ['9:16', '16:9', '1:1']],
        'seedance20' => ['label' => 'Seedance 2.0', 'id' => 'bytedance/seedance-2.0', 'per_second' => 22, 'durations' => [4, 15], 'resolution' => '720p', 'refs' => true, 'aspects' => ['9:16', '16:9', '1:1']],
        'seedance20_fast' => ['label' => 'Seedance 2.0 Fast', 'id' => 'bytedance/seedance-2.0-fast', 'per_second' => 13, 'durations' => [4, 15], 'resolution' => '720p', 'refs' => true, 'aspects' => ['9:16', '16:9', '1:1']],
        'wan30' => ['label' => 'Wan 3.0', 'id' => 'alibaba/wan-3.0', 'per_second' => 15, 'durations' => [2, 30], 'resolution' => '720p', 'refs' => false, 'aspects' => ['9:16', '16:9', '1:1']],
        'hailuo3' => ['label' => 'MiniMax H3', 'id' => 'minimax/hailuo-3', 'per_second' => 19, 'durations' => [5, 15], 'resolution' => '2K', 'refs' => false, 'aspects' => ['9:16', '16:9', '1:1']],
        'veo31_fast' => ['label' => 'Veo 3.1 Fast (new)', 'id' => 'google/veo-3.1-fast', 'per_second' => 15, 'steps' => [4, 6, 8], 'resolution' => '720p', 'refs' => false, 'aspects' => ['9:16', '16:9']],
        'veo31_lite' => ['label' => 'Veo 3.1 Lite', 'id' => 'google/veo-3.1-lite', 'per_second' => 8, 'steps' => [4, 6, 8], 'resolution' => '720p', 'refs' => false, 'aspects' => ['9:16', '16:9']],
        'grok15' => ['label' => 'Grok Imagine Video 1.5', 'id' => 'x-ai/grok-imagine-video-1.5', 'per_second' => 20, 'durations' => [1, 15], 'resolution' => '720p', 'refs' => false, 'aspects' => ['9:16', '16:9', '1:1']],
    ];

    /** The clip length the model makes nearest above $seconds (its steps, or its range), at most its longest. */
    public static function snap(string $key, int $seconds): int
    {
        $m = self::MODELS[$key] ?? null;
        if (! $m) return $seconds;
        if (isset($m['steps'])) { foreach ($m['steps'] as $s) if ($s >= $seconds) return $s; return max($m['steps']); }
        return max($m['durations'][0], min($m['durations'][1], $seconds));
    }

    /** The longest clip, and so the longest piece a plan is split into (capped at 15 s so a take holds together). */
    public static function maxClip(string $key): int
    {
        $m = self::MODELS[$key];
        return min(15, isset($m['steps']) ? max($m['steps']) : $m['durations'][1]);
    }

    public static function has(?string $key): bool
    {
        return $key !== null && isset(self::MODELS[$key]);
    }

    /** Offered to customers: models whose test take passed, listed by the owner in services.ugc.openrouter_models. */
    public static function live(string $key): bool
    {
        return self::has($key) && in_array($key, (array) config('services.ugc.openrouter_models', []), true);
    }

    /** What this user may pick: live models, and every model for staff (to run the test takes). */
    public static function usable(string $key, ?User $user): bool
    {
        return self::live($key) || (self::has($key) && $user && in_array($user->role, ['super_admin', 'platform_admin'], true));
    }

    public static function perSecond(string $key): int
    {
        return (int) (self::MODELS[$key]['per_second'] ?? 0);
    }
}
