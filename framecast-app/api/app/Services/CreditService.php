<?php

namespace App\Services;

use App\Models\CreditLedgerEntry;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

class CreditService
{
    // Credit costs — single source of truth.
    // LOCKED to spec/CREDIT_CALIBRATION.md (Option B): peg 1 credit = $0.004 of
    // COGS → round(COGS ÷ $0.004) = uniform ~60% margin on images/audio; VIDEO
    // is the one deliberate exception, held at ~50% (round(COGS ÷ $0.005)).
    // Everything non-AI (script/breakdown/stock/export) is included = 0.
    public const SCRIPT     = 0;   // included
    public const BREAKDOWN  = 0;   // included
    public const STOCK      = 0;   // included — per scene (stock video/image/audiogram)
    public const TTS        = 1;   // per scene, OpenAI tts-1 (~$0.001 COGS, 1cr floor)
    // Gemini 3.1 Flash TTS — the default expressive engine. Token-priced
    // upstream ($2/1M input, $0.04/1K output); a typical short-form scene
    // (~8-12s) lands near ~$0.012 COGS → 3cr (~75% margin, 1cr=$0.01 retail).
    // Actual per-call COGS is recorded via ApiUsageService for recalibration.
    public const TTS_GEMINI = 3;   // per scene, Gemini 3.1 Flash TTS (~$0.012 COGS)
    public const AI_MEDIUM    = 16;  // per scene, gpt-image-1 medium (~$0.063 COGS)
    public const AI_HIGH      = 63;  // per scene, gpt-image-1 high (~$0.25 COGS)
    public const AI_CHARACTER = 50;  // per scene, OpenAI gpt-image-2 /edits (~$0.20 COGS, character + reference image)
    public const AI_MUSIC     = 3;   // per scene, Replicate MusicGen (~$0.01 COGS)

    // Image-to-video animation tiers — per 6-second clip; longer clips scale roughly proportionally.
    // Held at ~50% margin (the moat). Wan 2.1 480p / Hailuo / Kling 2.1 mapping.
    public const VIDEO_QUICK    = 60;   // ~$0.30 per 6s clip — Wan 2.1 i2v 480p
    public const VIDEO_BALANCED = 120;  // ~$0.60 per 6s clip — Hailuo MiniMax
    public const VIDEO_PREMIUM  = 240;  // ~$1.20 per 6s clip — Kling 2.1
    public const VIDEO_SEEDANCE_LITE = 100;  // ~$0.50 per 5s clip — ByteDance Seedance 1 Lite
    public const VIDEO_SEEDANCE_PRO  = 200;  // ~$1.00 per 5s clip — ByteDance Seedance 1 Pro
    public const VIDEO_SPOKESPERSON  = 140;  // ~$0.64 per 8s clip — VEED Fabric 1.0 480p (image+audio lip-sync)
    public const EXPORT     = 0;   // included

    // Approximate upstream provider cost (COGS) in USD per operation. Stamped
    // onto credit_ledger.upstream_cost_usd at deduction time so future
    // recalibration runs on real spend data, not code comments
    // (CREDIT_CALIBRATION.md §2). Best-estimate per-unit cost; animation scales
    // ×2 for 10s clips (mirrors the credit cost).
    public const COGS_USD = [
        'ai_image:gpt-image-1'    => 0.063,
        'ai_image:gpt-image-2'    => 0.17,
        'ai_image:nano-banana'    => 0.039,
        'ai_image:nano-banana-pro' => 0.134,
        'ai_image:flux-schnell'   => 0.003,
        'ai_image:sdxl-lightning' => 0.003,
        'ai_image:character'      => 0.20,
        'tts'                     => 0.001,
        'tts:gemini'              => 0.012,
        'music'                   => 0.01,
        'video:quick'             => 0.30,
        'video:seedance_lite'     => 0.50,
        'video:balanced'          => 0.60,
        'video:seedance_pro'      => 1.00,
        'video:premium'           => 1.20,
        'video:spokesperson'      => 0.64,
    ];

    /** Best-estimate upstream USD cost for an op key (see COGS_USD). Null if unknown. */
    public static function cogsUsd(string $key): ?float
    {
        return self::COGS_USD[$key] ?? null;
    }

    // Monthly credit allocations per plan — sized for blended usage + breakage
    // (CREDIT_CALIBRATION.md §5). Clears ~50% margin even all-Kling worst case.
    public const PLAN_CREDITS = [
        'free'       => 0,      // one-time 200 via grant, never resets
        'starter'    => 1500,
        'creator'    => 3000,
        'pro'        => 6500,
        'agency'     => 13000,
        'enterprise' => 50000,
        // Legacy tier aliases
        'studio'     => 3000,   // mirrors creator
        'scale'      => 6500,   // mirrors pro
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
        if ($amount <= 0) {
            return true; // nothing to charge
        }

        // Atomic: lock the workspace row, re-check the balance UNDER the lock,
        // then decrement. Without this, two concurrent deductions (e.g. Cruise
        // "Apply all") both read the same balance, both pass the check, and
        // overdraw — the credit leak. The lock serialises them.
        $charged = DB::transaction(function () use ($workspaceId, $amount): bool {
            $workspace = Workspace::query()->whereKey($workspaceId)->lockForUpdate()->first();
            if (! $workspace || $workspace->creditsBalance() < $amount) {
                return false;
            }

            $remaining   = $amount;
            $fromMonthly = min($remaining, (int) $workspace->credits_monthly);
            $remaining  -= $fromMonthly;
            $fromTopup   = min($remaining, (int) $workspace->credits_topup);

            if ($fromMonthly > 0) {
                $workspace->decrement('credits_monthly', $fromMonthly);
            }
            if ($fromTopup > 0) {
                $workspace->decrement('credits_topup', $fromTopup);
            }

            return true;
        });

        if (! $charged) {
            return false;
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
                // Real upstream provider cost in USD, when the caller knows it.
                // Unblocks data-driven recalibration (CREDIT_CALIBRATION.md §2).
                'upstream_cost_usd' => isset($context['upstream_cost_usd']) ? (float) $context['upstream_cost_usd'] : null,
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
     * Refund credits for work that was charged up-front but then failed to
     * deliver (a generation job that errored or was aborted). Goes back to
     * credits_topup (like a grant) and lands a `refund:<op>` ledger row so it's
     * queryable. No-op for zero/negative amounts.
     */
    public function refund(int $workspaceId, int $amount, string $operation = ''): void
    {
        if ($amount <= 0) {
            return;
        }

        Workspace::where('id', $workspaceId)->increment('credits_topup', $amount);

        rescue(function () use ($workspaceId, $amount, $operation) {
            CreditLedgerEntry::query()->create([
                'workspace_id'  => $workspaceId,
                'operation'     => mb_substr('refund:'.($operation !== '' ? $operation : 'unspecified'), 0, 64),
                'credits'       => -$amount, // negative = credit going back INTO the workspace
                'balance_after' => $this->balance($workspaceId),
                'metadata'      => ['refund_of' => $operation],
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
