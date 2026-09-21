<?php

namespace App\Services;

use App\Models\ApiUsageEvent;
use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Channel;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\VoiceProfile;
use App\Models\Workspace;

class WorkspaceUsageService
{
    public const RENDER_LIMIT = 200;
    public const VOICE_MINUTES_LIMIT = 120;
    public const DUB_LANGUAGES_LIMIT = 3;
    public const CHANNEL_LIMIT = 5;
    public const VOICE_CLONING_LIMIT = 2;

    /**
     * Per-tier allowances.
     *
     * Two keys here are deliberately NOT enforced, and are no longer shown to
     * customers either:
     *
     *  - dub_languages_limit — nothing blocks a workspace making videos in more
     *    languages than its tier lists. Localisation is already paid for in
     *    credits, so the cap protects no cost; it only penalised the
     *    international customers we most want. The usage meters that displayed
     *    it were removed rather than left advertising a rule we do not apply.
     *  - ai_image_quality — the request validator accepts any quality from any
     *    tier. Higher quality costs more credits, so credits are the limiter
     *    (the same decision already recorded for pdf_vision_page_limit). It is
     *    shown in the admin plan view only, never to a customer.
     *
     * Enforce either one before putting it back in front of a customer.
     *
     * @return array<string, array<string, int|float|string>>
     */
    public static function plans(): array
    {
        $plans = [
            'free' => [
                'name'                => 'Free',
                'render_limit'        => 10,
                'voice_minutes_limit' => 20,
                'dub_languages_limit' => 1,
                'channel_limit'       => 1,
                'voice_cloning_limit' => 0,
                'api_budget_usd'      => 1.0,
                'watermark'           => true,
                'ai_image_quality'    => ['medium'],
            ],
            'starter' => [
                'name'                => 'Starter',
                'render_limit'        => 50,
                'voice_minutes_limit' => 100,
                'dub_languages_limit' => 2,
                'channel_limit'       => 1,
                'voice_cloning_limit' => 0,
                'api_budget_usd'      => 25.0,
                'watermark'           => false,
                'ai_image_quality'    => ['medium'],
            ],
            'creator' => [
                'name'                => 'Creator',
                'render_limit'        => self::RENDER_LIMIT,
                'voice_minutes_limit' => self::VOICE_MINUTES_LIMIT,
                'dub_languages_limit' => self::DUB_LANGUAGES_LIMIT,
                'channel_limit'       => 3,
                'voice_cloning_limit' => self::VOICE_CLONING_LIMIT,
                'api_budget_usd'      => 50.0,
                'watermark'           => false,
                'ai_image_quality'    => ['medium', 'high'],
            ],
            'pro' => [
                'name'                => 'Pro',
                'render_limit'        => 1000,
                'voice_minutes_limit' => 600,
                'dub_languages_limit' => 12,
                'channel_limit'       => 10,
                'voice_cloning_limit' => 10,
                'api_budget_usd'      => 150.0,
                'watermark'           => false,
                'ai_image_quality'    => ['medium', 'high'],
            ],
            'agency' => [
                'name'                => 'Agency',
                'render_limit'        => 10000,
                'voice_minutes_limit' => 5000,
                'dub_languages_limit' => 50,
                'channel_limit'       => 999,
                'voice_cloning_limit' => 100,
                'api_budget_usd'      => 1000.0,
                'watermark'           => false,
                'ai_image_quality'    => ['medium', 'high'],
            ],
            'enterprise' => [
                'name'                => 'Enterprise',
                'render_limit'        => 99999,
                'voice_minutes_limit' => 99999,
                'dub_languages_limit' => 99,
                'channel_limit'       => 9999,
                'voice_cloning_limit' => 999,
                'api_budget_usd'      => 9999.0,
                'watermark'           => false,
                'ai_image_quality'    => ['medium', 'high'],
            ],
            // Legacy tier aliases (backwards-compatible)
            'studio' => [
                'name'                => 'Creator',
                'render_limit'        => self::RENDER_LIMIT,
                'voice_minutes_limit' => self::VOICE_MINUTES_LIMIT,
                'dub_languages_limit' => self::DUB_LANGUAGES_LIMIT,
                'channel_limit'       => self::CHANNEL_LIMIT,
                'voice_cloning_limit' => self::VOICE_CLONING_LIMIT,
                'api_budget_usd'      => 50.0,
                'watermark'           => false,
                'ai_image_quality'    => ['medium', 'high'],
            ],
            'scale' => [
                'name'                => 'Pro',
                'render_limit'        => 1000,
                'voice_minutes_limit' => 600,
                'dub_languages_limit' => 12,
                'channel_limit'       => 25,
                'voice_cloning_limit' => 10,
                'api_budget_usd'      => 150.0,
                'watermark'           => false,
                'ai_image_quality'    => ['medium', 'high'],
            ],
        ];
        // Resolve shared entitlements from the same catalogue as the API/UI.
        foreach (CreditService::PLAN_LIMITS as $tier => $limits) {
            $base = preg_replace('/^(appsumo|lifetime)_/', '', $tier);
            $plans[$tier] ??= $plans[$base] ?? $plans['free'];
            $plans[$tier]['credits_monthly'] = CreditService::PLAN_CREDITS[$tier] ?? 0;
            // Preserve grandfathered legacy channel allowances.
            $plans[$tier]['channel_limit'] = in_array($tier, ['studio', 'scale'], true)
                ? $plans[$tier]['channel_limit'] : $limits['max_channels'];
        }
        return $plans;
    }

    /**
     * @return array{
     *     plan:string,
     *     renders_used:int,
     *     render_limit:int,
     *     voice_minutes_used:int,
     *     voice_minutes_limit:int,
     *     dub_languages_used:int,
     *     dub_languages_limit:int,
     *     active_channels:int,
     *     channel_limit:int,
     *     voice_cloning_used:int,
     *     voice_cloning_limit:int,
     *     assets:int,
     *     brand_kits:int,
     *     projects:int,
     *     api_budget_usd:float
     * }
     */
    public function summaryForUser(User $user): array
    {
        if ($user->workspace) {
            return $this->summaryForWorkspace($user->workspace);
        }

        return $this->buildSummary((int) $user->workspace_id, 'free');
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForWorkspace(Workspace $workspace): array
    {
        $summary = $this->buildSummary((int) $workspace->getKey(), (string) ($workspace->plan_tier ?: 'free'));

        // A client workspace copies its agency's plan_tier so that feature
        // gating matches, which had the side effect of quoting the agency's
        // allowance back to the client: an Enterprise agency's client was told
        // it had 50,000 credits a month. It has no plan and no allowance — it
        // spends what the agency gave it, or the agency's own balance.
        if ($workspace->parent_workspace_id) {
            $summary['plan'] = 'Client workspace';
            $summary['credits_monthly'] = 0;

            if ($workspace->isFunded()) {
                $summary['credits_balance'] = (int) $workspace->creditsBalance();
                $summary['credits_topup'] = (int) $workspace->credits_topup;
                $summary['credits_source'] = 'allocated';
            } else {
                // Deliberately not the agency's balance. A client being shown
                // how much its agency holds is both misleading and none of its
                // business; null says "not yours to count" where 0 would read
                // as "you have run out".
                $summary['credits_balance'] = null;
                $summary['credits_topup'] = 0;
                $summary['credits_source'] = 'agency';
            }
        }

        return $summary;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(int $workspaceId, string $planTier): array
    {
        $plan = self::plans()[$planTier] ?? self::plans()['free'];

        $voiceSeconds = (float) Scene::query()
            ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('duration_seconds');

        $dubLanguagesUsed = Project::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('primary_language')
            ->distinct('primary_language')
            ->count('primary_language');

        $workspace = Workspace::find($workspaceId);

        return [
            'plan' => $plan['name'],
            'plan_tier' => $planTier,
            // Credits are the binding constraint on every generation — surfaced
            // here so the billing/usage UI can show an out-of-credits state.
            // Plan limits (renders/voice/dub) are secondary caps; a workspace
            // with limit headroom but zero credits still can't generate.
            'credits_balance' => $workspace ? (int) $workspace->creditsBalance() : 0,
            'credits_monthly' => $workspace ? (int) $workspace->credits_monthly : 0,
            'credits_topup'   => $workspace ? (int) $workspace->credits_topup : 0,
            'renders_used' => ExportJob::query()
                ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfMonth())
                ->count(),
            'render_limit' => (int) $plan['render_limit'],
            'voice_minutes_used' => (int) ceil($voiceSeconds / 60),
            'voice_minutes_limit' => (int) $plan['voice_minutes_limit'],
            'dub_languages_used' => $dubLanguagesUsed,
            'dub_languages_limit' => (int) $plan['dub_languages_limit'],
            'active_channels' => Channel::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->count(),
            'channel_limit' => $plan['channel_limit'],
            'voice_cloning_used' => VoiceProfile::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_cloned', true)
                ->count(),
            'voice_cloning_limit' => (int) $plan['voice_cloning_limit'],
            'assets' => Asset::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->count(),
            'brand_kits' => BrandKit::query()->where('workspace_id', $workspaceId)->count(),
            'projects' => Project::query()->where('workspace_id', $workspaceId)->count(),
            'api_budget_usd' => (float) $plan['api_budget_usd'],
        ];
    }

    public static function isAdmin(User $user): bool
    {
        return in_array($user->role, ['super_admin', 'platform_admin'], true);
    }

    public function hasReachedChannelLimit(User $user): bool
    {
        if (self::isAdmin($user)) {
            return false;
        }

        $planTier = (string) ($user->workspace?->plan_tier ?: 'free');
        $plan = self::plans()[$planTier] ?? self::plans()['free'];

        if ($plan['channel_limit'] === null) {
            return false;
        }

        return Channel::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('status', 'active')
            ->count() >= (int) $plan['channel_limit'];
    }

    public function hasReachedExportLimit(User $user): bool
    {
        if (self::isAdmin($user)) {
            return false;
        }

        $planTier = (string) ($user->workspace?->plan_tier ?: 'free');
        $plan = self::plans()[$planTier] ?? self::plans()['free'];

        $used = ExportJob::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfMonth())
            ->count();

        return $used >= (int) $plan['render_limit'];
    }

    public function hasExceededApiBudget(User $user): bool
    {
        if (self::isAdmin($user)) {
            return false;
        }

        // Paid usage is limited by purchased credits and any client cap.
        // An internal cost estimate must not make a paid top-up unusable.
        if (app(CreditService::class)->limitFor((int) $user->workspace_id, 'ugc_ads')) {
            return false;
        }

        $planTier = (string) ($user->workspace?->plan_tier ?: 'free');
        $plan = self::plans()[$planTier] ?? self::plans()['free'];
        $budget = (float) $plan['api_budget_usd'];

        $monthSpend = (float) ApiUsageEvent::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd');

        return $monthSpend >= $budget;
    }

    /**
     * @return array{plan:string,used:int,limit:int}
     */
    /** Remaining exports this period, or null if unlimited (admin/unbounded plan). */
    public function exportsRemaining(User $user): ?int
    {
        if (self::isAdmin($user)) {
            return null;
        }

        $planTier = (string) ($user->workspace?->plan_tier ?: 'free');
        $plan = self::plans()[$planTier] ?? self::plans()['free'];
        $used = ExportJob::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfMonth())
            ->count();

        return max(0, (int) $plan['render_limit'] - $used);
    }

    public function exportLimitContext(User $user): array
    {
        $planTier = (string) ($user->workspace?->plan_tier ?: 'free');
        $plan = self::plans()[$planTier] ?? self::plans()['free'];
        $used = ExportJob::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
            ->where('status', 'completed')
            ->where('completed_at', '>=', now()->startOfMonth())
            ->count();

        return [
            'plan' => (string) $plan['name'],
            'used' => $used,
            'limit' => (int) $plan['render_limit'],
        ];
    }

    public function hasReachedVoiceLimit(User $user): bool
    {
        if (self::isAdmin($user)) {
            return false;
        }

        $planTier = (string) ($user->workspace?->plan_tier ?: 'free');
        $plan = self::plans()[$planTier] ?? self::plans()['free'];

        $voiceSeconds = (float) Scene::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('duration_seconds');

        return (int) ceil($voiceSeconds / 60) >= (int) $plan['voice_minutes_limit'];
    }

    /**
     * @return array{plan:string,used:int,limit:int}
     */
    public function voiceLimitContext(User $user): array
    {
        $planTier = (string) ($user->workspace?->plan_tier ?: 'free');
        $plan = self::plans()[$planTier] ?? self::plans()['free'];

        $voiceSeconds = (float) Scene::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
            ->where('created_at', '>=', now()->startOfMonth())
            ->sum('duration_seconds');

        return [
            'plan' => (string) $plan['name'],
            'used' => (int) ceil($voiceSeconds / 60),
            'limit' => (int) $plan['voice_minutes_limit'],
        ];
    }

    /**
     * @return array{plan:string,spent_usd:float,budget_usd:float}
     */
    public function apiBudgetContext(User $user): array
    {
        $planTier = (string) ($user->workspace?->plan_tier ?: 'free');
        $plan = self::plans()[$planTier] ?? self::plans()['free'];

        $spent = (float) ApiUsageEvent::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('occurred_at', '>=', now()->startOfMonth())
            ->sum('estimated_cost_usd');

        return [
            'plan' => (string) $plan['name'],
            'spent_usd' => round($spent, 4),
            'budget_usd' => (float) $plan['api_budget_usd'],
        ];
    }
}
