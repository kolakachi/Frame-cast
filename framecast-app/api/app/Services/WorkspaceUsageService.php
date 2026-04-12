<?php

namespace App\Services;

use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Channel;
use App\Models\ExportJob;
use App\Models\Project;
use App\Models\Scene;
use App\Models\User;
use App\Models\VoiceProfile;

class WorkspaceUsageService
{
    public const RENDER_LIMIT = 200;
    public const VOICE_MINUTES_LIMIT = 120;
    public const DUB_LANGUAGES_LIMIT = 3;
    public const CHANNEL_LIMIT = 5;
    public const VOICE_CLONING_LIMIT = 2;

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
     *     projects:int
     * }
     */
    public function summaryForUser(User $user): array
    {
        $workspaceId = $user->workspace_id;

        $voiceSeconds = (float) Scene::query()
            ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))
            ->sum('duration_seconds');

        $dubLanguagesUsed = Project::query()
            ->where('workspace_id', $workspaceId)
            ->whereNotNull('primary_language')
            ->distinct('primary_language')
            ->count('primary_language');

        return [
            'plan' => 'Studio',
            'renders_used' => ExportJob::query()
                ->whereHas('project', fn ($query) => $query->where('workspace_id', $workspaceId))
                ->where('status', 'completed')
                ->count(),
            'render_limit' => self::RENDER_LIMIT,
            'voice_minutes_used' => (int) ceil($voiceSeconds / 60),
            'voice_minutes_limit' => self::VOICE_MINUTES_LIMIT,
            'dub_languages_used' => $dubLanguagesUsed,
            'dub_languages_limit' => self::DUB_LANGUAGES_LIMIT,
            'active_channels' => Channel::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->count(),
            'channel_limit' => self::CHANNEL_LIMIT,
            'voice_cloning_used' => VoiceProfile::query()
                ->where('workspace_id', $workspaceId)
                ->where('is_cloned', true)
                ->count(),
            'voice_cloning_limit' => self::VOICE_CLONING_LIMIT,
            'assets' => Asset::query()
                ->where('workspace_id', $workspaceId)
                ->where('status', 'active')
                ->count(),
            'brand_kits' => BrandKit::query()->where('workspace_id', $workspaceId)->count(),
            'projects' => Project::query()->where('workspace_id', $workspaceId)->count(),
        ];
    }

    public function hasReachedChannelLimit(User $user): bool
    {
        return Channel::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('status', 'active')
            ->count() >= self::CHANNEL_LIMIT;
    }
}
