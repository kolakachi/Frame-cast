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
     * @return array<string, array<string, int|float|string>>
     */
    public static function plans(): array
    {
        return [
            'free' => [
                'name' => 'Free',
                'render_limit' => 10,
                'voice_minutes_limit' => 20,
                'dub_languages_limit' => 1,
                'channel_limit' => 1,
                'voice_cloning_limit' => 0,
                'api_budget_usd' => 1.0,
            ],
            'studio' => [
                'name' => 'Studio',
                'render_limit' => self::RENDER_LIMIT,
                'voice_minutes_limit' => self::VOICE_MINUTES_LIMIT,
                'dub_languages_limit' => self::DUB_LANGUAGES_LIMIT,
                'channel_limit' => self::CHANNEL_LIMIT,
                'voice_cloning_limit' => self::VOICE_CLONING_LIMIT,
                'api_budget_usd' => 25.0,
            ],
            'scale' => [
                'name' => 'Scale',
                'render_limit' => 1000,
                'voice_minutes_limit' => 600,
                'dub_languages_limit' => 12,
                'channel_limit' => 25,
                'voice_cloning_limit' => 10,
                'api_budget_usd' => 150.0,
            ],
            'enterprise' => [
                'name' => 'Enterprise',
                'render_limit' => 10000,
                'voice_minutes_limit' => 5000,
                'dub_languages_limit' => 50,
                'channel_limit' => 250,
                'voice_cloning_limit' => 100,
                'api_budget_usd' => 1000.0,
            ],
        ];
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

        return $this->buildSummary((int) $user->workspace_id, 'studio');
    }

    /**
     * @return array<string, mixed>
     */
    public function summaryForWorkspace(Workspace $workspace): array
    {
        return $this->buildSummary((int) $workspace->getKey(), (string) ($workspace->plan_tier ?: 'studio'));
    }

    /**
     * @return array<string, mixed>
     */
    private function buildSummary(int $workspaceId, string $planTier): array
    {
        $plan = self::plans()[$planTier] ?? self::plans()['studio'];

        $voiceSeconds = (float) Scene::query()
            ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->sum('duration_seconds');

        $dubLanguagesUsed = Project::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('primary_language')
            ->distinct('primary_language')
            ->count('primary_language');

        return [
            'plan' => $plan['name'],
            'plan_tier' => $planTier,
            'renders_used' => ExportJob::query()
                ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('status', 'completed')
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
            'channel_limit' => (int) $plan['channel_limit'],
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

        $planTier = (string) ($user->workspace?->plan_tier ?: 'studio');
        $plan = self::plans()[$planTier] ?? self::plans()['studio'];

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

        $planTier = (string) ($user->workspace?->plan_tier ?: 'studio');
        $plan = self::plans()[$planTier] ?? self::plans()['studio'];

        $used = ExportJob::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
            ->where('status', 'completed')
            ->count();

        return $used >= (int) $plan['render_limit'];
    }

    public function hasExceededApiBudget(User $user): bool
    {
        if (self::isAdmin($user)) {
            return false;
        }

        $planTier = (string) ($user->workspace?->plan_tier ?: 'studio');
        $plan = self::plans()[$planTier] ?? self::plans()['studio'];
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
    public function exportLimitContext(User $user): array
    {
        $planTier = (string) ($user->workspace?->plan_tier ?: 'studio');
        $plan = self::plans()[$planTier] ?? self::plans()['studio'];
        $used = ExportJob::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
            ->where('status', 'completed')
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

        $planTier = (string) ($user->workspace?->plan_tier ?: 'studio');
        $plan = self::plans()[$planTier] ?? self::plans()['studio'];

        $voiceSeconds = (float) Scene::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
            ->sum('duration_seconds');

        return (int) ceil($voiceSeconds / 60) >= (int) $plan['voice_minutes_limit'];
    }

    /**
     * @return array{plan:string,used:int,limit:int}
     */
    public function voiceLimitContext(User $user): array
    {
        $planTier = (string) ($user->workspace?->plan_tier ?: 'studio');
        $plan = self::plans()[$planTier] ?? self::plans()['studio'];

        $voiceSeconds = (float) Scene::query()
            ->whereHas('project', fn ($q) => $q->where('workspace_id', $user->workspace_id))
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
        $planTier = (string) ($user->workspace?->plan_tier ?: 'studio');
        $plan = self::plans()[$planTier] ?? self::plans()['studio'];

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
