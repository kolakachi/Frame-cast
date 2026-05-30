<?php

namespace App\Services;

use App\Models\Workspace;

class CreditService
{
    // Credit costs — single source of truth
    public const SCRIPT     = 2;
    public const BREAKDOWN  = 1;
    public const STOCK      = 1;   // per scene (stock video/image/audiogram)
    public const TTS        = 2;   // per scene
    public const AI_MEDIUM    = 15;  // per scene, gpt-image-1 medium
    public const AI_HIGH      = 40;  // per scene, gpt-image-1 high
    public const AI_CHARACTER = 35;  // per scene, Replicate ideogram-character (character + reference image)

    // Image-to-video animation tiers — per 6-second clip; longer clips scale roughly proportionally.
    // Wan 2.1 480p / Hailuo / Kling 2.1 mapping.
    public const VIDEO_QUICK    = 60;   // ~$0.30 per 6s clip — Wan 2.1 i2v 480p
    public const VIDEO_BALANCED = 120;  // ~$0.60 per 6s clip — Hailuo MiniMax
    public const VIDEO_PREMIUM  = 240;  // ~$1.20 per 6s clip — Kling 2.1
    public const EXPORT     = 5;

    // Monthly credit allocations per plan
    public const PLAN_CREDITS = [
        'free'       => 0,      // one-time 200 via grant, never resets
        'starter'    => 500,
        'creator'    => 1500,
        'pro'        => 4000,
        'agency'     => 10000,
        'enterprise' => 50000,
        // Legacy tier aliases
        'studio'     => 1500,
        'scale'      => 4000,
    ];

    public function balance(int $workspaceId): int
    {
        $workspace = Workspace::find($workspaceId);
        return $workspace ? $workspace->creditsBalance() : 0;
    }

    public function canAfford(int $workspaceId, int $amount): bool
    {
        return $this->balance($workspaceId) >= $amount;
    }

    /**
     * Deduct credits. Spends credits_monthly first, then credits_topup.
     * Returns false if insufficient balance (does not deduct partial amounts).
     */
    public function deduct(int $workspaceId, int $amount, string $operation = ''): bool
    {
        $workspace = Workspace::find($workspaceId);
        if (! $workspace || $workspace->creditsBalance() < $amount) {
            return false;
        }

        $remaining = $amount;

        // Spend monthly first
        $fromMonthly = min($remaining, (int) $workspace->credits_monthly);
        $remaining  -= $fromMonthly;

        // Then top-up
        $fromTopup = min($remaining, (int) $workspace->credits_topup);

        $workspace->decrement('credits_monthly', $fromMonthly);
        if ($fromTopup > 0) {
            $workspace->decrement('credits_topup', $fromTopup);
        }

        return true;
    }

    /**
     * Grant credits (registration, top-up purchase, admin refund).
     * Grants always go to credits_topup so they don't interfere with monthly resets.
     */
    public function grant(int $workspaceId, int $amount, string $reason = ''): void
    {
        Workspace::where('id', $workspaceId)->increment('credits_topup', $amount);

        if ($reason === 'registration') {
            Workspace::where('id', $workspaceId)->increment('credits_free_granted', $amount);
        }
    }

    /**
     * Reset monthly credits on billing renewal.
     */
    public function resetMonthly(Workspace $workspace): void
    {
        $monthly = self::PLAN_CREDITS[$workspace->plan_tier] ?? 0;
        $workspace->update([
            'credits_monthly'   => $monthly,
            'billing_renews_at' => now()->addMonth(),
        ]);
    }

    /**
     * Credit cost estimate for a project before creation.
     * Returns [min, max, mid, breakdown].
     */
    public function estimateProject(
        string $sourceType,
        ?string $sourceContent,
        string $visualMode,
        string $aiQuality = 'medium',
    ): array {
        [$scenesMin, $scenesMax] = $this->estimateSceneCount($sourceType, $sourceContent);

        $visualPerScene = match ($visualMode) {
            'ai_images', 'ai_broll' => $aiQuality === 'high' ? self::AI_HIGH : self::AI_MEDIUM,
            default                  => self::STOCK, // stock_video, stock_images, waveform, etc.
        };

        $fixed = self::SCRIPT + self::BREAKDOWN + self::EXPORT;

        $min = $fixed + $scenesMin * ($visualPerScene + self::TTS);
        $max = $fixed + $scenesMax * ($visualPerScene + self::TTS);
        $mid = (int) round(($min + $max) / 2);

        return [
            'scenes_min'        => $scenesMin,
            'scenes_max'        => $scenesMax,
            'credits_min'       => $min,
            'credits_max'       => $max,
            'credits_mid'       => $mid,
            'breakdown' => [
                'script_and_breakdown' => self::SCRIPT + self::BREAKDOWN,
                'visual_per_scene'     => $visualPerScene,
                'voice_per_scene'      => self::TTS,
                'export'               => self::EXPORT,
            ],
        ];
    }

    /**
     * Estimate scene count from source type and content length.
     * Returns [min, max].
     */
    private function estimateSceneCount(string $sourceType, ?string $content): array
    {
        $words = $content ? str_word_count($content) : 0;

        return match ($sourceType) {
            'script' => [
                max(8,  (int) round($words / 14)),
                max(10, (int) round($words / 10)),
            ],
            'prompt' => $words > 10 ? [
                max(6,  (int) round($words / 15)),
                max(8,  (int) round($words / 11)),
            ] : [8, 12],
            'url'               => [9, 13],
            'images'            => [6, 10],
            'product_description'=> [8, 12],
            'audio_upload'      => [8, 14],
            'video_upload'      => [8, 14],
            default             => [8, 12],
        };
    }
}
