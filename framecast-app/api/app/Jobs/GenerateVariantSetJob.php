<?php

namespace App\Jobs;

use App\Models\Asset;
use App\Models\Project;
use App\Models\Scene;
use App\Models\Variant;
use App\Models\VariantSet;
use App\Models\VoiceProfile;
use App\Services\Generation\TTS\TTSAdapter;
use App\Services\Generation\Visual\VisualProviderAdapter;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class GenerateVariantSetJob implements ShouldQueue
{
    use Queueable;

    public function __construct(
        public readonly int $variantSetId,
    ) {
        $this->onQueue('generation');
    }

    public function handle(TTSAdapter $tts, VisualProviderAdapter $visualProvider): void
    {
        $variantSet = VariantSet::query()->with(['baseProject', 'variants'])->find($this->variantSetId);

        if (! $variantSet || ! $variantSet->baseProject) {
            return;
        }

        $variantSet->forceFill(['status' => 'generating'])->save();

        $successCount = 0;
        $failedCount = 0;

        foreach ($variantSet->variants as $variant) {
            try {
                $this->generateVariant($variant, $variantSet->baseProject, $tts, $visualProvider);
                $successCount++;
            } catch (\Throwable $exception) {
                $variant->forceFill(['status' => 'failed'])->save();
                $failedCount++;
                report($exception);
            }
        }

        $variantSet->forceFill([
            'status' => $this->resolveVariantSetStatus($successCount, $failedCount),
        ])->save();
    }

    private function generateVariant(
        Variant $variant,
        Project $baseProject,
        TTSAdapter $tts,
        VisualProviderAdapter $visualProvider,
    ): void {
        $variant->forceFill(['status' => 'generating'])->save();

        $changed = $variant->changed_dimensions_json ?? [];

        DB::transaction(function () use ($variant, $baseProject, $changed, $tts, $visualProvider): void {
            $derivedProject = Project::query()->create([
                'workspace_id' => $baseProject->workspace_id,
                'channel_id' => $baseProject->channel_id,
                'brand_kit_id' => $baseProject->brand_kit_id,
                'template_id' => $baseProject->template_id,
                'source_type' => $baseProject->source_type,
                'source_content_raw' => $baseProject->source_content_raw,
                'source_content_normalized' => $baseProject->source_content_normalized,
                'content_goal' => $baseProject->content_goal,
                'platform_target' => $baseProject->platform_target,
                'duration_target_seconds' => $baseProject->duration_target_seconds,
                'aspect_ratio' => (string) ($changed['format']['aspect_ratio'] ?? $baseProject->aspect_ratio),
                'tone' => $baseProject->tone,
                'primary_language' => $baseProject->primary_language,
                'title' => trim(($baseProject->title ?: 'Untitled project').' ('.$variant->variant_label.')'),
                'script_text' => $baseProject->script_text,
                'status' => 'generating',
                'current_revision_id' => $baseProject->current_revision_id,
                'family_id' => $baseProject->family_id ?: $baseProject->getKey(),
                'created_by_user_id' => $baseProject->created_by_user_id,
            ]);

            $baseScenes = Scene::query()
                ->where('project_id', $baseProject->getKey())
                ->orderBy('scene_order')
                ->get();

            $voiceProfile = null;
            if (isset($changed['voice']['voice_profile_id'])) {
                $voiceProfile = VoiceProfile::query()->find((int) $changed['voice']['voice_profile_id']);
            }

            foreach ($baseScenes as $baseScene) {
                $voiceSettings = $baseScene->voice_settings_json ?? [];

                if ($voiceProfile) {
                    $voiceSettings['voice_id'] = $voiceProfile->provider_voice_key ?: data_get($voiceSettings, 'voice_id', 'alloy');
                    unset($voiceSettings['audio_asset_id']);
                }

                $clonedScene = Scene::query()->create([
                    'project_id' => $derivedProject->getKey(),
                    'scene_order' => $baseScene->scene_order,
                    'scene_type' => $baseScene->scene_type,
                    'label' => $baseScene->label,
                    'script_text' => $baseScene->script_text,
                    'duration_seconds' => $baseScene->duration_seconds,
                    'voice_profile_id' => $voiceProfile?->getKey() ?: $baseScene->voice_profile_id,
                    'voice_settings_json' => $voiceSettings,
                    'caption_settings_json' => $baseScene->caption_settings_json,
                    'visual_type' => $baseScene->visual_type,
                    'visual_asset_id' => $baseScene->visual_asset_id,
                    'visual_prompt' => $baseScene->visual_prompt,
                    'transition_rule' => $baseScene->transition_rule,
                    'status' => $baseScene->status,
                    'locked_fields_json' => $baseScene->locked_fields_json,
                ]);

                if ($clonedScene->scene_order === 1 && isset($changed['hook']['hook_text'])) {
                    $clonedScene->forceFill([
                        'script_text' => (string) $changed['hook']['hook_text'],
                    ])->save();
                }
            }

            /** @var Collection<int, Scene> $derivedScenes */
            $derivedScenes = Scene::query()
                ->where('project_id', $derivedProject->getKey())
                ->orderBy('scene_order')
                ->get();

            if (Arr::has($changed, 'visual.refresh')) {
                $this->regenerateVisuals($derivedProject, $derivedScenes, $visualProvider);
            }

            if (Arr::has($changed, 'hook.hook_text') || Arr::has($changed, 'voice.voice_profile_id')) {
                $this->regenerateTts($derivedProject, $derivedScenes, $tts);
            }

            $derivedProject->forceFill([
                'status' => 'ready_for_review',
            ])->save();

            $variant->forceFill([
                'derived_project_id' => $derivedProject->getKey(),
                'status' => 'ready_for_review',
            ])->save();
        });
    }

    /**
     * @param Collection<int, Scene> $scenes
     */
    private function regenerateVisuals(Project $project, Collection $scenes, VisualProviderAdapter $visualProvider): void
    {
        foreach ($scenes as $scene) {
            $prompt = trim((string) ($scene->visual_prompt ?: $scene->script_text ?: $scene->label ?: 'short-form video'));
            $orientation = (string) ($project->aspect_ratio ?? '') === '16:9' ? 'landscape' : 'portrait';
            $match = $visualProvider->match($prompt, $orientation, 'stock_clip');

            $asset = Asset::query()->create([
                'workspace_id' => $project->workspace_id,
                'channel_id' => $project->channel_id,
                'asset_type' => $match['asset_type'],
                'title' => 'Variant visual for project '.$project->getKey(),
                'description' => $prompt,
                'storage_url' => $match['asset_url'],
                'thumbnail_url' => $match['thumbnail_url'],
                'duration_seconds' => $match['duration_seconds'],
                'dimensions_json' => [
                    'width' => $match['width'],
                    'height' => $match['height'],
                ],
                'mime_type' => $match['mime_type'],
                'tags' => ['variant_visual', $match['provider_key']],
                'usage_count' => 1,
                'status' => 'active',
                'created_by_user_id' => $project->created_by_user_id,
            ]);

            $scene->forceFill([
                'visual_asset_id' => $asset->getKey(),
                'visual_type' => 'stock_clip',
                'visual_prompt' => $prompt,
            ])->save();
        }
    }

    /**
     * @param Collection<int, Scene> $scenes
     */
    private function regenerateTts(Project $project, Collection $scenes, TTSAdapter $tts): void
    {
        foreach ($scenes as $scene) {
            $voiceSettings = $scene->voice_settings_json ?? [];
            $voiceId = (string) data_get($voiceSettings, 'voice_id', 'alloy');
            $speed = (float) data_get($voiceSettings, 'speed', 1.0);
            $sceneText = trim((string) $scene->script_text);

            $audio = $tts->synthesize(
                $sceneText,
                (string) ($project->primary_language ?: 'en'),
                $voiceId,
                $speed
            );

            $asset = Asset::query()->create([
                'workspace_id' => $project->workspace_id,
                'channel_id' => $project->channel_id,
                'asset_type' => 'audio',
                'title' => 'Variant TTS audio for project '.$project->getKey().' scene '.$scene->scene_order,
                'description' => mb_substr($sceneText, 0, 180),
                'storage_url' => $audio['audio_url'],
                'duration_seconds' => $audio['duration_seconds'],
                'mime_type' => 'audio/mpeg',
                'tags' => ['variant_tts', $audio['provider_key']],
                'usage_count' => 1,
                'status' => 'active',
                'created_by_user_id' => $project->created_by_user_id,
            ]);

            $voiceSettings['provider_key'] = $audio['provider_key'];
            $voiceSettings['voice_id'] = $audio['provider_voice_id'];
            $voiceSettings['speed'] = $speed;
            $voiceSettings['language'] = (string) ($project->primary_language ?: 'en');
            $voiceSettings['audio_asset_id'] = $asset->getKey();
            $voiceSettings['is_outdated'] = false;

            $scene->forceFill([
                'duration_seconds' => $audio['duration_seconds'],
                'voice_settings_json' => $voiceSettings,
            ])->save();
        }
    }

    private function resolveVariantSetStatus(int $successCount, int $failedCount): string
    {
        if ($successCount > 0 && $failedCount > 0) {
            return 'partial_success';
        }

        if ($successCount > 0) {
            return 'completed';
        }

        return 'failed';
    }
}
