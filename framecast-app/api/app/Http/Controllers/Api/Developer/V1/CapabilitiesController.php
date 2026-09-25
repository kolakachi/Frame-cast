<?php

namespace App\Http\Controllers\Api\Developer\V1;

use App\Models\User;
use App\Services\AnimatedShotPlan;
use App\Services\CreditService;
use App\Services\Generation\Image\ImageAdapterFactory;
use App\Services\WorkspaceUsageService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this workspace may ask for through the API, and what it costs.
 *
 * The lists here are the pilot contract — a deliberate subset of what the
 * dashboard accepts — and QuoteController validates against the same
 * constants, so a client that reads this cannot be told "yes" here and "no"
 * at the quote. No billing, member or key data.
 */
class CapabilitiesController extends DeveloperController
{
    public const SOURCE_TYPES = ['prompt', 'script'];
    public const VISUAL_MODES = ['stock', 'ai_images', 'ai_video'];
    public const ANIMATE_TIERS = ['quick', 'balanced', 'premium', 'seedance_lite', 'seedance_pro', 'veo_fast', 'seedance_25'];
    public const ASPECT_RATIOS = ['9:16', '1:1', '16:9'];

    public function __construct(
        private readonly CreditService $credits,
        private readonly WorkspaceUsageService $usage,
        private readonly ImageAdapterFactory $images,
    ) {
    }

    public function show(Request $request): JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $workspaceId = (int) $user->workspace_id;

        $aiPerScene = $this->images->costFor(null);
        $aiVideoPerScene = [];
        foreach (self::ANIMATE_TIERS as $tier) {
            $aiVideoPerScene[$tier] = $aiPerScene + CreditService::animationCost(
                $tier,
                CreditService::videoQuality($tier, null),
                AnimatedShotPlan::requestSeconds(null),
            );
        }

        return response()->json(['data' => [
            'plan' => $this->credits->planTier($workspaceId),
            'credits' => ['balance' => $this->credits->balance($workspaceId)],
            'limits' => [
                'max_duration_seconds' => $this->credits->maxDurationSeconds($workspaceId),
                'exports_remaining_this_month' => $this->usage->exportsRemaining($user),
                'quote_ttl_minutes' => \App\Models\ApiQuote::TTL_MINUTES,
            ],
            'video' => [
                'source_types' => self::SOURCE_TYPES,
                'visual_modes' => self::VISUAL_MODES,
                'animate_tiers' => self::ANIMATE_TIERS,
                'animation_pacing' => ['short', 'long'],
                'aspect_ratios' => self::ASPECT_RATIOS,
                'duration_seconds' => ['min' => 5, 'max' => 600, 'default' => 60],
                'language_default' => 'en',
            ],
            'costs' => [
                'unit' => 'credits',
                'script_and_breakdown' => CreditService::SCRIPT + CreditService::BREAKDOWN,
                'voice_per_scene' => CreditService::TTS,
                'export' => CreditService::EXPORT,
                'visual_per_scene' => [
                    'stock' => CreditService::STOCK,
                    'ai_images' => $aiPerScene,
                    'ai_video' => $aiVideoPerScene,
                ],
            ],
        ], 'meta' => []]);
    }
}
