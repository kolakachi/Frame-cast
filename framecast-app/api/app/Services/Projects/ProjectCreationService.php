<?php

namespace App\Services\Projects;

use App\Jobs\GenerateScriptJob;
use App\Models\Asset;
use App\Models\BrandKit;
use App\Models\Channel;
use App\Models\Niche;
use App\Models\Project;
use App\Models\Series;
use App\Models\Template;
use App\Models\User;
use App\Services\CreditService;
use App\Services\WorkspaceUsageService;

/**
 * Creating a project from validated input — the one path shared by the
 * dashboard (ProjectController::store) and the developer API.
 *
 * Everything that used to live in the controller after validation is here
 * unchanged: plan duration cap, credit guard, resolving channel / brand kit /
 * template / niche / series, defaults, the insert and the first job. Failures
 * are ProjectCreationException; the callers turn them into their own envelope.
 */
class ProjectCreationService
{
    public const SOURCE_TYPES = [
        'blank',
        'prompt',
        'script',
        'url',
        'images',
        'product_description',
        'csv_topic',
        'audio_upload',
        'video_upload',
        'pdf_upload',
    ];

    public function __construct(
        private readonly WorkspaceUsageService $usageService,
        private readonly CreditService $credits,
    ) {
    }

    /**
     * @param  array<string, mixed>  $validated  Already validated against the store() rules.
     * @return array{project: Project, channel: ?Channel, brand_kit_id: ?int, template: ?Template}
     *
     * @throws ProjectCreationException
     */
    public function create(User $user, array $validated, ?int $apiKeyId = null): array
    {
        if (! $user->workspace_id) {
            throw new ProjectCreationException('workspace_required', 'User is not assigned to a workspace.', 422);
        }

        if ($this->usageService->hasExceededApiBudget($user)) {
            $ctx = $this->usageService->apiBudgetContext($user);
            throw ProjectCreationException::limit(
                'api_budget_exceeded',
                "Your workspace has reached its \${$ctx['budget_usd']} AI budget for the {$ctx['plan']} plan this month.",
                $ctx,
            );
        }

        if (isset($validated['visual_type'])) {
            $validated['visual_generation_mode'] = $this->visualGenerationModeFromVisualType($validated['visual_type']);
        }

        $sourceError = $this->validateSourceContent($validated['source_type'], $validated['source_content_raw'] ?? null);

        if ($sourceError) {
            throw new ProjectCreationException('invalid_source_content', $sourceError, 422);
        }

        // ── Plan duration guard ──────────────────────────────────────────
        // Free tier caps at 60s; paid tiers go higher. Reject up front rather
        // than letting the user wait through generation.
        $maxDuration = $this->credits->maxDurationSeconds((int) $user->workspace_id);
        $requestedDuration = (int) ($validated['duration_target_seconds'] ?? 0);
        if ($maxDuration !== null && $requestedDuration > 0 && $requestedDuration > $maxDuration) {
            $planTier = $this->credits->planTier((int) $user->workspace_id);
            throw new ProjectCreationException('plan_duration_exceeded',
                "Your {$planTier} plan caps video length at {$maxDuration}s. This project asks for {$requestedDuration}s — shorten it or upgrade for longer videos.",
                422, ['plan' => $planTier, 'max_duration_seconds' => $maxDuration, 'requested' => $requestedDuration]);
        }

        // ── Credit guard ─────────────────────────────────────────────────
        $estimate = $this->credits->estimateProject(
            sourceType:    $validated['source_type'],
            sourceContent: $validated['source_content_raw'] ?? null,
            visualMode:    $validated['visual_generation_mode'] ?? 'stock',
            aiQuality:     $validated['ai_image_quality'] ?? 'medium',
            durationSeconds: (int) ($validated['duration_target_seconds'] ?? 60),
            animateTier:   $validated['animate_tier'] ?? null,
            animateQuality: $validated['animate_quality'] ?? null,
            animationPacing: $validated['animation_pacing'] ?? null,
        );
        $balance = $this->credits->balance((int) $user->workspace_id);
        if ($balance < $estimate['credits_min']) {
            throw new ProjectCreationException('insufficient_credits',
                "You need at least {$estimate['credits_min']} credits to generate this video. Your balance is {$balance}.",
                402, [
                    'balance'      => $balance,
                    'estimate_min' => $estimate['credits_min'],
                    'estimate_max' => $estimate['credits_max'],
                    'shortage'     => $estimate['credits_min'] - $balance,
                ]);
        }

        $sourceImageAssetIds = $this->resolveSourceImageAssetIds(
            $validated['source_type'],
            $validated['source_image_asset_ids'] ?? [],
            $user,
            $validated['visual_generation_mode'] ?? null,
        );

        if ($sourceImageAssetIds === null) {
            throw new ProjectCreationException('invalid_source_images', 'Upload Images requires 1-15 image assets from this workspace.', 422);
        }

        $channel = null;

        if (! empty($validated['channel_id'])) {
            $channel = Channel::query()
                ->whereKey($validated['channel_id'])
                ->where('workspace_id', $user->workspace_id)
                ->first();

            if (! $channel) {
                throw new ProjectCreationException('invalid_channel', 'Selected channel does not exist in this workspace.', 422);
            }
        }

        if (! empty($validated['brand_kit_id'])) {
            $brandKitExists = BrandKit::query()
                ->whereKey($validated['brand_kit_id'])
                ->where('workspace_id', $user->workspace_id)
                ->exists();

            if (! $brandKitExists) {
                throw new ProjectCreationException('invalid_brand_kit', 'Selected brand kit does not exist in this workspace.', 422);
            }
        }

        $template = null;

        if (! empty($validated['template_id'])) {
            $template = Template::query()
                ->whereKey($validated['template_id'])
                ->where(function ($query) use ($user): void {
                    $query->whereNull('workspace_id')
                        ->orWhere('workspace_id', $user->workspace_id);
                })
                ->first();

            if (! $template) {
                throw new ProjectCreationException('invalid_template', 'Selected template is not available in this workspace.', 422);
            }
        }

        $allowedTemplateIds = $channel?->allowed_template_ids;

        if ($channel && is_array($allowedTemplateIds) && $allowedTemplateIds !== []) {
            $allowedTemplateIds = array_map(static fn (mixed $id): int => (int) $id, $allowedTemplateIds);

            if ($template && ! in_array((int) $template->getKey(), $allowedTemplateIds, true)) {
                throw new ProjectCreationException('template_channel_conflict', 'Selected template is not allowed for this channel.', 422);
            }

            if (! $template) {
                $template = Template::query()
                    ->whereIn('id', $allowedTemplateIds)
                    ->where(function ($query) use ($user): void {
                        $query->whereNull('workspace_id')
                            ->orWhere('workspace_id', $user->workspace_id);
                    })
                    ->first();
            }
        }

        $brandKitId = $validated['brand_kit_id'] ?? $channel?->brand_kit_id;

        // Resolve niche and apply its defaults where not explicitly overridden.
        $niche = null;
        $nicheMusicAssetId = null;
        $nicheTone = null;
        $nicheVisualStyle = null;

        // Resolve character — validates workspace ownership; nullable.
        $defaultCharacterId = null;
        if (! empty($validated['character_id'])) {
            $character = \App\Models\Character::query()
                ->whereKey($validated['character_id'])
                ->where('workspace_id', $user->workspace_id)
                ->where('status', 'active')
                ->first();
            if (! $character) {
                throw new ProjectCreationException('invalid_character', 'Character not found in this workspace.', 422);
            }
            $defaultCharacterId = $character->getKey();
        }

        if (! empty($validated['niche_id'])) {
            $niche = Niche::query()->find($validated['niche_id']);

            if ($niche) {
                // Inherit tone from niche if not explicitly set.
                if (empty($validated['tone'])) {
                    $nicheTone = $niche->default_voice_tone;
                }

                if (empty($validated['visual_style'])) {
                    $nicheVisualStyle = $niche->default_visual_style;
                }

                // Pick a music asset matching the niche's mood if no template already sets one.
                if ($niche->default_music_mood) {
                    $nicheMusicAssetId = Asset::query()
                        ->where('workspace_id', $user->workspace_id)
                        ->where('asset_type', 'music')
                        ->whereJsonContains('tags', $niche->default_music_mood)
                        ->value('id');
                }

                // Use niche's template type to pick template if one wasn't explicitly chosen.
                if (! $template && $niche->default_template_type) {
                    $template = Template::query()
                        ->where('template_type', $niche->default_template_type)
                        ->where(function ($query) use ($user): void {
                            $query->whereNull('workspace_id')
                                ->orWhere('workspace_id', $user->workspace_id);
                        })
                        ->first();
                }
            }
        }

        // Resolve series and apply its defaults.
        $series = null;
        $seriesEpisodeNumber = null;

        if (! empty($validated['series_id'])) {
            $series = Series::query()
                ->whereKey($validated['series_id'])
                ->where('workspace_id', $user->workspace_id)
                ->first();

            if ($series) {
                $seriesEpisodeNumber = $validated['series_episode_number']
                    ?? ((int) Project::query()->where('series_id', $series->getKey())->max('series_episode_number') + 1);

                // Inherit series defaults where the request doesn't override.
                if (empty($validated['aspect_ratio']) && $series->aspect_ratio) {
                    $validated['aspect_ratio'] = $series->aspect_ratio;
                }
                if (empty($validated['duration_target_seconds']) && $series->duration_target_seconds) {
                    $validated['duration_target_seconds'] = $series->duration_target_seconds;
                }
                if (empty($validated['tone']) && $series->tone) {
                    $validated['tone'] = $series->tone;
                }
                if (empty($validated['platform_target']) && ! empty($series->platform_targets)) {
                    $validated['platform_target'] = $series->platform_targets[0];
                }
                if (empty($validated['languages']) && $series->default_language) {
                    $validated['languages'] = [$series->default_language];
                }
            }
        }

        // Apply final defaults for required fields still missing after all inheritance.
        if (empty($validated['aspect_ratio'])) {
            $validated['aspect_ratio'] = '9:16';
        }
        if (empty($validated['platform_target'])) {
            $validated['platform_target'] = 'tiktok';
        }
        if (empty($validated['languages'])) {
            $validated['languages'] = ['en'];
        }

        if (! isset($validated['voice_settings_json']) && isset($validated['voice_settings'])) {
            $validated['voice_settings_json'] = $validated['voice_settings'];
        }

        if (
            isset($validated['image_generation_settings_json']) &&
            ($validated['visual_generation_mode'] ?? null) === 'waveform'
        ) {
            $validated['waveform_settings_json'] = $validated['image_generation_settings_json'];
        }

        $defaultVisualStyle = $validated['visual_style']
            ?? $validated['ai_broll_style']
            ?? $nicheVisualStyle;

        if (
            empty($validated['ai_broll_style'])
            && ($validated['visual_generation_mode'] ?? null) === 'ai_images'
            && $defaultVisualStyle
        ) {
            $validated['ai_broll_style'] = $defaultVisualStyle;
        }

        $defaultVoiceSettings = is_array($validated['voice_settings_json'] ?? null)
            ? array_filter(
                $validated['voice_settings_json'],
                static fn (mixed $value): bool => $value !== null && $value !== ''
            )
            : null;

        $waveformSettings = is_array($validated['waveform_settings_json'] ?? null)
            ? array_filter(
                $validated['waveform_settings_json'],
                static fn (mixed $value): bool => $value !== null && $value !== ''
            )
            : null;

        $project = Project::query()->create([
            'workspace_id' => $user->workspace_id,
            'channel_id' => $channel?->getKey() ?? $series?->channel_id,
            'brand_kit_id' => $brandKitId,
            'template_id' => $template?->getKey(),
            'niche_id' => $niche?->getKey(),
            'default_character_id' => $defaultCharacterId,
            'music_asset_id' => $nicheMusicAssetId,
            'music_settings_json' => $nicheMusicAssetId ? ['volume' => 30, 'duck_volume' => 8, 'fade_in_ms' => 500, 'loop' => true, 'duck_during_voice' => true] : null,
            'source_type' => $validated['source_type'],
            'source_content_raw' => $validated['source_content_raw'] ?? null,
            'source_content_normalized' => $this->normalizeSource($validated['source_content_raw'] ?? ''),
            // Preserve the consent collected after the PDF dry run. Without
            // this assignment the database default (false) wins, so scanned
            // pages are never rendered even when the user selected AI reading.
            'pdf_read_scanned' => (bool) ($validated['pdf_read_scanned'] ?? false),
            'allow_script_edit' => (bool) ($validated['allow_script_edit'] ?? false),
            'source_image_asset_ids' => $sourceImageAssetIds,
            'visual_generation_mode' => $validated['visual_generation_mode'] ?? null,
            // The animation choice lives in visual_brief so the whole pipeline
            // (pacing, image job, estimator) reads one place. The brief job
            // MERGES into this column, so the seed survives enrichment.
            'visual_brief' => ($validated['visual_generation_mode'] ?? null) === 'ai_video' ? [
                'animate_tier'    => $validated['animate_tier'] ?? 'quick',
                'animation_pacing' => $validated['animation_pacing'] ?? 'short',
                'animate_quality' => $validated['animate_quality'] ?? null,
            ] : null,
            'ai_broll_style' => $validated['ai_broll_style'] ?? null,
            'waveform_settings_json' => $waveformSettings,
            'default_visual_style' => $defaultVisualStyle,
            'custom_visual_style' => $validated['custom_visual_style'] ?? null,
            'content_goal' => $validated['content_goal'] ?? null,
            'platform_target' => $validated['platform_target'],
            'duration_target_seconds' => $validated['duration_target_seconds'] ?? null,
            'aspect_ratio' => $validated['aspect_ratio'],
            'tone' => $validated['tone'] ?? $nicheTone,
            'default_voice_settings_json' => $defaultVoiceSettings,
            'primary_language' => $validated['languages'][0],
            'title' => $validated['title'] ?? null,
            'status' => $validated['source_type'] === 'blank' ? 'ready_for_review' : 'generating',
            'created_by_user_id' => $user->getKey(),
            'api_key_id' => $apiKeyId,
            'series_id' => $series?->getKey(),
            'series_episode_number' => $seriesEpisodeNumber,
        ]);

        // Blank projects skip AI generation — user builds all scenes manually in the editor.
        if ($validated['source_type'] !== 'blank') {
            GenerateScriptJob::dispatch($project->getKey());
        }

        return [
            'project' => $project,
            'channel' => $channel,
            'brand_kit_id' => $brandKitId,
            'template' => $template,
        ];
    }

    public function validateSourceContent(string $sourceType, ?string $source): ?string
    {
        // Blank projects have no source content — user builds scenes in the editor.
        if ($sourceType === 'blank') {
            return null;
        }

        $trimmed = trim((string) $source);

        if ($trimmed === '') {
            return 'Source content is required for the selected source type.';
        }

        if ($sourceType === 'url' && ! filter_var($trimmed, FILTER_VALIDATE_URL) && mb_strlen($trimmed) < 50) {
            return 'URL/article source requires a valid URL or at least 50 characters of article text.';
        }

        if (in_array($sourceType, ['prompt', 'script', 'product_description'], true) && mb_strlen($trimmed) < 10) {
            return 'Text source content must be at least 10 characters.';
        }

        return null;
    }

    /**
     * @param  mixed  $assetIds
     * @return list<int>|null
     */
    public function resolveSourceImageAssetIds(string $sourceType, mixed $assetIds, User $user, ?string $visualGenerationMode): ?array
    {
        if ($sourceType !== 'images') {
            return [];
        }

        $ids = array_values(array_unique(array_map(
            static fn (mixed $id): int => (int) $id,
            is_array($assetIds) ? $assetIds : [],
        )));

        if ($ids === [] && $visualGenerationMode === 'ai_images') {
            return [];
        }

        if ($ids === [] || count($ids) > 15) {
            return null;
        }

        $foundIds = Asset::query()
            ->where('workspace_id', $user->workspace_id)
            ->where('asset_type', 'image')
            ->whereIn('id', $ids)
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();

        if (count($foundIds) !== count($ids)) {
            return null;
        }

        return $ids;
    }

    public function normalizeSource(string $source): string
    {
        return trim(preg_replace('/\s+/', ' ', $source) ?? '');
    }

    public function visualGenerationModeFromVisualType(?string $visualType): ?string
    {
        return match ($visualType) {
            'ai_image' => 'ai_images',
            'stock_image' => 'stock_images',
            'waveform' => 'waveform',
            'stock_clip' => 'stock',
            default => null,
        };
    }
}
