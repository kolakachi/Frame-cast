<?php

namespace App\Services;

use App\Models\CreditLedgerEntry;
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
    public const AI_CHARACTER = 50;  // per scene, OpenAI gpt-image-2 /edits (character + reference image, high quality)
    public const AI_MUSIC     = 5;   // per scene, Replicate MusicGen (~$0.01 upstream, ~$0.05 retail at 1cr=$0.01)

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

    // Per-plan resource caps + capability flags. Free-tier gates exist to
    // make the free trial feel real ("publish one video, see the moat") but
    // stop short of supporting a real workflow ("more characters, more
    // channels, publish to social"). Upgrade-path levers, not punishment.
    //
    // null = unlimited.
    public const PLAN_LIMITS = [
        'free'       => ['max_duration_seconds' => 60,  'max_characters' => 1,  'max_brand_kits' => 1,  'max_channels' => 1, 'social_publishing' => false],
        'starter'    => ['max_duration_seconds' => 180, 'max_characters' => 3,  'max_brand_kits' => 1,  'max_channels' => 1, 'social_publishing' => true],
        'creator'    => ['max_duration_seconds' => 300, 'max_characters' => 10, 'max_brand_kits' => 3,  'max_channels' => 3, 'social_publishing' => true],
        'pro'        => ['max_duration_seconds' => 600, 'max_characters' => 50, 'max_brand_kits' => 10, 'max_channels' => 10,'social_publishing' => true],
        'agency'     => ['max_duration_seconds' => 600, 'max_characters' => null,'max_brand_kits' => null,'max_channels' => null,'social_publishing' => true],
        'enterprise' => ['max_duration_seconds' => 600, 'max_characters' => null,'max_brand_kits' => null,'max_channels' => null,'social_publishing' => true],
        'studio'     => ['max_duration_seconds' => 300, 'max_characters' => 10, 'max_brand_kits' => 3,  'max_channels' => 3, 'social_publishing' => true],
        'scale'      => ['max_duration_seconds' => 600, 'max_characters' => 50, 'max_brand_kits' => 10, 'max_channels' => 10,'social_publishing' => true],
    ];

    /**
     * Resolve a plan-level limit/flag for a workspace by id. Falls back to
     * the 'free' row if the workspace's tier isn't recognised.
     *
     * Use the typed helpers below where possible — this is the raw form.
     */
    public function limitFor(int $workspaceId, string $key): mixed
    {
        $workspace = Workspace::find($workspaceId);
        $tier = $workspace?->plan_tier ?? 'free';
        $limits = self::PLAN_LIMITS[$tier] ?? self::PLAN_LIMITS['free'];
        return $limits[$key] ?? null;
    }

    public function maxDurationSeconds(int $workspaceId): ?int
    {
        $v = $this->limitFor($workspaceId, 'max_duration_seconds');
        return $v === null ? null : (int) $v;
    }

    public function canPublishToSocial(int $workspaceId): bool
    {
        return (bool) $this->limitFor($workspaceId, 'social_publishing');
    }

    public function planTier(int $workspaceId): string
    {
        $workspace = Workspace::find($workspaceId);
        return (string) ($workspace?->plan_tier ?? 'free');
    }

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
     *
     * Writes a credit_ledger row on success so we can answer "where did this
     * workspace's credits go today?" without reconstructing from logs. Ledger
     * writes are best-effort (rescued): a logging failure must never cost the
     * user their generation.
     *
     * @param  array<string, mixed>  $context  optional caller context — keys recognised:
     *                                          - project_id (int)
     *                                          - scene_id (int)
     *                                          - user_id (int)
     *                                          - metadata (array) — model, tier, quality, etc.
     */
    public function deduct(int $workspaceId, int $amount, string $operation = '', array $context = []): bool
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

        // Best-effort ledger write — never let a logging failure mask a
        // successful deduction.
        rescue(function () use ($workspaceId, $amount, $operation, $context) {
            CreditLedgerEntry::query()->create([
                'workspace_id'  => $workspaceId,
                'user_id'       => isset($context['user_id'])    ? (int) $context['user_id']    : null,
                'project_id'    => isset($context['project_id']) ? (int) $context['project_id'] : null,
                'scene_id'      => isset($context['scene_id'])   ? (int) $context['scene_id']   : null,
                'operation'     => mb_substr($operation !== '' ? $operation : 'unknown', 0, 64),
                'credits'       => $amount,
                'balance_after' => $this->balance($workspaceId),
                'metadata'      => is_array($context['metadata'] ?? null) ? $context['metadata'] : null,
            ]);
        }, null, false);

        return true;
    }

    /**
     * Grant credits (registration, top-up purchase, admin refund).
     * Grants always go to credits_topup so they don't interfere with monthly resets.
     *
     * Also writes a ledger row with a negative `credits` value so grant /
     * refund history is queryable from the same place as deductions. The
     * `operation` is prefixed with 'grant:' (e.g. 'grant:registration',
     * 'grant:admin_top_up') so filtering is trivial.
     */
    public function grant(int $workspaceId, int $amount, string $reason = ''): void
    {
        Workspace::where('id', $workspaceId)->increment('credits_topup', $amount);

        if ($reason === 'registration') {
            Workspace::where('id', $workspaceId)->increment('credits_free_granted', $amount);
        }

        rescue(function () use ($workspaceId, $amount, $reason) {
            CreditLedgerEntry::query()->create([
                'workspace_id'  => $workspaceId,
                'operation'     => mb_substr('grant:'.($reason !== '' ? $reason : 'unspecified'), 0, 64),
                'credits'       => -$amount, // negative = credit going INTO the workspace
                'balance_after' => $this->balance($workspaceId),
                'metadata'      => ['reason' => $reason],
            ]);
        }, null, false);
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
