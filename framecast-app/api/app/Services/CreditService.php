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
    // Chatterbox (Resemble) cloned-voice synthesis — flat ~$0.009/run on
    // Replicate regardless of length → 2cr (~78% margin, 1cr=$0.01 retail).
    public const TTS_CLONE  = 2;   // per scene, Chatterbox cloned voice (~$0.009 COGS)
    public const AI_MEDIUM    = 16;  // per scene, gpt-image-1 medium (~$0.063 COGS)
    public const AI_HIGH      = 63;  // per scene, gpt-image-1 high (~$0.25 COGS)
    public const AI_CHARACTER = 50;  // per scene, OpenAI gpt-image-2 /edits (~$0.20 COGS, character + reference image)
    // Reading ONE scanned PDF page with a vision model, at detail=high.
    // Measured on a real rendered page (A4 @150dpi): 36,857 input + 292 output
    // tokens on gpt-4o-mini = ~$0.0057 COGS, which at the standard peg
    // (COGS / $0.004) is 1.43 -> 1 credit.
    //
    // detail=high is not optional. At detail=low the same page costs ~9x less
    // but transcribes INACCURATELY — fluently inventing plausible words rather
    // than failing visibly, which is the worst outcome for a document reader.
    // Token count scales with page dimensions, so this is calibrated for
    // roughly A4; unusually large pages cost more.
    public const PDF_VISION_PAGE = 1;

    /**
     * Hard ceiling on vision pages read from ONE document, whatever the plan.
     *
     * Plan limits control monthly generosity; this controls blast radius. An
     * "unlimited" plan otherwise means a single upload of a 5,000-page scanned
     * archive is 5,000 credits and ~$28 of COGS in one action, with no human
     * decision between the click and the bill. Unlimited should mean "as many
     * documents as you like", not "any one document can be arbitrarily large".
     */
    // 60, down from 200: units arrive as ~2.5MB rendered slices that one
    // worker pass must hold; 200 would be ~500MB of base64 and no realistic
    // memory_limit survives it. Raise only alongside batched rendering.
    public const PDF_VISION_MAX_PER_DOCUMENT = 60;

    /**
     * Plans whose exports jump the queue.
     *
     * "Top priority queue" is advertised on the Agency card and specified in
     * PRD.md ("Higher tier jobs are dequeued first"), but was never built:
     * every export was created with priority 0 and nothing ordered by it.
     */
    public const PRIORITY_EXPORT_TIERS = ['agency', 'enterprise', 'scale', 'appsumo_agency'];

    /**
     * Queue an export should run on.
     *
     * Laravel drains `--queue=a,b` strictly left to right, so putting priority
     * work on its own queue name IS the priority mechanism — no custom dequeue
     * logic, no polling, nothing to keep in sync. The workers list
     * exports-priority ahead of exports.
     */
    public static function exportQueueFor(?string $planTier): string
    {
        return in_array((string) $planTier, self::PRIORITY_EXPORT_TIERS, true)
            ? 'exports-priority'
            : 'exports';
    }

    /** Numeric priority stored on the export row, for display and reporting. */
    public static function exportPriorityFor(?string $planTier): int
    {
        return in_array((string) $planTier, self::PRIORITY_EXPORT_TIERS, true) ? 10 : 0;
    }

    /**
     * Credit cost for a TTS engine name (see RoutingTTSAdapter::engineFor).
     * Mirrors GenerateTTSJob::ttsBilling, which keys off the provider the
     * adapter actually returned — this is the pre-flight side of the same
     * pricing, used to quote a bulk re-record before anything runs.
     */
    public static function ttsCostForEngine(string $engine): int
    {
        return match ($engine) {
            'chatterbox' => self::TTS_CLONE,
            'gemini'     => self::TTS_GEMINI,
            default      => self::TTS,
        };
    }

    /**
     * Scanned pages we'll read from one document for this plan.
     *
     * Always an int: null in PLAN_LIMITS means "no plan limit", never "no
     * ceiling". Centralised because both the dry-run estimate and the job must
     * agree — if they diverge, users are quoted one number and charged another.
     */
    public static function pdfVisionPageCap(?string $planTier): int
    {
        // Product decision (2026-08-31): the CREDITS are the limiter, not the
        // plan tier. A document quotes what it actually needs — a 21-section
        // sheet is 21 credits on any plan — and the per-document ceiling
        // exists only as blast-radius protection. The per-tier
        // pdf_vision_page_limit values in PLAN_LIMITS are vestigial; kept for
        // reference, deliberately unread here so quote and charge can't split.
        return self::PDF_VISION_MAX_PER_DOCUMENT;
    }

    public const AI_MUSIC     = 2;   // per scene, Replicate MusicGen (~$0.01 COGS) — 50%

    // Image-to-video animation tiers — base clip; 10s = 2×. Recalibrated to
    // real per-second COGS at the PINNED resolution (CREDIT_CALIBRATION.md §12,
    // 2026-06-14). Resolutions pinned in ReplicateI2VAdapter so cost is fixed.
    public const VIDEO_QUICK    = 50;   // Wan 2.5 i2v @480p, 5s (~$0.25) — 50%
    public const VIDEO_BALANCED = 35;   // Hailuo 2.3-fast @768p, 6s (~$0.19) — 46%
    public const VIDEO_PREMIUM  = 100;  // Kling 2.1 pro mode, 5s (~$0.45) — 55%
    public const VIDEO_SEEDANCE_LITE = 30;   // Seedance 1 Lite @720p, 5s (~$0.18) — 40%
    public const VIDEO_SEEDANCE_PRO  = 125;  // Seedance 1 Pro @1080p, 5s (~$0.75) — 40%
    // Veo 3.1 Fast bills PER SECOND ($0.10/s without audio), so its buckets are
    // 4s and 8s — the only pair the model offers where long is exactly 2× short,
    // which keeps the universal ×2 long-clip rule honest.
    public const VIDEO_VEO_FAST      = 80;   // Veo 3.1 Fast @720p, 4s (~$0.40) — 50%
    public const VIDEO_SEEDANCE_25   = 105;  // Seedance 2.5 @480p, 5s (~$0.514) — 51%
    // Spokesperson (VEED Fabric) is LENGTH-BASED — Fabric bills per second
    // ($0.08/s @ 480p), so a flat charge loses money on long clips. Buckets
    // hold ~50% margin across lengths (see spokespersonCost). The constant is
    // the ≤8s base, used as the pre-flight/estimate default.
    public const VIDEO_SPOKESPERSON  = 130;  // ≤8s base — VEED Fabric 1.0 480p (image+audio lip-sync)

    // User-selectable quality per video tier. Each option = credits (~40–55%
    // margin) + base-clip COGS at that resolution/mode (CREDIT_CALIBRATION.md
    // §12/§13). `param` is the model input the resolved value is sent as. 10s
    // clips cost 2×. The VIDEO_* constants above mirror each tier's default.
    public const VIDEO_PRICING = [
        'quick' => [
            'label' => 'Wan 2.5', 'param' => 'resolution', 'default' => '480p',
            'options' => [
                '480p'  => ['cr' => 50,  'cogs' => 0.25],
                '720p'  => ['cr' => 100, 'cogs' => 0.50],
                '1080p' => ['cr' => 150, 'cogs' => 0.75],
            ],
        ],
        'seedance_lite' => [
            'label' => 'Seedance Lite', 'param' => 'resolution', 'default' => '720p',
            'options' => [
                '480p'  => ['cr' => 15, 'cogs' => 0.09],
                '720p'  => ['cr' => 30, 'cogs' => 0.18],
                '1080p' => ['cr' => 60, 'cogs' => 0.36],
            ],
        ],
        'veo_fast' => [
            // Same per-second price at either resolution, so 1080p is a free
            // upgrade — offered anyway so the choice is explicit.
            'label' => 'Veo 3.1 Fast', 'param' => 'resolution', 'default' => '720p',
            'options' => [
                '720p'  => ['cr' => 80, 'cogs' => 0.40],
                '1080p' => ['cr' => 80, 'cogs' => 0.40],
            ],
        ],
        'seedance_25' => [
            'label' => 'Seedance 2.5', 'param' => 'resolution', 'default' => '480p',
            'options' => [
                '480p' => ['cr' => 105, 'cogs' => 0.514],
                '720p' => ['cr' => 235, 'cogs' => 1.156],
            ],
        ],
        'balanced' => [
            'label' => 'Hailuo 2.3', 'param' => 'resolution', 'default' => '768p',
            'options' => [
                '768p'  => ['cr' => 35, 'cogs' => 0.19],
                '1080p' => ['cr' => 60, 'cogs' => 0.33],
            ],
        ],
        'seedance_pro' => [
            'label' => 'Seedance Pro', 'param' => 'resolution', 'default' => '1080p',
            'options' => [
                '480p'  => ['cr' => 25,  'cogs' => 0.15],
                '720p'  => ['cr' => 50,  'cogs' => 0.30],
                '1080p' => ['cr' => 125, 'cogs' => 0.75],
            ],
        ],
        'premium' => [
            'label' => 'Kling 2.1', 'param' => 'mode', 'default' => 'pro',
            'options' => [
                'standard' => ['cr' => 55,  'cogs' => 0.25],
                'pro'      => ['cr' => 100, 'cogs' => 0.45],
            ],
        ],
    ];
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
        'tts:chatterbox'          => 0.009,
        'music'                   => 0.01,
        'video:quick'             => 0.25,  // Wan 2.5 @480p, 5s ($0.05/s)
        'video:seedance_lite'     => 0.18,  // Seedance Lite @720p, 5s ($0.036/s)
        'video:balanced'          => 0.19,  // Hailuo 2.3-fast @768p, 6s
        'video:seedance_pro'      => 0.75,  // Seedance Pro @1080p, 5s ($0.15/s)
        'video:veo_fast'          => 0.40,  // Veo 3.1 Fast no-audio, 4s ($0.10/s)
        'video:seedance_25'       => 0.514, // Seedance 2.5 @480p non_video_in, 5s ($0.1028/s)
        'video:premium'           => 0.45,  // Kling 2.1 pro, 5s ($0.09/s)
        'video:spokesperson'      => 0.64,  // Fabric 480p ~8s; actual scales per-second
    ];

    /** Best-estimate upstream USD cost for an op key (see COGS_USD). Null if unknown. */
    public static function cogsUsd(string $key): ?float
    {
        return self::COGS_USD[$key] ?? null;
    }

    /**
     * Length-based credit cost for a spokesperson (Fabric lip-sync) clip — its
     * length follows the voiceover, and Fabric bills per second, so a flat
     * charge loses money on long clips. Buckets hold ~50% margin
     * (CREDIT_CALIBRATION.md §11): ≤8s → 130, ≤15s → 240, longer → 320.
     */
    public static function spokespersonCost(float $seconds): int
    {
        if ($seconds <= 8.0) {
            return 130;
        }
        if ($seconds <= 15.0) {
            return 240;
        }

        return 320;
    }

    /** Upstream COGS (USD) for a spokesperson clip — Fabric per-second rate × length. */
    public static function spokespersonCogsUsd(float $seconds, string $resolution = '480p'): float
    {
        $perSecond = $resolution === '720p' ? 0.15 : 0.08;

        return round(max(1.0, $seconds) * $perSecond, 4);
    }

    /** Resolve a requested video quality to a valid option for the tier (its default if unknown/absent). */
    public static function videoQuality(string $tier, ?string $quality): string
    {
        $cfg = self::VIDEO_PRICING[$tier] ?? null;
        if (! $cfg) {
            return (string) ($quality ?? '');
        }

        return isset($cfg['options'][$quality]) ? (string) $quality : (string) $cfg['default'];
    }

    /** The model input name a tier's quality is sent as: 'resolution' or, for Kling, 'kling_mode'. */
    public static function videoQualityParam(string $tier): string
    {
        return (self::VIDEO_PRICING[$tier]['param'] ?? 'resolution') === 'mode' ? 'kling_mode' : 'resolution';
    }

    /** Credit cost for a video tier at a given quality + duration (10s = 2×). */
    public static function animationCost(string $tier, ?string $quality, int $seconds): int
    {
        $cfg = self::VIDEO_PRICING[$tier] ?? null;
        $mult = $seconds >= 10 ? 2 : 1;
        if (! $cfg) {
            return self::VIDEO_QUICK * $mult;
        }
        $q = self::videoQuality($tier, $quality);

        return (int) ($cfg['options'][$q]['cr'] ?? self::VIDEO_QUICK) * $mult;
    }

    /** Upstream COGS (USD) for a video tier at a given quality + duration (10s = 2×). */
    public static function animationCogsUsd(string $tier, ?string $quality, int $seconds): ?float
    {
        $cfg = self::VIDEO_PRICING[$tier] ?? null;
        $mult = $seconds >= 10 ? 2 : 1;
        if (! $cfg) {
            $base = self::cogsUsd('video:'.$tier) ?? self::cogsUsd('video:quick');
            return $base === null ? null : round($base * $mult, 4);
        }
        $q = self::videoQuality($tier, $quality);
        $cogs = $cfg['options'][$q]['cogs'] ?? null;

        return $cogs === null ? null : round($cogs * $mult, 4);
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
        // Lifetime tiers get NO monthly allocation. Their credits are a
        // one-time bucket in credits_topup, same as the AppSumo LTDs — a
        // recurring refill would both undercut the subscriptions and make the
        // per-licence cost unbounded.
        'lifetime_starter' => 0,
        'lifetime_creator' => 0,
        'lifetime_agency'  => 0,
        // Legacy tier aliases
        'studio'     => 3000,   // mirrors creator
        'scale'      => 6500,   // mirrors pro
    ];

    /**
     * Tiers whose unused monthly credits carry forward at renewal instead of
     * being replaced. Advertised as "Credit rollover" on the Agency plan card
     * (marketing/index.html, help.html) and PRICING.md §5.
     *
     * Key present = rollover enabled. Value = the maximum multiple of the
     * monthly allocation the bucket may hold, or null for uncapped (which is
     * what the pricing page currently promises — it carries no qualifier). To
     * bound the liability later, set e.g. 'agency' => 3 and update the copy to
     * say "roll over for up to 3 months".
     */
    public const PLAN_ROLLOVER = [
        'agency' => null,
    ];

    /**
     * Credits the monthly bucket should hold after a renewal refill.
     *
     * Non-rollover tiers replace the bucket (unused credits are forfeited);
     * rollover tiers add the new allocation on top of what's left, optionally
     * capped. Callers must pass the CURRENT monthly balance.
     */
    public static function refilledMonthlyCredits(string $planTier, int $currentMonthly): int
    {
        $allocation = self::PLAN_CREDITS[$planTier] ?? 0;

        if (! array_key_exists($planTier, self::PLAN_ROLLOVER)) {
            return $allocation;
        }

        $total = max(0, $currentMonthly) + $allocation;
        $capMultiplier = self::PLAN_ROLLOVER[$planTier];

        return $capMultiplier === null
            ? $total
            : min($total, $allocation * $capMultiplier);
    }

    // Per-plan resource caps + capability flags. Free-tier gates exist to
    // make the free trial feel real ("publish one video, see the moat") but
    // stop short of supporting a real workflow ("more characters, more
    // channels, publish to social"). Upgrade-path levers, not punishment.
    //
    // null = unlimited.
    // pdf_page_limit        — pages we'll READ from an uploaded PDF (text: free).
    // pdf_vision_page_limit  — scanned pages we'll RENDER and read with vision.
    // Two numbers because the costs differ by an order of magnitude: text
    // extraction is free, vision is billed per page. One combined limit would
    // either strangle the cheap path or leave the expensive one exposed.
    // null = unlimited.
    public const PLAN_LIMITS = [
        'free'       => ['max_duration_seconds' => 60,  'max_characters' => 1,  'max_brand_kits' => 1,  'max_channels' => 1, 'social_publishing' => false, 'pdf_page_limit' => 5, 'pdf_vision_page_limit' => 0],
        'starter'    => ['max_duration_seconds' => 180, 'max_characters' => 3,  'max_brand_kits' => 1,  'max_channels' => 1, 'social_publishing' => true, 'pdf_page_limit' => 20, 'pdf_vision_page_limit' => 5],
        'creator'    => ['max_duration_seconds' => 300, 'max_characters' => 10, 'max_brand_kits' => 3,  'max_channels' => 3, 'social_publishing' => true, 'pdf_page_limit' => 50, 'pdf_vision_page_limit' => 15],
        'pro'        => ['max_duration_seconds' => 600, 'max_characters' => 50, 'max_brand_kits' => 10, 'max_channels' => 10,'social_publishing' => true, 'pdf_page_limit' => 150, 'pdf_vision_page_limit' => 40],
        'agency'     => ['max_duration_seconds' => 600, 'max_characters' => null,'max_brand_kits' => null,'max_channels' => null,'social_publishing' => true, 'pdf_page_limit' => null, 'pdf_vision_page_limit' => 100],
        'enterprise' => ['max_duration_seconds' => 600, 'max_characters' => null,'max_brand_kits' => null,'max_channels' => null,'social_publishing' => true, 'pdf_page_limit' => null, 'pdf_vision_page_limit' => null],
        'studio'     => ['max_duration_seconds' => 300, 'max_characters' => 10, 'max_brand_kits' => 3,  'max_channels' => 3, 'social_publishing' => true, 'pdf_page_limit' => 50, 'pdf_vision_page_limit' => 15],
        'scale'      => ['max_duration_seconds' => 600, 'max_characters' => 50, 'max_brand_kits' => 10, 'max_channels' => 10,'social_publishing' => true, 'pdf_page_limit' => 150, 'pdf_vision_page_limit' => 40],
        // AppSumo LTD tiers — own limits (they differ from the subscription
        // tiers of the same name), one-time credit bucket, never renews.
        'appsumo_starter' => ['max_duration_seconds' => 180, 'max_characters' => 2,  'max_brand_kits' => 1,    'max_channels' => 1,    'social_publishing' => true, 'pdf_page_limit' => 20, 'pdf_vision_page_limit' => 5],
        'appsumo_creator' => ['max_duration_seconds' => 300, 'max_characters' => 5,  'max_brand_kits' => 5,    'max_channels' => 3,    'social_publishing' => true, 'pdf_page_limit' => 50, 'pdf_vision_page_limit' => 15],
        'appsumo_agency'  => ['max_duration_seconds' => 600, 'max_characters' => 10, 'max_brand_kits' => null, 'max_channels' => null, 'social_publishing' => true, 'pdf_page_limit' => null, 'pdf_vision_page_limit' => 100],
        // Direct lifetime tiers ($89/$199/$399). Same product as the AppSumo
        // LTDs, sold from our own checkout — identical limits, separate keys so
        // reporting can tell the two cohorts apart.
        'lifetime_starter' => ['max_duration_seconds' => 180, 'max_characters' => 2,  'max_brand_kits' => 1,    'max_channels' => 1,    'social_publishing' => true, 'pdf_page_limit' => 20, 'pdf_vision_page_limit' => 5],
        'lifetime_creator' => ['max_duration_seconds' => 300, 'max_characters' => 5,  'max_brand_kits' => 5,    'max_channels' => 3,    'social_publishing' => true, 'pdf_page_limit' => 50, 'pdf_vision_page_limit' => 15],
        'lifetime_agency'  => ['max_duration_seconds' => 600, 'max_characters' => 10, 'max_brand_kits' => null, 'max_channels' => null, 'social_publishing' => true, 'pdf_page_limit' => null, 'pdf_vision_page_limit' => 100],
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
            // The single strongest top-up-intent signal in the product: a user
            // tried to spend and the balance refused. Every real charge funnels
            // through here, so one hook covers all of them.
            \App\Services\Analytics\PostHogService::capture(
                isset($context['user_id']) ? (int) $context['user_id'] : null,
                'credit_blocked',
                [
                    'operation' => $operation,
                    'required'  => $amount,
                    'balance'   => $this->balance($workspaceId),
                ],
                $workspaceId,
            );

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
     * Refill monthly credits on billing renewal.
     *
     * Replaces the bucket on normal tiers; adds to it on rollover tiers (see
     * PLAN_ROLLOVER). The next anchor advances from the PREVIOUS renewal date,
     * not from `now()` — the job runs hourly, so anchoring on `now()` pushed
     * every customer's renewal a little later each cycle. If several cycles
     * were missed the anchor is walked forward to the next future date, so one
     * run always grants exactly one allocation.
     */
    public function resetMonthly(Workspace $workspace): void
    {
        $anchor = $workspace->billing_renews_at?->copy() ?? now();
        do {
            $anchor->addMonth();
        } while ($anchor->isPast());

        $workspace->update([
            'credits_monthly'   => self::refilledMonthlyCredits(
                (string) $workspace->plan_tier,
                (int) $workspace->credits_monthly,
            ),
            'billing_renews_at' => $anchor,
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
        ?int $durationSeconds = null,
        ?string $animateTier = null,
        ?string $animateQuality = null,
    ): array {
        [$scenesMin, $scenesMax] = $this->estimateSceneCount($sourceType, $sourceContent);

        // Animated projects are paced differently — fewer, longer scenes (see
        // ScenePacing) — so the scene range comes from the pacing rule, not
        // from source-content heuristics tuned for 5s still scenes.
        if ($visualMode === 'ai_video') {
            $target    = \App\Services\ScenePacing::targetScenes($durationSeconds ?: 60, $visualMode, true);
            $scenesMin = max(\App\Services\ScenePacing::MIN_SCENES, (int) round($target * 0.8));
            $scenesMax = min(\App\Services\ScenePacing::MAX_SCENES, (int) round($target * 1.2));
        }

        // AI visuals render on ImageAdapterFactory::DEFAULT_MODEL. Price the
        // quote from the factory so the estimate a user is shown before
        // creating a project equals what the job actually deducts — a stale
        // AI_MEDIUM here would quote 16cr/scene and then charge 43cr.
        $aiPerScene = app(\App\Services\Generation\Image\ImageAdapterFactory::class)->costFor(null);

        $visualPerScene = match ($visualMode) {
            'ai_images', 'ai_broll' => $aiPerScene,
            // Still first, then an i2v render on top of it — both are charged.
            'ai_video' => $aiPerScene + self::animationCost(
                $animateTier ?: 'quick',
                self::videoQuality($animateTier ?: 'quick', $animateQuality),
                5,
            ),
            default => self::STOCK, // stock_video, stock_images, waveform, etc.
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
            // A document carries more material than a prompt, and the estimate
            // is made before the PDF is read, so it can't key off word count.
            'pdf_upload'        => [9, 14],
            default             => [8, 12],
        };
    }
}
